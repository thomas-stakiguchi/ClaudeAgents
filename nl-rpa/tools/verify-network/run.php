<?php

/**
 * 通信遮断の事前検証（仕様14-6「1aの最初の作業」）。chrome-php/chrome で、次を確かめる。
 *  - 遷移の検査（SEC-5）：リダイレクト、window.open、target=_blank、iframe、meta refresh、JavaScriptによる遷移
 *  - 遷移以外の外部通信（SEC-6）：fetch/XHR、WebSocket、sendBeacon、フォームの外部送信、ダウンロード、Service Worker、画像・スクリプト
 * 「外部」は、偽のサーバー（カナリア）。ここに要求が届いたら、遮断に失敗している。
 * 遮断の方法を、3つ試す：
 *   A：エンジンが、CDPのFetchドメインで、すべての要求を検査する（A2は、新しいウィンドウを禁止するフラグも付ける）
 *   B：Chromeの名前解決を、許可した名前以外は失敗にする（--host-resolver-rules）
 *   C：Chromeの通信を、すべて、使えないプロキシへ向け、許可したものだけ迂回する（--proxy-server）
 * 使い方：php tools/verify-network/run.php [--chrome=/path/to/chrome]
 */

use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Communication\Message;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$opts = getopt('', ['chrome::', 'out::', 'only::']);
$chrome = $opts['chrome'] ?? (glob('/opt/pw-browsers/chromium-*/chrome-linux64/chrome')[0] ?? 'google-chrome');
$out = $opts['out'] ?? __DIR__.'/result.json';
$only = isset($opts['only']) ? explode(',', $opts['only']) : null;
$tmp = sys_get_temp_dir().'/nlrpa_verify_'.bin2hex(random_bytes(3));
mkdir($tmp, 0700, true);
$canaryLog = "$tmp/canary.log";
$appLog = "$tmp/app.log";
touch($canaryLog);
touch($appLog);

const APP_PORT = 18091;
const CANARY_PORT = 18092;

// ---- 偽のサーバーを起動 ----
$servers = [];
foreach ([[APP_PORT, 'router_app.php'], [CANARY_PORT, 'router_canary.php']] as [$port, $router]) {
    $servers[] = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__.'/'.$router], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p, __DIR__, array_merge(getenv(), ['CANARY_LOG' => $canaryLog, 'APP_LOG' => $appLog]));
}
register_shutdown_function(function () use ($servers, $tmp): void {
    foreach ($servers as $s) {
        @proc_terminate($s);
    }
    exec('rm -rf '.escapeshellarg($tmp));
});
usleep(800000);

$navCases = ['redirect' => '/redirect', 'window_open' => '/window_open.html', 'target_blank' => '/target_blank.html', 'iframe' => '/iframe.html', 'meta_refresh' => '/meta_refresh.html', 'js_location' => '/js_location.html'];
$netCases = ['fetch' => '/fetch.html', 'xhr' => '/xhr.html', 'websocket' => '/websocket.html', 'beacon' => '/beacon.html', 'form_post' => '/form_post.html', 'download' => '/download.html', 'image' => '/image.html', 'script' => '/script.html', 'service_worker' => '/service_worker.html'];
$allowedHost = 'localhost';
$allowedPort = APP_PORT;

/** CDP：すべての要求を検査して、許可した接続先（スキーム・ホスト・ポートの完全一致）以外は、失敗にする */
function enableFetchGuard($page, string $host, int $port, array &$violations): void
{
    $session = $page->getSession();
    $session->on('method:Fetch.requestPaused', function (array $params) use ($session, $host, $port, &$violations): void {
        $u = parse_url($params['request']['url']);
        $ok = ($u['scheme'] ?? '') === 'http' && ($u['host'] ?? '') === $host && ($u['port'] ?? 80) === $port;
        if ($ok) {
            $session->sendMessage(new Message('Fetch.continueRequest', ['requestId' => $params['requestId']]));
        } else {
            $violations[] = $params['request']['url'];
            $session->sendMessage(new Message('Fetch.failRequest', ['requestId' => $params['requestId'], 'errorReason' => 'BlockedByClient']));
        }
    });
    $session->sendMessageSync(new Message('Fetch.enable', ['patterns' => [['urlPattern' => '*']]]));
}

