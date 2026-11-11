<?php

namespace App\Console\Commands;

use App\Support\Engine;
use Illuminate\Console\Command;
use Src\State\Flags;

/** キルスイッチの解除（と、立てる）。コンソールからだけ。変更は記録する（SEC-12、SEC-13） */
class NlrpaKillSwitch extends Command
{
    protected $signature = 'nlrpa:kill-switch {state : on か off}';

    protected $description = 'キルスイッチを立てる／解除する';

    public function handle(Engine $e): int
    {
        $state = (string) $this->argument('state');
        if (! in_array($state, ['on', 'off'], true)) {
            $this->error('on か off を指定してください');

            return self::FAILURE;
        }
        $e->flags->set(Flags::KILL_SWITCH, $state === 'on', 'console ('.Engine::osUser().')');
        $this->info('キルスイッチ：'.$state);

        return self::SUCCESS;
    }
}
