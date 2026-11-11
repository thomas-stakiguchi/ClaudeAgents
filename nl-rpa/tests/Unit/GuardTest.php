<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Src\Ai\StubAiClient;
use Src\Guard\ApprovalChecker;
use Src\Guard\GuardedAction;
use Src\Guard\GuardOutcome;
use Src\Guard\GuardRequest;
use Src\Guard\IrreversibleGuard;
use Src\Guard\Permit;
use Src\Guard\Redeemed;
use Src\Guard\RunRegistrar;
use Src\Plan\PlanHasher;
use Src\Plan\StrictJson;
use Src\State\Db;
use Src\State\Flags;
use Src\State\Ledger;
use Src\State\StepStateStore;

/** 試験用の取り消せない操作。実際に操作した回数（effect を通った回数）を数える */
final class TestAction extends GuardedAction
{
    public int $calls = 0;
    /** @var (\Closure(Permit):void)|null */
    public ?\Closure $during = null;
    public ?Redeemed $lastProof = null;

    public function __construct(IrreversibleGuard $g, private readonly bool $result = true, private readonly bool $throw = false)
    {
        parent::__construct($g);
    }

    protected function execute(Redeemed $proof): bool
    {
        $this->lastProof = $proof;
        $this->during && ($this->during)($proof->permit);
        $this->effect($proof, function (\stdClass $step): void {
            $this->calls++;
            if ($this->throw) {
                throw new \RuntimeException('接続が切れた');
            }
        });

        return $this->result;
    }

    /** 裏口：検査を通らずに execute を呼ぶ */
    public function backdoor(Redeemed $forged): bool
    {
        return $this->execute($forged);
    }
}

class GuardTest extends TestCase
{
    private Db $db;
    private Ledger $ledger;
    private StepStateStore $states;
    private Flags $flags;
    private IrreversibleGuard $guard;
    private ApprovalChecker $approvals;
    private RunRegistrar $registrar;
    private \stdClass $plan;
    /** @var array<string,mixed> */
    private array $inputs;
    private int $t = 0;
    /** @var list<array<string,string>> */
    private array $rows;

    protected function setUp(): void
    {
        $this->build(Db::open(':memory:'));
        $this->plan = StrictJson::decode(StubAiClient::defaultPlanJson());
        $this->inputs = ['operations' => (object) ['v' => 1], 'destinations' => (object) ['v' => 1], 'registrations' => [], 'screen_record' => (object) []];
        $this->rows = [
            ['商品コード' => 'ABC', '数量' => '1200', '伝票番号' => 'K-001'],
            ['商品コード' => 'DEF', '数量' => '30', '伝票番号' => 'K-002'],
        ];
        $this->approve();
        $this->assertSame([], $this->register('RUN-1'));
    }

    private function build(Db $db): void
    {
        $this->db = $db;
        $clock = fn (): string => sprintf('2026-11-11T00:00:%02dZ', $this->t++ % 60);
        $this->ledger = new Ledger($this->db, $clock);
        $this->states = new StepStateStore($this->db, $this->ledger, $clock);
        $this->flags = new Flags($this->db, $this->ledger);
        $this->approvals = new ApprovalChecker($this->ledger);
        $this->guard = new IrreversibleGuard($this->ledger, $this->states, $this->flags, $this->approvals);
        $this->registrar = new RunRegistrar($this->ledger, $this->approvals);
    }

    private function approve(string $env = 'stub', string $result = 'passed', string $dryRunId = 'DR-1'): void
    {
        $hash = PlanHasher::hash($this->plan, $this->inputs);
        $this->ledger->append('approval', $hash, (object) ['stage' => 'final', 'approver' => '佐藤', 'os_user' => 'tester']);
        $this->ledger->append('dry_run', $hash, (object) ['dry_run_id' => $dryRunId, 'result' => $result, 'environment' => $env, 'destination_name' => 'stub']);
    }

    /** @return list<string> */
    private function register(string $runId, ?array $rows = null, string $dest = 'stub'): array
    {
        return $this->registrar->register($runId, $this->plan, $this->inputs, 'DR-1', $dest, $rows ?? $this->rows);
    }

