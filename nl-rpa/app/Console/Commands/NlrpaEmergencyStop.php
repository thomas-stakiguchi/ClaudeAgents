<?php

namespace App\Console\Commands;

use App\Support\Engine;
use App\Support\ProcessInfo;
use Illuminate\Console\Command;
use Src\State\Flags;

/**
 * 緊急停止（R12）。キルスイッチを立てたうえで、記録したワーカーと、その子孫のプロセス（Chromeなど）を、まとめて終了する。
 * 画面の停止ボタン（協調停止）とは別。
 *
 * ワーカーは、起動時に、PIDと開始時刻（/proc の starttime）を記録する。緊急停止は、PIDと開始時刻が一致するプロセスだけを対象にする
 * （PIDが再利用されて、無関係なプロセスになっていても、終了しない。コマンドラインの書き方にも、頼らない）。
 * 終了の手順：全体に SIGSTOP を送って止め（増えないように）、子孫を数え直して、安定するまで繰り返してから、SIGKILL を送る。
 * 方法はOSごとに決める（11章）。今は Linux（/proc）だけ対応。ほかのOSでは、キルスイッチだけ立てる（未対応）。
 */
class NlrpaEmergencyStop extends Command
{
    protected $signature = 'nlrpa:emergency-stop';

    protected $description = '緊急停止：キルスイッチを立て、ワーカーと子孫のプロセスを終了する';

    public function handle(Engine $e): int
    {
        $by = 'console ('.Engine::osUser().')';
        $e->flags->set(Flags::KILL_SWITCH, true, $by);
        $this->warn('キルスイッチを立てました（解除は nlrpa:kill-switch off）');
        if (ProcessInfo::isWindows()) {
            return $this->stopWindows($e);
        }
        if (! is_dir('/proc') || ! function_exists('posix_kill')) {
            $this->error('このOSでは、プロセスの終了は未対応です。ワーカーを、手で終了してください');

            return self::FAILURE;
        }
        $killed = 0;
        $failed = 0;
        foreach ($e->store->workers() as $w) {
            $now = ProcessInfo::startTime($w['pid']);
            if ($w['proc_start'] === null || $now === null || $now !== $w['proc_start'] || ! ProcessInfo::isAlive($w['pid'])) {
                $this->line("PID {$w['pid']}：すでに終了しているか、記録したワーカーと別のプロセスです（飛ばします）");
                $e->store->unregisterWorker($w['pid']);
                // そのワーカーが残した、使い捨てプロファイルと、孤児のChromeは、回収する
                $orphans = $e->store->profiles($w['pid']);
                $r = \App\Support\ProfileCleaner::clean($e->profileRoot(), array_column($orphans, 'dir'));
                $killed += $r['processes'];
                foreach ($orphans as $p) {
                    in_array($p['dir'], $r['skipped'], true) || $e->store->unregisterProfile($p['dir']);
                }
                continue;
            }
            // 全体を止めて（新しい子が増えないようにして）、子孫を数え直す。変わらなくなるまで繰り返す
            $targets = [$w['pid']];
            for ($i = 0; $i < 10; $i++) {
                foreach ($targets as $pid) {
                    @posix_kill($pid, SIGSTOP);
                }
                $next = array_values(array_unique(array_merge([$w['pid']], ProcessInfo::descendants($w['pid']))));
                if (count($next) === count($targets)) {
                    $targets = $next;
                    break;
                }
                $targets = $next;
            }
            foreach ($targets as $pid) {
                @posix_kill($pid, SIGKILL);
            }
            usleep(300000);
            $alive = array_values(array_filter($targets, static fn (int $pid): bool => ProcessInfo::isAlive($pid)));
            if ($alive !== []) {
                $failed += count($alive);
                $this->error("PID {$w['pid']} の関連プロセスが、終了できませんでした: ".implode(', ', $alive));
                continue; // 登録は消さない（もう一度、実行できるように）
            }
            $killed += count($targets);
            $e->store->unregisterWorker($w['pid']);
            // このワーカーの使い捨てプロファイルも、回収する（親を失って孤児になったChromeは、子孫として見つからないため）
            $profiles = $e->store->profiles($w['pid']);
            $r = \App\Support\ProfileCleaner::clean($e->profileRoot(), array_column($profiles, 'dir'));
            $killed += $r['processes'];
            foreach ($profiles as $p) {
                in_array($p['dir'], $r['skipped'], true) || $e->store->unregisterProfile($p['dir']);
            }
            $this->line("PID {$w['pid']} と子孫のプロセスを終了しました（".count($targets).'件）');
        }
        if ($failed > 0) {
            $this->error("終了できなかったプロセスが {$failed} 件あります。手で終了してください");
        }
        $this->info("終了したプロセス：{$killed}件。次に php artisan nlrpa:recover で後始末をしてください");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Windows。記録したワーカーのうち、PIDと開始時刻が一致するものだけを、子孫ごと終了する（taskkill /T /F）。
     * 開始時刻を読めない（PowerShellが使えない）ときは、終了せず、手で終了するよう案内する（別のプロセスを終了しないため）。
     * Windowsでは、本物のChromeでの実行は未対応のため、プロファイルの回収はしない。
     */
    private function stopWindows(Engine $e): int
    {
        $killed = 0;
        $failed = 0;
        foreach ($e->store->workers() as $w) {
            $pid = (int) $w['pid'];
            if (! ProcessInfo::aliveWindows($pid)) {
                $this->line("PID {$pid}：すでに終了しています（飛ばします）");
                $e->store->unregisterWorker($pid);
                continue;
            }
            $now = ProcessInfo::startTime($pid);
            if ($now === null || $w['proc_start'] === null) {
                $failed++;
                $this->error("PID {$pid}：開始時刻を確認できないため、終了しません。タスクマネージャーで、このワーカー（php.exe）を、手で終了してください");
                continue;
            }
            if ($now !== $w['proc_start']) {
                $this->line("PID {$pid}：記録したワーカーとは別のプロセスです（飛ばします）");
                $e->store->unregisterWorker($pid);
                continue;
            }
            if (ProcessInfo::killTreeWindows($pid)) {
                $killed++;
                $e->store->unregisterWorker($pid);
                $this->line("PID {$pid} と子孫のプロセスを終了しました");
            } else {
                $failed++;
                $this->error("PID {$pid} を終了できませんでした。タスクマネージャーで、手で終了してください");
            }
        }
        if ($failed > 0) {
            $this->error("終了できなかったプロセスが {$failed} 件あります");
        }
        $this->info("終了したワーカー：{$killed}件。次に php artisan nlrpa:recover で後始末をしてください");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