/** イベントを処理しながら、待つ（chrome-php は、読み取りのときに、イベントを配る） */
function pump($conn, float $sec): void
{
    $end = microtime(true) + $sec;
    while (microtime(true) < $end) {
        try {
            $conn->readData();
        } catch (\Throwable) {
        }
        usleep(20000);
    }
}

function hits(string $log, string $case): int
{
    $n = 0;
    foreach (file($log, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $j = json_decode($line, true);
        $j && ($j['case'] ?? '') === $case && $n++;
    }

    return $n;
}

$layers = [
    'A1' => ['flags' => ['--host-resolver-rules=MAP evil.test 127.0.0.1'], 'fetch' => true],
    'A2' => ['flags' => ['--host-resolver-rules=MAP evil.test 127.0.0.1', '--block-new-web-contents'], 'fetch' => true],
    'B' => ['flags' => ['--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE localhost'], 'fetch' => false],
    'C' => ['flags' => ['--host-resolver-rules=MAP evil.test 127.0.0.1', '--proxy-server=http://127.0.0.1:1', '--proxy-bypass-list=<-loopback>;localhost:'.APP_PORT], 'fetch' => false],
    'X' => ['flags' => ['--host-resolver-rules=MAP evil.test 127.0.0.1'], 'fetch' => false], // 対照（遮断なし）。攻撃が、実際に届くことの確認
];
$evils = ['name' => 'http://evil.test:'.CANARY_PORT, 'ip' => 'http://127.0.0.1:'.CANARY_PORT];
$results = [];
$factory = new BrowserFactory($chrome);

foreach ($layers as $layer => $cfg) {
    if ($only && ! in_array($layer, $only, true)) {
        continue;
    }
    foreach ($evils as $evilName => $evil) {
        $profile = "$tmp/profile_{$layer}_{$evilName}";
        $browser = $factory->createBrowser([
            'headless' => true, 'noSandbox' => true, 'userDataDir' => $profile, 'startupTimeout' => 30,
            'customFlags' => $cfg['flags'],
        ]);
        $conn = $browser->getConnection();
        try {
            foreach (['nav' => $navCases, 'net' => $netCases] + ['base' => ['allowed_nav' => '/allowed_nav.html']] as $kind => $cases) {
                foreach ($cases as $case => $path) {
                    file_put_contents($canaryLog, '');
                    file_put_contents($appLog, '');
                    $violations = [];
                    $page = $browser->createPage();
                    $cfg['fetch'] && enableFetchGuard($page, $allowedHost, $allowedPort, $violations);
                    $url = "http://{$allowedHost}:".APP_PORT.$path.(str_contains($path, '?') ? '&' : '?').'evil='.urlencode($evil);
                    $err = null;
                    try {
                        $page->navigate($url)->waitForNavigation(\HeadlessChromium\Page::LOAD, 8000);
                    } catch (\Throwable $e) {
                        $err = substr($e->getMessage(), 0, 60);
                    }
                    pump($conn, 1.5);
                    $pages = count($browser->getPages());
                    $results[] = [
                        'layer' => $layer, 'evil' => $evilName, 'kind' => $kind, 'case' => $case,
                        'canary_hits' => hits($canaryLog, $case), 'app_ok_hit' => str_contains((string) file_get_contents($appLog), '/ok'),
                        'blocked_by_engine' => count($violations), 'pages' => $pages, 'nav_error' => $err,
                    ];
                    foreach ($browser->getPages() as $pg) { // 次のケースに、持ち越さない
                        try {
                            $pg->close();
                        } catch (\Throwable) {
                        }
                    }
                }
            }
        } finally {
            $browser->close();
        }
        fwrite(STDERR, "done: layer={$layer} evil={$evilName}\n");
    }
}
file_put_contents($out, json_encode(['chrome' => $chrome, 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "結果: {$out}\n";
