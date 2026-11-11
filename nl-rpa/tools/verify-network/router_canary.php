<?php

// 偽の「外部」サーバー。届いた要求を、すべて記録する（1行1件のJSON）。ここに届いたら、遮断に失敗している。
$log = getenv('CANARY_LOG') ?: '/tmp/canary.log';
$q = [];
parse_str($_SERVER['QUERY_STRING'] ?? '', $q);
file_put_contents($log, json_encode([
    'case' => $q['case'] ?? '(none)',
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'host' => $_SERVER['HTTP_HOST'] ?? '',
    'upgrade' => $_SERVER['HTTP_UPGRADE'] ?? '',
], JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
header('Access-Control-Allow-Origin: *');
header('Content-Type: text/html; charset=UTF-8');
echo '<html><body>CANARY</body></html>';
