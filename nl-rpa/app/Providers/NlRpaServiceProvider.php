<?php

namespace App\Providers;

use App\Support\AccessToken;
use App\Support\Engine;
use Illuminate\Support\ServiceProvider;

class NlRpaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 実際に使われるときに作る（設定は、そのときに読む）
        $this->app->singleton(Engine::class, fn () => new Engine(
            config('nlrpa.data_dir'),
            base_path(),
            config('nlrpa.stub_dir'),
            (int) config('nlrpa.org_max_rows'),
            [
                'mode' => config('nlrpa.browser'),
                'binary' => config('nlrpa.chrome_binary'),
                'no_sandbox' => (bool) config('nlrpa.chrome_no_sandbox'),
                'allowed_major' => config('nlrpa.chrome_allowed_major'),
                'stub_port' => (int) config('nlrpa.stub_port'),
                'app_port' => (int) config('nlrpa.port'),
            ],
        ));
        $this->app->singleton(AccessToken::class, fn ($app) => new AccessToken($app->make(Engine::class)->dataDir));
    }
}
