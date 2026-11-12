<?php

// 本物のブラウザ（Chrome）で操作するための、スタブの発注画面（php -S のルーター）。偽の部品用の stubs/order_system.json と、同じ画面の構成。
// 発注の登録（副作用）は、環境変数 ORDER_LOG のファイルに、1行ずつ書く。
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$log = getenv('ORDER_LOG') ?: sys_get_temp_dir().'/nlrpa_orders.log';
header('Content-Type: text/html; charset=UTF-8');
$attacks = getenv('NLRPA_STUB_ATTACKS') === '1';
if (! $attacks && in_array($path, ['/webrtc', '/download', '/file.bin', '/blank', '/blob', '/trap', '/late'], true)) {
    http_response_code(404);
    echo 'not found';
    exit;
}
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
    // ---- 以下は、安全性の試験用の、攻撃の画面。環境変数 NLRPA_STUB_ATTACKS=1 のときだけ、開ける（nlrpa:stub-web では、無効） ----
    case '/webrtc': // WebRTC（UDP）で、?stun=host:port へ送る
        echo '<html><body>webrtc<script>var q=new URLSearchParams(location.search).get("stun");try{var pc=new RTCPeerConnection({iceServers:[{urls:"stun:"+q}]});pc.createDataChannel("x");pc.createOffer().then(function(o){return pc.setLocalDescription(o);});}catch(e){}</script></body></html>';
        break;
    case '/download': // 許可した接続先からの、ダウンロード
        echo '<html><body>download<a id="a" href="/file.bin">x</a><script>document.getElementById("a").click();</script></body></html>';
        break;
    case '/file.bin':
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="nlrpa_dl_test.bin"');
        echo 'DOWNLOADED';
        break;
    case '/blank': // 主フレームを、about:blank へ移す
        echo '<html><body>blank<script>setTimeout(function(){location.href="about:blank";},100);</script></body></html>';
        break;
    case '/blob': // 主フレームを、blob: へ移す
        echo '<html><body>blob<script>setTimeout(function(){location.href=URL.createObjectURL(new Blob(["<p>x</p>"],{type:"text/html"}));},100);</script></body></html>';
        break;
    case '/trap': // querySelector を差し替えて、呼ばれたら、フォームを送信する（読み取りを、副作用に変える罠）
        echo '<html><body><input id="item_code"><form id="f" method="post" action="/submit"><input name="item_code" value="TRAP"><input name="quantity" value="1"></form>'
            .'<script>var orig=Document.prototype.querySelector;Document.prototype.querySelector=function(){document.getElementById("f").submit();return orig.apply(this,arguments);};</script></body></html>';
        break;
    case '/late': // 少し遅れて、新しいウィンドウを開く
        echo '<html><body>late<script>setTimeout(function(){window.open("/login");},1200);</script></body></html>';
        break;
    default:
        http_response_code(404);
        echo 'not found';
}
