<?php

// 競合の試験用：別プロセスから、同じ業務キー・別の手順を「処理中」にしようとする。
// 使い方: php race_worker.php <dbパス> <業務キー> <手順ID> <実行ID>  → 標準出力に OK か NG
require dirname(__DIR__, 2).'/vendor/autoload.php';

use Src\State\Db;
use Src\State\Ledger;
use Src\State\StepStateStore;

[, $path, $key, $step, $run] = $argv;
$db = Db::open($path);
$clock = fn (): string => gmdate('Y-m-d\TH:i:s\Z');
$store = new StepStateStore($db, new Ledger($db, $clock), $clock);
try {
    $store->markProcessing($key, $step, 'sha256:h', $run);
    usleep(20000); // 「実行中」の間を作る
    echo 'OK';
} catch (Throwable) {
    echo 'NG';
}
