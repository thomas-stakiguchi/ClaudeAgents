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
        foreach ($e->store->workers() as $w) {
            $cmd = @file_get_contents('/proc/'.$w['pid'].'/cmdline');
            if (! is_string($cmd) || ! str_contains($cmd, 'nlrpa:worker')) {
                $this->line("PID {$w['pid']}：すでに終了しているか、ワーカーではありません（飛ばします）");
                $e->store->unregisterWorker($w['pid']);
                continue;
            }
            $targets = array_merge(self::descendants($w['pid']), [$w['pid']]);
            foreach ($targets as $pid) {
                @posix_kill($pid, SIGTERM);
            }
            usleep(500000);
            foreach ($targets as $pid) {
                if (is_dir('/proc/'.$pid)) {
                    @posix_kill($pid, SIGKILL);
                }
            }
            $killed += count($targets);
            $e->store->unregisterWorker($w['pid']);
            $this->line("PID {$w['pid']} と子プロセスを終了しました（".count($targets).'件）');
        }
        $this->info("終了したプロセス：{$killed}件。次に php artisan nlrpa:recover で後始末をしてください");

        return self::SUCCESS;
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
