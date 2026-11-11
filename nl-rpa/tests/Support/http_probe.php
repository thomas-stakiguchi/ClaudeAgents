<?php

// 試験用：別プロセスのPHP（php.ini の設定を変えて起動）で、要求を1つ処理して、状態コードを出力する
require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(Illuminate\Http\Request::create('http://localhost:8080/login'));
echo $response->getStatusCode();
