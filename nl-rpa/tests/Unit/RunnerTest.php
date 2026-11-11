<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Src\Browser\FakeBrowser;
use Src\Guard\ApprovalChecker;
use Src\Guard\IrreversibleGuard;
use Src\Guard\Permit;
use Src\Guard\Redeemed;
use Src\Guard\RunRegistrar;
use Src\Plan\PlanHasher;
use Src\Plan\StrictJson;
use Src\Runner\PlanRunner;
use Src\Runner\RunLock;
use Src\Runner\RunReport;
use Src\State\Db;
use Src\State\Flags;
use Src\State\Ledger;
use Src\State\StepStateStore;

/** 計画 → 承認 → ドライラン → 最終承認 → 実行の登録 → 本番実行（スタブ相手）の、通しの試験 */
class RunnerTest extends TestCase
{
    private Db $db;
    private Ledger $ledger;
    private StepStateStore $states;
    private Flags $flags;
    private ApprovalChecker $approvals;
    private IrreversibleGuard $guard;
    private RunRegistrar $registrar;
    private FakeBrowser $browser;
    private PlanRunner $runner;
    private string $lockPath;
    private \stdClass $plan;
    /** @var array<string,mixed> */
    private array $inputs;
    /** @var list<array<string,string>> */
    private array $rows;
    private int $t = 0;

    protected function setUp(): void
    {
        $this->db = Db::open(':memory:');
        $clock = fn (): string => sprintf('2026-11-11T00:00:%02dZ', $this->t++ % 60);
        $this->ledger = new Ledger($this->db, $clock);
        $this->states = new StepStateStore($this->db, $this->ledger, $clock);
        $this->flags = new Flags($this->db, $this->ledger);
        $this->approvals = new ApprovalChecker($this->ledger);
        $this->guard = new IrreversibleGuard($this->ledger, $this->states, $this->flags, $this->approvals);
        $this->registrar = new RunRegistrar($this->ledger, $this->approvals);
        $this->browser = new FakeBrowser(dirname(__DIR__, 2).'/stubs/order_system.json', $this->guard);
        $this->lockPath = sys_get_temp_dir().'/nlrpa_lock_'.bin2hex(random_bytes(4));
        $this->runner = new PlanRunner($this->ledger, $this->states, $this->flags, $this->guard, $this->approvals, $this->browser, new RunLock($this->lockPath));
        $this->plan = StrictJson::decode(file_get_contents(dirname(__DIR__, 2).'/stubs/order_plan.json'));
        $this->inputs = [
            'operations' => (object) ['v' => 1],
            'destinations' => (object) ['v' => 1],
            'registrations' => (object) [
                'autosave_free_inputs' => [(object) ['destination' => 'stub', 'target' => 'item_code'], (object) ['destination' => 'stub', 'target' => 'quantity']],
                'urls' => [(object) ['destination' => 'stub', 'target' => 'login_page'], (object) ['destination' => 'stub', 'target' => 'order_page']],
            ],
            'screen_record' => (object) [],
        ];
        $this->rows = [
            ['商品コード' => 'ABC', '数量' => '1200', '伝票番号' => 'K-001'],
            ['商品コード' => 'DEF', '数量' => '30', '伝票番号' => 'K-002'],
            ['商品コード' => 'GHI', '数量' => '5', '伝票番号' => 'K-003'],
        ];
    }

    protected function tearDown(): void
    {
        @unlink($this->lockPath);
    }

    private function approve(string $stage): void
    {
        $this->ledger->append('approval', PlanHasher::hash($this->plan, $this->inputs), (object) ['stage' => $stage, 'approver' => '佐藤', 'os_user' => 'tester']);
    }

