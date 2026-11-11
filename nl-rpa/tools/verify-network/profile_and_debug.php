<?php

/**
 * 使い捨てプロファイルと、デバッグ接続の検証（仕様14-6「1bに入る追加条件」の一部）。
 *  1. プロファイル：既定で、一時ディレクトリが作られ、終了時に消えるか。異常終了（kill）のときは、どうか
 *  2. デバッグ接続：どのアドレスで待ち受けるか。他のWebページ（Origin）からの接続を、受け付けるか。Hostヘッダを偽っても、受け付けるか
 *  3. 起動オプションで、Originの許可を、狭められるか
 * 使い方：php tools/verify-network/profile_and_debug.php [--chrome=/path]
 */

use HeadlessChromium\BrowserFactory;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$opts = getopt('', ['chrome::']);
$chrome = $opts['chrome'] ?? (glob('/opt/pw-browsers/chromium-*/chrome-linux64/chrome')[0] ?? 'google-chrome');
$factory = new BrowserFactory($chrome);
$out = [];

/** 生のTCPで、HTTPの要求を送り、最初の行を返す */
function raw(string $host, int $port, string $req): string
{
    $s = @stream_socket_client("tcp://{$host}:{$port}", $e, $m, 3);
    if (! $s) {
        return "接続できない: {$m}";
    }
    stream_set_timeout($s, 3);
    fwrite($s, $req);
    $line = fgets($s) ?: '(応答なし)';
    fclose($s);

    return trim($line);
}

function procDirs(): array
{
    return glob(sys_get_temp_dir().'/chromium-php-*') ?: [];
}

// ---- 1. 使い捨てプロファイル ----
$before = procDirs();
$b = $factory->createBrowser(['headless' => true, 'noSandbox' => true]);
$during = array_values(array_diff(procDirs(), $before));
$b->close();
usleep(500000);
$after = array_values(array_diff(procDirs(), $before));
$out['profile'] = ['作られたディレクトリ' => count($during), '正常終了のあと、残っている' => count($after)];

// 異常終了（親のPHPが、SIGKILLで死ぬ）
$script = 'require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).'; $f = new HeadlessChromium\BrowserFactory('.var_export($chrome, true).'); $b = $f->createBrowser(["headless"=>true,"noSandbox"=>true]); echo getmypid(), "\n"; sleep(60);';
$before2 = procDirs();
$p = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
$pid = (int) trim((string) fgets($pipes[1]));
usleep(500000);
$created = array_values(array_diff(procDirs(), $before2));
posix_kill($pid, SIGKILL);
usleep(1500000);
$left = array_values(array_filter($created, 'is_dir'));
$chromeAlive = trim((string) shell_exec('pgrep -f "user-data-dir='.($created[0] ?? 'none').'" | wc -l'));
$out['profile']['PHPをSIGKILLしたとき_ディレクトリが残る'] = count($left);
$out['profile']['PHPをSIGKILLしたとき_Chromeが残る'] = (int) $chromeAlive;
foreach ($left as $d) {
    shell_exec('pkill -f '.escapeshellarg("user-data-dir={$d}").'; rm -rf '.escapeshellarg($d));
}
@proc_close($p);

// ---- 2・3. デバッグ接続 ----
foreach (['既定' => [], 'Originを狭める指定を足す' => ['customFlags' => ['--remote-allow-origins=http://127.0.0.1']]] as $label => $extra) {
    $b = $factory->createBrowser(['headless' => true, 'noSandbox' => true] + $extra);
    $uri = $b->getSocketUri(); // ws://127.0.0.1:PORT/devtools/browser/ID
    $u = parse_url($uri);
    $key = base64_encode(random_bytes(16));
    $upgrade = fn (string $origin) => "GET {$u['path']} HTTP/1.1\r\nHost: {$u['host']}:{$u['port']}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n".($origin !== '' ? "Origin: {$origin}\r\n" : '')."\r\n";
    $out['debug'][$label] = [
        '待ち受けアドレス' => $u['host'].':'.$u['port'].' ('.(str_starts_with($u['host'], '127.') ? 'ループバックのみ' : '要確認').')',
        'Originなし（ローカルの別プロセス）' => raw($u['host'], (int) $u['port'], $upgrade('')),
        'Origin=外部のWebページ' => raw($u['host'], (int) $u['port'], $upgrade('http://evil.example')),
        'HostヘッダがIPでない（DNSリバインディング）' => raw($u['host'], (int) $u['port'], "GET /json/version HTTP/1.1\r\nHost: evil.test:{$u['port']}\r\n\r\n"),
        'HostヘッダがIP（通常）' => raw($u['host'], (int) $u['port'], "GET /json/version HTTP/1.1\r\nHost: {$u['host']}:{$u['port']}\r\n\r\n"),
    ];
    $out['debug'][$label]['Chromeの起動引数（--remote-*）'] = trim((string) shell_exec("ps -eo args | grep -- '--remote-debugging-port' | grep -v grep | tr ' ' '\\n' | grep -E '^--remote' | sort -u | tr '\\n' ' '"));
    $b->close();
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
