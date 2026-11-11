<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Src\Plan\CanonicalJson;
use Src\Plan\PlanHasher;

class CanonicalJsonTest extends TestCase
{
    private function j(string $json): mixed
    {
        return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function inputs(int $v = 5): array
    {
        return [
            'operations' => (object) ['v' => $v],
            'destinations' => [],
            'registrations' => [],
            'screen_record' => (object) [],
        ];
    }

    public function test_RFC8785_数値の例(): void
    {
        $cases = [
            [0.0, '0'], [-0.0, '0'], [1.0, '1'], [-1.0, '-1'],
            [5e-324, '5e-324'], [1.7976931348623157e308, '1.7976931348623157e+308'],
            [9007199254740992.0, '9007199254740992'], [-9007199254740992.0, '-9007199254740992'],
            [295147905179352830000.0, '295147905179352830000'], [9.999999999999997e22, '9.999999999999997e+22'],
            [1e22, '1e+22'], [1e21, '1e+21'], [999999999999999900000.0, '999999999999999900000'],
            [4.5, '4.5'], [0.002, '0.002'], [0.000001, '0.000001'], [1e-7, '1e-7'],
            [333333333.3333332, '333333333.3333332'], [1.5e-7, '1.5e-7'], [123456789.0, '123456789'],
        ];
        foreach ($cases as [$in, $out]) {
            $this->assertSame($out, CanonicalJson::encode($in), (string) $in);
        }
    }

    public function test_RFC8785_キーの並び順はUTF16(): void
    {
        $obj = (object) [
            "\u{20ac}" => 'Euro Sign', "\r" => 'Carriage Return', "\u{fb33}" => 'Hebrew Letter Dalet With Dagesh',
            '1' => 'One', "\u{1f600}" => 'Emoji: Grinning Face', "\u{0080}" => 'Control',
            "\u{00f6}" => 'Latin Small Letter O With Diaeresis',
        ];
        $this->assertSame(
            '{"\r":"Carriage Return","1":"One","'."\u{0080}".'":"Control","'."\u{00f6}".'":"Latin Small Letter O With Diaeresis","'."\u{20ac}".'":"Euro Sign","'."\u{1f600}".'":"Emoji: Grinning Face","'."\u{fb33}".'":"Hebrew Letter Dalet With Dagesh"}',
            CanonicalJson::encode($obj)
        );
        // 数字のキーは、文字列として比べる（"10" は "9" より前）
        $this->assertSame('{"10":1,"9":2,"a":3}', CanonicalJson::encode((object) ['a' => 3, '9' => 2, '10' => 1]));
    }

    public function test_RFC8785_付録の例(): void
    {
        $in = <<<'JSON'
{"numbers":[333333333.33333329,1E30,4.50,2e-3,0.000000000000000000000000001],"string":"€$\u000F\u000aA'B\"\\\\\"/","literals":[null,true,false]}
JSON;
        $expected = <<<'JSON'
{"literals":[null,true,false],"numbers":[333333333.3333333,1e+30,4.5,0.002,1e-27],"string":"€$\u000f\nA'B\"\\\\\"/"}
JSON;
        $this->assertSame($expected, CanonicalJson::encode($this->j($in)));
    }

    public function test_エスケープの境界(): void
    {
        $this->assertSame('"\u001f"', CanonicalJson::encode("\x1f"));
        $this->assertSame("\"\x7f\"", CanonicalJson::encode("\x7f"));
        $this->assertSame("\"\u{2028}\"", CanonicalJson::encode("\u{2028}"));
        $this->assertSame('"/"', CanonicalJson::encode('/'));
    }

    public function test_空のオブジェクトと空の配列(): void
    {
        $this->assertSame('{}', CanonicalJson::encode(new \stdClass()));
        $this->assertSame('[]', CanonicalJson::encode([]));
        $this->assertSame('{"a":{},"b":[]}', CanonicalJson::encode($this->j('{"b":[],"a":{}}')));
    }

    public function test_nullの値と数字のキーが混ざっても取り違えない(): void
    {
        $a = (object) ['abc' => null, '0' => 'x'];
        $b = (object) ['abc' => 'x', '0' => 'x'];
        $this->assertSame('{"0":"x","abc":null}', CanonicalJson::encode($a));
        $this->assertNotSame(CanonicalJson::encode($a), CanonicalJson::encode($b));
        $this->assertSame('{"0":"DELETE","a":null,"z":null}', CanonicalJson::encode((object) ['z' => null, 'a' => null, '0' => 'DELETE']));
    }

    public function test_連想配列は拒否(): void
    {
        foreach ([['a' => 1], [1 => 'x'], [1 => 'a', 0 => 'b']] as $bad) {
            $this->expectException(\InvalidArgumentException::class);
            CanonicalJson::encode($bad);
        }
    }

    public function test_不正な値は拒否(): void
    {
        $bads = [NAN, INF, -INF, 9007199254740993, PHP_INT_MIN, "\xff", new \ArrayObject([]), (object) ["\xff" => 1], fopen('php://memory', 'r')];
        foreach ($bads as $bad) {
            try {
                CanonicalJson::encode($bad);
                $this->fail('拒否されるはず');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame('9007199254740992', CanonicalJson::encode(9007199254740992));
    }

    public function test_型の違いは区別される(): void
    {
        $this->assertNotSame(CanonicalJson::encode('1'), CanonicalJson::encode(1));
        $this->assertNotSame(CanonicalJson::encode(true), CanonicalJson::encode(1));
        $this->assertSame(CanonicalJson::encode(1), CanonicalJson::encode(1.0)); // RFC どおり。型の固定はスキーマで行う（付録A）
    }

    public function test_計画ハッシュはplan_hash欄と並び順に影響されない(): void
    {
        $a = $this->j('{"plan_id":"P","version":1,"plan_hash":"x","steps":[{"id":"S-1"}]}');
        $b = $this->j('{"steps":[{"id":"S-1"}],"version":1,"plan_id":"P","plan_hash":"y"}');
        $this->assertSame(PlanHasher::hash($a, $this->inputs()), PlanHasher::hash($b, $this->inputs()));
        $this->assertSame('x', $a->plan_hash, '呼び出し元の計画は変えない');
    }

    public function test_入れ子のplan_hashは残る(): void
    {
        $a = $this->j('{"plan_id":"P","steps":[{"plan_hash":"x"}]}');
        $b = $this->j('{"plan_id":"P","steps":[{"plan_hash":"y"}]}');
        $this->assertNotSame(PlanHasher::hash($a, $this->inputs()), PlanHasher::hash($b, $this->inputs()));
    }

    public function test_計画ハッシュは中身が変わると変わる(): void
    {
        $p = $this->j('{"plan_id":"P","steps":[{"id":"S-1"}]}');
        $base = PlanHasher::hash($p, $this->inputs(5));
        $this->assertNotSame($base, PlanHasher::hash($p, $this->inputs(6)));
        $p->steps[0]->id = 'S-2';
        $this->assertNotSame($base, PlanHasher::hash($p, $this->inputs(5)));
        $this->assertStringStartsWith('sha256:', $base);
    }

    public function test_入力が欠けたら拒否(): void
    {
        $p = $this->j('{"plan_id":"P"}');
        foreach (PlanHasher::REQUIRED_INPUTS as $name) {
            $in = $this->inputs();
            unset($in[$name]);
            try {
                PlanHasher::hash($p, $in);
                $this->fail("{$name} が欠けても通った");
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        PlanHasher::hash($p, $this->inputs() + ['extra' => (object) []]);
    }

    public function test_版番号など中身でない値は拒否(): void
    {
        $in = $this->inputs();
        $in['operations'] = 5;
        $this->expectException(\InvalidArgumentException::class);
        PlanHasher::hash($this->j('{"plan_id":"P"}'), $in);
    }

    public function test_決め打ちの期待値(): void
    {
        // 方式が意図せず変わったら、ここで気付く。意図して変えるときは、SCHEME_VERSION を上げる
        $p = $this->j('{"plan_id":"PLN-0001","version":3,"steps":[{"id":"S-001","action":"open_url","reversible":true}]}');
        $this->assertSame(
            'sha256:'.hash('sha256', '{"inputs":{"destinations":[],"operations":{"v":5},"registrations":[],"screen_record":{}},"plan":{"plan_id":"PLN-0001","steps":[{"action":"open_url","id":"S-001","reversible":true}],"version":3},"type":"nl-rpa/plan-hash","v":1}'),
            PlanHasher::hash($p, $this->inputs())
        );
    }
}
