<?php

namespace App\Console\Commands;

use App\Support\Engine;
use Illuminate\Console\Command;

/**
 * スタブの発注画面（Web版。stubs/web/router.php）を、127.0.0.1の別ポートで起動する（仕様14-4：スタブは、アプリとは別のポート）。
 * 本物のChrome（NL_RPA_BROWSER=chrome）が操作する相手。発注の登録（副作用）は、データの置き場の stub-orders.log に、1行ずつ記録する。
 * アプリ自身（NL_RPA_PORT）とは、別のポートでなければならない。シェルは使わない（引数の配列で起動する）。
 */
class NlrpaStubWeb extends Command
{
    protected $signature = 'nlrpa:stub-web';

    protected $description = 'スタブの発注画面（Web版）を、127.0.0.1の別ポートで起動する（本物のChrome用）';

    public function handle(Engine $engine): int
    {
        $port = (int) config('nlrpa.stub_port');
        if ($port < 1 || $port > 65535 || $port === (int) config('nlrpa.port')) {
            $this->error('NL_RPA_STUB_PORT は、1〜65535 で、アプリ自身のポートとは別にしてください');

            return self::FAILURE;
        }
        $probe = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
        if ($probe === false) {
            $this->error("ポート {$port} は、使用中です（{$errstr}）");

            return self::FAILURE;
        }
        fclose($probe);
        $this->info("スタブの発注画面を、http://localhost:{$port} で起動します。止めるときは、Ctrl+C を押してください。");
        $env = array_merge(getenv(), ['ORDER_LOG' => $engine->stubOrdersLog()]);
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'expose_php=0', '-S', '127.0.0.1:'.$port, base_path('stubs/web/router.php')],
            [0 => STDIN, 1 => STDOUT, 2 => STDERR],
            $pipes,
            base_path('stubs/web'),
            $env,
        );
        if (! is_resource($proc)) {
            $this->error('サーバーを起動できませんでした');

            return self::FAILURE;
        }
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

        return in_array($code, [0, 130, 143, -1], true) ? self::SUCCESS : self::FAILURE;
    }
}
