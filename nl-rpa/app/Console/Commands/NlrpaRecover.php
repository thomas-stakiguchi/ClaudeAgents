<?php

namespace App\Console\Commands;

use App\Support\Engine;
use App\Support\ProcessInfo;
use Illuminate\Console\Command;

/** 異常終了のあとの後始末（14-3の5）。ロックを取ってから、「処理中」を「要確認」に、「実行中」の要求を「異常終了」にする */
class NlrpaRecover extends Command
{
    protected $signature = 'nlrpa:recover';

    protected $description = '異常終了のあとの後始末（処理中→要確認、実行中の要求→異常終了）';

    public function handle(Engine $e): int
    {
        $lock = $e->runLock();
        if (! $lock->acquire()) {
            $this->error('別の実行が動いています。終わってから、実行してください');

            return self::FAILURE;
        }
        try {
            $n = $e->states->recoverAfterCrash();
            $m = $e->store->failStaleRunning();
            // 記録したワーカーのうち、PIDと開始時刻が一致して、今も動いているもの（実行の合間に、ブラウザを持ったまま待っているなど）は、触らない
            $live = [];
            foreach ($e->store->workers() as $w) {
                if ($w['proc_start'] !== null && ProcessInfo::startTime($w['pid']) === $w['proc_start'] && ProcessInfo::isAlive($w['pid'])) {
                    $live[$w['pid']] = true;
                    $this->warn("PID {$w['pid']} のワーカーは、動いています。触りません");
                } else {
                    $e->store->unregisterWorker($w['pid']);
                }
            }
            // 残った使い捨てプロファイルと、孤児のChromeを回収する（動いているワーカーのものは除く）
            $profiles = array_values(array_filter($e->store->profiles(), static function (array $p) use ($live): bool {
                $alive = isset($live[$p['worker_pid']]) || ($p['proc_start'] !== null && ProcessInfo::startTime($p['worker_pid']) === $p['proc_start'] && ProcessInfo::isAlive($p['worker_pid']));

                return ! $alive;
            }));
            $r = \App\Support\ProfileCleaner::clean($e->profileRoot(), array_column($profiles, 'dir'));
            foreach ($profiles as $p) {
                in_array($p['dir'], $r['skipped'], true) || $e->store->unregisterProfile($p['dir']);
            }
            $this->info("「処理中」を「要確認」にした件数：{$n}／異常終了にした要求：{$m}／回収したChromeのプロセス：{$r['processes']}／プロファイル：{$r['dirs']}");
            foreach ($r['skipped'] as $s) {
                $this->warn("置き場の外・形が違うため、触りませんでした: {$s}");
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
