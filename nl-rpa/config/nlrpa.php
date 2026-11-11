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

    // アクセストークンを間違えてよい回数（5分あたり）
    'login_max_attempts' => 10,
];
