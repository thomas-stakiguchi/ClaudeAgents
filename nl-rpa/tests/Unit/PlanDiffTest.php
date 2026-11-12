<?php

namespace Tests\Unit;

use App\Support\PlanDiff;
use PHPUnit\Framework\TestCase;
use Src\Plan\StrictJson;
use Src\Policy\StubRegistrations;

class PlanDiffTest extends TestCase
{
    private function plan(): \stdClass
    {
        return StrictJson::decode(file_get_contents(dirname(__DIR__, 2).'/stubs/order_plan.json'));
    }

    /** @param list<array{kind:string,where:string,old:string,new:string,important:bool}> $c */
    private function find(array $c, string $where): ?array
    {
        foreach ($c as $x) {
            if ($x['where'] === $where) {
                return $x;
            }
        }

        return null;
    }

    public function test_同じ計画は差分なし(): void
    {
        $in = StubRegistrations::hashInputs();
        $this->assertSame([], PlanDiff::compare($this->plan(), $in, $this->plan(), $in));
    }

    public function test_対象や確認条件や接続先の変更は重要(): void
    {
        $in = StubRegistrations::hashInputs();
        $new = $this->plan();
        $new->steps[1]->steps[3]->target = 'cancel_button';
        $new->steps[1]->steps[3]->verify->value = '発注キャンセル';
        $new->steps[1]->steps[1]->destination->name = 'other';
        $c = PlanDiff::compare($this->plan(), $in, $new, $in);
        foreach (['手順 S-005 / target', '手順 S-005 / verify', '手順 S-003 / destination'] as $w) {
            $x = $this->find($c, $w);
            $this->assertNotNull($x, $w);
            $this->assertSame('変更', $x['kind']);
            $this->assertTrue($x['important'], $w);
        }
        $this->assertStringContainsString('submit_button', $this->find($c, '手順 S-005 / target')['old']);
        $this->assertStringContainsString('cancel_button', $this->find($c, '手順 S-005 / target')['new']);
    }

    public function test_根拠の文やラベルの変更は重要ではない(): void
    {
        $in = StubRegistrations::hashInputs();
        $new = $this->plan();
        $new->steps[1]->steps[3]->source = null;
        $new->steps[1]->steps[3]->label = '別の説明';
        $c = PlanDiff::compare($this->plan(), $in, $new, $in);
        $this->assertFalse($this->find($c, '手順 S-005 / source')['important']);
        $this->assertCount(2, array_filter($c, fn ($x) => str_starts_with($x['where'], '手順 S-005')));
    }

    public function test_手順の追加と削除(): void
    {
        $in = StubRegistrations::hashInputs();
        $new = $this->plan();
        array_pop($new->steps[1]->steps); // S-006 を削除
        $new->steps[1]->steps[] = StrictJson::decode('{"id":"S-099","action":"read_text","destination":{"kind":"browser","name":"stub"},"target":"order_number","verify":{"type":"element_exists","value":"order_number"},"source":null}');
        $c = PlanDiff::compare($this->plan(), $in, $new, $in);
        $removed = array_values(array_filter($c, fn ($x) => $x['kind'] === '削除' && str_contains($x['where'], 'S-006')));
        $added = array_values(array_filter($c, fn ($x) => $x['kind'] === '追加' && str_contains($x['where'], 'S-099')));
        $this->assertNotEmpty($removed);
        $this->assertNotEmpty($added);
        $this->assertTrue($this->find($c, '手順 S-099')['important'], '手順の追加は、重要');
        $this->assertTrue($this->find($c, '手順 S-006')['important'], '手順の削除は、重要');
        $this->assertNotNull($this->find($c, '手順 S-002 / 中の手順'), 'ループの中の手順の並びの変更');
    }

    public function test_入力データの列と上限と許可リストの変更(): void
    {
        $in = StubRegistrations::hashInputs();
        $new = $this->plan();
        $new->input->columns[1]->role = 'personal_info';
        $new->limits->max_rows_per_run = 5000;
        $in2 = $in;
        $in2['registrations'] = (object) ['buttons' => [(object) ['destination' => 'stub', 'target' => 'submit_button']]];
        $c = PlanDiff::compare($this->plan(), $in, $new, $in2);
        $this->assertTrue($this->find($c, '入力データの列「数量」')['important']);
        $this->assertTrue($this->find($c, '上限 / max_rows_per_run')['important'], '上限の変更は、重要');
        $this->assertTrue($this->find($c, '許可リスト・個別登録・画面の記録 / registrations')['important']);
    }

    public function test_長い値は省略して表示する(): void
    {
        $in = StubRegistrations::hashInputs();
        $new = $this->plan();
        $new->steps[0]->label = str_repeat('あ', 500);
        $x = $this->find(PlanDiff::compare($this->plan(), $in, $new, $in), '手順 S-001 / label');
        $this->assertLessThanOrEqual(201, mb_strlen($x['new']));
    }

    public function test_最上位の手順の並べ替えも差分に出る(): void
    {
        $in = StubRegistrations::hashInputs();
        $new = $this->plan();
        $new->steps = array_reverse($new->steps);
        $c = PlanDiff::compare($this->plan(), $in, $new, $in);
        $x = $this->find($c, '計画 / 最上位の手順の並び');
        $this->assertNotNull($x);
        $this->assertTrue($x['important']);
    }

    public function test_上限の削除や引き上げ_と_許可リストの版の変更は重要(): void
    {
        $in = StubRegistrations::hashInputs();
        $new = $this->plan();
        unset($new->limits->max_quantity_per_run);
        $new->limits->max_rows_per_run = 1000;
        $new->allowlist_versions->operations = 9;
        $new->screen_record_version = 9;
        $c = PlanDiff::compare($this->plan(), $in, $new, $in);
        foreach (['上限 / max_quantity_per_run', '上限 / max_rows_per_run', '計画 / allowlist_versions', '計画 / screen_record_version'] as $w) {
            $this->assertTrue($this->find($c, $w)['important'] ?? false, $w);
        }
        $this->assertSame('削除', $this->find($c, '上限 / max_quantity_per_run')['kind']);
    }

    public function test_長い値の後ろのほうだけが変わっても_違いが読める(): void
    {
        $in = StubRegistrations::hashInputs();
        $a = $in;
        $b = $in;
        $long = str_repeat('x', 400);
        $a['screen_record'] = (object) ['selectors' => (object) ['a' => $long.'#submit_button']];
        $b['screen_record'] = (object) ['selectors' => (object) ['a' => $long.'#evil']];
        $c = PlanDiff::compare($this->plan(), $a, $this->plan(), $b);
        $x = $this->find($c, '許可リスト・個別登録・画面の記録 / screen_record');
        $this->assertNotSame($x['old'], $x['new'], '前と後が、同じに見える');
        $this->assertStringContainsString('submit_button', $x['old']);
        $this->assertStringContainsString('evil', $x['new']);
        $this->assertLessThanOrEqual(402, mb_strlen($x['old']));
    }
}
