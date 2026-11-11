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
if (($dataDir === false || $dataDir === '') && class_exists(\Dotenv\Dotenv::class)) {
    // .env にだけ書かれている場合も、Laravelが .env を読む前に、置き場を知る必要がある（環境変数は、書き換えない）
    $vars = \Dotenv\Dotenv::createArrayBacked(dirname(__DIR__))->safeLoad();
    $dataDir = $vars['NL_RPA_DATA_DIR'] ?? false;
}
$storage = null;
$repoRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
// 「リポジトリの外か」は、「//」「..」やシンボリックリンクを整理してから判定する（Engine::checkedDataDir と同じ関数）
if (is_string($dataDir) && $dataDir !== '' && str_starts_with($dataDir, '/') && \App\Support\Engine::resolvePath($dataDir) !== null && ! \App\Support\Engine::isInside($dataDir, $repoRoot)) {
    $storage = rtrim($dataDir, '/\\').'/storage';
    foreach (['framework/sessions', 'framework/views', 'framework/cache/data', 'logs'] as $sub) {
        @mkdir($storage.'/'.$sub, 0700, true);
    }
}

if ($storage === null && PHP_SAPI === 'cli-server') {
    // 画面（HTTP）は、データの置き場が正しく決まっていなければ、起動しない（ログ・セッションを、リポジトリの中へ書かせない。LOC-4）
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'NL_RPA_DATA_DIR に、リポジトリの外の絶対パスを指定してください（.env または環境変数）';
    exit(1);
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
