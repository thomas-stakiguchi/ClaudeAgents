<?php

namespace App\Console\Commands;

use App\Support\Engine;
use Illuminate\Console\Command;
use Src\State\Flags;

/**
 * 緊急停止（R12）。キルスイッチを立てたうえで、記録したプロセスIDのワーカーと、その子プロセス（Chromeなど）を、まとめて終了する。
 * 画面の停止ボタン（協調停止）とは別。プロセスIDが再利用されていても、無関係なプロセスを終了しないよう、
 * コマンドラインに nlrpa:worker が含まれるプロセスだけを対象にする。
 * 方法はOSごとに決める（11章）。今は Linux（/proc）だけ対応。ほかのOSでは、キルスイッチだけ立てる（未対応）。
 */
class NlrpaEmergencyStop extends Command
{
    protected $signature = 'nlrpa:emergency-stop';

    protected $description = '緊急停止：キルスイッチを立て、ワーカーと子プロセスを終了する';

    public function handle(Engine $e): int
    {
        $by = 'console ('.Engine::osUser().')';
        $e->flags->set(Flags::KILL_SWITCH, true, $by);
        $this->warn('キルスイッチを立てました（解除は nlrpa:kill-switch off）');
        if (! is_dir('/proc') || ! function_exists('posix_kill')) {
            $this->error('このOSでは、プロセスの終了は未対応です。ワーカーを、手で終了してください');

            return self::FAILURE;
        }
        $killed = 0;
        $failed = 0;
        foreach ($e->store->workers() as $w) {
            // コマンドラインが「... artisan nlrpa:worker {要求の番号}」と、完全に一致するプロセスだけを対象にする
            // （PIDが再利用されて、無関係なプロセスになっていても、終了しない）
            $cmd = @file_get_contents('/proc/'.$w['pid'].'/cmdline');
            $args = is_string($cmd) ? explode("\0", rtrim($cmd, "\0")) : [];
            $i = array_search('nlrpa:worker', $args, true);
            $isWorker = $i !== false && ($args[$i + 1] ?? null) === (string) $w['request_id']
                && count(array_filter($args, static fn (string $a): bool => $a === 'artisan' || str_ends_with($a, '/artisan'))) >= 1;
            if (! $isWorker) {
                $this->line("PID {$w['pid']}：すでに終了しているか、ワーカーではありません（飛ばします）");
                $e->store->unregisterWorker($w['pid']);
                continue;
            }
            // 子孫を列挙して終了する。終了のあとに、もう一度列挙して、取りこぼし（列挙のあとに生まれた子）も終了する
            $targets = array_merge(self::descendants($w['pid']), [$w['pid']]);
            foreach ($targets as $pid) {
                @posix_kill($pid, SIGTERM);
            }
            usleep(500000);
            $targets = array_values(array_unique(array_merge($targets, self::descendants($w['pid']))));
            foreach ($targets as $pid) {
                if (self::isAlive($pid)) {
                    @posix_kill($pid, SIGKILL);
                }
            }
            usleep(200000);
            $alive = array_values(array_filter($targets, static fn (int $pid): bool => self::isAlive($pid)));
            if ($alive !== []) {
                $failed += count($alive);
                $this->error("PID {$w['pid']} の関連プロセスが、終了できませんでした: ".implode(', ', $alive));
                continue; // 登録は消さない（もう一度、実行できるように）
            }
            $killed += count($targets);
            $e->store->unregisterWorker($w['pid']);
            $this->line("PID {$w['pid']} と子プロセスを終了しました（".count($targets).'件）');
        }
        if ($failed > 0) {
            $this->error("終了できなかったプロセスが {$failed} 件あります。手で終了してください");
        }
        $this->info("終了したプロセス：{$killed}件。次に php artisan nlrpa:recover で後始末をしてください");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * プロセスが生きているか。/proc/PID/stat を読んで判定する（is_dir は、PHPのstatキャッシュで、古い結果を返すことがある）。
     * 終了していて、親が回収していないだけのプロセス（ゾンビ）は、生きていないものとして扱う。
     */
    private static function isAlive(int $pid): bool
    {
        clearstatcache();
        $stat = @file_get_contents('/proc/'.$pid.'/stat');

        return is_string($stat) && ! str_contains($stat, ') Z');
    }

    /** @return list<int> 子孫のプロセスID */
    private static function descendants(int $root): array
    {
        $children = [];
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $f) {
            $stat = @file_get_contents($f);
            if (is_string($stat) && preg_match('/^(\d+) \(.*\) \S (\d+) /s', $stat, $m)) {
                $children[(int) $m[2]][] = (int) $m[1];
            }
        }
        $out = [];
        $stack = [$root];
        while ($stack) {
            $p = array_pop($stack);
            foreach ($children[$p] ?? [] as $c) {
                $out[] = $c;
                $stack[] = $c;
            }
        }

        return $out;
    }
}
