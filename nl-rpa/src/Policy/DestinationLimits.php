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

    /**
     * スタブの画面（Web版）に使ってよいポート（ビルド時の定数。SEC-6）。
     * 設定値（NL_RPA_STUB_PORT）が、この中になければ、使わない。PostgreSQL など、同じPCの別のサービスのポートへ、
     * 自動操作のChromeを向けられないようにするため。8081 が既定。18300〜18399 は、試験と、予備。
     *
     * @return list<int>
     */
    public static function stubPorts(): array
    {
        return [8081, ...range(18300, 18399)];
    }

    public static function isAllowedStubPort(int $port): bool
    {
        return in_array($port, self::stubPorts(), true);
    }

    public static function isAllowed(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }
}