    /** @param array<string,mixed> $o */
    private function req(array $o = []): GuardRequest
    {
        $d = array_merge([
            'plan' => $this->plan,
            'hashInputs' => $this->inputs,
            'stepId' => 'S-005',
            'businessKey' => 'K-001',
            'runId' => 'RUN-1',
            'actualDestinationName' => 'stub',
            'rowValues' => $this->rows[0],
            'readbackRaw' => ['item_code' => ' ABC ', 'quantity' => '1,200'],
        ], $o);

        return new GuardRequest(...$d);
    }

    private function action(bool $result = true, bool $throw = false): TestAction
    {
        return new TestAction($this->guard, $result, $throw);
    }

    private function assertRefused(GuardOutcome $o, TestAction $a, string $needle, string $key = 'K-001'): void
    {
        $this->assertFalse($o->executed);
        $this->assertSame(0, $a->calls, '拒否したのに操作が実行された');
        $this->assertNull($this->states->get($key, 'S-005'), '拒否したのに「処理中」が残った');
        $this->assertTrue((bool) array_filter($o->reasons, fn ($r) => str_contains($r, $needle)), "「{$needle}」がない: ".implode(' / ', $o->reasons));
        $this->assertNotNull($this->ledger->latest('refusal', StepStateStore::subject($key, 'S-005')), '拒否の理由が記録されていない');
    }

    // ---- 基本 ----

    public function test_すべて満たせば実行され成功が記録される(): void
    {
        $a = $this->action();
        $o = $this->guard->run($this->req(), $a);
        $this->assertSame([], $o->reasons);
        $this->assertTrue($o->executed && $o->confirmed);
        $this->assertSame(1, $a->calls);
        $this->assertSame('succeeded', $this->states->get('K-001', 'S-005'));
        $this->assertNull($this->ledger->verify());
    }

    public function test_最終承認がなければ拒否(): void
    {
        $this->inputs['operations'] = (object) ['v' => 2]; // 許可リストの中身が変わると、計画ハッシュが変わる
        $a = $this->action();
        $this->assertRefused($this->guard->run($this->req(['hashInputs' => $this->inputs]), $a), $a, '最終承認');
    }

    public function test_承認後に計画が書き換わったら拒否(): void
    {
        $this->plan->steps[1]->steps[2]->target = 'delete_button';
        $a = $this->action();
        $this->assertRefused($this->guard->run($this->req(), $a), $a, '最終承認');
    }

    public function test_承認の記録の形が欠けていれば無効(): void
    {
        $this->build(Db::open(':memory:'));
        $hash = PlanHasher::hash($this->plan, $this->inputs);
        $this->ledger->append('approval', $hash, (object) ['stage' => 'final']); // 承認者がない
        $this->ledger->append('dry_run', $hash, (object) ['dry_run_id' => 'DR-1', 'result' => 'passed', 'environment' => 'stub']); // 接続先がない
        $this->assertNotSame([], $this->register('RUN-X'));
    }

    public function test_実行の登録がなければ拒否(): void
    {
        $a = $this->action();
        $o = $this->guard->run($this->req(['runId' => 'RUN-999']), $a);
        $this->assertFalse($o->executed);
        $this->assertSame(0, $a->calls);
        $this->assertContains('実行が登録されていません', $o->reasons);
    }

    public function test_不合格のドライランでは登録できない(): void
    {
        $this->build(Db::open(':memory:'));
        $this->approve('stub', 'failed');
        $this->assertNotSame([], $this->register('RUN-X'));
    }

    public function test_1bに入ったらスタブのドライランは無効(): void
    {
        $approvals = new ApprovalChecker($this->ledger, ['test_env']);
        $guard = new IrreversibleGuard($this->ledger, $this->states, $this->flags, $approvals);
        $a = new TestAction($guard);
        $o = $guard->run($this->req(), $a);
        $this->assertFalse($o->executed);
        $this->assertSame(0, $a->calls);
        $this->assertNotEmpty(array_filter($o->reasons, fn ($r) => str_contains($r, 'ドライランの環境')));
    }

