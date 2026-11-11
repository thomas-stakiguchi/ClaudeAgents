<?php

declare(strict_types=1);

namespace Src\Plan;

/**
 * RFC 8785（JSON Canonicalization Scheme）に沿った正規化。
 *
 * 入力の約束：
 *  - JSONのオブジェクトは、必ず stdClass で渡す（json_decode の assoc=false の結果）
 *  - JSONの配列は、0から連番の PHP配列で渡す。キーが連番でない配列は拒否する
 *    （連想配列を受けると、{"0":..} と [..]、{} と [] が区別できず、別の計画が同じハッシュになるため）
 *  - 数値は int か 有限の float（NaN・INFは拒否）
 */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return self::number((float) $value, $value);
        }
        if (is_float($value)) {
            return self::number($value, null);
        }
        if (is_string($value)) {
            return self::string($value);
        }
        if ($value instanceof \stdClass) {
            return self::object(get_object_vars($value));
        }
        if (is_array($value)) {
            if (! array_is_list($value)) {
                throw new \InvalidArgumentException('連想配列は使えません。オブジェクトは stdClass で渡してください');
            }

            return '['.implode(',', array_map(self::encode(...), $value)).']';
        }

        throw new \InvalidArgumentException('正規化できない型です: '.get_debug_type($value));
    }

    /** @param array<array-key,mixed> $obj */
    private static function object(array $obj): string
    {
        // キーと値を組にして並べ替える（キーだけ取り出して引き直すと、nullの値や数字のキーで取り違える）
        $pairs = [];
        foreach ($obj as $k => $v) {
            $key = (string) $k;
            $pairs[] = [$key, self::utf16($key), $v];
        }
        // キーは、UTF-16のコード単位の順に並べる（RFC 8785 3.2.3）
        usort($pairs, static fn (array $a, array $b): int => strcmp($a[1], $b[1]));
        $parts = [];
        foreach ($pairs as [$key, , $v]) {
            $parts[] = self::string($key).':'.self::encode($v);
        }

        return '{'.implode(',', $parts).'}';
    }

    /** UTF-16BE のバイト列にして、そのまま辞書順で比べられる形にする */
    private static function utf16(string $s): string
    {
        $out = mb_convert_encoding($s, 'UTF-16BE', 'UTF-8');
        if ($out === false) {
            throw new \InvalidArgumentException('UTF-8として不正な文字列です');
        }

        return $out;
    }

    private static function string(string $s): string
    {
        if (! mb_check_encoding($s, 'UTF-8')) {
            throw new \InvalidArgumentException('UTF-8として不正な文字列です');
        }
        $out = '"';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            $o = ord($c);
            $out .= match (true) {
                $c === '"' => '\\"',
                $c === '\\' => '\\\\',
                $o === 0x08 => '\\b',
                $o === 0x09 => '\\t',
                $o === 0x0A => '\\n',
                $o === 0x0C => '\\f',
                $o === 0x0D => '\\r',
                $o < 0x20 => sprintf('\\u%04x', $o),
                default => $c, // 0x7F以上のUTF-8のバイトは、そのまま出す
            };
        }

        return $out.'"';
    }

    /** ECMAScript の Number::toString に合わせた数値表記（RFC 8785 3.2.2.3） */
    private static function number(float $f, ?int $int): string
    {
        if (is_nan($f) || is_infinite($f)) {
            throw new \InvalidArgumentException('NaN・Infinityは使えません');
        }
        if ($int !== null && abs($int) > 9007199254740992) {
            throw new \InvalidArgumentException('2^53を超える整数は使えません（文字列で渡してください）');
        }
        if ($f == 0.0) {
            return '0'; // -0 も 0
        }
        $neg = $f < 0;
        // 最短の往復可能な表記（仮数部の桁と指数）を得る
        $repr = sprintf('%.17e', abs($f));
        $digits = null;
        for ($p = 1; $p <= 17; $p++) {
            $try = sprintf('%.'.($p - 1).'e', abs($f));
            if ((float) $try === abs($f)) {
                $repr = $try;
                break;
            }
        }
        [$mant, $exp] = explode('e', $repr);
        $digits = rtrim(str_replace('.', '', $mant), '0');
        if ($digits === '') {
            $digits = '0';
        }
        $k = strlen($digits);
        $n = (int) $exp + 1; // 小数点の位置
        if ($k <= $n && $n <= 21) {
            $s = $digits.str_repeat('0', $n - $k);
        } elseif (0 < $n && $n <= 21) {
            $s = substr($digits, 0, $n).'.'.substr($digits, $n);
        } elseif (-6 < $n && $n <= 0) {
            $s = '0.'.str_repeat('0', -$n).$digits;
        } else {
            $e = $n - 1;
            $es = ($e < 0 ? '-' : '+').abs($e);
            $s = $k === 1 ? $digits.'e'.$es : $digits[0].'.'.substr($digits, 1).'e'.$es;
        }

        return ($neg ? '-' : '').$s;
    }
}
