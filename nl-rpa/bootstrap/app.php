<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

// ログ・セッション・キャッシュの置き場を、リポジトリの外（NL_RPA_DATA_DIR）に向ける（仕様14-4、LOC-4）
if (($dataDir = getenv('NL_RPA_DATA_DIR')) !== false && $dataDir !== '') {
    $app->useStoragePath(rtrim($dataDir, '/\\').'/storage');
}

return $app;
