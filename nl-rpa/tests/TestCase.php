<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
