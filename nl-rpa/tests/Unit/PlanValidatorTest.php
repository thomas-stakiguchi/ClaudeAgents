<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Src\Ai\AiClient;
use Src\Ai\AiPlanGate;
use Src\Ai\StubAiClient;
use Src\Plan\PlanValidator;
use Src\Plan\StrictJson;
use Src\Policy\OperationPolicy;

class PlanValidatorTest extends TestCase
{
    private function base(): \stdClass
    {
        return StrictJson::decode(StubAiClient::defaultPlanJson());
    }

    /** @param list<string> $errors */
    private function assertHasError(array $errors, string $needle): void
    {
        $this->assertNotSame([], $errors, '拒否されるはず');
        $this->assertTrue(
            (bool) array_filter($errors, static fn (string $e) => str_contains($e, $needle)),
            "「{$needle}」を含むエラーがない: ".implode(' / ', $errors)
        );
    }

    public function test_スタブの計画は合格(): void
    {
        $this->assertSame([], PlanValidator::validate($this->base()));
    }

    public function test_AIClientのスタブはゲートを通る(): void
    {
        $ai = new StubAiClient();
        $this->assertInstanceOf(AiClient::class, $ai);
        $r = AiPlanGate::accept($ai->createPlan('x', ['商品コード'], ['login_page' => 'page']));
        $this->assertSame([], $r['errors']);
        $this->assertInstanceOf(\stdClass::class, $r['plan']);
    }

    public function test_ゲートはAIのplan_hashを捨てる(): void
    {
        $p = $this->base();
        $p->plan_hash = 'sha256:aaaa';
        $r = AiPlanGate::accept(json_encode($p, JSON_UNESCAPED_UNICODE));
        $this->assertSame([], $r['errors']);
        $this->assertFalse(property_exists($r['plan'], 'plan_hash'));
    }

    public function test_ゲートは壊れた出力を拒否(): void
    {
        $this->assertNull(AiPlanGate::accept('これはJSONではない')['plan']);
        $this->assertNull(AiPlanGate::accept('{"a":1,"a":2}')['plan']);
        $this->assertNull(AiPlanGate::accept('[]')['plan']);
    }

    public function test_知らない項目は拒否(): void
    {
        $p = $this->base();
        $p->skip_approval = true;
        $this->assertHasError(PlanValidator::validate($p), '知らない項目');
        $p = $this->base();
        $p->steps[1]->steps[0]->evil = 1;
        $this->assertHasError(PlanValidator::validate($p), '知らない項目');
    }

    public function test_必須の項目が欠けたら拒否(): void
    {
        foreach (['plan_id', 'version', 'scenario_id', 'created_by_ai', 'allowlist_versions', 'screen_record_version', 'input', 'limits', 'steps'] as $k) {
            $p = $this->base();
            unset($p->{$k});
            $this->assertHasError(PlanValidator::validate($p), $k);
        }
    }

    public function test_確認条件は必須(): void
    {
        $p = $this->base();
        unset($p->steps[0]->verify);
        $this->assertHasError(PlanValidator::validate($p), 'verify');
    }

    public function test_value_matchesは照合ルール必須(): void
    {
        $p = $this->base();
        unset($p->steps[1]->steps[0]->verify->rule);
        $this->assertHasError(PlanValidator::validate($p), '照合ルール');
    }

    public function test_業務キー列はちょうど1つ(): void
    {
        $p = $this->base();
        $p->input->columns[2]->role = 'amount';
        $this->assertHasError(PlanValidator::validate($p), '業務キー');
        $p = $this->base();
        $p->input->columns[0]->role = 'business_key';
        $this->assertHasError(PlanValidator::validate($p), '業務キー');
    }

    public function test_列定義にない列は拒否(): void
    {
        $p = $this->base();
        $p->steps[1]->steps[0]->column = '存在しない列';
        $this->assertHasError(PlanValidator::validate($p), '列定義にない');
    }

    public function test_固定値と列の両方は拒否(): void
    {
        $p = $this->base();
        $p->steps[1]->steps[0]->value = 'x';
        $this->assertHasError(PlanValidator::validate($p), 'どちらか一方');
    }

    public function test_手順IDの重複は拒否(): void
    {
        $p = $this->base();
        $p->steps[1]->steps[0]->id = 'S-001';
        $this->assertHasError(PlanValidator::validate($p), '重複');
    }

    public function test_for_each_rowの入れ子は拒否(): void
    {
        $p = $this->base();
        $p->steps[1]->steps[] = StrictJson::decode('{"id":"S-099","action":"for_each_row","source":null,"steps":[]}');
        $this->assertHasError(PlanValidator::validate($p), '入れ子');
    }

    public function test_知らない操作は拒否(): void
    {
        $p = $this->base();
        $p->steps[0]->action = 'run_shell';
        $this->assertHasError(PlanValidator::validate($p), '知らない操作');
    }

    public function test_取り消せない操作があるのに件数の上限がなければ拒否(): void
    {
        $p = $this->base();
        unset($p->limits->max_rows_per_run);
        $this->assertHasError(PlanValidator::validate($p), '件数の上限');
    }

    public function test_wait_forの待ち時間(): void
    {
        $p = $this->base();
        $p->steps[] = StrictJson::decode('{"id":"S-050","action":"wait_for","source":null,"verify":{"type":"text_visible","value":"x"},"timeout_sec":9999}');
        $this->assertHasError(PlanValidator::validate($p), 'timeout_sec');
    }

    public function test_未確定が残っているか(): void
    {
        $p = $this->base();
        $this->assertFalse(PlanValidator::hasUnresolved($p));
        $p->unresolved = ['納品日の入力元が不明'];
        $this->assertSame([], PlanValidator::validate($p));
        $this->assertTrue(PlanValidator::hasUnresolved($p));
    }

    public function test_型が違う値は拒否(): void
    {
        foreach ([null, 1, 'x', [], []] as $bad) {
            $this->assertNotSame([], PlanValidator::validate($bad));
        }
        $p = $this->base();
        $p->version = '1';
        $this->assertHasError(PlanValidator::validate($p), 'version');
    }

    public function test_取り消し可否は実行エンジンが計算しAIの申告を使わない(): void
    {
        $p = $this->base();
        // AIが「取り消せる」と申告しても、登録がなければ click は取り消せない
        $p->steps[1]->steps[2]->reversible = true;
        $policy = new OperationPolicy();
        $this->assertSame(['S-001', 'S-003', 'S-004', 'S-005'], $policy->irreversibleStepIds($p));

        // 登録があれば、取り消せる扱いになる
        $policy = new OperationPolicy(
            registeredButtons: ['submit_button' => true],
            registeredAutosaveFreeInputs: ['item_code' => true, 'quantity' => true],
            registeredUrls: ['login_page' => true],
        );
        $this->assertSame([], $policy->irreversibleStepIds($p));
    }

    public function test_press_keyとconfirm_dialogは常に取り消せない(): void
    {
        $policy = new OperationPolicy(registeredButtons: ['x' => true]);
        foreach (['press_key', 'confirm_dialog', 'unknown'] as $a) {
            $this->assertFalse($policy->isReversible((object) ['action' => $a, 'target' => 'x', 'reversible' => true]));
        }
        $this->assertFalse($policy->isReversible((object) []));
    }
}
