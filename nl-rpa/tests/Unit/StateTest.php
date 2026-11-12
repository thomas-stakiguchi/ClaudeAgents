<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Guard\ReadbackComparator;
use Src\State\Db;
use Src\State\Flags;
use Src\State\Ledger;
use Src\State\StepStateStore;

class StateTest extends TestCase
{
    private Db $db;
    private Ledger $ledger;
    private StepStateStore $states;
    private int $t = 0;

    protected function setUp(): void
    {
        $this->db = Db::open(':memory:');
        $clock = fn (): string => sprintf('2026-11-11T00:00:%02dZ', $this->t++ % 60);
        $this->ledger = new Ledger($this->db, $clock);
        $this->states = new StepStateStore($this->db, $this->ledger, $clock);
    }

    public function test_記録のファイルは所有者だけが読み書きできる(): void
    {
        $dir = sys_get_temp_dir().'/nlrpa_perm_'.bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $path = $dir.'/x.sqlite';
        $old = umask(0022);
        try {
            Db::open($path);
            $this->assertSame(0, fileperms($path) & 0077, 'データベースのファイルの権限が広い');
            foreach (['-wal', '-shm'] as $ext) {
                is_file($path.$ext) && $this->assertSame(0, fileperms($path.$ext) & 0077, $ext.' の権限が広い');
            }
            // 以前に、広い権限で作ったファイルも、開くときに絞る
            chmod($path, 0644);
            Db::open($path);
            $this->assertSame(0, fileperms($path) & 0077);
        } finally {
            umask($old);
            foreach (glob($dir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    public function test_記録のチェーンは連なり検証できる(): void
    {
        $this->assertSame(Ledger::GENESIS, $this->ledger->head());
        $this->ledger->append('a', 's1', (object) ['x' => 1]);
        $this->ledger->append('a', 's1', (object) ['x' => 2]);
        $this->assertNull($this->ledger->verify());
        $this->assertSame(2, $this->ledger->latest('a', 's1')->x);
        $this->assertCount(2, $this->ledger->all('a', 's1'));
        $this->assertNotSame(Ledger::GENESIS, $this->ledger->head());
    }

    public function test_記録は更新も削除もできない(): void
    {
        $this->ledger->append('a', 's', (object) ['x' => 1]);
        foreach (['UPDATE ledger SET kind = "b"', 'DELETE FROM ledger'] as $sql) {
            try {
                $this->db->pdo->exec($sql);
                $this->fail('拒否されるはず');
            } catch (\PDOException $e) {
                $this->assertStringContainsString('追記のみ', $e->getMessage());
            }
        }
    }

    public function test_トリガーを外して書き換えられたら検証で見つかる(): void
    {
        $this->ledger->append('a', 's', (object) ['x' => 1]);
        $this->ledger->append('a', 's', (object) ['x' => 2]);
        $this->db->pdo->exec('DROP TRIGGER ledger_no_update');
        $this->db->pdo->exec('DROP TRIGGER ledger_no_delete');
        $this->db->pdo->exec("UPDATE ledger SET payload = '{\"x\":9}' WHERE seq = 1");
        $this->assertNotNull($this->ledger->verify());
    }

    public function test_途中の記録を消すと検証で見つかる(): void
    {
        foreach ([1, 2, 3] as $i) {
            $this->ledger->append('a', 's', (object) ['x' => $i]);
        }
        $this->db->pdo->exec('DROP TRIGGER ledger_no_delete');
        $this->db->pdo->exec('DELETE FROM ledger WHERE seq = 2');
        $this->assertNotNull($this->ledger->verify());
    }

    public function test_状態の遷移(): void
    {
        $s = $this->states;
        $this->assertNull($s->get('K1', 'S-005'));
        $s->markProcessing('K1', 'S-005', 'h', 'r1');
        $this->assertSame('processing', $s->get('K1', 'S-005'));
        $this->assertSame(['S-005'], $s->unresolvedFor('K1'));
        $s->markSucceeded('K1', 'S-005');
        $this->assertSame('succeeded', $s->get('K1', 'S-005'));
        $this->assertSame([], $s->unresolvedFor('K1'));
        $this->assertNull($this->ledger->verify());
    }

    public function test_成功のあとは処理中にできない(): void
    {
        $this->states->markProcessing('K1', 'S-005', 'h', 'r1');
        $this->states->markSucceeded('K1', 'S-005');
        $this->expectException(\LogicException::class);
        $this->states->markProcessing('K1', 'S-005', 'h', 'r2');
    }

    public function test_要確認は人の判定で解消する(): void
    {
        $s = $this->states;
        $s->markProcessing('K1', 'S-005', 'h', 'r1');
        $s->markNeedsReview('K1', 'S-005');
        try {
            $s->markProcessing('K1', 'S-005', 'h', 'r2');
            $this->fail('要確認のままでは再実行できない');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        $s->resolve('K1', 'S-005', executed: false, judge: '山田');
        $this->assertSame('failed', $s->get('K1', 'S-005'));
        $s->markProcessing('K1', 'S-005', 'h', 'r3'); // 失敗は、再実行の対象
        $s->markNeedsReview('K1', 'S-005');
        $s->resolve('K1', 'S-005', executed: true, judge: '山田');
        $this->assertSame('succeeded', $s->get('K1', 'S-005'));
    }

    public function test_成功は人の判定でも変えられない(): void
    {
        $this->states->markProcessing('K1', 'S-005', 'h', 'r1');
        $this->states->markSucceeded('K1', 'S-005');
        $this->expectException(\LogicException::class);
        $this->states->resolve('K1', 'S-005', false, 'x');
    }

    public function test_異常終了のあとは処理中が要確認になる(): void
    {
        $this->states->markProcessing('K1', 'S-005', 'h', 'r1');
        $this->states->markProcessing('K2', 'S-005', 'h', 'r1');
        $this->states->markSucceeded('K2', 'S-005');
        $this->assertSame(1, $this->states->recoverAfterCrash());
        $this->assertSame('needs_review', $this->states->get('K1', 'S-005'));
        $this->assertSame('succeeded', $this->states->get('K2', 'S-005'));
    }

    public function test_状態の保存に失敗したら例外(): void
    {
        $this->db->pdo->exec("CREATE TRIGGER boom BEFORE INSERT ON step_states BEGIN SELECT RAISE(ABORT, 'boom'); END");
        try {
            $this->states->markProcessing('K1', 'S-005', 'h', 'r1');
            $this->fail('例外のはず');
        } catch (\PDOException) {
            $this->assertNull($this->states->get('K1', 'S-005'));
        }
        $this->assertNull($this->ledger->verify());
    }

    public function test_状態と記録は同じトランザクション(): void
    {
        // ledger の書き込みが失敗したら、状態も残らない
        $this->db->pdo->exec("CREATE TRIGGER boom BEFORE INSERT ON ledger BEGIN SELECT RAISE(ABORT, 'boom'); END");
        try {
            $this->states->markProcessing('K1', 'S-005', 'h', 'r1');
            $this->fail('例外のはず');
        } catch (\PDOException) {
            $this->assertNull($this->states->get('K1', 'S-005'));
        }
    }

    public function test_旗の変更は記録される(): void
    {
        $f = new Flags($this->db, $this->ledger);
        $this->assertFalse($f->isSet(Flags::KILL_SWITCH));
        $f->set(Flags::KILL_SWITCH, true, 'admin');
        $this->assertTrue($f->isSet(Flags::KILL_SWITCH));
        $this->assertTrue($this->ledger->latest('flag', Flags::KILL_SWITCH)->value);
        $this->expectException(\InvalidArgumentException::class);
        $f->set('unknown', true, 'x');
    }

    /** @return array<string,array{string,string,?array<string,mixed>,bool}> */
    public static function comparisons(): array
    {
        return [
            'ルールなしは完全一致' => ['A1', 'A1', null, true],
            'ルールなしで空白違い' => ['A1', 'A1 ', null, false],
            'trim' => ['A1', " A1\u{3000}", ['trim' => true], true],
            '桁区切り' => ['1234', '1,234', ['thousand_separator' => true], true],
            '桁区切りの形が不正' => ['1234', '1,23,4', ['thousand_separator' => true], false],
            '全角を半角に' => ['ＡＢ１２', 'AB12', ['width' => 'half'], true],
            '半角を全角に' => ['AB12', 'ＡＢ１２', ['width' => 'full'], true],
            '付録Aの書き方の書式' => ['2026-11-11', '2026-11-11', ['date_format' => 'YYYY-MM-DD'], true],
            '付録Aの書き方で、日付が違う' => ['2026-11-11', '2026-11-12', ['date_format' => 'YYYY-MM-DD'], false],
            '付録Aの書き方で、時刻まで含む' => ['2026-11-11 10:00', '2026-11-11 10:00', ['date_format' => 'YYYY-MM-DD HH:mm'], true],
            '対応しない書式は一致しない' => ['2026-11-11', '2026-11-11', ['date_format' => 'YYYY年MM月'], false],
            '日付の書式' => ['2026-11-11', '2026/11/11', ['date_format' => 'Y-m-d'], false],
            '日付は同じ書式で読める' => ['2026-11-11', '2026-11-11', ['date_format' => 'Y-m-d'], true],
            '日付が不正' => ['2026-13-40', '2026-13-40', ['date_format' => 'Y-m-d'], false],
            '切り詰め' => ['ABCDEFG', 'ABCDE', ['max_length' => 5], true],
            '切り詰めと違う' => ['ABCDEFG', 'ABCDX', ['max_length' => 5], false],
            '画面のほうが長い' => ['ABC', 'ABCDE', ['max_length' => 2], false],
            '値が違う' => ['100', '1000', ['thousand_separator' => true], false],
            '時刻が違う日付' => ['2026-11-11 10:00', '2026-11-11 23:59', ['date_format' => 'Y-m-d H:i'], false],
            '時刻が同じ日付' => ['2026-11-11 10:00', '2026-11-11 10:00', ['date_format' => 'Y-m-d H:i'], true],
            'UNIX秒の違い' => ['0', '86399', ['date_format' => 'U'], false],
        ];
    }

    /** @param array<string,mixed>|null $rule */
    #[DataProvider('comparisons')]
    public function test_照合ルール(string $expected, string $actual, ?array $rule, bool $ok): void
    {
        $this->assertSame($ok, ReadbackComparator::equal($expected, $actual, $rule === null ? null : (object) $rule));
    }

    public function test_入れ子の内側が失敗して外側が握りつぶしても内側の書き込みは残らない(): void
    {
        $this->db->immediate(function (): void {
            try {
                $this->db->immediate(function (): void {
                    $this->db->pdo->exec("INSERT INTO flags VALUES ('inner', 1)");
                    throw new \RuntimeException('内側の失敗');
                });
            } catch (\RuntimeException) {
                // 外側は、握りつぶして続ける
            }
            $this->db->pdo->exec("INSERT INTO flags VALUES ('outer', 1)");
        });
        $names = array_column($this->db->pdo->query('SELECT name FROM flags ORDER BY name')->fetchAll(), 'name');
        $this->assertSame(['outer'], $names);
    }

    public function test_業務キーに改行があっても別の組と取り違えない(): void
    {
        $this->assertNotSame(StepStateStore::subject("A\nS-005", 'X'), StepStateStore::subject('A', "S-005\nX"));
    }

    public function test_状態の表の削除はできない(): void
    {
        $this->states->markProcessing('K1', 'S-005', 'h', 'r1');
        $this->expectException(\PDOException::class);
        $this->db->pdo->exec('DELETE FROM step_states');
    }

    public function test_同じ業務キーの別の手順は未解消の間は処理中にできない(): void
    {
        $this->states->markProcessing('K1', 'S-003', 'h', 'r1');
        $this->expectException(\LogicException::class);
        $this->states->markProcessing('K1', 'S-005', 'h', 'r1');
    }

    public function test_複数のプロセスが同時に新しいデータベースを開いても失敗しない(): void
    {
        $failures = [];
        for ($round = 0; $round < 5; $round++) {
            $path = sys_get_temp_dir().'/nlrpa_dbopen_'.bin2hex(random_bytes(4)).'.sqlite';
            $procs = [];
            for ($i = 0; $i < 6; $i++) {
                $procs[] = [proc_open([PHP_BINARY, dirname(__DIR__).'/Support/db_open.php', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes];
            }
            foreach ($procs as [$p, $pipes]) {
                $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($p);
                $out === 'OK' || $failures[] = $out;
            }
            foreach (glob($path.'*') ?: [] as $f) {
                @unlink($f);
            }
        }
        $this->assertSame([], $failures);
    }

    public function test_ほかのプロセスが長く排他ロックしていても_待って開ける(): void
    {
        $path = sys_get_temp_dir().'/nlrpa_hold_'.bin2hex(random_bytes(4)).'.sqlite';
        // WALではない、ふつうのDBを、先に作る。Db::open は、WALへの切り替えが要り、他のプロセスが排他ロックしている間は、待たされる
        $raw = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $raw->exec('CREATE TABLE t (x INTEGER)');
        unset($raw);
        $p = proc_open([PHP_BINARY, dirname(__DIR__).'/Support/db_hold.php', $path, '1.5'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        try {
            $this->assertSame('LOCKED', trim((string) fgets($pipes[1])));
            $t = microtime(true);
            $db = Db::open($path);
            $this->assertGreaterThan(0.3, microtime(true) - $t, 'ロックの間に、待たずに開いた（試験が、意味を持っていない）');
            $this->assertNotNull($db->pdo->query('SELECT 1')->fetch());
        } finally {
            @proc_close($p);
            foreach (glob($path.'*') ?: [] as $f) {
                @unlink($f);
            }
        }
    }

    public function test_古いデータベースに同時に列を足しても_重複で失敗しない(): void
    {
        $path = sys_get_temp_dir().'/nlrpa_old_'.bin2hex(random_bytes(4)).'.sqlite';
        // proc_start の列がない、古い形のDBを作る
        $pdo = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE workers (pid INTEGER PRIMARY KEY, request_id INTEGER NOT NULL, started_at TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE browser_profiles (dir TEXT PRIMARY KEY, worker_pid INTEGER NOT NULL, created_at TEXT NOT NULL)');
        unset($pdo);
        $procs = [];
        for ($i = 0; $i < 6; $i++) {
            $procs[] = [proc_open([PHP_BINARY, dirname(__DIR__).'/Support/db_open.php', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes];
        }
        $out = [];
        foreach ($procs as [$p, $pipes]) {
            $out[] = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($p);
        }
        $this->assertSame(array_fill(0, 6, 'OK'), $out);
        $cols = array_column(Db::open($path)->pdo->query('PRAGMA table_info(workers)')->fetchAll(), 'name');
        $this->assertContains('proc_start', $cols);
        foreach (glob($path.'*') ?: [] as $f) {
            @unlink($f);
        }
    }
}