    public function test_キルスイッチと停止の旗(): void
    {
        foreach ([Flags::KILL_SWITCH => 'キルスイッチ', Flags::STOP => '停止の旗'] as $flag => $msg) {
            $this->flags->set($flag, true, 'admin');
            $a = $this->action();
            $this->assertRefused($this->guard->run($this->req(), $a), $a, $msg);
            $this->flags->set($flag, false, 'admin');
        }
    }

    public function test_旗の表を書き換えて消しても記録が優先される(): void
    {
        $this->flags->set(Flags::KILL_SWITCH, true, 'admin');
        $this->db->pdo->exec('UPDATE flags SET value = 0'); // 表だけ書き換える
        $a = $this->action();
        $this->assertRefused($this->guard->run($this->req(), $a), $a, 'キルスイッチ');
        $this->expectException(\PDOException::class); // 削除はできない
        $this->db->pdo->exec('DELETE FROM flags');
    }

    public function test_未確定が残っていれば拒否(): void
    {
        $this->plan->unresolved = ['納品日の入力元'];
        $this->approve();
        $this->assertContains('「未確定」が残っています', $this->register('RUN-X'));
    }

    // ---- 読み返し照合 ----

    public function test_読み返した値が違えば拒否(): void
    {
        $a = $this->action();
        $this->assertRefused($this->guard->run($this->req(['readbackRaw' => ['item_code' => 'ABX', 'quantity' => '1,200']]), $a), $a, '一致しません');
    }

    public function test_読み返した値がなければ拒否(): void
    {
        $a = $this->action();
        $this->assertRefused($this->guard->run($this->req(['readbackRaw' => ['item_code' => 'ABC']]), $a), $a, '読み返した値がありません');
    }

    public function test_読み返し項目が指定されていない手順は拒否(): void
    {
        unset($this->plan->steps[1]->steps[2]->readback);
        $this->approve();
        $this->assertSame([], $this->register('RUN-2'));
        $a = $this->action();
        $this->assertRefused($this->guard->run($this->req(['runId' => 'RUN-2']), $a), $a, '読み返し照合の項目');
    }

    public function test_行の値を偽って照合を通そうとしても拒否(): void
    {
        // 読み返した値に合わせて、行の値を偽る
        $a = $this->action();
        $fake = ['商品コード' => 'ZZZ', '数量' => '999', '伝票番号' => 'K-001'];
        $o = $this->guard->run($this->req(['rowValues' => $fake, 'readbackRaw' => ['item_code' => 'ZZZ', 'quantity' => '999']]), $a);
        $this->assertRefused($o, $a, '登録した入力データと違います');
    }

    // ---- 手順・接続先 ----

    public function test_取り消せる手順はこの関数を通さない(): void
    {
        $a = $this->action();
        $o = $this->guard->run($this->req(['stepId' => 'S-006']), $a);
        $this->assertFalse($o->executed);
        $this->assertSame(0, $a->calls);
        $this->assertContains('取り消せる手順は、この関数を通しません', $o->reasons);
    }

    public function test_計画にない手順IDは拒否(): void
    {
        $a = $this->action();
        $o = $this->guard->run($this->req(['stepId' => 'S-999']), $a);
        $this->assertFalse($o->executed);
        $this->assertSame(0, $a->calls);
    }

    public function test_実際の接続先が計画と違えば拒否(): void
    {
        $a = $this->action();
        $this->assertRefused($this->guard->run($this->req(['actualDestinationName' => 'prod']), $a), $a, '接続先');
    }

    public function test_定数にない接続先は登録も実行もできない(): void
    {
        $this->assertNotSame([], $this->register('RUN-2', null, 'prod'));
        $this->plan->steps[1]->steps[2]->destination->name = 'prod';
        $this->approve();
        $this->assertNotSame([], $this->register('RUN-3', null, 'prod'));
    }