    /** 計画の承認 → ドライラン（合格）→ 最終承認 → 実行の登録まで */
    private function prepareRun(string $runId = 'RUN-1'): void
    {
        $this->approve('plan');
        $dry = $this->runner->dryRun($this->plan, $this->inputs, $this->rows, 'DR-1');
        $this->assertSame(RunReport::COMPLETED, $dry->status, implode(' / ', $dry->reasons));
        $this->approve('final');
        $this->assertSame([], $this->registrar->register($runId, $this->plan, $this->inputs, 'DR-1', 'stub', $this->rows));
    }

    public function test_通しの流れ_初回1件で止まり_確認後に残りを実行し_再実行は何もしない(): void
    {
        $this->prepareRun();
        $this->assertSame([], $this->browser->orders(), 'ドライランでは、取り消せない操作を実行しない');
        $this->assertNull($this->states->get('K-001', 'S-005'));

        $r1 = $this->runner->run($this->plan, $this->inputs, 'RUN-1', $this->rows);
        $this->assertSame(RunReport::AWAITING_FIRST_ROW, $r1->status);
        $this->assertSame(['ORD-0001'], $this->browser->orders());
        $this->assertSame('succeeded', $this->states->get('K-001', 'S-005'));
        $this->assertSame('ORD-0001', $r1->rows[0]['reads']['order_number']);

        // 確認しないまま再度動かしても、残りは実行されない
        $again = $this->runner->run($this->plan, $this->inputs, 'RUN-1', $this->rows);
        $this->assertSame(RunReport::AWAITING_FIRST_ROW, $again->status);
        $this->assertCount(1, $this->browser->orders());

        $this->runner->confirmFirstRow('RUN-1', '佐藤', 'tester');
        $r2 = $this->runner->run($this->plan, $this->inputs, 'RUN-1', $this->rows);
        $this->assertSame(RunReport::COMPLETED, $r2->status, implode(' / ', $r2->reasons));
        $this->assertSame(['ORD-0001', 'ORD-0002', 'ORD-0003'], $this->browser->orders());
        $this->assertSame(['skipped', 'done', 'done'], array_column($r2->rows, 'status'));

        // もう一度流しても、成功した行は飛ばす（二重実行しない）
        $r3 = $this->runner->run($this->plan, $this->inputs, 'RUN-1', $this->rows);
        $this->assertSame(RunReport::COMPLETED, $r3->status);
        $this->assertCount(3, $this->browser->orders());
        $this->assertNull($this->ledger->verify());
    }

    public function test_別の実行IDでも_成功した業務キーは二重に実行しない(): void
    {
        $this->prepareRun();
        $this->runner->run($this->plan, $this->inputs, 'RUN-1', $this->rows);
        $this->runner->confirmFirstRow('RUN-1', '佐藤', 'tester');
        $this->runner->run($this->plan, $this->inputs, 'RUN-1', $this->rows);
        $this->assertSame(RunReport::COMPLETED, $this->runner->run($this->plan, $this->inputs, 'RUN-1', $this->rows)->status);

        $this->assertSame([], $this->registrar->register('RUN-2', $this->plan, $this->inputs, 'DR-1', 'stub', $this->rows));
        $this->runner->confirmFirstRow('RUN-2', '佐藤', 'tester');
        $r = $this->runner->run($this->plan, $this->inputs, 'RUN-2', $this->rows);
        $this->assertSame(['skipped', 'skipped', 'skipped'], array_column($r->rows, 'status'));
        $this->assertCount(3, $this->browser->orders());
    }

    public function test_承認がなければドライランに進めない(): void
    {
        $r = $this->runner->dryRun($this->plan, $this->inputs, $this->rows, 'DR-1');
        $this->assertSame(RunReport::REFUSED_TO_START, $r->status);
        $this->assertSame([], $this->browser->orders());
    }

    public function test_ドライランで画面が合わなければ不合格になり登録できない(): void
    {
        $this->browser->typedValueTransform = fn (string $v): string => $v.'X'; // 画面が、入力値を書き換えてしまう
        $this->approve('plan');
        $r = $this->runner->dryRun($this->plan, $this->inputs, $this->rows, 'DR-1');
        $this->assertSame(RunReport::HALTED, $r->status);
        $this->approve('final');
        $this->assertNotSame([], $this->registrar->register('RUN-1', $this->plan, $this->inputs, 'DR-1', 'stub', $this->rows));
    }

