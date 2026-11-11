<?php

// 検証用の「対象システム」。fixtures/ の画面を返す。
// ?evil=... で、外部（カナリア）の基点URLを、画面に渡す。
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents(getenv('APP_LOG') ?: '/tmp/app.log', $path."\n", FILE_APPEND | LOCK_EX); // 対象システムに届いた要求（基準の確認用）
$evil = $_GET['evil'] ?? 'http://evil.test:8082';
$dir = __DIR__.'/fixtures';
if ($path === '/redirect') { // サーバー側のリダイレクト
    header('Location: '.$evil.'/?case=redirect', true, 302);
    exit;
}
if ($path === '/sw.js') { // Service Worker：登録されると、外部へ fetch する
    header('Content-Type: application/javascript');
    echo "self.addEventListener('install', e => { self.skipWaiting(); fetch(".json_encode($evil.'/?case=service_worker')."); });";
    exit;
}
if ($path === '/ok') { // 許可された画面（基準）
    echo '<html><body>OK-PAGE</body></html>';
    exit;
}
$name = ltrim($path, '/') ?: 'index.html';
$file = $dir.'/'.basename($name);
if (! is_file($file)) {
    http_response_code(404);
    exit('not found');
}
$html = str_replace('__EVIL__', $evil, file_get_contents($file));
header('Content-Type: text/html; charset=UTF-8');
echo $html;
