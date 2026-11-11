<?php

namespace App\Console\Commands;

use App\Support\AccessToken;
use App\Support\Engine;
use Illuminate\Console\Command;

/**
 * 専用の起動コマンド（LOC-1、LOC-3、LOC-7）。127.0.0.1だけで待ち受け、起動ごとにアクセストークンを発行して、コンソールに表示する。
 * PHPの設定（session.upload_progress.enabled=0、display_errors off）を、起動前に確かめる。
 */
class NlrpaServe extends Command
{
    protected $signature = 'nlrpa:serve';

    protected $description = 'ローカルのテスト用アプリを、127.0.0.1だけで起動する（アクセストークンを発行して表示）';

    public function handle(Engine $engine, AccessToken $tokens): int
    {
        $problems = [];
        if (filter_var(ini_get('session.upload_progress.enabled'), FILTER_VALIDATE_BOOLEAN)) {
            $problems[] = 'php.ini で session.upload_progress.enabled=0 にしてください（LOC-7）。確認：php -i';
        }
        if (filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN) && ! in_array(strtolower((string) ini_get('display_errors')), ['stderr'], true)) {
            $problems[] = 'php.ini で display_errors=Off にしてください（LOC-7）';
        }
        if (config('app.debug')) {
            $problems[] = '.env で APP_DEBUG=false にしてください';
        }
        if ($problems !== []) {
            foreach ($problems as $p) {
                $this->error($p);
            }

            return self::FAILURE;
        }
        $port = (int) config('nlrpa.port');
        $token = $tokens->issue();
        $this->line('');
        $this->info("http://localhost:{$port} を開いて、次のアクセストークンを入力してください（再起動すると変わります）：");
        $this->line($token);
        $this->line('');
        $this->line('データの置き場：'.$engine->dataDir);

        return $this->call('serve', ['--host' => '127.0.0.1', '--port' => (string) $port]);
    }
}
