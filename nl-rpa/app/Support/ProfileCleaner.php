<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 使い捨てプロファイルの回収（仕様14-3の5、SEC-3）。異常終了のあとに残った、Chromeのプロセスと、プロファイルのディレクトリを片付ける。
 * Chromeは、「--user-data-dir=ディレクトリ」という引数と「完全に一致」するプロセスだけを対象にする（ディレクトリ名は、実行ごとの乱数）。
 * 片付けるディレクトリは、プロファイルの置き場の中の「profile-」で始まるものだけ（それ以外は、触らない）。
 * プロセスの探索は、Linuxの /proc のみ。ほかのOSでは、ディレクトリの回収だけ行い、プロセスは、手で終了する（未対応）。
 */
final class ProfileCleaner
{
    /**
     * @param list<string> $dirs 記録されているプロファイルのディレクトリ
     * @return array{processes:int, dirs:int, skipped:list<string>}
     */
    public static function clean(string $profileRoot, array $dirs): array
    {
        $killed = 0;
        $removed = 0;
        $skipped = [];
        $root = Engine::resolvePath($profileRoot);
        foreach ($dirs as $dir) {
            $real = Engine::resolvePath($dir);
            if ($root === null || $real === null || ! str_starts_with($real, $root.'/profile-') || str_contains(substr($real, strlen($root) + 1), '/')) {
                $skipped[] = $dir; // 置き場の外・形が違うものは、触らない

                continue;
            }
            $killed += self::killChrome($dir);
            if (is_dir($real)) {
                self::removeDir($real);
                $removed += is_dir($real) ? 0 : 1;
            }
        }

        return ['processes' => $killed, 'dirs' => $removed, 'skipped' => $skipped];
    }

    /** 引数「--user-data-dir=DIR」が、完全に一致するプロセス（と、その子孫）を、終了する。終了した数を返す */
    private static function killChrome(string $dir): int
    {
        if (! is_dir('/proc') || ! function_exists('posix_kill')) {
            return 0;
        }
        $needle = '--user-data-dir='.$dir;
        $targets = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $f) {
            $cmd = @file_get_contents($f);
            if (is_string($cmd) && in_array($needle, explode("\0", rtrim($cmd, "\0")), true)) {
                $pid = (int) basename(dirname($f));
                $targets[] = $pid;
                array_push($targets, ...ProcessInfo::descendants($pid));
            }
        }
        $targets = array_values(array_unique($targets));
        // 止めて（増えないようにして）、子孫を数え直す。変わらなくなるまで繰り返してから、終了する
        for ($i = 0; $i < 10 && $targets !== []; $i++) {
            foreach ($targets as $pid) {
                @posix_kill($pid, SIGSTOP);
            }
            $next = $targets;
            foreach ($targets as $pid) {
                array_push($next, ...ProcessInfo::descendants($pid));
            }
            $next = array_values(array_unique($next));
            if (count($next) === count($targets)) {
                break;
            }
            $targets = $next;
        }
        foreach ($targets as $pid) {
            @posix_kill($pid, SIGKILL);
        }
        usleep(300000);

        return count($targets);
    }

    private static function removeDir(string $d): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($d, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isLink() || ! $f->isDir() ? @unlink($f->getPathname()) : @rmdir($f->getPathname());
        }
        @rmdir($d);
    }
}
