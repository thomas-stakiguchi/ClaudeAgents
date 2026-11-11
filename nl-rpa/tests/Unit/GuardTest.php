<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Src\Ai\StubAiClient;
use Src\Guard\GuardOutcome;
use Src\Guard\GuardRequest;
use Src\Guard\IrreversibleAction;
use Src\Guard\IrreversibleGuard;
use Src\Guard\Permit;
use Src\Plan\PlanHasher;
use Src\Plan\StrictJson;
use Src\Policy\OperationPolicy;
use Src\State\Db;
use Src\State\Flags;
use Src\State\Ledger;
use Src\State\StepStateStore;

class GuardTest extends TestCase
{
    private Db $db;
    private Ledger $ledger;
    private StepStateStore $states;
    private Flags $flags;
    private IrreversibleGuard $guard;
    private \stdClass $plan;
    /** @var array<string,mixed> */
    private array $inputs;
    private int $t = 0;

    protected function setUp(): void
    {
        $this->db = Db::open(':memory:');
        $clock = fn (): string => sprintf('2026-11-11T00:00:%02dZ', $this->t++ % 60);
        $this->ledger = new Ledger($this->db, $clock);
        $this->states = new StepStateStore($this->db, $this->ledger, $clock);
        $this->flags = new Flags($this->db, $this->ledger);
        $this->guard = new IrreversibleGuard($this->ledger, $this->states, $this->flags, new OperationPolicy());
        $this->plan = StrictJson::decode(StubAiClient::defaultPlanJson());
        $this->inputs = ['operations' => (object) ['v' => 1], 'destinations' => (object) ['v' => 1], 'registrations' => [], 'screen_record' => (object) []];
        $this->approve();
    }