    public function test_本番で画面の値が入力データと違えば取り消せない操作は実行されない(): void
    {
        $this->prepareRun();
        $this->browser->typedValueTransform = fn (string $v): string => $v.'X'; // ドライランのあとに、画面が変わった
        $r = $this->runner->run($this->plan, $this->inputs, 'RUN-1', $this->rows);
        $this->assertSame(RunReport::HALTED, $r->status);
        $this->assertSame([], $this->browser->orders());
        $this->assertNull($this->states->get('K-001', 'S-005'));
        // 入力直後の確認条件で止まる（読み返し照合まで進まない）。Guard自身の読み返し照合は GuardTest で確認する
        $this->assertNotEmpty(array_filter($r->reasons, fn ($x) => str_contains($x, '確認条件を満たしません')));
    }

    public function test_キルスイッチが立っていれば何も実行しない(): void
    {
        $this->prepareRun();
        $this->flags->set(Flags::KILL_SWITCH, true, 'admin');
        $r = $this->runner->run($this->plan, $this->inputs, 'RUN-1', $this->rows);
        $this->assertSame(RunReport::HALTED, $r->status);
        $this->assertSame([], $this->browser->orders());
    }

    public function test_停止の旗は各手順の前に読まれる(): void
    {
        $this->prepareRun();
        // 1行目の途中（入力のあと）で、停止の旗を立てる
        $flags = $this->flags;
        $this->browser->typedValueTransform = function (string $v) use ($flags): string {
            $flags->set(Flags::STOP, true, 'operator');

            return $v;
        };
        $r = $this->runner->run($this->plan, $this->inputs, 'RUN-1', $this->rows);
        $this->assertSame(RunReport::HALTED, $r->status);
        $this->assertSame([], $this->browser->orders());
    }

    public function test_二重起動はできない(): void
    {
        $this->prepareRun();
        $other = new RunLock($this->lockPath);
        $this->assertTrue($other->acquire());
        $r = $this->runner->run($this->plan, $this->inputs, 'RUN-1', $this->rows);
        $this->assertSame(RunReport::REFUSED_TO_START, $r->status);
        $this->assertSame([], $this->browser->orders());
        $other->release();
        $this->assertSame(RunReport::AWAITING_FIRST_ROW, $this->runner->run($this->plan, $this->inputs, 'RUN-1', $this->rows)->status);
    }

    public function test_クリック後に通信が切れたら要確認になり_再実行しても二重に実行しない(): void
    {
        $this->prepareRun();
        $this->browser->failAfterClick = true;
        $r = $this->runner->run($this->plan, $this->inputs, 'RUN-1', $this->rows);
        $this->assertSame(RunReport::HALTED, $r->status);
        $this->assertSame('needs_review', $this->states->get('K-001', 'S-005'));
        $this->assertCount(1, $this->browser->orders());

        $this->browser->failAfterClick = false;
        $this->runner->confirmFirstRow('RUN-1', '佐藤', 'tester');
        $r2 = $this->runner->run($this->plan, $this->inputs, 'RUN-1', $this->rows);
        $this->assertSame(RunReport::HALTED, $r2->status);
        $this->assertCount(1, $this->browser->orders(), '要確認のままの行を、再実行してはいけない');

        // 人が「実行済み」と判定したら、成功になり、飛ばされる。判定のあとに、あらためて初回の確認が要る
        $this->states->resolve('K-001', 'S-005', true, '佐藤');
        $stale = $this->runner->run($this->plan, $this->inputs, 'RUN-1', $this->rows);
        $this->assertSame(RunReport::HALTED, $stale->status, '判定より前の確認は無効');
        $this->assertCount(1, $this->browser->orders());
        $this->runner->confirmFirstRow('RUN-1', '佐藤', 'tester');
        $r3 = $this->runner->run($this->plan, $this->inputs, 'RUN-1', $this->rows);
        $this->assertSame(RunReport::COMPLETED, $r3->status, implode(' / ', $r3->reasons));
        $this->assertCount(3, $this->browser->orders());
    }

