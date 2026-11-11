<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Plan\StrictJson;

class StrictJsonTest extends TestCase
{
    public function test_普通のJSONを読める(): void
    {
        $v = StrictJson::decode(' {"a":[1,2.5,"あ😀",null,true],"b":{}} ');
        $this->assertInstanceOf(\stdClass::class, $v);
        $this->assertSame([1, 2.5, "あ😀", null, true], $v->a);
        $this->assertEquals(new \stdClass(), $v->b);
    }

    #[DataProvider('bad')]
    public function test_不正なJSONは拒否(string $json): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StrictJson::decode($json);
    }

    /** @return array<string,array{string}> */
    public static function bad(): array
    {
        return [
            '重複キー' => ['{"a":1,"a":2}'],
            '入れ子の重複キー' => ['{"x":{"a":1,"a":2}}'],
            '2^53超' => ['{"a":9007199254740993}'],
            '巨大な整数' => ['{"a":123456789012345678901234567890}'],
            '孤立した上位サロゲート' => ['"\ud83d"'],
            '孤立した下位サロゲート' => ['"\ude00"'],
            '末尾の余分' => ['{} x'],
            '末尾のカンマ' => ['[1,2,]'],
            'シングルクォート' => ["{'a':1}"],
            '制御文字' => ["\"a\tb\""],
            '先頭ゼロ' => ['01'],
            '不正なUTF-8' => ["\"\xff\""],
            '空' => [''],
            'NaN' => ['NaN'],
            '深すぎる入れ子' => [str_repeat('[', 100).str_repeat(']', 100)],
            '桁あふれの小数' => ['1e999'],
        ];
    }

    public function test_キーが数字でも取り違えない(): void
    {
        $v = StrictJson::decode('{"0":"a","1":"b"}');
        $this->assertInstanceOf(\stdClass::class, $v);
        $this->assertSame('a', $v->{'0'});
    }

    public function test_2の53乗ちょうどは通る(): void
    {
        $this->assertSame(9007199254740992, StrictJson::decode('9007199254740992'));
        $this->assertSame(-9007199254740992, StrictJson::decode('-9007199254740992'));
    }
}
