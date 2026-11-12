<?php

return [
    // データの置き場（リポジトリの外。絶対パス）。ログ、記録、入力データ、アクセストークンのハッシュが入る
    'data_dir' => env('NL_RPA_DATA_DIR'),

    // 待ち受けのポート。Hostヘッダ・Originの検査にも使う（仕様LOC-1、LOC-2）
    'port' => (int) env('NL_RPA_PORT', 8080),

    // スタブ（偽の画面と固定の計画）の置き場。1aは、ここだけが接続先
    'stub_dir' => base_path('stubs'),

    // 組織全体の件数の最大値（SEC-14）。ルール管理者が決める値（1aは仮の値）
    'org_max_rows' => 10000,

    // 要求を処理するPHPの設定（session.upload_progress.enabled=0、display_errors off）を、毎回確かめる（LOC-7）。試験のときだけ false
    'enforce_php_ini' => filter_var(env('NL_RPA_ENFORCE_PHP_INI', true), FILTER_VALIDATE_BOOLEAN),

    // ブラウザ部品：'fake'（Chromeを使わない偽の部品。stubs/order_system.json）か、'chrome'（本物のChrome。stubs/web/ のスタブ画面だけに接続）
    // 切り替えると、環境の名前が変わり、もう一方の環境のドライランは、無効になる（9-2）
    'browser' => env('NL_RPA_BROWSER', 'fake'),
    // Chromeの実行ファイル（未指定なら、自動検出）。実行PCに合わせて決める（11章）
    'chrome_binary' => env('NL_RPA_CHROME'),
    // サンドボックスの無効化。rootで動かす環境（コンテナなど）のためだけ。接続先の制限とは無関係
    'chrome_no_sandbox' => filter_var(env('NL_RPA_CHROME_NO_SANDBOX', false), FILTER_VALIDATE_BOOLEAN),
    // 許可するChromeのメジャーバージョン（カンマ区切り。未指定なら、確認しない）。1bの前に、docs/TECH_STACK.md の範囲に決める
    'chrome_allowed_major' => env('NL_RPA_CHROME_MAJOR'),
    // スタブの画面（Web版）のポート。アプリ自身のポートとは、別でなければならない（14-4：アプリ自身は、接続先許可リストに入れない）
    'stub_port' => (int) env('NL_RPA_STUB_PORT', 8081),

    // アクセストークンを間違えてよい回数（5分あたり）
    'login_max_attempts' => 10,
];