    public function test_不正な計画は拒否(): void
    {
        $this->plan->steps[1]->steps[2]->evil = 1;
        $a = $this->action();
        $o = $this->guard->run($this->req(), $a);
        $this->assertFalse($o->executed);
        $this->assertSame(0, $a->calls);
    }

    // ---- 二重実行・状態 ----

    public function test_すでに成功した業務キーは拒否(): void
    {
        $this->guard->run($this->req(), $this->action());
        $a = $this->action();
        $o = $this->guard->run($this->req(), $a);
        $this->assertFalse($o->executed);
        $this->assertSame(0, $a->calls);
        $this->assertContains('この業務キー・手順は、すでに成功しています', $o->reasons);
    }

    public function test_同じ業務キーに未解消があれば別の手順も拒否(): void
    {
        $this->states->markProcessing('K-001', 'S-003', 'h', 'RUN-0');
        $this->states->markNeedsReview('K-001', 'S-003');
        $a = $this->action();
        $o = $this->guard->run($this->req(), $a);
        $this->assertFalse($o->executed);
        $this->assertSame(0, $a->calls);
        $this->assertNotEmpty(array_filter($o->reasons, fn ($r) => str_contains($r, '未解消')));
    }

    public function test_状態の表を書き換えたら記録との食い違いで拒否(): void
    {
        $this->guard->run($this->req(), $this->action());
        $this->db->pdo->exec("UPDATE step_states SET state = 'failed'"); // 成功を失敗に書き換えて、再実行させようとする
        $a = $this->action();
        $o = $this->guard->run($this->req(), $a);
        $this->assertFalse($o->executed);
        $this->assertSame(0, $a->calls);
        $this->assertNotEmpty(array_filter($o->reasons, fn ($r) => str_contains($r, '食い違って')));
    }

    public function test_業務キーが登録した入力データになければ拒否(): void
    {
        $a = $this->action();
        $o = $this->guard->run($this->req(['businessKey' => 'K-999', 'rowValues' => ['商品コード' => 'ABC', '数量' => '1200', '伝票番号' => 'K-999']]), $a);
        $this->assertFalse($o->executed);
        $this->assertSame(0, $a->calls);
    }

    // ---- 上限・初回1件 ----

    public function test_登録時に件数の上限を超えていれば登録できない(): void
    {
        $this->plan->limits->max_rows_per_run = 1;
        $this->approve();
        $this->assertContains('件数の上限を超えています', $this->register('RUN-2'));
    }

    public function test_登録時に数量の上限を超えていれば登録できない(): void
    {
        $this->plan->limits->max_quantity_per_run = 1000;
        $this->approve();
        $this->assertContains('数量の上限を超えています', $this->register('RUN-2'));
    }

    public function test_入力データの不正は登録できない(): void
    {
        $bad = [
            [['商品コード' => 'A', '数量' => '1', '伝票番号' => 'K-1'], ['商品コード' => 'B', '数量' => '1', '伝票番号' => 'K-1']], // 重複
            [['商品コード' => 'A', '数量' => 'abc', '伝票番号' => 'K-1']],
            [['商品コード' => "A\n", '数量' => '1', '伝票番号' => 'K-1']],
            [['商品コード' => 'A', '数量' => '1']],
            [['商品コード' => str_repeat('A', 13), '数量' => '1', '伝票番号' => 'K-1']],
            [],
        ];
        foreach ($bad as $i => $rows) {
            $this->assertNotSame([], $this->register("RUN-B{$i}", $rows), "case {$i}");
        }
    }

    public function test_同じ実行IDは二重に登録できない(): void
    {
        $this->assertNotSame([], $this->register('RUN-1'));
    }

