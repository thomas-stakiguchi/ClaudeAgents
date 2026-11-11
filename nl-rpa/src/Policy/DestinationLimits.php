<?php

declare(strict_types=1);

namespace Src\Policy;

/**
 * 接続してよい接続先の上限（ビルド時の定数。SEC-6、LOC-8）。実行時の設定では変えられない。
 * 1aは、スタブだけ。ここにない名前は、許可リストにも追加できない。
 * （変更はGitの差分で確認する。ビルドの工程はない）
 */
final class DestinationLimits
{
    /** 接続先の名前（計画の destination.name） */
    public const NAMES = ['stub'];

    public static function isAllowed(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }
}