    public function test_クリックしても確認条件を満たさなければ要確認になる(): void
    {
        $this->prepareRun();
        $this->browser->clickDoesNothing = true;
        $r = $this->runner->run($this->plan, $this->inputs, 'RUN-1', $this->rows);
        $this->assertSame(RunReport::HALTED, $r->status);
        $this->assertSame('needs_review', $this->states->get('K-001', 'S-005'));
    }

    public function test_承認後に計画が変わったら開始しない(): void
    {
        $this->prepareRun();
        $this->plan->steps[1]->steps[3]->target = 'cancel_button';
        $r = $this->runner->run($this->plan, $this->inputs, 'RUN-1', $this->rows);
        $this->assertSame(RunReport::REFUSED_TO_START, $r->status);
        $this->assertSame([], $this->browser->orders());
    }

    public function test_登録と違う並びの入力データでは実行しない(): void
    {
        $this->prepareRun();
        $rows = array_reverse($this->rows);
        $r = $this->runner->run($this->plan, $this->inputs, 'RUN-1', $rows);
        $this->assertSame(RunReport::HALTED, $r->status);
        $this->assertSame([], $this->browser->orders());
    }

    public function test_登録のない実行IDでは開始しない(): void
    {
        $this->prepareRun();
        $r = $this->runner->run($this->plan, $this->inputs, 'RUN-NONE', $this->rows);
        $this->assertSame(RunReport::REFUSED_TO_START, $r->status);
    }

    public function test_未確定が残る計画は開始しない(): void
    {
        $this->plan->unresolved = ['納品日の入力元'];
        $this->approve('plan');
        $r = $this->runner->dryRun($this->plan, $this->inputs, $this->rows, 'DR-1');
        $this->assertSame(RunReport::REFUSED_TO_START, $r->status);
    }

    public function test_登録を外すと取り消せる扱いに変わり_計画ハッシュも変わる(): void
    {
        // 個別登録が変わると、承認済みの計画ハッシュが変わるので、実行できない
        $this->prepareRun();
        $this->inputs['registrations']->buttons = [(object) ['destination' => 'stub', 'target' => 'submit_button']];
        $r = $this->runner->run($this->plan, $this->inputs, 'RUN-1', $this->rows);
        $this->assertSame(RunReport::REFUSED_TO_START, $r->status);
        $this->assertSame([], $this->browser->orders());
    }

    public function test_ブラウザ部品に偽の証明で取り消せない操作はさせられない(): void
    {
        $this->prepareRun();
        $permit = Permit::mint($this->guard, 'h', 'S-005', 'K-001', 'RUN-1', 'x', '{"action":"click","target":"submit_button"}');
        $this->browser->openUrl('order_page');
        $this->expectException(\Throwable::class);
        try {
            $this->browser->performIrreversible(Redeemed::mint($this->guard, $permit, 'guess'), []);
        } finally {
            $this->assertSame([], $this->browser->orders());
        }
    }

    public function test_ドライランは状態を変えない(): void
    {
        $this->approve('plan');
        $this->runner->dryRun($this->plan, $this->inputs, $this->rows, 'DR-1');
        foreach (['K-001', 'K-002', 'K-003'] as $k) {
            foreach (['S-003', 'S-004', 'S-005'] as $s) {
                $this->assertNull($this->states->get($k, $s));
            }
        }
        $this->assertSame('login_page', $this->browser->currentPage(), '各行の終わりに、画面は初期状態へ戻る');
    }

    // ---- Opusレビュー（Runner）の再現入力 ----

