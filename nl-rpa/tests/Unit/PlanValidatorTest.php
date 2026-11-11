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
        $p->steps[] = StrictJson::decode('{"id":"S-050","destination":{"kind":"browser","name":"stub"},"action":"wait_for","source":null,"verify":{"type":"text_visible","value":"x"},"timeout_sec":9999}');
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
            registeredButtons: [OperationPolicy::registrationKey('stub', 'submit_button') => true],
            registeredAutosaveFreeInputs: [OperationPolicy::registrationKey('stub', 'item_code') => true, OperationPolicy::registrationKey('stub', 'quantity') => true],
            registeredUrls: [OperationPolicy::registrationKey('stub', 'login_page') => true],
        );
        $this->assertSame([], $policy->irreversibleStepIds($p));
    }

    public function test_press_keyとconfirm_dialogは常に取り消せない(): void
    {
        $policy = new OperationPolicy(registeredButtons: [OperationPolicy::registrationKey('stub', 'x') => true]);
        foreach (['press_key', 'confirm_dialog', 'unknown'] as $a) {
            $this->assertFalse($policy->isReversible((object) ['action' => $a, 'target' => 'x', 'destination' => (object) ['kind' => 'browser', 'name' => 'stub'], 'reversible' => true]));
        }
        $this->assertFalse($policy->isReversible((object) []));
    }

    // ---- Opusレビュー（A〜K）の再現入力 ----

    public function test_必須項目にnullを入れても検査をすり抜けない(): void
    {
        $paths = [
            fn (\stdClass $p) => $p->steps[1]->steps[2]->id = null,
            fn (\stdClass $p) => $p->limits = null,
            fn (\stdClass $p) => $p->steps[0]->verify = null,
            fn (\stdClass $p) => $p->steps = null,
            fn (\stdClass $p) => $p->steps[1]->steps = null,
            fn (\stdClass $p) => $p->plan_id = null,
            fn (\stdClass $p) => $p->version = null,
            fn (\stdClass $p) => $p->scenario_id = null,
            fn (\stdClass $p) => $p->input->columns[0]->name = null,
        ];
        foreach ($paths as $i => $mutate) {
            $p = $this->base();
            $mutate($p);
            $this->assertNotSame([], PlanValidator::validate($p), "case {$i} が受理された");
        }
    }

    public function test_idがなくても取り消せない手順の一覧から消えない(): void
    {
        $p = $this->base();
        $p->steps[1]->steps[2]->id = null;
        $this->assertContains('(idなし)', (new OperationPolicy())->irreversibleStepIds($p));
    }

    public function test_OKする確認ダイアログは取り消せないしその並びのclickも取り消せない(): void
    {
        $p = $this->base();
        $p->steps[1]->steps[] = StrictJson::decode('{"id":"S-010","action":"on_dialog","source":null,"dialog_text":"登録しますか","response":"ok"}');
        $policy = new OperationPolicy(
            registeredButtons: [OperationPolicy::registrationKey('stub', 'submit_button') => true],
            registeredAutosaveFreeInputs: [OperationPolicy::registrationKey('stub', 'item_code') => true, OperationPolicy::registrationKey('stub', 'quantity') => true],
            registeredUrls: [OperationPolicy::registrationKey('stub', 'login_page') => true],
        );
        $this->assertSame(['S-005', 'S-010'], $policy->irreversibleStepIds($p));
        // stop なら、取り消せる
        $p->steps[1]->steps[4]->response = 'stop';
        $this->assertSame([], $policy->irreversibleStepIds($p));
    }

    public function test_件数の上限は登録の有無にかかわらず取り消せない候補があれば必須(): void
    {
        foreach (['type_text', 'open_url', 'login'] as $action) {
            $p = StrictJson::decode(StubAiClient::defaultPlanJson());
            $p->steps = [StrictJson::decode('{"id":"S-001","destination":{"kind":"browser","name":"stub"},"action":"'.$action.'","target":"t","value":"v","source":null,"verify":{"type":"text_visible","value":"x"}}')];
            if ($action !== 'type_text') {
                unset($p->steps[0]->value);
            }
            unset($p->limits->max_rows_per_run);
            $this->assertHasError(PlanValidator::validate($p), '件数の上限');
        }
    }

    public function test_組織の最大値を超える上限は拒否(): void
    {
        $p = $this->base();
        $p->limits->max_rows_per_run = 1000;
        $this->assertSame([], PlanValidator::validate($p));
        $this->assertHasError(PlanValidator::validate($p, 500), '組織全体の最大値');
    }

    public function test_業務キー列はrequired_trueが必須(): void
    {
        $p = $this->base();
        $p->input->columns[2]->required = false;
        $this->assertHasError(PlanValidator::validate($p), 'required:true');
    }

    public function test_固定値や要素名の制御文字を拒否(): void
    {
        foreach (["abc\n", "a\tb", "a\0b", "a\x7fb", "\u{202e}abc"] as $bad) {
            $p = $this->base();
            $p->steps[1]->steps[0] = StrictJson::decode('{"id":"S-003","destination":{"kind":"browser","name":"stub"},"action":"type_text","target":"item_code","value":"x","source":null,"verify":{"type":"text_visible","value":"x"}}');
            $p->steps[1]->steps[0]->value = $bad;
            $this->assertNotSame([], PlanValidator::validate($p), json_encode($bad));
        }
    }

    public function test_列はfor_each_rowの外では使えない(): void
    {
        $p = $this->base();
        $p->steps[] = StrictJson::decode('{"id":"S-060","destination":{"kind":"browser","name":"stub"},"action":"type_text","target":"item_code","column":"伝票番号","source":null,"verify":{"type":"text_visible","value":"x"}}');
        $this->assertHasError(PlanValidator::validate($p), 'for_each_row の中');
    }

    public function test_ゲートはreversibleを捨てる(): void
    {
        $p = $this->base();
        $p->steps[1]->steps[2]->reversible = true;
        $r = AiPlanGate::accept(json_encode($p, JSON_UNESCAPED_UNICODE));
        $this->assertSame([], $r['errors']);
        $this->assertFalse(property_exists($r['plan']->steps[1]->steps[2], 'reversible'));
    }

    public function test_ゲートはシナリオにない根拠を補ったことにする(): void
    {
        $scenario = '発注管理システムにログインする。商品コード・数量・納品日を入力する。';
        $r = AiPlanGate::accept(StubAiClient::defaultPlanJson(), scenario: $scenario);
        $this->assertSame([], $r['errors']);
        $steps = $r['plan']->steps;
        $this->assertSame('発注管理システムにログインする。', $steps[0]->source);
        $this->assertSame('商品コード・数量・納品日を入力する。', $steps[1]->steps[0]->source);
        $this->assertNull($steps[1]->steps[2]->source, '登録ボタンの文はシナリオにないので補った扱い');
    }

    public function test_ゲートは人の列定義と違えば拒否(): void
    {
        $cols = [
            '商品コード' => ['type' => 'text', 'required' => true],
            '数量' => ['type' => 'number', 'role' => 'quantity', 'required' => true],
            '伝票番号' => ['type' => 'text', 'role' => 'business_key', 'required' => true],
        ];
        $this->assertSame([], AiPlanGate::accept(StubAiClient::defaultPlanJson(), expectedColumns: $cols)['errors']);
        $bad = $cols;
        $bad['数量']['role'] = 'amount';
        $this->assertNotSame([], AiPlanGate::accept(StubAiClient::defaultPlanJson(), expectedColumns: $bad)['errors']);
        $bad = $cols;
        unset($bad['商品コード']);
        $this->assertNotSame([], AiPlanGate::accept(StubAiClient::defaultPlanJson(), expectedColumns: $bad)['errors']);
    }

    public function test_ゲートは画面の記録にない要素名を拒否(): void
    {
        $ok = ['login_page', 'item_code', 'quantity', 'submit_button', 'order_number'];
        $this->assertSame([], AiPlanGate::accept(StubAiClient::defaultPlanJson(), elementNames: $ok)['errors']);
        $this->assertNotSame([], AiPlanGate::accept(StubAiClient::defaultPlanJson(), elementNames: ['login_page'])['errors']);
    }

    public function test_NULキーなどでも例外が門の外へ漏れない(): void
    {
        foreach (['{"\u0000a":1}', '{"a":"\u0000"}', str_repeat(' ', AiPlanGate::MAX_BYTES + 1)] as $bad) {
            $r = AiPlanGate::accept($bad);
            $this->assertNull($r['plan']);
            $this->assertNotSame([], $r['errors']);
        }
    }

    public function test_接続先が違えば登録は使えない(): void
    {
        $p = $this->base();
        $policy = new OperationPolicy(registeredButtons: [OperationPolicy::registrationKey('other', 'submit_button') => true]);
        $this->assertContains('S-005', $policy->irreversibleStepIds($p));
    }

    public function test_接続先の欄は必須(): void
    {
        $p = $this->base();
        unset($p->steps[0]->destination);
        $this->assertHasError(PlanValidator::validate($p), 'destination');
        $p = $this->base();
        $p->steps[0]->destination->kind = 'ssh';
        $this->assertHasError(PlanValidator::validate($p), 'browser');
    }
}
