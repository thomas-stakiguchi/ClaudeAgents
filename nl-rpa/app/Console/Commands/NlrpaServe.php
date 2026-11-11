<?php

namespace App\Console\Commands;

use App\Support\AccessToken;
use App\Support\Engine;
use Illuminate\Console\Command;

/**
 * 専用の起動コマンド（LOC-1、LOC-3、LOC-7）。127.0.0.1だけで待ち受け、起動ごとにアクセストークンを発行して、コンソールに表示する。
 *
 * Laravelの `artisan serve` は、子のプロセスに、php の設定（-d）や、環境変数（データの置き場など）を引き継がない。
 * そのため、`php -S` を、このコマンドが直接起動し、必要な設定（session.upload_progress.enabled=0、display_errors=0、expose_php=0）を
 * -d で渡す。シェルは使わない（引数の配列で起動する）。要求を処理するPHPの設定は、ミドルウェアが、毎回確かめる。
 * `php -S` は、開発用のサーバー。ローカルのテスト用途に限る（LOC-9）。
 */
class NlrpaServe extends Command
{
    protected $signature = 'nlrpa:serve';

    protected $description = 'ローカルのテスト用アプリを、127.0.0.1だけで起動する（アクセストークンを発行して表示）';

    /** @return list<string> 起動するコマンド（引数の配列）。待ち受けは、127.0.0.1だけ */
    public static function serverCommand(int $port): array
    {
        return [
            PHP_BINARY,
            '-d', 'session.upload_progress.enabled=0',
            '-d', 'display_errors=0',
            '-d', 'expose_php=0',
            '-S', '127.0.0.1:'.$port,
            '-t', public_path(),
            base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'),
        ];
    }

    public function handle(Engine $engine, AccessToken $tokens): int
    {
        if (config('app.debug')) {
            $this->error('.env で APP_DEBUG=false にしてください');

            return self::FAILURE;
        }
        $port = (int) config('nlrpa.port');
        if ($port < 1 || $port > 65535) {
            $this->error('NL_RPA_PORT は、1〜65535 の整数にしてください。トークンは発行しませんでした');

            return self::FAILURE;
        }
        // 待ち受けられないのに、トークンを発行して、今動いている画面のセッションを無効にしない
        $probe = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
        if ($probe === false) {
            $this->error("ポート {$port} は、使用中です（{$errstr}）。トークンは発行しませんでした");

            return self::FAILURE;
        }
        fclose($probe);
        $token = $tokens->issue();
        $this->line('');
        $this->info("http://localhost:{$port} を開いて、次のアクセストークンを入力してください（再起動すると変わります）：");
        $this->line($token);
        $this->line('');
        $this->line('データの置き場：'.$engine->dataDir);
        $this->line('止めるときは、Ctrl+C を押してください。');

        // 子のプロセスには、データの置き場と、デバッグ無効を、環境変数で渡す
        $env = array_merge(getenv(), ['NL_RPA_DATA_DIR' => $engine->dataDir, 'APP_DEBUG' => 'false', 'NL_RPA_PORT' => (string) $port]);
        $proc = proc_open(self::serverCommand($port), [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, public_path(), $env);
        if (! is_resource($proc)) {
            $this->error('サーバーを起動できませんでした');

            return self::FAILURE;
        }

        // 親だけを止められても、子のサーバーが残らないよう、終了の合図を子に転送する
        $stop = static function () use ($proc): void {
            @proc_terminate($proc);
        };
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGINT, SIGTERM, SIGHUP] as $sig) {
                pcntl_signal($sig, $stop);
            }
        }
        $code = null;
        while (true) {
            $status = proc_get_status($proc);
            if (! $status['running']) {
                $code = $status['exitcode'];
                break;
            }
            usleep(200000);
        }
        proc_close($proc);

        // 合図で止めたときは、正常な終了として扱う（143=SIGTERM、130=SIGINT）
        return in_array($code, [0, 130, 143, -1], true) ? self::SUCCESS : self::FAILURE;
    }
}
