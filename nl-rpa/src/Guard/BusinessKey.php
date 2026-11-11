<?php

declare(strict_types=1);

namespace Src\Guard;

/**
 * 業務キー（9-4）。表記の違い（全角半角、前後の空白、ゼロ幅の文字など）で、同じ相手への二重実行を見逃さないための規則。
 *  - NFKC正規化しても変わらない文字列だけを認める（全角の英数字などは不可）
 *  - 空白（Unicodeの空白類を含む）、制御・書式・未割り当ての文字は不可
 *  - 比較と保存は、大文字小文字を区別しない形（fold）で行う（区別するかは、仕様で未定。安全側＝同じものとして扱う）
 */
final class BusinessKey
{
    public const MAX_BYTES = 200;

    /** @return string|null 不正なら、理由 */
    public static function problem(string $key): ?string
    {
        if ($key === '' || strlen($key) > self::MAX_BYTES) {
            return '空か長すぎます';
        }
        if (! mb_check_encoding($key, 'UTF-8')) {
            return '不正なUTF-8です';
        }
        if (preg_match('/[\p{C}\p{Z}]/u', $key)) {
            return '空白・制御文字・ゼロ幅の文字などは使えません';
        }
        if (\Normalizer::normalize($key, \Normalizer::FORM_KC) !== $key) {
            return '全角の英数字や互換文字は使えません（正規化したものを使ってください）';
        }

        return null;
    }

    /** 保存・比較に使う形 */
    public static function fold(string $key): string
    {
        return mb_strtolower($key, 'UTF-8');
    }
}
