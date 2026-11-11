<?php

declare(strict_types=1);

namespace Src\Runner;

use Src\Browser\BrowserPort;
use Src\Guard\ReadbackComparator;

/**
 * 確認条件の判定（付録A-4）。機械で判定する。AIは使わない。
 *  - text_visible：画面に、value の文字がある
 *  - text_equals：step.target の値が、value（固定値）と等しい
 *  - element_exists：value という名前の要素が、画面にある
 *  - url_matches：開いている画面の名前が、value と等しい
 *  - value_matches：step.target の値が、入力データの列（value＝列名）の値と、照合ルールのもとで一致する
 */
final class Verifier
{
    public function __construct(private readonly BrowserPort $browser)
    {
    }

    /** @param array<string,string> $rowValues */
    public function check(\stdClass $verify, \stdClass $step, array $rowValues): bool
    {
        $value = (string) ($verify->value ?? '');
        switch ($verify->type ?? null) {
            case 'text_visible':
                return $this->browser->textVisible($value);
            case 'text_equals':
                return isset($step->target) && $this->browser->readValue($step->target) === $value;
            case 'element_exists':
                return $this->browser->elementExists($value);
            case 'url_matches':
                return $this->browser->currentPage() === $value;
            case 'value_matches':
                if (! isset($step->target) || ! array_key_exists($value, $rowValues)) {
                    return false;
                }

                return ReadbackComparator::equal($rowValues[$value], $this->browser->readValue($step->target), $verify->rule ?? null);
        }

        return false; // 知らない種類は、不合格
    }
}
