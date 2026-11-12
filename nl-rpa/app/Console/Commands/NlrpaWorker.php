<?php

namespace App\Console\Commands;

use App\Support\Engine;
use Illuminate\Console\Command;
use Src\Policy\StubRegistrations;

/**
 * ワーカー（14-3の2・3）。担当者が、コンソールで起動する。実行要求の「番号だけ」を受け取り、
 * 保存先から内容を読んで、12-1の検査をすべて src/ で行ってから実行する。画面からは、起動しない。
 */
class NlrpaWorker extends Command
{
    protected $signature = 'nlrpa:worker {request : 実行要求の番号}';

    protected $description = '実行要求（ドライラン・実行）を、1件処理する';

    public function handle(Engine $e): int
    {
        $arg = (string) $this->argument('request');
        if (! preg_match('/\A[0-9]{1,12}\z/', $arg)) {
            $this->error('要求の番号は、数字で指定してください');

            return self::FAILURE;
        }
        $id = (int) $arg;
        $req = $e->store->getRequest($id);
        if ($req === null) {
            $this->error('その番号の要求はありません');

            return self::FAILURE;
        }
        if (! $e->store->claimRequest($id)) {
            $this->error('この要求は、すでに実行中か、完了しています');

            return self::FAILURE;
        }
        $pid = getmypid();
        $e->store->registerWorker($pid, $id, \App\Support\ProcessInfo::startTime($pid));
        $e->ledger->append('worker', 'request:'.$id, (object) ['event' => 'started', 'pid' => $pid, 'os_user' => Engine::osUser(), 'host' => gethostname() ?: 'unknown']);
        $result = ['status' => 'aborted', 'reasons' => ['ワーカーが異常終了しました']];
        $browser = null;
        $browserInfo = [];
        // 終了の合図（kill、Ctrl+C、端末を閉じる）を受けたら、ブラウザを閉じてから終了する（Chromeが孤児になって、実行のロックを持ち続けないように）。
        // 要求の状態などの後始末は、ここでは、しない（途中の書き込みを壊さない）。次に nlrpa:recover が、「処理中」→「要確認」、要求→異常終了にする
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $onSignal = function (int $sig) use (&$browser, $e, $pid): void {
                try {
                    $browser?->close();
                } catch (\Throwable) {
                }
                // 閉じ方が、中途半端でも、このワーカーのプロファイルと、残ったChromeは、確実に回収する
                try {
                    $mine = $e->store->profiles($pid);
                    $r = \App\Support\ProfileCleaner::clean($e->profileRoot(), array_column($mine, 'dir'));
                    foreach ($mine as $p) {
                        in_array($p['dir'], $r['skipped'], true) || $e->store->unregisterProfile($p['dir']);
                    }
                } catch (\Throwable) {
                }
                exit(128 + $sig);
            };
            foreach ([SIGTERM, SIGINT, SIGHUP] as $sig) {
                pcntl_signal($sig, $onSignal);
            }
        }
        try {
            if ($req['kind'] === 'run') {
                $run = $e->store->getRun((string) $req['run_id']) ?? throw new \RuntimeException('実行の登録がありません');
                $planHash = (string) $run['plan_hash'];
                $set = $e->store->getInputSet((int) $run['input_set_id']) ?? throw new \RuntimeException('入力データがありません');
                $rows = $set['rows'];
            } else {
                $planHash = (string) $req['plan_hash'];
                $rows = $req['input_set_id'] !== null
                    ? ($e->store->getInputSet((int) $req['input_set_id'])['rows'] ?? throw new \RuntimeException('入力データがありません'))
                    : StubRegistrations::dummyRows();
            }
            $p = $e->store->getPlan($planHash) ?? throw new \RuntimeException('計画がありません');
            if (! Engine::inputsAreCurrent($p['inputs'])) {
                throw new \RuntimeException('許可リスト・個別登録が、承認のときから変わっています。計画を作り直して、再承認してください');
            }
            $e->reclaimDeadProfiles(); // 前のワーカーが残した、Chromeとプロファイルを、先に回収する（孤児のChromeが、実行のロックを持ち続けないように）
            $browser = $e->newBrowser();
            $runner = $e->newRunner($browser);
            $report = $req['kind'] === 'run'
                ? $runner->run($p['plan'], $p['inputs'], (string) $req['run_id'], $rows)
                : $runner->dryRun($p['plan'], $p['inputs'], $rows, (string) $req['dry_run_id']);
            $result = ['status' => $report->status, 'reasons' => $report->reasons, 'rows' => $report->rows];
        } catch (\Throwable $t) {
            $result = ['status' => 'aborted', 'reasons' => ['例外: '.$t->getMessage()]];
        } finally {
            // ブラウザは、必ず閉じる（使い捨てプロファイルを、破棄する）。閉じる時点で、確かめていなかった異常も、記録に残す
            if ($browser !== null) {
                try {
                    $browser->close();
                } catch (\Throwable $t) {
                    $result['reasons'][] = 'ブラウザを閉じるときにエラー: '.$t->getMessage();
                }
                $browserInfo = ['browser' => $browser->environmentName()];
                if ($browser instanceof \Src\Browser\ChromeBrowser) {
                    $browserInfo['chrome_version'] = $browser->chromeVersion();
                    $browserInfo['unseen_at_close'] = $browser->unseenAtClose();
                }
            }
            $e->ledger->append('worker', 'request:'.$id, (object) (['event' => 'finished', 'status' => $result['status'], 'reasons' => $result['reasons'], 'pid' => $pid, 'os_user' => Engine::osUser(), 'host' => gethostname() ?: 'unknown'] + $browserInfo));
            $e->store->finishRequest($id, $result);
            $e->store->unregisterWorker($pid);
        }
        $this->line('結果：'.$result['status']);
        foreach ($result['reasons'] as $r) {
            $this->line('  '.$r);
        }

        return in_array($result['status'], ['completed', 'awaiting_first_row_confirmation'], true) ? self::SUCCESS : self::FAILURE;
    }
}