    public function test_2行目は初回の1件が成功し確認されるまで拒否(): void
    {
        $a = $this->action();
        $req2 = fn () => $this->req([
            'businessKey' => 'K-002',
            'rowValues' => $this->rows[1],
            'readbackRaw' => ['item_code' => 'DEF', 'quantity' => '30'],
        ]);
        $o = $this->guard->run($req2(), $a);
        $this->assertContains('初回の1件の確認が済んでいません', $o->reasons);
        // 呼び出し側が行の順番や件数を偽っても、2行目は2行目として扱われる（登録した順番で決まる）。1件目が成功する前の確認は、無効
        $this->ledger->append('first_row_confirmed', 'RUN-1', (object) ['by' => '佐藤', 'os_user' => 'tester']);
        $o = $this->guard->run($req2(), $a);
        $this->assertContains('初回の1件が、成功していません', $o->reasons);
        $this->assertTrue($this->guard->run($this->req(), $this->action())->confirmed);
        $o = $this->guard->run($req2(), $a);
        $this->assertContains('初回の1件の確認が、その1件の成功より前に記録されています', $o->reasons);
        // 1件目の成功のあとに、確認者つきで確認したら、2件目に進める
        $this->ledger->append('first_row_confirmed', 'RUN-1', (object) ['by' => '佐藤', 'os_user' => 'tester']);
        $this->assertTrue($this->guard->run($req2(), $a)->executed);
        // 確認者のない記録は、無効
        $this->assertSame('succeeded', $this->states->get('K-002', 'S-005'));
    }

    // ---- 実行後の異常 ----

    public function test_操作が例外になれば要確認になる(): void
    {
        $o = $this->guard->run($this->req(), $this->action(throw: true));
        $this->assertTrue($o->executed);
        $this->assertFalse($o->confirmed);
        $this->assertSame('needs_review', $this->states->get('K-001', 'S-005'));
    }

    public function test_確認条件を満たせなければ要確認になる(): void
    {
        $o = $this->guard->run($this->req(), $this->action(result: false));
        $this->assertFalse($o->confirmed);
        $this->assertSame('needs_review', $this->states->get('K-001', 'S-005'));
        $a = $this->action();
        $this->assertFalse($this->guard->run($this->req(), $a)->executed);
        $this->assertSame(0, $a->calls);
    }

    public function test_処理中を保存できなければ実行しない(): void
    {
        $this->db->pdo->exec("CREATE TRIGGER boom BEFORE INSERT ON step_states BEGIN SELECT RAISE(ABORT, 'boom'); END");
        $a = $this->action();
        $o = $this->guard->run($this->req(), $a);
        $this->assertFalse($o->executed);
        $this->assertSame(0, $a->calls);
        $this->assertNotEmpty(array_filter($o->reasons, fn ($r) => str_contains($r, '処理中')));
    }

    public function test_実行後の状態を保存できなくても例外で抜けずに結果を返す(): void
    {
        $a = $this->action();
        $a->during = function (): void {
            $this->db->pdo->exec("CREATE TRIGGER boom2 BEFORE UPDATE ON step_states BEGIN SELECT RAISE(ABORT, 'boom'); END");
        };
        $o = $this->guard->run($this->req(), $a);
        $this->assertTrue($o->executed);
        $this->assertFalse($o->confirmed);
        $this->assertSame('processing', $this->states->get('K-001', 'S-005'));
        $this->assertNotNull($this->ledger->latest('post_exec_failure', StepStateStore::subject('K-001', 'S-005')), '拒否ではなく、実行後の失敗として記録される');
        $this->assertNull($this->ledger->latest('refusal', StepStateStore::subject('K-001', 'S-005')));
        $this->db->pdo->exec('DROP TRIGGER boom2');
        $this->assertSame(1, $this->states->recoverAfterCrash()); // 次の起動時に、要確認になる
    }

    // ---- 迂回の試み（Opusレビュー H1・M3・高-1） ----

    public function test_許可オブジェクトの偽造はどれも操作の直前で止まる(): void
    {
        $a = $this->action();
        $rc = new \ReflectionClass(Permit::class);
        // 最も強い攻撃：生成したあとに、privateのコンストラクタを直接呼ぶ
        $viaCtor = function (string $nonce) use ($rc): Permit {
            $o = $rc->newInstanceWithoutConstructor();
            $rc->getConstructor()->invoke($o, 'h', 'S-005', 'K-001', 'RUN-1', $nonce, '{}');

            return $o;
        };
        $forged = [
            fn () => $rc->newInstanceWithoutConstructor(),
            fn () => $viaCtor('guess'),
            fn () => Permit::mint((new \ReflectionClass(IrreversibleGuard::class))->newInstanceWithoutConstructor(), 'h', 'S-005', 'K-001', 'RUN-1', 'guess', '{}'),
        ];
        foreach ($forged as $i => $make) {
            try {
                $a->perform($make());
                $this->fail("case {$i}: 偽造した許可で実行できた");
            } catch (\Throwable $e) {
                $this->assertSame(0, $a->calls, "case {$i}");
            }
        }
    }

