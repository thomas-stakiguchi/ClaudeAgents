<?php

declare(strict_types=1);

namespace App\Support;

/**
 * プロセスの情報（Linuxの /proc のみ）。プロセスID（PID）は、再利用されうる。
 * PIDと「開始時刻」の組で、同じプロセスかを判定する（コマンドラインの書き方には、頼らない）。
 */
final class ProcessInfo
{
    /**
     * /proc/PID/stat を解析して、[状態, 親PID, 開始時刻] を返す。
     * プロセス名に空白や ')' が入っていても、最後の ')' のあとから読む。なければ null。
     *
     * @return array{state:string, ppid:int, starttime:string}|null
     */
    public static function read(int $pid): ?array
    {
        $stat = @file_get_contents('/proc/'.$pid.'/stat');
        if (! is_string($stat) || ($close = strrpos($stat, ')')) === false) {
            return null;
        }
        $rest = explode(' ', trim(substr($stat, $close + 1)));
        // $rest: [0]=状態, [1]=ppid, ..., [19]=starttime（全体の22番目）
        if (count($rest) < 20) {
            return null;
        }

        return ['state' => $rest[0], 'ppid' => (int) $rest[1], 'starttime' => $rest[19]];
    }

    public static function startTime(int $pid): ?string
    {
        return self::read($pid)['starttime'] ?? null;
    }

    /** 生きているか。終了して、親が回収していないだけのプロセス（ゾンビ Z）や、終了中（X）は、生きていないものとして扱う */
    public static function isAlive(int $pid): bool
    {
        $r = self::read($pid);

        return $r !== null && ! in_array($r['state'], ['Z', 'X'], true);
    }

    /** @return list<int> 子孫のプロセスID */
    public static function descendants(int $root): array
    {
        $children = [];
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $f) {
            $pid = (int) basename(dirname($f));
            $r = self::read($pid);
            $r !== null && $children[$r['ppid']][] = $pid;
        }
        $out = [];
        $stack = [$root];
        while ($stack) {
            $p = array_pop($stack);
            foreach ($children[$p] ?? [] as $c) {
                if (! in_array($c, $out, true)) {
                    $out[] = $c;
                    $stack[] = $c;
                }
            }
        }

        return $out;
    }
}
