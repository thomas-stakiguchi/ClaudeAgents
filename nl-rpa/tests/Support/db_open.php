<?php

// 試験用：データベースを開いて、OK か NG を出力する（複数のプロセスが、同時に、新しいファイルを開く試験）
require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    Src\State\Db::open($argv[1]);
    echo 'OK';
} catch (Throwable $e) {
    echo 'NG: '.get_class($e).': '.substr($e->getMessage(), 0, 120);
}