    private function approve(string $env = 'stub', string $result = 'passed', string $dryRunId = 'DR-1'): void
    {
        $hash = PlanHasher::hash($this->plan, $this->inputs);
        $this->ledger->append('approval', $hash, (object) ['stage' => 'final', 'approver' => '佐藤', 'os_user' => 'tester']);
        $this->ledger->append('dry_run', $hash, (object) ['dry_run_id' => $dryRunId, 'result' => $result, 'environment' => $env, 'destination_name' => 'stub']);
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
            'dryRunId' => 'DR-1',
            'actualDestinationName' => 'stub',
            'rowValues' => ['商品コード' => 'ABC', '数量' => '1200', '伝票番号' => 'K-001'],
            'readbackRaw' => ['item_code' => ' ABC ', 'quantity' => '1,200'],
            'rowIndexInRun' => 0,
        ], $o);

        return new GuardRequest(...$d);
    }

    private function action(bool $result = true, bool $throw = false): IrreversibleAction&\Countable
    {
        return new class($result, $throw) implements IrreversibleAction, \Countable {
            public int $calls = 0;

            public function __construct(private bool $result, private bool $throw)
            {
            }

            public function perform(Permit $permit): bool
            {
                $this->calls++;
                if ($this->throw) {
                    throw new \RuntimeException('接続が切れた');
                }

                return $this->result;
            }

            public function count(): int
            {
                return $this->calls;
            }
        };
    }

    private function assertRefused(GuardOutcome $o, IrreversibleAction&\Countable $a, string $needle): void
    {
        $this->assertFalse($o->executed);
        $this->assertCount(0, $a, '拒否したのに操作が実行された');
        $this->assertNull($this->states->get('K-001', 'S-005'), '拒否したのに「処理中」が残った');
        $this->assertTrue((bool) array_filter($o->reasons, fn ($r) => str_contains($r, $needle)), "「{$needle}」がない: ".implode(' / ', $o->reasons));
        $this->assertNotNull($this->ledger->latest('refusal', "K-001\nS-005"), '拒否の理由が記録されていない');
    }

    public function test_すべて満たせば実行され成功が記録される(): void
    {
        $a = $this->action();
        $o = $this->guard->run($this->req(), $a);
        $this->assertSame([], $o->reasons);
        $this->assertTrue($o->executed && $o->confirmed);
        $this->assertCount(1, $a);
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

    public function test_承認だけでドライランがなければ拒否(): void
    {
        $a = $this->action();
        $this->assertRefused($this->guard->run($this->req(['dryRunId' => 'DR-999']), $a), $a, 'ドライラン');
    }

    public function test_不合格のドライランでは拒否(): void
    {
        $this->setUp2('stub', 'failed');
        $a = $this->action();
        $this->assertRefused($this->guard->run($this->req(), $a), $a, 'ドライラン');
    }

    public function test_1bに入ったらスタブのドライランは無効(): void
    {
        $guard = new IrreversibleGuard($this->ledger, $this->states, $this->flags, new OperationPolicy(), ['test_env']);
        $a = $this->action();
        $o = $guard->run($this->req(), $a);
        $this->assertRefused($o, $a, 'ドライランの環境');
    }

    private function setUp2(string $env, string $result): void
    {
        $this->setUp();
        // setUp の承認とドライランを、別の内容で作り直す（記録は追記のみなので、新しいDBで）
        $this->db = Db::open(':memory:');
        $clock = fn (): string => sprintf('2026-11-11T00:00:%02dZ', $this->t++ % 60);
        $this->ledger = new Ledger($this->db, $clock);
        $this->states = new StepStateStore($this->db, $this->ledger, $clock);
        $this->flags = new Flags($this->db, $this->ledger);
        $this->guard = new IrreversibleGuard($this->ledger, $this->states, $this->flags, new OperationPolicy());
        $this->approve($env, $result);
    }

    public function test_キルスイッチと停止の旗(): void
    {
        foreach ([Flags::KILL_SWITCH, Flags::STOP] as $flag) {
            $this->flags->set($flag, true, 'admin');
            $a = $this->action();
            $this->assertRefused($this->guard->run($this->req(), $a), $a, $flag === Flags::KILL_SWITCH ? 'キルスイッチ' : '停止の旗');
            $this->flags->set($flag, false, 'admin');
        }
    }

    public function test_未確定が残っていれば拒否(): void
    {
        $this->plan->unresolved = ['納品日の入力元'];
        $this->approve(dryRunId: 'DR-1'); // 未確定のまま承認とドライランを作っても、実行できない
        $a = $this->action();
        $this->assertRefused($this->guard->run($this->req(), $a), $a, '未確定');
    }

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
        $a = $this->action();
        $this->assertRefused($this->guard->run($this->req(), $a), $a, '読み返し照合の項目');
    }

    public function test_取り消せる手順はこの関数を通さない(): void
    {
        $a = $this->action();
        $o = $this->guard->run($this->req(['stepId' => 'S-006']), $a);
        $this->assertFalse($o->executed);
        $this->assertCount(0, $a);
        $this->assertContains('取り消せる手順は、この関数を通しません', $o->reasons);
    }

    public function test_計画にない手順IDは拒否(): void
    {
        $a = $this->action();
        $o = $this->guard->run($this->req(['stepId' => 'S-999']), $a);
        $this->assertFalse($o->executed);
        $this->assertCount(0, $a);
    }

    public function test_すでに成功した業務キーは拒否(): void
    {
        $this->guard->run($this->req(), $this->action());
        $a = $this->action();
        $o = $this->guard->run($this->req(['runId' => 'RUN-2']), $a);
        $this->assertFalse($o->executed);
        $this->assertCount(0, $a);
        $this->assertContains('この業務キー・手順は、すでに成功しています', $o->reasons);
    }

    public function test_同じ業務キーに未解消があれば別の手順も拒否(): void
    {
        $this->states->markProcessing('K-001', 'S-003', 'h', 'RUN-0');
        $this->states->markNeedsReview('K-001', 'S-003');
        $a = $this->action();
        $o = $this->guard->run($this->req(), $a);
        $this->assertFalse($o->executed);
        $this->assertCount(0, $a);
        $this->assertNotEmpty(array_filter($o->reasons, fn ($r) => str_contains($r, '未解消')));
    }

    public function test_業務キーが行の値と違えば拒否(): void
    {
        $a = $this->action();
        $o = $this->guard->run($this->req(['businessKey' => 'K-002']), $a);
        $this->assertFalse($o->executed);
        $this->assertCount(0, $a);
        $this->assertNull($this->states->get('K-002', 'S-005'));
        $this->assertNotEmpty(array_filter($o->reasons, fn ($r) => str_contains($r, '業務キー')));
        $this->assertNotNull($this->ledger->latest('refusal', "K-002\nS-005"));
    }

    public function test_件数の上限を超えたら拒否(): void
    {
        $this->plan->limits->max_rows_per_run = 1;
        $this->approve();
        $a = $this->action();
        $o = $this->guard->run($this->req(['rowIndexInRun' => 1]), $a);
        $this->assertFalse($o->executed);
        $this->assertContains('件数の上限を超えます', $o->reasons);
    }

    public function test_数量の上限を超えたら拒否(): void
    {
        $a = $this->action();
        $this->assertRefused($this->guard->run($this->req(['quantityTotalInRun' => 5001]), $a), $a, '数量の上限');
    }

    public function test_2行目以降は初回の確認が済むまで拒否(): void
    {
        $a = $this->action();
        $o = $this->guard->run($this->req(['rowIndexInRun' => 1]), $a);
        $this->assertFalse($o->executed);
        $this->assertContains('初回の1件の確認が済んでいません', $o->reasons);
        $this->ledger->append('first_row_confirmed', 'RUN-1', (object) ['by' => '佐藤']);
        $this->assertTrue($this->guard->run($this->req(['rowIndexInRun' => 1]), $a)->executed);
    }

    public function test_実際の接続先が計画と違えば拒否(): void
    {
        $a = $this->action();
        $this->assertRefused($this->guard->run($this->req(['actualDestinationName' => 'prod']), $a), $a, '接続先');
    }

    public function test_定数にない接続先は拒否(): void
    {
        $this->plan->steps[1]->steps[2]->destination->name = 'prod';
        $this->approve();
        $a = $this->action();
        $this->assertRefused($this->guard->run($this->req(['actualDestinationName' => 'prod']), $a), $a, '接続先');
    }

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
        // 要確認のままの再実行は、拒否される
        $a = $this->action();
        $this->assertFalse($this->guard->run($this->req(['runId' => 'RUN-2']), $a)->executed);
        $this->assertCount(0, $a);
    }

    public function test_処理中を保存できなければ実行しない(): void
    {
        $this->db->pdo->exec("CREATE TRIGGER boom BEFORE INSERT ON step_states BEGIN SELECT RAISE(ABORT, 'boom'); END");
        $a = $this->action();
        $o = $this->guard->run($this->req(), $a);
        $this->assertFalse($o->executed);
        $this->assertCount(0, $a);
        $this->assertNotEmpty(array_filter($o->reasons, fn ($r) => str_contains($r, '処理中')));
    }

    public function test_許可オブジェクトは外から作れない(): void
    {
        $ctor = (new \ReflectionClass(Permit::class))->getConstructor();
        $this->assertTrue($ctor->isPrivate());
        $this->expectException(\Error::class);
        new Permit('h', 'S', 'K', 'R'); // @phpstan-ignore-line
    }

    public function test_操作の実体は許可オブジェクトなしでは呼べない(): void
    {
        $a = $this->action();
        $this->expectException(\TypeError::class);
        $a->perform(null); // @phpstan-ignore-line
    }

    public function test_不正な計画は拒否(): void
    {
        $this->plan->steps[1]->steps[2]->evil = 1;
        $a = $this->action();
        $o = $this->guard->run($this->req(), $a);
        $this->assertFalse($o->executed);
        $this->assertCount(0, $a);
    }
}
