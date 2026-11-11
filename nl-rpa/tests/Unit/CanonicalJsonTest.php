<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Src\Plan\CanonicalJson;
use Src\Plan\PlanHasher;

class CanonicalJsonTest extends TestCase
{
    public function test_RFC8785_数値の例(): void
    {
        // RFC 8785 付録B（ECMAScript表記）の代表値
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

    public function test_RFC8785_並べ替えと文字列(): void
    {
        // RFC 8785 3.2.3 のキー順の例（UTF-16の順。U+10000は U+FB33 より前に来る）
        $obj = [
            "\u{20ac}" => 'Euro Sign',
            "\r" => 'Carriage Return',
            "\u{fb33}" => 'Hebrew Letter Dalet With Dagesh',
            '1' => 'One',
            "\u{1f600}" => 'Emoji: Grinning Face',
            "\u{0080}" => 'Control',
            "\u{00f6}" => 'Latin Small Letter O With Diaeresis',
        ];
        $json = CanonicalJson::encode($obj);
        $this->assertSame(
            '{"\r":"Carriage Return","1":"One","'."\u{0080}".'":"Control","'."\u{00f6}".'":"Latin Small Letter O With Diaeresis","'."\u{20ac}".'":"Euro Sign","'."\u{1f600}".'":"Emoji: Grinning Face","'."\u{fb33}".'":"Hebrew Letter Dalet With Dagesh"}',
            $json
        );
    }

    public function test_RFC8785_付録の例(): void
    {
        $in = <<<'JSON'
{"numbers":[333333333.33333329,1E30,4.50,2e-3,0.000000000000000000000000001],"string":"€$\u000F\u000aA'B\"\\\\\"/","literals":[null,true,false]}
JSON;
        $expected = <<<'JSON'
{"literals":[null,true,false],"numbers":[333333333.3333333,1e+30,4.5,0.002,1e-27],"string":"€$\u000f\nA'B\"\\\\\"/"}
JSON;
        $this->assertSame($expected, CanonicalJson::encode(json_decode($in)));
    }

    public function test_空のオブジェクトと空の配列(): void
    {
        $this->assertSame('{}', CanonicalJson::encode(new \stdClass()));
        $this->assertSame('[]', CanonicalJson::encode([]));
    }

    public function test_不正な値は拒否(): void
    {
        foreach ([NAN, INF, 9007199254740993, "\xff"] as $bad) {
            try {
                CanonicalJson::encode($bad);
                $this->fail('拒否されるはず');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_計画ハッシュはplan_hash欄と並び順に影響されない(): void
    {
        $a = ['plan_id' => 'P', 'version' => 1, 'plan_hash' => 'x', 'steps' => [['id' => 'S-1']]];
        $b = ['steps' => [['id' => 'S-1']], 'version' => 1, 'plan_id' => 'P', 'plan_hash' => 'y'];
        $in = ['operations' => ['v' => 5]];
        $this->assertSame(PlanHasher::hash($a, $in), PlanHasher::hash($b, $in));
    }

    public function test_計画ハッシュは中身が変わると変わる(): void
    {
        $p = ['plan_id' => 'P', 'steps' => [['id' => 'S-1']]];
        $base = PlanHasher::hash($p, ['operations' => ['v' => 5]]);
        $this->assertNotSame($base, PlanHasher::hash($p, ['operations' => ['v' => 6]]));
        $p['steps'][0]['id'] = 'S-2';
        $this->assertNotSame($base, PlanHasher::hash($p, ['operations' => ['v' => 5]]));
        $this->assertStringStartsWith('sha256:', $base);
    }
}