    public function test_手順の接続先が実際の接続先と違えば開始しない(): void
    {
        $this->plan->steps[1]->steps[1]->destination->name = 'other';
        $this->inputs['registrations']->autosave_free_inputs[0]->destination = 'other'; // 登録も、同じ接続先名に差し替える
        $this->approve('plan');
        $r = $this->runner->dryRun($this->plan, $this->inputs, $this->rows, 'DR-1');
        $this->assertSame(RunReport::REFUSED_TO_START, $r->status);
        $this->assertSame('login_page', $this->browser->currentPage());
        $this->assertSame('', $this->browser->readValueOrEmpty('item_code'));
    }

    public function test_別のGuardの証明ではブラウザ部品は操作しない(): void
    {
        // 別のDBで、正しい流れを通した Guard の証明を、このブラウザに渡す
        $otherDb = Db::open(':memory:');
        $clock = fn (): string => sprintf('2026-11-11T00:01:%02dZ', $this->t++ % 60);
        $otherLedger = new Ledger($otherDb, $clock);
        $otherStates = new StepStateStore($otherDb, $otherLedger, $clock);
        $otherApprovals = new ApprovalChecker($otherLedger);
        $otherGuard = new IrreversibleGuard($otherLedger, $otherStates, new Flags($otherDb, $otherLedger), $otherApprovals);
        $captured = null;
        $evil = new class($otherGuard, $captured) extends \Src\Guard\GuardedAction {
            public function __construct(IrreversibleGuard $g, public mixed &$cap)
            {
                parent::__construct($g);
            }

            protected function execute(Redeemed $proof): bool
            {
                $this->cap = $proof;

                return true;
            }
        };
        $hash = PlanHasher::hash($this->plan, $this->inputs);
        $otherLedger->append('approval', $hash, (object) ['stage' => 'final', 'approver' => 'x', 'os_user' => 'y']);
        $otherLedger->append('dry_run', $hash, (object) ['dry_run_id' => 'DR-1', 'result' => 'passed', 'environment' => 'stub', 'destination_name' => 'stub']);
        $this->assertSame([], (new RunRegistrar($otherLedger, $otherApprovals))->register('RUN-1', $this->plan, $this->inputs, 'DR-1', 'stub', $this->rows));
        $o = $otherGuard->run(new \Src\Guard\GuardRequest($this->plan, $this->inputs, 'S-005', 'K-001', 'RUN-1', 'stub', $this->rows[0], ['item_code' => 'ABC', 'quantity' => '1200']), $evil);
        $this->assertSame([], $o->reasons);
        $this->assertInstanceOf(Redeemed::class, $captured);
        // 本物のGuardが発行した証明だが、このブラウザ（別のGuardに結び付く）は、受け付けない
        $this->browser->openUrl('order_page');
        try {
            $this->browser->performIrreversible($captured, $this->rows[0]);
            $this->fail('別のGuardの証明で操作できた');
        } catch (\Throwable) {
            $this->assertSame([], $this->browser->orders());
        }
    }

    public function test_OKする確認ダイアログを含む計画は1aでは開始しない(): void
    {
        $this->plan->steps[1]->steps[] = StrictJson::decode('{"id":"S-010","action":"on_dialog","source":null,"dialog_text":"登録しますか","response":"ok"}');
        $this->approve('plan');
        $this->assertSame(RunReport::REFUSED_TO_START, $this->runner->dryRun($this->plan, $this->inputs, $this->rows, 'DR-1')->status);
    }

    public function test_最終承認がなければ本番は開始しない_入力も実行されない(): void
    {
        $this->approve('plan');
        $this->assertSame(RunReport::COMPLETED, $this->runner->dryRun($this->plan, $this->inputs, $this->rows, 'DR-1')->status);
        // 最終承認なしで、実行の記録（run）を偽って追記する
        $hash = PlanHasher::hash($this->plan, $this->inputs);
        $this->ledger->append('run', 'RUN-X', (object) ['plan_hash' => $hash, 'dry_run_id' => 'DR-1', 'destination_name' => 'stub', 'rows' => array_map(fn ($r) => (object) ['business_key' => $r['伝票番号'], 'values_hash' => RunRegistrar::rowHash($r)], $this->rows)]);
        $r = $this->runner->run($this->plan, $this->inputs, 'RUN-X', $this->rows);
        $this->assertSame(RunReport::REFUSED_TO_START, $r->status);
        $this->assertSame('login_page', $this->browser->currentPage(), '何も入力されていない');
    }