    public function test_継承して_execute_を直接呼ぶ裏口でも_effect_で止まる(): void
    {
        $a = $this->action();
        $rcP = new \ReflectionClass(Permit::class);
        $permit = Permit::mint($this->guard, 'h', 'S-005', 'K-001', 'RUN-1', 'x', '{}');
        $rcR = new \ReflectionClass(Redeemed::class);
        $viaCtor = $rcR->newInstanceWithoutConstructor();
        $rcR->getConstructor()->invoke($viaCtor, $permit, 'guess');
        $forgedProofs = [
            Redeemed::mint($this->guard, $permit, 'guess'),
            $viaCtor,
            $rcR->newInstanceWithoutConstructor(),
        ];
        foreach ($forgedProofs as $i => $proof) {
            try {
                $a->backdoor($proof);
                $this->fail("case {$i}: 裏口から実行できた");
            } catch (\Throwable) {
                $this->assertSame(0, $a->calls, "case {$i}");
            }
        }
        $this->assertNull($this->states->get('K-001', 'S-005'));
    }

    public function test_本物の証明も使い回せない(): void
    {
        $a = $this->action();
        $this->assertTrue($this->guard->run($this->req(), $a)->confirmed);
        $this->assertSame(1, $a->calls);
        $this->expectException(\LogicException::class);
        $a->backdoor($a->lastProof); // 使用済みの証明を、もう一度
    }

    public function test_個別登録は承認済みの内容から読む_登録があれば取り消せる扱いになりGuardを通らない(): void
    {
        $this->inputs['registrations'] = (object) ['buttons' => [(object) ['destination' => 'stub', 'target' => 'submit_button']]];
        $this->plan->steps[1]->steps[2]->readback = $this->plan->steps[1]->steps[2]->readback; // 変更なし
        $this->approve();
        $this->assertSame([], $this->register('RUN-R'));
        $a = $this->action();
        // S-005 は、登録されたボタンなので取り消せる扱い。この関数は通さない
        $o = $this->guard->run($this->req(['runId' => 'RUN-R', 'hashInputs' => $this->inputs]), $a);
        $this->assertFalse($o->executed);
        $this->assertContains('取り消せる手順は、この関数を通しません', $o->reasons);
        // S-003（type_text、登録なし）は、取り消せない
        $this->assertNotContains('取り消せる手順は、この関数を通しません', $this->guard->run($this->req(['runId' => 'RUN-R', 'hashInputs' => $this->inputs, 'stepId' => 'S-003']), $a)->reasons);
    }

    public function test_個別登録の形が不正なら拒否(): void
    {
        $this->inputs['registrations'] = (object) ['evil' => []];
        $this->approve();
        $this->assertSame([], $this->register('RUN-R'));
        $a = $this->action();
        $o = $this->guard->run($this->req(['runId' => 'RUN-R', 'hashInputs' => $this->inputs]), $a);
        $this->assertFalse($o->executed);
        $this->assertNotEmpty(array_filter($o->reasons, fn ($r) => str_contains($r, '個別登録の形式')));
    }

    public function test_親のコンストラクタを呼ばない継承でも例外を漏らさず拒否(): void
    {
        $evil = new class($this->guard) extends GuardedAction {
            public function __construct(IrreversibleGuard $g)
            {
                // 親を呼ばない
            }

            protected function execute(Redeemed $proof): bool
            {
                return true;
            }
        };
        $o = $this->guard->run($this->req(), $evil);
        $this->assertFalse($o->executed);
        $this->assertNull($this->states->get('K-001', 'S-005'));
    }

