<?php

use App\Http\Middleware\LocalRequestGuard;
use App\Http\Middleware\RequireAccessToken;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

// ログ・セッション・キャッシュの置き場を、リポジトリの外（NL_RPA_DATA_DIR）に向ける（仕様14-4、LOC-4）。
// Laravelの既定の置き場（リポジトリの中の storage）には、何も書かせない。
$dataDir = getenv('NL_RPA_DATA_DIR');
$storage = null;
if ($dataDir !== false && $dataDir !== '' && str_starts_with($dataDir, '/')) {
    $storage = rtrim($dataDir, '/\\').'/storage';
    foreach (['framework/sessions', 'framework/views', 'framework/cache/data', 'logs'] as $sub) {
        @mkdir($storage.'/'.$sub, 0700, true);
    }
}

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Hostヘッダ・Originの検査と、セキュリティヘッダは、すべての要求にかける（LOC-2）
        $middleware->prepend(LocalRequestGuard::class);
        $middleware->prepend(SecurityHeaders::class);
        $middleware->alias(['nlrpa.token' => RequireAccessToken::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->expectsJson(),
        );
    })->create();

$storage !== null && $app->useStoragePath($storage);

return $app;
