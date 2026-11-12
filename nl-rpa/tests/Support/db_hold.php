<?php

// 試験用：データベースを、指定の秒数、排他ロックしたまま、保持する（ほかのプロセスが、ロックの間に、開こうとする試験用）
// 使い方: php db_hold.php <パス> <秒数>
$pdo = new PDO('sqlite:'.$argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA busy_timeout = 5000');
$pdo->exec('BEGIN EXCLUSIVE');
echo "LOCKED\n";
flush();
usleep((int) ((float) $argv[2] * 1000000));
$pdo->exec('COMMIT');
