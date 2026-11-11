<?php

// 本物のブラウザ（Chrome）で操作するための、スタブの発注画面（php -S のルーター）。偽の部品用の stubs/order_system.json と、同じ画面の構成。
// 発注の登録（副作用）は、環境変数 ORDER_LOG のファイルに、1行ずつ書く。
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$log = getenv('ORDER_LOG') ?: sys_get_temp_dir().'/nlrpa_orders.log';
header('Content-Type: text/html; charset=UTF-8');
switch ($path) {
    case '/login':
        echo '<html><body><h1>ログイン</h1></body></html>';
        break;
    case '/order':
        echo '<html><body><h1>発注入力</h1><form method="post" action="/submit">'
            .'<input id="item_code" name="item_code" type="text"><input id="quantity" name="quantity" type="text">'
            .'<button id="submit_button" type="submit">登録</button></form></body></html>';
        break;
    case '/submit':
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            exit;
        }
        $n = count(file($log) ?: []) + 1;
        file_put_contents($log, ($_POST['item_code'] ?? '').'|'.($_POST['quantity'] ?? '')."\n", FILE_APPEND | LOCK_EX);
        header('Location: /done?n='.sprintf('ORD-%04d', $n), true, 303);
        break;
    case '/done':
        $n = preg_match('/\AORD-[0-9]{4}\z/', $_GET['n'] ?? '') ? $_GET['n'] : '';
        echo '<html><body><p>発注番号</p><span id="order_number">'.htmlspecialchars($n).'</span></body></html>';
        break;
    default:
        http_response_code(404);
        echo 'not found';
}
