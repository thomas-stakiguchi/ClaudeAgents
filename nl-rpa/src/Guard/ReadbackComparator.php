<?php

declare(strict_types=1);

namespace Src\Guard;

/**
 * 読み返し照合の比較（9-3、A-4）。
 * ルールがなければ、完全一致。ルールがあれば、指定された正規化だけを、両方に（max_length は、期待値だけ）かけてから比べる。
 */
final class ReadbackComparator
{
    public static function equal(string $expected, string $actual, ?\stdClass $rule): bool
    {
        if ($rule === null) {
            return $expected === $actual;
        }
        if (($rule->max_length ?? null) !== null) {
            $expected = mb_substr($expected, 0, (int) $rule->max_length, 'UTF-8'); // 画面側の切り詰め
        }
        if (($rule->trim ?? false) === true) {
            $expected = self::trim($expected);
            $actual = self::trim($actual);
        }
        if (isset($rule->width)) {
            $mode = $rule->width === 'full' ? 'AS' : 'as';
            $expected = mb_convert_kana($expected, $mode, 'UTF-8');
            $actual = mb_convert_kana($actual, $mode, 'UTF-8');
        }
        if (($rule->thousand_separator ?? false) === true) {
            $expected = self::stripThousands($expected);
            $actual = self::stripThousands($actual);
        }
        if (isset($rule->date_format)) {
            $e = self::date($expected, (string) $rule->date_format);
            $a = self::date($actual, (string) $rule->date_format);

            return $e !== null && $a !== null && $e === $a;
        }

        return $expected === $actual;
    }

    private static function trim(string $s): string
    {
        return preg_replace('/\A[\s\x{3000}]+|[\s\x{3000}]+\z/u', '', $s) ?? $s;
    }

    private static function stripThousands(string $s): string
    {
        return preg_match('/\A[+-]?[0-9]{1,3}(?:[,，][0-9]{3})*(?:\.[0-9]+)?\z/u', $s) ? str_replace([',', '，'], '', $s) : $s;
    }

    private static function date(string $s, string $format): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!'.$format, $s);
        $err = \DateTimeImmutable::getLastErrors();
        if ($d === false || ($err !== false && ($err['warning_count'] > 0 || $err['error_count'] > 0))) {
            return null;
        }

        return $d->format('Y-m-d');
    }
}
