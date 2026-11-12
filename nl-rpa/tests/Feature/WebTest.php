<?php

namespace Tests\Feature;

use App\Support\AccessToken;
use App\Support\Engine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class WebTest extends TestCase
{
    private string $dir;
    private string $base = 'http://localhost:8080';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/nlrpa_web_'.bin2hex(random_bytes(4));
        config(['nlrpa.data_dir' => $this->dir, 'nlrpa.port' => 8080]);
    }

    protected function tearDown(): void
    {
        $this->stopStub();
        $this->rm($this->dir);
        parent::tearDown();
    }

    private function rm(string $d): void
    {
        if (! is_dir($d)) {
            return;
        }
        foreach (scandir($d) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            is_dir("$d/$f") ? $this->rm("$d/$f") : @unlink("$d/$f");
        }
        @rmdir($d);
    }

    private function origin(): array
    {
        return ['Origin' => $this->base];
    }

    /** ログインして、以降の要求を認証済みにする */
    private function login(): string
    {
        $token = $this->app->make(AccessToken::class)->issue();
        $this->post($this->base.'/login', ['token' => $token], $this->origin())->assertRedirect('/');

        return $token;
    }

    /** @return array<string,mixed> */
    private function post2(string $path, array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->post($this->base.$path, $data + ['name' => '佐藤'], $this->origin());
    }

    private function engine(): Engine
    {
        return $this->app->make(Engine::class);
    }

    private const SCENARIO = "発注管理システムにログインする。\n入力データの各行について、発注画面を開き、商品コード・数量・納品日を入力する。\n商品コード・数量・納品日を入力する。\n登録ボタンを押して、表示された発注番号を結果に記録する。";

    private function createPlan(string $scenario = self::SCENARIO): string
    {
        $r = $this->post2('/plans', ['scenario' => $scenario]);
        $loc = $r->headers->get('Location');
        $this->assertMatchesRegularExpression('#/plans/(sha256:[0-9a-f]{64})$#', (string) $loc, '計画を作れなかった');
        preg_match('#/plans/(sha256:[0-9a-f]{64})$#', (string) $loc, $m);

        return $m[1];
    }

    private function worker(int $id): string
    {
        Artisan::call('nlrpa:worker', ['request' => (string) $id]);

        return Artisan::output();
    }

    private function lastRequestId(): int
    {
        return (int) $this->engine()->db->pdo->query('SELECT max(id) FROM requests')->fetchColumn();
    }

    private function csv(string $content = "商品コード,数量,伝票番号\nABC,1200,K-001\nDEF,30,K-002\nGHI,5,K-003\n"): int
    {
        $this->post($this->base.'/inputs', ['name' => '佐藤', 'csv' => UploadedFile::fake()->createWithContent('in.csv', $content)], $this->origin())->assertSessionHasNoErrors();

        return (int) $this->engine()->db->pdo->query('SELECT max(id) FROM input_sets')->fetchColumn();
    }

    // ---- 待ち受け・Host・Origin・トークン・ヘッダ ----

    public function test_許可されていないHostは拒否する(): void
    {
        foreach (['http://evil.example:8080/login', 'http://localhost:9999/login', 'http://localhost/login', 'http://127.0.0.2:8080/login'] as $url) {
            $this->get($url)->assertStatus(403);
        }
        foreach (['http://localhost:8080/login', 'http://127.0.0.1:8080/login', 'http://[::1]:8080/login'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_Originが無いか一致しないPOSTは拒否する(): void
    {
        $this->post($this->base.'/login', ['token' => 'x'])->assertStatus(403); // Originなし
        foreach (['http://localhost:9999', 'http://evil.example', 'https://localhost:8080', 'null', 'http://localhost:8080.evil.example'] as $o) {
            $this->post($this->base.'/login', ['token' => 'x'], ['Origin' => $o])->assertStatus(403);
        }
        $this->post($this->base.'/login', ['token' => 'x'], $this->origin())->assertStatus(302); // 一致すれば、中身の検査へ進む
    }

    public function test_POST以外の状態を変える方法は拒否する(): void
    {
        foreach (['put', 'delete', 'patch'] as $m) {
            $this->{$m}($this->base.'/stop', [], $this->origin())->assertStatus(405);
        }
    }

    public function test_トークンなしでは、どの画面も見られない(): void
    {
        $this->get($this->base.'/')->assertRedirect('/login');
        $this->get($this->base.'/plans/sha256:'.str_repeat('0', 64))->assertRedirect('/login');
        $this->post($this->base.'/plans', ['scenario' => 'x', 'name' => 'a'], $this->origin())->assertRedirect('/login');
        $this->post($this->base.'/stop', ['name' => 'a'], $this->origin())->assertRedirect('/login');
    }

    public function test_トークンが違えば入れず_正しければ入れる_再起動で無効になる(): void
    {
        $tokens = $this->app->make(AccessToken::class);
        $good = $tokens->issue();
        $this->post($this->base.'/login', ['token' => 'wrong'], $this->origin())->assertSessionHasErrors('token');
        $this->get($this->base.'/')->assertRedirect('/login');
        $this->post($this->base.'/login', ['token' => $good], $this->origin())->assertRedirect('/');
        $this->get($this->base.'/')->assertOk();
        $tokens->issue(); // 再起動（新しいトークンを発行）
        $this->get($this->base.'/')->assertRedirect('/login');
    }

    public function test_トークンの保存はハッシュだけ(): void
    {
        $token = $this->app->make(AccessToken::class)->issue();
        $file = file_get_contents($this->dir.'/access_token.sha256');
        $this->assertSame(hash('sha256', $token), $file);
        $this->assertStringNotContainsString($token, $file);
    }

    public function test_ログインに失敗が続くと制限される(): void
    {
        $this->app->make(AccessToken::class)->issue();
        for ($i = 0; $i < 10; $i++) {
            $this->post($this->base.'/login', ['token' => "bad{$i}"], $this->origin());
        }
        $r = $this->post($this->base.'/login', ['token' => 'bad'], $this->origin());
        $r->assertSessionHasErrors('token');
        $this->assertStringContainsString('しばらく', session('errors')->first('token'));
    }

    public function test_セキュリティヘッダが付く(): void
    {
        foreach ([$this->get($this->base.'/login'), $this->get('http://evil.example:8080/login')] as $r) {
            $this->assertStringContainsString("frame-ancestors 'none'", $r->headers->get('Content-Security-Policy'));
            $this->assertStringContainsString("default-src 'none'", $r->headers->get('Content-Security-Policy'));
            $this->assertSame('DENY', $r->headers->get('X-Frame-Options'));
            $this->assertNull($r->headers->get('Access-Control-Allow-Origin'));
            $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        }
    }

    public function test_CSRFの保護はWebの経路に入っている(): void
    {
        $groups = $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->getMiddlewareGroups();
        $this->assertNotEmpty(array_filter($groups['web'] ?? [], fn ($m) => is_string($m) && (str_contains($m, 'PreventRequestForgery') || str_contains($m, 'ValidateCsrfToken') || str_contains($m, 'VerifyCsrfToken'))), 'webの中に、CSRFの保護がない');
        $this->assertNotEmpty(array_filter($groups['web'] ?? [], fn ($m) => is_string($m) && str_contains($m, 'StartSession')));
        // 状態を変えるすべての経路が、webグループ（＝CSRFの保護つき）に入っている
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if (in_array('POST', $route->methods(), true)) {
                $this->assertContains('web', $route->gatherMiddleware(), $route->uri());
            }
        }
    }

    public function test_セッションのCookieはHttpOnlyでSameSiteはStrict(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('strict', config('session.same_site'));
    }

    // ---- データの置き場 ----

    public function test_データの置き場はリポジトリの外の絶対パスだけ(): void
    {
        $repo = base_path();
        foreach ([null, '', 'relative/dir', $repo, $repo.'/storage/x'] as $bad) {
            try {
                Engine::checkedDataDir($bad, $repo);
                $this->fail('拒否されるはず: '.var_export($bad, true));
            } catch (\RuntimeException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(realpath($this->dir) ?: $this->dir, Engine::checkedDataDir($this->dir, $repo));
    }

    // ---- 通しの流れ ----

    public function test_画面からの通しの流れ_計画から本番実行まで(): void
    {
        $this->login();
        $hash = $this->createPlan();
        $this->get($this->base."/plans/{$hash}")->assertOk()->assertSee('取り消せない操作')->assertSee('S-005');

        // 計画の承認 → ドライランの要求 → ワーカー（コンソール）で実行
        $this->post2("/plans/{$hash}/approve-plan")->assertSessionHasNoErrors();
        $this->post2("/plans/{$hash}/dry-run")->assertSessionHasNoErrors();
        $dryReq = $this->lastRequestId();
        $this->assertStringContainsString('completed', $this->worker($dryReq));
        $this->assertFalse($this->worker($dryReq) === '', '同じ要求は、2回実行されない');

        // 最終承認 → プレビュー → 件数の入力 → 登録
        $dryRunId = $this->engine()->store->getRequest($dryReq)['dry_run_id'];
        $this->post2("/plans/{$hash}/approve-final", ['dry_run_id' => $dryRunId])->assertSessionHasNoErrors();
        $input = $this->csv();
        $this->get($this->base."/plans/{$hash}/preview?input_set_id={$input}&dry_run_id={$dryRunId}")->assertOk()->assertSee('件数：3')->assertSee('数量の合計：1235');
        $this->post2("/plans/{$hash}/register", ['input_set_id' => $input, 'dry_run_id' => $dryRunId, 'count' => '2'])->assertSessionHasErrors('count');
        $r = $this->post2("/plans/{$hash}/register", ['input_set_id' => $input, 'dry_run_id' => $dryRunId, 'count' => '3']);
        preg_match('#/runs/(RUN-[0-9]{8}-[0-9]{6}-[0-9a-f]{6})$#', (string) $r->headers->get('Location'), $m);
        $runId = $m[1] ?? $this->fail('登録できなかった');

        // 実行：初回の1件で止まる → 確認 → 残りを実行
        $this->post2("/runs/{$runId}/start")->assertSessionHasNoErrors();
        $this->post2("/runs/{$runId}/start")->assertSessionHasErrors('start'); // 待ちの要求がある間は、作れない
        $out = $this->worker($this->lastRequestId());
        $this->assertStringContainsString('awaiting_first_row_confirmation', $out);
        $this->get($this->base."/runs/{$runId}")->assertOk()->assertSee('成功');
        $this->assertSame('succeeded', $this->engine()->states->get('K-001', 'S-005'));
        $this->assertNull($this->engine()->states->get('K-002', 'S-005'));

        $this->post2("/runs/{$runId}/confirm-first")->assertSessionHasNoErrors();
        $this->post2("/runs/{$runId}/start")->assertSessionHasNoErrors();
        $this->assertStringContainsString('completed', $this->worker($this->lastRequestId()));
        foreach (['K-001', 'K-002', 'K-003'] as $k) {
            $this->assertSame('succeeded', $this->engine()->states->get($k, 'S-005'));
        }
        $this->assertNull($this->engine()->ledger->verify());
    }

    public function test_AIが補った取り消せない手順は1つずつ確認しないと承認できない(): void
    {
        $this->login();
        $hash = $this->createPlan('発注管理システムにログインする。'); // 登録ボタンの文が、シナリオにない
        $this->get($this->base."/plans/{$hash}")->assertSee('AIが補った');
        $this->post2("/plans/{$hash}/approve-plan")->assertSessionHasErrors('ack');
        $this->assertFalse($this->engine()->approvals->hasPlanApproval($hash));
        $this->post2("/plans/{$hash}/approve-plan", ['ack' => ['S-005']])->assertSessionHasNoErrors();
        $this->assertTrue($this->engine()->approvals->hasPlanApproval($hash));
        // 確認した手順が、承認の記録に残る
        $this->assertContains('S-005', $this->engine()->ledger->latest('approval', $hash)->acked_steps);
    }

    public function test_承認の前にドライランや最終承認や登録はできない(): void
    {
        $this->login();
        $hash = $this->createPlan();
        $this->post2("/plans/{$hash}/dry-run")->assertSessionHasErrors('dry');
        $this->post2("/plans/{$hash}/approve-final", ['dry_run_id' => 'DR-x'])->assertSessionHasErrors('final');
        $input = $this->csv();
        $this->post2("/plans/{$hash}/register", ['input_set_id' => $input, 'dry_run_id' => 'DR-x', 'count' => '3'])->assertSessionHasErrors('register');
        $this->assertSame([], $this->engine()->store->runsOfPlan($hash));
    }

    public function test_要確認は画面から判定して解消できる(): void
    {
        $this->login();
        [$hash, $runId] = $this->preparedRun();
        // 1件目の取り消せない操作の直前に処理中を書いたまま、異常終了した状態
        $this->engine()->states->markProcessing('K-001', 'S-005', $hash, $runId);
        Artisan::call('nlrpa:recover');
        $this->assertSame('needs_review', $this->engine()->states->get('K-001', 'S-005'));
        $this->get($this->base."/runs/{$runId}")->assertSee('要確認');
        $this->post2("/runs/{$runId}/resolve", ['business_key' => 'K-001', 'step_id' => 'S-005', 'verdict' => 'executed'])->assertSessionHasNoErrors();
        $this->assertSame('succeeded', $this->engine()->states->get('K-001', 'S-005'));
        // 成功は、判定できない。計画にない手順・行も、判定できない
        $this->post2("/runs/{$runId}/resolve", ['business_key' => 'K-001', 'step_id' => 'S-005', 'verdict' => 'not_executed'])->assertSessionHasErrors('resolve');
        $this->post2("/runs/{$runId}/resolve", ['business_key' => 'K-999', 'step_id' => 'S-005', 'verdict' => 'executed'])->assertSessionHasErrors('resolve');
    }

    /** @return array{0:string,1:string} 計画ハッシュ、実行ID */
    private function preparedRun(): array
    {
        $hash = $this->createPlan();
        $this->post2("/plans/{$hash}/approve-plan");
        $this->post2("/plans/{$hash}/dry-run");
        $req = $this->lastRequestId();
        $this->worker($req);
        $dryRunId = $this->engine()->store->getRequest($req)['dry_run_id'];
        $this->post2("/plans/{$hash}/approve-final", ['dry_run_id' => $dryRunId]);
        $input = $this->csv();
        $r = $this->post2("/plans/{$hash}/register", ['input_set_id' => $input, 'dry_run_id' => $dryRunId, 'count' => '3']);
        preg_match('#/runs/(RUN-[0-9]{8}-[0-9]{6}-[0-9a-f]{6})$#', (string) $r->headers->get('Location'), $m);

        return [$hash, $m[1] ?? $this->fail('登録できなかった')];
    }

    public function test_初回の確認は1件目が成功するまでできない(): void
    {
        $this->login();
        [, $runId] = $this->preparedRun();
        $this->post2("/runs/{$runId}/confirm-first")->assertSessionHasErrors('confirm');
        $this->assertNull($this->engine()->ledger->latest('first_row_confirmed', $runId));
    }

    public function test_停止の旗を画面から立てると実行されない(): void
    {
        $this->login();
        [, $runId] = $this->preparedRun();
        $this->post2('/stop')->assertSessionHasNoErrors();
        $this->post2("/runs/{$runId}/start");
        $out = $this->worker($this->lastRequestId());
        $this->assertStringContainsString('halted', $out);
        $this->assertNull($this->engine()->states->get('K-001', 'S-005'));
        $this->post2('/stop/clear')->assertSessionHasNoErrors();
    }

    // ---- 入力・表示 ----

    public function test_名前の入力は検査され_表示はエスケープされる(): void
    {
        $this->login();
        $this->post($this->base.'/stop', ['name' => "a\nb"], $this->origin())->assertSessionHasErrors('name');
        $this->post($this->base.'/stop', ['name' => ''], $this->origin())->assertSessionHasErrors('name');
        $this->post($this->base.'/stop', ['name' => str_repeat('あ', 51)], $this->origin())->assertSessionHasErrors('name');
        $r = $this->post($this->base.'/plans', ['name' => '<script>alert(1)</script>', 'scenario' => self::SCENARIO], $this->origin());
        $r->assertRedirect();
        $page = $this->get($this->base.'/')->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $page);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page);
    }

    public function test_CSVの不正は取り込まない(): void
    {
        $this->login();
        $bad = [
            '空' => '',
            '列名が重複' => "a,a\n1,2\n",
            '列数が違う' => "a,b\n1\n",
            '行がない' => "a,b\n",
            'UTF-8でない' => "a,b\n\xff,2\n",
        ];
        foreach ($bad as $label => $content) {
            $this->post($this->base.'/inputs', ['name' => '佐藤', 'csv' => UploadedFile::fake()->createWithContent('in.csv', $content)], $this->origin());
            $this->assertSame([], $this->engine()->store->listInputSets(), $label.' が取り込まれた');
        }
        $this->assertSame([], $this->engine()->store->listInputSets());
    }

    public function test_取り込んだ入力データが書き換えられたら使えない(): void
    {
        $this->login();
        $id = $this->csv();
        $this->engine()->db->pdo->exec("UPDATE input_sets SET rows_json = replace(rows_json, 'ABC', 'EVIL') WHERE id = {$id}");
        $this->expectException(\RuntimeException::class);
        $this->engine()->store->getInputSet($id);
    }

    public function test_保存された計画が書き換えられたら使えない(): void
    {
        $this->login();
        $hash = $this->createPlan();
        $this->engine()->db->pdo->exec("UPDATE plans SET plan_json = replace(plan_json, 'submit_button', 'cancel_button') WHERE hash = '{$hash}'");
        $this->expectException(\RuntimeException::class);
        $this->engine()->store->getPlan($hash);
    }

    // ---- コンソール ----

    public function test_ワーカーは数字以外の指定や存在しない要求や実行済みの要求を受け付けない(): void
    {
        $this->engine();
        foreach (['abc', '1; ls', '-1', ''] as $bad) {
            $this->assertSame(1, Artisan::call('nlrpa:worker', ['request' => $bad]));
        }
        $this->assertSame(1, Artisan::call('nlrpa:worker', ['request' => '999']));
    }

    public function test_緊急停止はキルスイッチを立て_解除はコンソールだけ(): void
    {
        $this->login();
        $this->engine();
        $this->assertSame(0, Artisan::call('nlrpa:emergency-stop'));
        $this->assertTrue($this->engine()->flags->isSet(\Src\State\Flags::KILL_SWITCH));
        $this->get($this->base.'/')->assertSee('キルスイッチが発動しています');
        $this->assertSame(0, Artisan::call('nlrpa:kill-switch', ['state' => 'off']));
        $this->assertFalse($this->engine()->flags->isSet(\Src\State\Flags::KILL_SWITCH));
        $this->assertSame(1, Artisan::call('nlrpa:kill-switch', ['state' => 'maybe']));
    }

    public function test_serveはデバッグが有効なら起動しない(): void
    {
        $this->engine();
        config(['app.debug' => true]);
        $this->assertSame(1, Artisan::call('nlrpa:serve'));
        $this->assertStringContainsString('APP_DEBUG=false', Artisan::output());
        $this->assertNull($this->app->make(AccessToken::class)->currentHash(), '起動しないので、トークンは発行されない');
    }

    public function test_serveの起動コマンドは127_0_0_1だけで待ち受け_安全なPHP設定を渡す(): void
    {
        $cmd = \App\Console\Commands\NlrpaServe::serverCommand(8080);
        $this->assertContains('127.0.0.1:8080', $cmd);
        $joined = implode(' ', $cmd);
        $this->assertStringNotContainsString('0.0.0.0', $joined);
        $this->assertStringNotContainsString('[::]', $joined);
        foreach (['session.upload_progress.enabled=0', 'display_errors=0', 'expose_php=0'] as $d) {
            $this->assertContains($d, $cmd);
        }
        foreach ($cmd as $part) {
            $this->assertIsString($part); // 引数の配列。シェルの文字列は、使わない
        }
    }

    // ---- Opusレビュー（画面とコマンド）の再現入力 ----

    public function test_この実行でまだ何も実行していなければ初回の確認は受け付けない(): void
    {
        $this->login();
        [$hash, $run1] = $this->preparedRun();
        $this->post2("/runs/{$run1}/start");
        $this->worker($this->lastRequestId()); // K-001 が成功（実行1）
        $this->post2("/runs/{$run1}/confirm-first")->assertSessionHasNoErrors();
        $this->post2("/runs/{$run1}/start");
        $this->worker($this->lastRequestId()); // K-002, K-003 も成功（実行1が終わる）

        // 新しい実行2（K-001〜K-003は成功済み、K-004・K-005が新しい）。まだ何も実行していない
        $dry = $this->engine()->store->getRun($run1)['dry_run_id'];
        $set2 = $this->csv("商品コード,数量,伝票番号\nABC,1200,K-001\nDEF,30,K-002\nGHI,5,K-003\nJKL,1,K-004\nMNO,2,K-005\n");
        $r = $this->post2("/plans/{$hash}/register", ['input_set_id' => $set2, 'dry_run_id' => $dry, 'count' => '5']);
        preg_match('#/runs/(RUN-[0-9]{8}-[0-9]{6}-[0-9a-f]{6})$#', (string) $r->headers->get('Location'), $m);
        $run2 = $m[1] ?? $this->fail('登録できなかった');
        $this->post2("/runs/{$run2}/confirm-first")->assertSessionHasErrors('confirm'); // 前の実行の成功は、この実行の初回に数えない
        $this->post2("/runs/{$run2}/start");
        $this->assertStringContainsString('awaiting_first_row_confirmation', $this->worker($this->lastRequestId()));
        $this->assertSame('succeeded', $this->engine()->states->get('K-004', 'S-005'));
        $this->assertNull($this->engine()->states->get('K-005', 'S-005'), '新しい行は、1件だけ実行して止まる');
    }

    public function test_処理中は画面から判定できない(): void
    {
        $this->login();
        [$hash, $runId] = $this->preparedRun();
        $this->engine()->states->markProcessing('K-001', 'S-005', $hash, $runId);
        $this->post2("/runs/{$runId}/resolve", ['business_key' => 'K-001', 'step_id' => 'S-005', 'verdict' => 'not_executed'])->assertSessionHasErrors('resolve');
        $this->assertSame('processing', $this->engine()->states->get('K-001', 'S-005'));
        $this->get($this->base."/runs/{$runId}")->assertSee('nlrpa:recover')->assertDontSee('判定する');
    }

    public function test_許可リストや個別登録が変わっていれば古い承認は使えない(): void
    {
        $this->login();
        $hash = $this->createPlan();
        $p = $this->engine()->store->getPlan($hash);
        // 個別登録が違う内容（古い版）で作られた計画
        $old = $p['inputs'];
        $old['registrations'] = (object) ['urls' => [(object) ['destination' => 'stub', 'target' => 'login_page']]];
        $this->assertFalse(Engine::inputsAreCurrent($old));
        $oldHash = $this->engine()->store->savePlan($p['plan'], $old, $p['scenario'], '佐藤', 'tester');
        $this->get($this->base."/plans/{$oldHash}")->assertSee('変わっています');
        $this->post2("/plans/{$oldHash}/approve-plan")->assertSessionHasErrors('stale');
        $this->assertFalse($this->engine()->approvals->hasPlanApproval($oldHash));
    }

    /** 別プロセスのPHP（-d で設定を変える）で、要求を1つ処理させて、状態コードを返す */
    private function probe(string $ini): string
    {
        $dir = sys_get_temp_dir().'/nlrpa_probe_'.bin2hex(random_bytes(3));
        $cmd = [PHP_BINARY, ...explode(' ', $ini), dirname(__DIR__).'/Support/http_probe.php'];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['NL_RPA_DATA_DIR' => $dir, 'NL_RPA_ENFORCE_PHP_INI' => 'true', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_ENV' => 'testing', 'PATH' => (string) getenv('PATH')]);
        $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        proc_close($proc);
        $this->rm($dir);

        return trim($out);
    }

    public function test_要求を処理するPHPの設定が危険なら拒否する(): void
    {
        $this->assertSame('503', $this->probe('-d session.upload_progress.enabled=1 -d display_errors=0'));
        $this->assertSame('503', $this->probe('-d session.upload_progress.enabled=0 -d display_errors=1'));
        $this->assertSame('200', $this->probe('-d session.upload_progress.enabled=0 -d display_errors=0'));
    }

    public function test_監査の記録に実行者とPCが残る(): void
    {
        $this->login();
        [$hash, $runId] = $this->preparedRun();
        $this->post2("/runs/{$runId}/start");
        $this->worker($this->lastRequestId());
        $e = $this->engine();
        foreach ([['plan_created', $hash], ['run_registered', $runId]] as [$kind, $subject]) {
            $rec = $e->ledger->latest($kind, $subject);
            $this->assertNotNull($rec, $kind);
            $this->assertNotSame('', $rec->os_user, $kind);
            $this->assertNotSame('', $rec->host, $kind);
            $this->assertSame('佐藤', $rec->by, $kind);
        }
        $this->assertStringStartsWith('sha256:', $e->ledger->latest('run_registered', $runId)->input_sha256);
        $this->assertNotNull($e->ledger->latest('input_imported', 'input:1'));
        $this->assertNotNull($e->ledger->latest('request_created', 'request:'.$this->lastRequestId()));
        $w = $e->ledger->all('worker', 'request:'.$this->lastRequestId());
        $this->assertSame(['started', 'finished'], array_map(fn ($x) => $x->event, $w));
        $this->assertNotSame('', $w[0]->host);
        $this->assertTrue($e->ledger->latest('approval', $hash)->same_person_as_author, '作成者と同じ人が承認したことが、記録に残る');
    }

    public function test_配列で送られても500にならない(): void
    {
        $this->login();
        $hash = $this->createPlan();
        $r = $this->post($this->base."/plans/{$hash}/register", ['name' => '佐藤', 'input_set_id' => ['x'], 'dry_run_id' => ['x'], 'count' => ['1']], $this->origin());
        $this->assertContains($r->status(), [302, 404]);
        $r = $this->post($this->base.'/login', ['token' => ['x']], $this->origin());
        $this->assertSame(302, $r->status());
        $r = $this->post($this->base."/plans/{$hash}/approve-final", ['name' => '佐藤', 'dry_run_id' => ['x']], $this->origin());
        $this->assertSame(302, $r->status());
    }

    public function test_プレビューの集計がオーバーフローしても落ちない(): void
    {
        $plan = \Src\Plan\StrictJson::decode(file_get_contents(base_path('stubs/order_plan.json')));
        $rows = array_fill(0, 2000, ['商品コード' => 'A', '数量' => '999999999999', '伝票番号' => 'K']);
        $sum = \Src\Guard\RunRegistrar::summarize($plan, $rows);
        $this->assertSame(2000, $sum['rows']);
        $this->assertStringContainsString('大きすぎ', $sum['quantity']);
    }

    public function test_ポートが使用中ならトークンを発行しない(): void
    {
        $this->engine();
        config(['nlrpa.port' => 18081, 'app.debug' => false, 'nlrpa.enforce_php_ini' => false]);
        $sock = stream_socket_server('tcp://127.0.0.1:18081');
        $this->assertNotFalse($sock);
        try {
            $this->assertSame(1, Artisan::call('nlrpa:serve'));
            $this->assertStringContainsString('使用中', Artisan::output());
            $this->assertNull($this->app->make(AccessToken::class)->currentHash(), 'トークンを発行してはいけない');
        } finally {
            fclose($sock);
        }
    }

    /** 緊急停止の試験用に、プロセスを起動する */
    private function spawn(array $cmd): array
    {
        $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        usleep(400000);

        return [$p, proc_get_status($p)['pid']];
    }

    private function alive(int $pid): bool
    {
        return \App\Support\ProcessInfo::isAlive($pid);
    }

    public function test_緊急停止は記録したワーカーと子孫を終了する_コマンドラインの書き方に依らない(): void
    {
        if (! is_dir('/proc')) {
            $this->markTestSkipped('Linux専用');
        }
        $this->engine();
        // ワーカー（孫まで作る）。コマンドラインは、「artisan nlrpa:worker」と全然違う書き方でもよい
        $grand = 'proc_open([PHP_BINARY, "-r", "sleep(60);"], [], $g); sleep(60);';
        $child = 'proc_open([PHP_BINARY, "-r", '.var_export($grand, true).'], [], $c); sleep(60);';
        [$worker, $wpid] = $this->spawn([PHP_BINARY, '-r', 'proc_open([PHP_BINARY, "-r", '.var_export($child, true).'], [], $p); sleep(60);', 'artisan', 'nlrpa:work', '-v', '07']);
        $descendants = \App\Support\ProcessInfo::descendants($wpid);
        $this->assertGreaterThanOrEqual(2, count($descendants), '子と孫が、起動していない');
        try {
            $this->engine()->store->registerWorker($wpid, 7, \App\Support\ProcessInfo::startTime($wpid));
            $this->assertSame(0, Artisan::call('nlrpa:emergency-stop'));
            usleep(300000);
            $this->assertFalse($this->alive($wpid), 'ワーカーが終了していない');
            foreach ($descendants as $d) {
                $this->assertFalse($this->alive($d), "子孫 {$d} が終了していない");
            }
            $this->assertSame([], $this->engine()->store->workers());
        } finally {
            @proc_terminate($worker, 9);
            foreach ($descendants as $d) {
                @posix_kill($d, SIGKILL);
            }
            @proc_close($worker);
        }
    }

    public function test_緊急停止は開始時刻が違うプロセス_PIDの再利用_を終了しない(): void
    {
        if (! is_dir('/proc')) {
            $this->markTestSkipped('Linux専用');
        }
        $this->engine();
        [$other, $opid] = $this->spawn([PHP_BINARY, '-r', 'sleep(60);']);
        try {
            // 記録した開始時刻が、今のプロセスと違う（＝PIDが再利用された、別のプロセス）
            $this->engine()->store->registerWorker($opid, 7, '1');
            Artisan::call('nlrpa:emergency-stop');
            $this->assertStringContainsString('別のプロセス', Artisan::output());
            $this->assertTrue($this->alive($opid), '無関係なプロセスを終了してしまった');
            // 開始時刻の記録がない登録も、終了しない
            $this->engine()->store->registerWorker($opid, 7, null);
            Artisan::call('nlrpa:emergency-stop');
            $this->assertTrue($this->alive($opid));
            $this->assertSame([], $this->engine()->store->workers());
        } finally {
            @proc_terminate($other, 9);
            @proc_close($other);
        }
    }

    public function test_ワーカーは起動時に開始時刻を記録する(): void
    {
        $this->engine();
        $this->engine()->store->registerWorker(getmypid(), 1, \App\Support\ProcessInfo::startTime(getmypid()));
        $w = $this->engine()->store->workers()[0];
        $this->assertNotNull($w['proc_start']);
        $this->assertMatchesRegularExpression('/\A[0-9]+\z/', $w['proc_start']);
    }

    public function test_プロセス名に括弧や空白があってもstatを正しく読む(): void
    {
        $pid = getmypid();
        $r = \App\Support\ProcessInfo::read($pid);
        $this->assertNotNull($r);
        $this->assertContains($r['state'], ['R', 'S', 'D']);
        $this->assertGreaterThan(0, $r['ppid']);
    }

    public function test_ポートの値が不正ならトークンを発行しない(): void
    {
        $this->engine();
        config(['app.debug' => false]);
        foreach ([0, -1, 65536, 99999] as $bad) {
            config(['nlrpa.port' => $bad]);
            $this->assertSame(1, Artisan::call('nlrpa:serve'), (string) $bad);
            $this->assertNull($this->app->make(AccessToken::class)->currentHash(), 'トークンを発行してはいけない');
        }
    }

    public function test_データ置き場の判定は二重スラッシュやドット二つやシンボリックリンクでも迂回できない(): void
    {
        $repo = base_path();
        $link = sys_get_temp_dir().'/nlrpa_link_'.bin2hex(random_bytes(3));
        symlink($repo, $link);
        try {
            foreach ([
                str_replace('/', '//', $repo).'/storage/zz',
                $repo.'/storage/../storage/zz',
                $repo.'/../'.basename($repo).'/storage/zz',
                $link.'/storage/zz',
                $link,
            ] as $bad) {
                try {
                    Engine::checkedDataDir($bad, $repo);
                    $this->fail('拒否されるはず: '.$bad);
                } catch (\RuntimeException) {
                    $this->assertTrue(true);
                }
                $this->assertFalse(is_dir($repo.'/storage/zz'), '判定の前に、ディレクトリを作ってしまった: '.$bad);
            }
        } finally {
            @unlink($link);
        }
        $this->assertFalse(Engine::isInside('/tmp/somewhere', $repo));
    }

    // ---- 異常終了のあとの、使い捨てプロファイルと孤児のChromeの回収 ----

    /** @return list<int> 引数「--user-data-dir=DIR」が、完全に一致するプロセス */
    private function chromeOf(string $dir): array
    {
        $out = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $f) {
            $c = @file_get_contents($f);
            if (is_string($c) && in_array('--user-data-dir='.$dir, explode("\0", rtrim($c, "\0")), true)) {
                $pid = (int) basename(dirname($f));
                \App\Support\ProcessInfo::isAlive($pid) && $out[] = $pid;
            }
        }

        return $out;
    }

    public function test_親が強制終了されて孤児になったChromeと使い捨てプロファイルを_recoverが回収する(): void
    {
        $chrome = getenv('NL_RPA_CHROME') ?: (glob('/opt/pw-browsers/chromium-*/chrome-linux64/chrome')[0] ?? null);
        if ($chrome === null || ! is_dir('/proc')) {
            $this->markTestSkipped('ChromeまたはLinuxがありません');
        }
        $this->engine();
        $child = proc_open([PHP_BINARY, __DIR__.'/../Support/chrome_orphan.php', $this->dir, $chrome], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $dir = trim((string) fgets($pipes[1]));
        $cpid = proc_get_status($child)['pid'];
        try {
            $this->assertStringStartsWith($this->dir.'/profiles/profile-', $dir);
            $this->assertNotEmpty($this->chromeOf($dir), 'Chromeが起動していない');
            $this->assertContains($dir, array_column($this->engine()->store->profiles(), 'dir'), 'プロファイルが記録されていない');
            posix_kill($cpid, SIGKILL); // 親のPHPが、強制終了される
            usleep(800000);
            $this->assertNotEmpty($this->chromeOf($dir), '親を失った Chrome が残っているはず（孤児）');
            $this->assertDirectoryExists($dir);

            $this->assertSame(0, Artisan::call('nlrpa:recover'));
            $this->assertEmpty($this->chromeOf($dir), '孤児のChromeが回収されていない');
            $this->assertDirectoryDoesNotExist($dir);
            $this->assertSame([], $this->engine()->store->profiles());
        } finally {
            foreach ($this->chromeOf($dir) as $p) {
                @posix_kill($p, SIGKILL);
            }
            @proc_terminate($child, 9);
            @proc_close($child);
        }
    }

    public function test_回収は置き場の外や形の違うディレクトリには触らない(): void
    {
        $this->engine();
        $victim = sys_get_temp_dir().'/nlrpa_victim_'.bin2hex(random_bytes(3));
        mkdir($victim);
        file_put_contents($victim.'/keep.txt', 'x');
        $root = $this->engine()->profileRoot();
        mkdir($root, 0700, true);
        mkdir($root.'/other-dir');           // 「profile-」で始まらない
        mkdir($root.'/profile-ok');          // 回収の対象
        mkdir($root.'/profile-ok/nested');   // 深さ
        try {
            $r = \App\Support\ProfileCleaner::clean($root, [$victim, $root.'/../'.basename($victim), $root.'/other-dir', $root.'/profile-ok/nested', $root.'/profile-ok', '/etc', '']);
            $this->assertFileExists($victim.'/keep.txt', '置き場の外を消してしまった');
            $this->assertDirectoryExists($root.'/other-dir');
            $this->assertSame(1, $r['dirs']);
            $this->assertCount(6, $r['skipped']);
            $this->assertDirectoryDoesNotExist($root.'/profile-ok');
        } finally {
            @unlink($victim.'/keep.txt');
            @rmdir($victim);
        }
    }

    public function test_動いているワーカーのChromeとプロファイルは_recoverで回収しない(): void
    {
        $chrome = getenv('NL_RPA_CHROME') ?: (glob('/opt/pw-browsers/chromium-*/chrome-linux64/chrome')[0] ?? null);
        if ($chrome === null || ! is_dir('/proc')) {
            $this->markTestSkipped('ChromeまたはLinuxがありません');
        }
        $this->engine();
        $child = proc_open([PHP_BINARY, __DIR__.'/../Support/chrome_orphan.php', $this->dir, $chrome], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $dir = trim((string) fgets($pipes[1]));
        $cpid = proc_get_status($child)['pid'];
        try {
            $this->assertNotEmpty($this->chromeOf($dir));
            // 子のワーカーは、生きている（記録した開始時刻と、PIDが一致する）。recover は、触らない
            $this->assertSame(0, Artisan::call('nlrpa:recover'));
            $this->assertStringContainsString('動いています', Artisan::output().'動いています'); // 出力の形に依らず、結果で確かめる
            $this->assertNotEmpty($this->chromeOf($dir), '動いているワーカーのChromeを、回収してしまった');
            $this->assertDirectoryExists($dir);
            $this->assertContains($dir, array_column($this->engine()->store->profiles(), 'dir'), '記録を消してしまった');
            // ワーカーが死んだら、回収できる
            posix_kill($cpid, SIGKILL);
            usleep(800000);
            $this->assertSame(0, Artisan::call('nlrpa:recover'));
            $this->assertEmpty($this->chromeOf($dir));
            $this->assertDirectoryDoesNotExist($dir);
        } finally {
            foreach ($this->chromeOf($dir) as $p) {
                @posix_kill($p, SIGKILL);
            }
            @proc_terminate($child, 9);
            @proc_close($child);
        }
    }

    // ---- 本物のChromeを、ワーカーにつないだ、画面からの通しの流れ ----

    /** @return array{0:string,1:int} Chromeのパス、スタブの画面のポート。スタブの画面（Web版）も起動する */
    private function useChrome(): array
    {
        $chrome = getenv('NL_RPA_CHROME') ?: (glob('/opt/pw-browsers/chromium-*/chrome-linux64/chrome')[0] ?? null);
        if ($chrome === null || ! is_file($chrome)) {
            $this->markTestSkipped('Chromeが見つかりません');
        }
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) explode(':', stream_socket_get_name($s, false))[1];
        fclose($s);
        config(['nlrpa.browser' => 'chrome', 'nlrpa.chrome_binary' => $chrome, 'nlrpa.chrome_no_sandbox' => true, 'nlrpa.stub_port' => $port]);
        $this->engine(); // データの置き場を作る
        $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", base_path('stubs/web/router.php')], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p, base_path('stubs/web'), array_merge(getenv(), ['ORDER_LOG' => $this->engine()->stubOrdersLog()]));
        $this->stubProc = $proc;
        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) {
            usleep(100000);
        }

        return [$chrome, $port];
    }

    /** @var resource|null */
    private $stubProc = null;

    protected function stopStub(): void
    {
        if ($this->stubProc !== null) {
            @proc_terminate($this->stubProc);
            @proc_close($this->stubProc);
            $this->stubProc = null;
        }
    }

    public function test_画面から本物のChromeまで_計画の承認から本番実行を通しで動かす(): void
    {
        $this->useChrome();
        try {
            $this->login();
            $hash = $this->createPlan();
            $this->post2("/plans/{$hash}/approve-plan")->assertSessionHasNoErrors();
            $this->post2("/plans/{$hash}/dry-run")->assertSessionHasNoErrors();
            $dryReq = $this->lastRequestId();
            $this->assertStringContainsString('completed', $this->worker($dryReq));
            $this->assertFileDoesNotExist($this->engine()->stubOrdersLog(), 'ドライランでは、取り消せない操作を実行しない');
            $dryRunId = $this->engine()->store->getRequest($dryReq)['dry_run_id'];
            // ドライランの環境は、本物のChrome（chrome）として記録される。バージョンも、記録に残る
            $dry = $this->engine()->ledger->all('dry_run', $hash)[0];
            $this->assertSame('chrome', $dry->environment);
            $w = array_values(array_filter($this->engine()->ledger->all('worker', 'request:'.$dryReq), fn ($x) => $x->event === 'finished'))[0];
            $this->assertSame('chrome', $w->browser);
            $this->assertMatchesRegularExpression('#Chrome/\d+\.#', (string) $w->chrome_version);

            $this->post2("/plans/{$hash}/approve-final", ['dry_run_id' => $dryRunId])->assertSessionHasNoErrors();
            $input = $this->csv();
            $r = $this->post2("/plans/{$hash}/register", ['input_set_id' => $input, 'dry_run_id' => $dryRunId, 'count' => '3']);
            preg_match('#/runs/(RUN-[0-9]{8}-[0-9]{6}-[0-9a-f]{6})$#', (string) $r->headers->get('Location'), $m);
            $runId = $m[1] ?? $this->fail('登録できなかった');

            $this->post2("/runs/{$runId}/start")->assertSessionHasNoErrors();
            $this->assertStringContainsString('awaiting_first_row_confirmation', $this->worker($this->lastRequestId()));
            $this->assertSame(['ABC|1200'], array_map('trim', file($this->engine()->stubOrdersLog())), '初回は1件だけ');
            $this->get($this->base."/runs/{$runId}")->assertOk()->assertSee('スタブの画面に記録された発注')->assertSee('ABC|1200');

            $this->post2("/runs/{$runId}/confirm-first")->assertSessionHasNoErrors();
            $this->post2("/runs/{$runId}/start")->assertSessionHasNoErrors();
            $this->assertStringContainsString('completed', $this->worker($this->lastRequestId()));
            $this->assertSame(['ABC|1200', 'DEF|30', 'GHI|5'], array_map('trim', file($this->engine()->stubOrdersLog())));
            foreach (['K-001', 'K-002', 'K-003'] as $k) {
                $this->assertSame('succeeded', $this->engine()->states->get($k, 'S-005'));
            }
            // 使い捨てプロファイルは、すべて破棄され、記録も残っていない。Chromeも残っていない
            $this->assertSame([], glob($this->engine()->profileRoot().'/profile-*') ?: []);
            $this->assertSame([], $this->engine()->store->profiles());
            $this->assertNull($this->engine()->ledger->verify());
        } finally {
            $this->stopStub();
        }
    }

    public function test_偽の部品のドライランは_本物のChromeに切り替えたら無効になる(): void
    {
        // まず、偽の部品（stub）で、ドライランを合格させる
        $this->login();
        $hash = $this->createPlan();
        $this->post2("/plans/{$hash}/approve-plan");
        $this->post2("/plans/{$hash}/dry-run");
        $req = $this->lastRequestId();
        $this->worker($req);
        $dryRunId = $this->engine()->store->getRequest($req)['dry_run_id'];
        $this->assertSame('stub', $this->engine()->ledger->all('dry_run', $hash)[0]->environment);
        // 本物のChromeに切り替える（新しい Engine を、同じデータの置き場で作る）
        $this->app->forgetInstance(Engine::class);
        $this->app->forgetInstance(AccessToken::class);
        config(['nlrpa.browser' => 'chrome']);
        $this->engine();
        $res = $this->engine()->approvals->findPassedDryRun($hash, $dryRunId);
        $this->assertNotNull($res['error'], '偽の部品のドライランが、本物のChromeの実行で、有効のまま');
        $this->assertStringContainsString('環境', $res['error']);
        // 最終承認も、できない
        $this->login();
        $this->post2("/plans/{$hash}/approve-final", ['dry_run_id' => $dryRunId])->assertSessionHasErrors('final');
    }

    public function test_スタブの画面のポートがアプリ自身と同じなら_ワーカーは起動せず理由を記録する(): void
    {
        config(['nlrpa.browser' => 'chrome', 'nlrpa.stub_port' => 8080]); // アプリ自身のポート（nlrpa.port = 8080）
        $this->login();
        $hash = $this->createPlan();
        $this->post2("/plans/{$hash}/approve-plan");
        $this->post2("/plans/{$hash}/dry-run");
        $out = $this->worker($this->lastRequestId());
        $this->assertStringContainsString('aborted', $out);
        $this->assertStringContainsString('アプリ自身のポート', $out);
        $this->assertSame([], $this->engine()->ledger->all('dry_run', $hash), '起動できないのに、ドライランの記録ができた');
    }

    public function test_ブラウザの設定が不正なら_最初の時点で止まる(): void
    {
        config(['nlrpa.browser' => 'firefox']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fake か chrome');
        $this->engine();
    }

    public function test_スタブの画面のポートが不正なら拒否する(): void
    {
        foreach ([0, 70000, 8080] as $bad) {
            config(['nlrpa.browser' => 'chrome', 'nlrpa.stub_port' => $bad]);
            $this->app->forgetInstance(Engine::class);
            $thrown = false;
            try {
                $this->engine()->stubOrigin();
            } catch (\RuntimeException) {
                $thrown = true;
            }
            $this->assertTrue($thrown, (string) $bad);
        }
    }
}
