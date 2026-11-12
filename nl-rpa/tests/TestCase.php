<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** スタブの「登録ボタンを押す」操作の、状態の表の識別子（EffectId。接続先 stub・操作 click・対象 submit_button から計算） */
    public const SUBMIT = 'E-cb6b5609f4dd4e29';

    //

    /**
     * 環境のせいで走らせられないテストを、飛ばす。
     * ただし、NL_RPA_REQUIRE_FULL_TESTS=1 のときは、飛ばさず、失敗にする
     * （完成の確認＝PHP 8.5.12 での全テストの合格［14-6］で、安全のテストが、黙って飛ばされないように）。
     */
    protected function skipOrFail(string $reason): never
    {
        if (getenv('NL_RPA_REQUIRE_FULL_TESTS') === '1') {
            $this->fail('NL_RPA_REQUIRE_FULL_TESTS=1 のため、飛ばさず失敗にします: '.$reason);
        }
        $this->markTestSkipped($reason);
    }
}
