<?php

// 試験用：Chromeを起動して、プロファイルの場所を出力し、待つ（親のPHPを、外から強制終了して、孤児のChromeを作るため）
// 使い方: php chrome_orphan.php <データの置き場> <Chromeのパス>
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Support\Engine;
use Src\Browser\ChromeBrowser;
use Src\Browser\ChromeLaunchPolicy;

[, $dataDir, $chrome] = $argv;
$e = new Engine($dataDir, dirname(__DIR__, 2), dirname(__DIR__, 2).'/stubs', 100);
$b = new ChromeBrowser($chrome, $e->profileRoot(), new ChromeLaunchPolicy(['http://localhost:1']), ['start' => 'http://localhost:1/x'], [], $e->guard, 'stub', 'chrome', true, $e->profileNotifier());
try {
    $b->currentPage(); // Chromeを起動する
} catch (\Throwable) {
}
echo $b->profileDir(), "\n";
flush();
sleep(120);
