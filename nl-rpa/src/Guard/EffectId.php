<?php

declare(strict_types=1);

namespace Src\Guard;

use Src\Plan\CanonicalJson;

/**
 * 「何に対して、何をするか」を表す、実行エンジンが決める識別子（9-4、10-3の「手順ID」の代わり）。
 * 状態の表（成功済みの判定）には、AIが振る手順ID（S-005 など）ではなく、これを使う。
 * 版が変わって、AIが手順IDを振り直しても、同じ接続先・同じ操作・同じ対象なら、同じ識別子になる。
 * 同じ業務キーを、もう一度、実行してしまうのを防ぐ。
 */
final class EffectId
{
    public static function of(\stdClass $step): string
    {
        $parts = [
            (string) ($step->destination->name ?? ''),
            (string) ($step->action ?? ''),
            (string) ($step->target ?? ''),
        ];

        return 'E-'.substr(hash('sha256', CanonicalJson::encode($parts)), 0, 16);
    }
}
