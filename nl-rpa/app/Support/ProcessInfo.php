<?php

declare(strict_types=1);

namespace App\Support;

/**
 * プロセスの情報（Linuxは /proc、Windowsは tasklist と PowerShell）。プロセスID（PID）は、再利用されうる。
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
        if (self::isWindows()) {
            return self::readWindows($pid);
        }
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

    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /**
     * Windows。生きているかは tasklist、開始時刻と親は PowerShell（CIM）で調べる。開始時刻は、UTCの Ticks。
     * PowerShell が使えず、開始時刻を読めないときは null（呼び出し側は、「確認できない」として扱う。生きている扱いにはしない）。
     *
     * @return array{state:string, ppid:int, starttime:string}|null
     */
    private static function readWindows(int $pid): ?array
    {
        if ($pid < 1 || ! self::aliveWindows($pid)) {
            return null;
        }
        $out = self::runWindows([
            'powershell.exe', '-NoProfile', '-NonInteractive', '-Command',
            '$p = Get-CimInstance Win32_Process -Filter \'ProcessId='.$pid.'\'; if ($p) { \'{0} {1}\' -f $p.ParentProcessId, $p.CreationDate.ToUniversalTime().Ticks }',
        ]);
        if ($out !== null && preg_match('/\A(\d+) (\d+)\z/', trim($out), $m) === 1) {
            return ['state' => 'R', 'ppid' => (int) $m[1], 'starttime' => $m[2]];
        }

        return null;
    }

    /** Windowsで、そのPIDのプロセスが、今あるか（tasklist） */
    public static function aliveWindows(int $pid): bool
    {
        $out = self::runWindows(['tasklist.exe', '/FI', 'PID eq '.$pid, '/NH', '/FO', 'CSV']);

        return $out !== null && preg_match('/","'.$pid.'","/', $out) === 1;
    }

    /** Windowsで、プロセスを、子孫ごと終了する（taskkill /T /F）。成功したか */
    public static function killTreeWindows(int $pid): bool
    {
        self::runWindows(['taskkill.exe', '/PID', (string) $pid, '/T', '/F']);
        usleep(300000);

        return ! self::aliveWindows($pid);
    }

    /** @param list<string> $cmd シェルを通さず、引数の配列で起動する。標準出力を返す。起動できなければ null */
    private static function runWindows(array $cmd): ?string
    {
        $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($proc)) {
            return null;
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return is_string($out) ? $out : null;
    }

    public static function startTime(int $pid): ?string
    {
        return self::read($pid)['starttime'] ?? null;
    }

    /** 生きているか。終了して、親が回収していないだけのプロセス（ゾンビ Z）や、終了中（X）は、生きていないものとして扱う */
    public static function isAlive(int $pid): bool
    {
        if (self::isWindows()) {
            return self::aliveWindows($pid); // PowerShellが使えなくても、生きているかは、tasklist で分かる
        }
        $r = self::read($pid);

        return $r !== null && ! in_array($r['state'], ['Z', 'X'], true);
    }

    /** @return list<int> 子孫のプロセスID */
    public static function descendants(int $root): array
    {
        if (self::isWindows()) {
            return []; // Windowsは、taskkill /T が、子孫ごと終了する
        }
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