    public function test_ドライランの記録の環境はブラウザ部品から取る(): void
    {
        $this->approve('plan');
        $this->runner->dryRun($this->plan, $this->inputs, $this->rows, 'DR-1');
        $d = $this->ledger->latest('dry_run', PlanHasher::hash($this->plan, $this->inputs));
        $this->assertSame($this->browser->environmentName(), $d->environment);
    }

    public function test_for_each_rowの外の取り消せない手順は1aでは開始しない(): void
    {
        $click = StrictJson::decode('{"id":"S-010","action":"click","destination":{"kind":"browser","name":"stub"},"target":"submit_button","source":null,"verify":{"type":"text_visible","value":"発注番号"},"readback":[{"target":"item_code","value":"x"}]}');
        array_unshift($this->plan->steps, $click);
        $this->approve('plan');
        $this->assertSame(RunReport::REFUSED_TO_START, $this->runner->dryRun($this->plan, $this->inputs, $this->rows, 'DR-1')->status);
    }

    public function test_読み返しのない取り消せない手順はドライランで不合格(): void
    {
        unset($this->plan->steps[1]->steps[3]->readback);
        $this->approve('plan');
        $r = $this->runner->dryRun($this->plan, $this->inputs, $this->rows, 'DR-1');
        $this->assertSame(RunReport::HALTED, $r->status);
        $this->assertNotEmpty(array_filter($r->reasons, fn ($x) => str_contains($x, '読み返し照合の項目')));
    }

    public function test_画面を初期状態に戻せなければ停止する(): void
    {
        $this->approve('plan');
        $this->browser->failReset = true;
        $r = $this->runner->dryRun($this->plan, $this->inputs, $this->rows, 'DR-1');
        $this->assertSame(RunReport::HALTED, $r->status);
        $d = $this->ledger->latest('dry_run', PlanHasher::hash($this->plan, $this->inputs));
        $this->assertSame('failed', $d->result);
    }

    public function test_登録より少ない行を渡すと開始しない(): void
    {
        $this->prepareRun();
        $r = $this->runner->run($this->plan, $this->inputs, 'RUN-1', array_slice($this->rows, 0, 2));
        $this->assertSame(RunReport::REFUSED_TO_START, $r->status);
        $this->assertSame([], $this->browser->orders());
    }

    public function test_行の値が登録と違えば取り消せる手順も実行しない(): void
    {
        $this->prepareRun();
        $rows = $this->rows;
        $rows[0]['商品コード'] = 'EVIL';
        $r = $this->runner->run($this->plan, $this->inputs, 'RUN-1', $rows);
        $this->assertSame(RunReport::HALTED, $r->status);
        $this->assertSame('login_page', $this->browser->currentPage(), 'EVIL が入力される前に止まる');
        $this->assertSame([], $this->browser->orders());
    }

    public function test_異常終了のあとの起動で処理中は要確認になり_その行は実行されない(): void
    {
        $this->prepareRun();
        // 前回、K-001 の取り消せない操作の直前に「処理中」を書いたまま、プロセスが死んだ状態を作る
        $this->states->markProcessing('K-001', 'S-005', PlanHasher::hash($this->plan, $this->inputs), 'RUN-0');
        $r = $this->runner->run($this->plan, $this->inputs, 'RUN-1', $this->rows);
        $this->assertSame('needs_review', $this->states->get('K-001', 'S-005'));
        $this->assertSame(RunReport::HALTED, $r->status);
        $this->assertSame([], $this->browser->orders());
    }
}