    public function test_許可は複製も直列化もできない(): void
    {
        $captured = null;
        $a = $this->action();
        $a->during = function (Permit $p) use (&$captured): void {
            $captured = $p;
        };
        $this->guard->run($this->req(), $a);
        $this->assertInstanceOf(Permit::class, $captured);
        foreach ([fn () => clone $captured, fn () => serialize($captured), fn () => unserialize('O:'.strlen(Permit::class).':"'.Permit::class.'":0:{}')] as $i => $f) {
            try {
                $f();
                $this->fail("case {$i}");
            } catch (\Throwable) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_使用済みの許可は再利用できない(): void
    {
        $captured = null;
        $a = $this->action();
        $a->during = function (Permit $p) use (&$captured): void {
            $captured = $p;
        };
        $this->guard->run($this->req(), $a);
        $this->expectException(\LogicException::class);
        $a->perform($captured); // 同じ許可で、もう一度
    }

    public function test_別のGuardに結び付いた操作は拒否(): void
    {
        $other = new IrreversibleGuard($this->ledger, $this->states, $this->flags, $this->approvals);
        $a = new TestAction($other);
        $o = $this->guard->run($this->req(), $a);
        $this->assertFalse($o->executed);
        $this->assertSame(0, $a->calls);
    }

    public function test_検査のあとに計画を書き換えても実行する手順は検査した時点のもの(): void
    {
        $seen = null;
        $a = $this->action();
        $a->during = function (Permit $p) use (&$seen): void {
            $this->plan->steps[1]->steps[2]->target = 'delete_button'; // 実行中に、計画の元のオブジェクトを書き換える
            $seen = $p->step()->target;
        };
        $this->assertTrue($this->guard->run($this->req(), $a)->confirmed);
        $this->assertSame('submit_button', $seen);
    }

    // ---- 同時実行（Opusレビュー H3） ----

    public function test_複数プロセスが同じ業務キーの別の手順を同時に処理しても1つだけ(): void
    {
        $path = sys_get_temp_dir().'/nlrpa_race_'.bin2hex(random_bytes(4)).'.sqlite';
        Db::open($path);
        $worker = dirname(__DIR__).'/Support/race_worker.php';
        $rounds = 15;
        $bothOk = 0;
        for ($i = 0; $i < $rounds; $i++) {
            $procs = [];
            foreach (['S-003', 'S-005', 'S-004'] as $step) {
                $cmd = [PHP_BINARY, $worker, $path, "K-{$i}", $step, "R-{$i}"];
                $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $procs[] = [$proc, $pipes];
            }
            $ok = 0;
            foreach ($procs as [$proc, $pipes]) {
                $ok += stream_get_contents($pipes[1]) === 'OK' ? 1 : 0;
                $err = stream_get_contents($pipes[2]);
                $this->assertSame('', $err, 'ワーカーが異常終了した: '.$err);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
            }
            $ok !== 1 && $bothOk++; // ちょうど1つだけが、成功するはず（0でも2以上でも失敗）
        }
        foreach (glob($path.'*') ?: [] as $f) {
            @unlink($f);
        }
        $this->assertSame(0, $bothOk, '同じ業務キーの別の手順で、ちょうど1つだけが「処理中」になる、にならなかった');
    }

    public function test_数量の負の値や巨大な値や不正な形で上限を迂回できない(): void
    {
        $bads = ['-99000', '-1e400', '1e400', ' 1200', '1200 ', '+12', '.5', '1,2,0,0', '5000.00001', '99999999999999999', '0x10'];
        foreach ($bads as $i => $q) {
            $rows = [['商品コード' => 'A', '数量' => $q, '伝票番号' => 'K-9']];
            $this->assertNotSame([], $this->register("RUN-Q{$i}", $rows), $q);
        }
        // 合計の上限は、固定小数で数える（小数の丸めで超えても通らない）
        $rows = [['商品コード' => 'A', '数量' => '5000', '伝票番号' => 'K-8'], ['商品コード' => 'B', '数量' => '0.0001', '伝票番号' => 'K-7']];
        $this->assertContains('数量の上限を超えています', $this->register('RUN-Q99', $rows));
        $ok = [['商品コード' => 'A', '数量' => '4,999.9999', '伝票番号' => 'K-6'], ['商品コード' => 'B', '数量' => '0.0001', '伝票番号' => 'K-5']];
        $this->assertSame([], $this->register('RUN-Q100', $ok));
    }

    public function test_列の型を検査する(): void
    {
        $this->plan->input->columns[0]->type = 'number'; // 商品コードを数値型に
        $this->approve();
        $this->assertNotSame([], $this->register('RUN-T1', [['商品コード' => 'ABC', '数量' => '1', '伝票番号' => 'K-1']]));
        $this->setUp();
        $this->plan->input->columns[0]->type = 'date';
        $this->plan->input->columns[0]->format = 'YYYY-MM-DD';
        $this->approve();
        $this->assertNotSame([], $this->register('RUN-T2', [['商品コード' => '2026-13-40', '数量' => '1', '伝票番号' => 'K-1']]));
        $this->assertSame([], $this->register('RUN-T3', [['商品コード' => '2026-11-11', '数量' => '1', '伝票番号' => 'K-1']]));
    }

    public function test_業務キーの表記ゆれによる重複や不正を拒否(): void
    {
        $bads = ['K-001 ', ' K-001', 'Ｋ－００１', "K-001\u{200b}", "K\u{2010}001\u{0085}", "K-001\u{2028}", 'K 001', ''];
        foreach ($bads as $i => $k) {
            $rows = [['商品コード' => 'A', '数量' => '1', '伝票番号' => $k]];
            $this->assertNotSame([], $this->register("RUN-K{$i}", $rows), json_encode($k));
        }
        // 完全に同じキーは、重複
        $rows = [['商品コード' => 'A', '数量' => '1', '伝票番号' => 'K-001'], ['商品コード' => 'B', '数量' => '1', '伝票番号' => 'K-001']];
        $this->assertNotSame([], $this->register('RUN-K99', $rows));
        // 大文字小文字だけが違うキーは、別の業務キー（区別する）
        $rows = [['商品コード' => 'A', '数量' => '1', '伝票番号' => 'K-001'], ['商品コード' => 'B', '数量' => '1', '伝票番号' => 'k-001']];
        $this->assertSame([], $this->register('RUN-K100', $rows));
    }

    public function test_大文字小文字だけ違う業務キーは状態も別扱い(): void
    {
        $this->states->markProcessing('K-001', 'S-003', 'h', 'r');
        $this->assertSame('processing', $this->states->get('K-001', 'S-003'));
        $this->assertNull($this->states->get('k-001', 'S-003'));
        $this->states->markProcessing('k-001', 'S-003', 'h', 'r'); // 別のキーなので、未解消にならない
        $this->assertSame('processing', $this->states->get('k-001', 'S-003'));
    }

    public function test_想定外の入力でも登録は例外でなく理由を返す(): void
    {
        $r1 = $this->registrar->register('RUN-E1', $this->plan, $this->inputs, 'DR-1', 'stub', [5 => $this->rows[0]]);
        $this->assertNotSame([], $r1);
        $r2 = $this->registrar->register('RUN-E2', $this->plan, $this->inputs, 'DR-1', 'stub', [['商品コード' => "\xff", '数量' => '1', '伝票番号' => 'K-1']]);
        $this->assertNotSame([], $r2);
        $r3 = $this->registrar->register('RUN-E3', $this->plan, $this->inputs, 'DR-1', 'stub', ['not-an-array']);
        $this->assertNotSame([], $r3);
    }

    public function test_組織全体の件数の最大値を超える計画は登録できない(): void
    {
        $reg = new RunRegistrar($this->ledger, $this->approvals, 50);
        $this->plan->limits->max_rows_per_run = 100;
        $this->approve();
        $r = $reg->register('RUN-O', $this->plan, $this->inputs, 'DR-1', 'stub', $this->rows);
        $this->assertNotSame([], $r);
    }
}
