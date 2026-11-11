<?php

declare(strict_types=1);

namespace Src\Plan;

/**
 * 厳密なJSON読み込み。承認の画面・ハッシュ・実行で、同じ解釈になるようにする。
 * 次を拒否する：重複キー、2^53を超える整数、不正なUTF-8、孤立したサロゲート、末尾の余分な文字、深すぎる入れ子。
 * オブジェクトは stdClass、配列は連番のPHP配列で返す。
 */
final class StrictJson
{
    private int $i = 0;

    private function __construct(private readonly string $s, private readonly int $maxDepth)
    {
    }

    public static function decode(string $json, int $maxDepth = 64): mixed
    {
        if (! mb_check_encoding($json, 'UTF-8')) {
            throw new \InvalidArgumentException('UTF-8として不正です');
        }
        $p = new self($json, $maxDepth);
        $p->ws();
        $v = $p->value(0);
        $p->ws();
        if ($p->i !== strlen($json)) {
            throw $p->err('末尾に余分な文字があります');
        }

        return $v;
    }

    private function err(string $m): \InvalidArgumentException
    {
        return new \InvalidArgumentException("JSONエラー（位置{$this->i}）: {$m}");
    }

    private function ws(): void
    {
        while ($this->i < strlen($this->s) && strpos(" \t\n\r", $this->s[$this->i]) !== false) {
            $this->i++;
        }
    }

    private function value(int $depth): mixed
    {
        if ($depth > $this->maxDepth) {
            throw $this->err('入れ子が深すぎます');
        }
        $c = $this->s[$this->i] ?? '';
        switch (true) {
            case $c === '{':
                return $this->object($depth);
            case $c === '[':
                return $this->arr($depth);
            case $c === '"':
                return $this->str();
            case $c === '-' || ($c >= '0' && $c <= '9' && $c !== ''):
                return $this->num();
        }
        foreach (['true' => true, 'false' => false, 'null' => null] as $lit => $val) {
            if (substr($this->s, $this->i, strlen($lit)) === $lit) {
                $this->i += strlen($lit);

                return $val;
            }
        }
        throw $this->err('値が読めません');
    }

    private function object(int $depth): \stdClass
    {
        $this->i++;
        $o = new \stdClass();
        $seen = [];
        $this->ws();
        if (($this->s[$this->i] ?? '') === '}') {
            $this->i++;

            return $o;
        }
        while (true) {
            $this->ws();
            if (($this->s[$this->i] ?? '') !== '"') {
                throw $this->err('キーは文字列にしてください');
            }
            $k = $this->str();
            if (isset($seen[$k])) {
                throw $this->err("キーが重複しています: {$k}");
            }
            $seen[$k] = true;
            $this->ws();
            if (($this->s[$this->i] ?? '') !== ':') {
                throw $this->err(':が必要です');
            }
            $this->i++;
            $this->ws();
            $o->{$k} = $this->value($depth + 1);
            $this->ws();
            $c = $this->s[$this->i] ?? '';
            $this->i++;
            if ($c === '}') {
                return $o;
            }
            if ($c !== ',') {
                throw $this->err(',か}が必要です');
            }
        }
    }

    /** @return list<mixed> */
    private function arr(int $depth): array
    {
        $this->i++;
        $a = [];
        $this->ws();
        if (($this->s[$this->i] ?? '') === ']') {
            $this->i++;

            return $a;
        }
        while (true) {
            $this->ws();
            $a[] = $this->value($depth + 1);
            $this->ws();
            $c = $this->s[$this->i] ?? '';
            $this->i++;
            if ($c === ']') {
                return $a;
            }
            if ($c !== ',') {
                throw $this->err(',か]が必要です');
            }
        }
    }

    private function str(): string
    {
        $this->i++; // 開きの"
        $out = '';
        $n = strlen($this->s);
        while (true) {
            if ($this->i >= $n) {
                throw $this->err('文字列が閉じていません');
            }
            $c = $this->s[$this->i];
            if ($c === '"') {
                $this->i++;

                return $out;
            }
            if (ord($c) < 0x20) {
                throw $this->err('文字列に制御文字があります');
            }
            if ($c !== '\\') {
                $out .= $c;
                $this->i++;
                continue;
            }
            $e = $this->s[$this->i + 1] ?? '';
            $this->i += 2;
            $map = ['"' => '"', '\\' => '\\', '/' => '/', 'b' => "\x08", 'f' => "\x0c", 'n' => "\n", 'r' => "\r", 't' => "\t"];
            if ($e !== '' && isset($map[$e])) {
                $out .= $map[$e];
            } elseif ($e === 'u') {
                $cp = $this->hex4();
                if ($cp >= 0xD800 && $cp <= 0xDBFF) {
                    if (substr($this->s, $this->i, 2) !== '\\u') {
                        throw $this->err('孤立したサロゲートです');
                    }
                    $this->i += 2;
                    $lo = $this->hex4();
                    if ($lo < 0xDC00 || $lo > 0xDFFF) {
                        throw $this->err('孤立したサロゲートです');
                    }
                    $cp = 0x10000 + (($cp - 0xD800) << 10) + ($lo - 0xDC00);
                } elseif ($cp >= 0xDC00 && $cp <= 0xDFFF) {
                    throw $this->err('孤立したサロゲートです');
                }
                $out .= mb_chr($cp, 'UTF-8');
            } else {
                throw $this->err('不正なエスケープです');
            }
        }
    }

    private function hex4(): int
    {
        $h = substr($this->s, $this->i, 4);
        if (! preg_match('/\A[0-9a-fA-F]{4}\z/', $h)) {
            throw $this->err('\\uの後は16進4桁です');
        }
        $this->i += 4;

        return (int) hexdec($h);
    }

    private function num(): int|float
    {
        if (! preg_match('/\G-?(?:0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?/', $this->s, $m, 0, $this->i)) {
            throw $this->err('数値が読めません');
        }
        $this->i += strlen($m[0]);
        if (($m[1] ?? '') === '' && ($m[2] ?? '') === '') {
            $digits = ltrim($m[0], '-');
            // 16桁以下なら、PHPの整数で正確に比べられる（floatに丸めて比べない）
            if (strlen($digits) > 16 || (int) $digits > 9007199254740992) {
                throw $this->err('2^53を超える整数は使えません');
            }

            return (int) $m[0];
        }
        $f = (float) $m[0];
        if (is_infinite($f)) {
            throw $this->err('数値が大きすぎます');
        }

        return $f;
    }
}
