<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Src\Browser\ChromeBrowser;
use Src\Browser\ChromeLaunchPolicy;
use Src\Guard\ApprovalChecker;
use Src\Guard\IrreversibleGuard;
use Src\Guard\RunRegistrar;
use Src\Plan\PlanHasher;
use Src\Plan\StrictJson;
use Src\Policy\StubRegistrations;
use Src\Runner\PlanRunner;
use Src\Runner\RunLock;
use Src\Runner\RunReport;
use Src\State\Db;
use Src\State\Flags;
use Src\State\Ledger;
use Src\State\StepStateStore;

/** 本物のChromeを使う試験。Chromeが見つからなければ、飛ばす */
class ChromeBrowserTest extends TestCase
{
    private string $tmp;
    /** @var list<resource> */
    private array $procs = [];
    private ?string $chrome = null;

    protected function setUp(): void
    {
        $this->chrome = getenv('NL_RPA_CHROME') ?: (glob('/opt/pw-browsers/chromium-*/chrome-linux64/chrome')[0] ?? null);
        if ($this->chrome === null || ! is_file($this->chrome)) {
            $this->markTestSkipped('Chromeが見つかりません（NL_RPA_CHROME で指定できます）');
        }
        $this->tmp = sys_get_temp_dir().'/nlrpa_chrome_'.bin2hex(random_bytes(4));
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->procs as $p) {
            @proc_terminate($p);
            @proc_close($p);
        }
        $this->rm($this->tmp ?? '');
    }

    private function rm(string $d): void
    {
        if ($d === '' || ! is_dir($d)) {
            return;
        }
        foreach (scandir($d) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                is_dir("$d/$f") && ! is_link("$d/$f") ? $this->rm("$d/$f") : @unlink("$d/$f");
            }
        }
        @rmdir($d);
    }

    private function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) explode(':', stream_socket_get_name($s, false))[1];
        fclose($s);

        return $port;
    }

    /** php -S を起動する（環境変数つき）。起動を待つ */
    private function serve(int $port, string $router, array $env): void
    {
        $this->procs[] = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $router], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p, dirname($router), array_merge(getenv(), $env));
        for ($i = 0; $i < 50; $i++) {
            if (@fsockopen('127.0.0.1', $port)) {
                return;
            }
            usleep(100000);
        }
        $this->fail('サーバーが起動しない');
    }

    private function browser(IrreversibleGuard $guard, int $port, string $env = 'chrome', ?\Closure $onProfile = null): ChromeBrowser
    {
        $base = "http://localhost:{$port}";

        return new ChromeBrowser(
            $this->chrome, $this->tmp.'/profiles', new ChromeLaunchPolicy([$base]),
            ['start' => "{$base}/login", 'login_page' => "{$base}/login", 'order_page' => "{$base}/order", 'done_page' => "{$base}/done"],
            ['item_code' => '#item_code', 'quantity' => '#quantity', 'submit_button' => '#submit_button', 'order_number' => '#order_number'],
            $guard, 'stub', $env, true, $onProfile,
        );
    }

    // ---- 起動時の遮断の方針（Chrome不要の部分は、ChromeLaunchPolicyTest） ----

    public function test_通しの流れ_本物のChromeで_ドライランから本番実行まで(): void
    {
        $port = $this->freePort();
        $this->serve($port, dirname(__DIR__, 2).'/stubs/web/router.php', ['ORDER_LOG' => $this->tmp.'/orders.log']);
        $db = Db::open(':memory:');
        $t = 0;
        $clock = fn (): string => sprintf('2026-11-11T00:%02d:%02dZ', intdiv($t, 60) % 60, $t++ % 60);
        $ledger = new Ledger($db, $clock);
        $states = new StepStateStore($db, $ledger, $clock);
        $flags = new Flags($db, $ledger);
        $approvals = new ApprovalChecker($ledger, ['chrome']); // 1b：スタブ（偽の部品）のドライランは、無効
        $guard = new IrreversibleGuard($ledger, $states, $flags, $approvals);
        $registrar = new RunRegistrar($ledger, $approvals);
        $profiles = [];
        $browser = $this->browser($guard, $port, 'chrome', function (string $ev, string $dir) use (&$profiles): void {
            $profiles[] = [$ev, $dir];
        });
        $runner = new PlanRunner($ledger, $states, $flags, $guard, $approvals, $browser, new RunLock($this->tmp.'/run.lock'));
        $plan = StrictJson::decode(file_get_contents(dirname(__DIR__, 2).'/stubs/order_plan.json'));
        $inputs = StubRegistrations::hashInputs();
        $rows = [
            ['商品コード' => 'ABC', '数量' => '1200', '伝票番号' => 'K-001'],
            ['商品コード' => 'DEF', '数量' => '30', '伝票番号' => 'K-002'],
            ['商品コード' => 'GHI', '数量' => '5', '伝票番号' => 'K-003'],
        ];
        $hash = PlanHasher::hash($plan, $inputs);
        try {
            $ledger->append('approval', $hash, (object) ['stage' => 'plan', 'approver' => '佐藤', 'os_user' => 't']);
            $dry = $runner->dryRun($plan, $inputs, $rows, 'DR-1');
            $this->assertSame(RunReport::COMPLETED, $dry->status, implode(' / ', $dry->reasons));
            $this->assertFileDoesNotExist($this->tmp.'/orders.log', 'ドライランでは、取り消せない操作を実行しない');
            $ledger->append('approval', $hash, (object) ['stage' => 'final', 'approver' => '佐藤', 'os_user' => 't']);
            $this->assertSame([], $registrar->register('RUN-1', $plan, $inputs, 'DR-1', 'stub', $rows));
            $r1 = $runner->run($plan, $inputs, 'RUN-1', $rows);
            $this->assertSame(RunReport::AWAITING_FIRST_ROW, $r1->status, implode(' / ', $r1->reasons));
            $this->assertCount(1, file($this->tmp.'/orders.log'), '初回は1件だけ');
            $this->assertSame('ORD-0001', $r1->rows[0]['reads']['order_number']);
            $runner->confirmFirstRow('RUN-1', '佐藤', 't');
            $r2 = $runner->run($plan, $inputs, 'RUN-1', $rows);
            $this->assertSame(RunReport::COMPLETED, $r2->status, implode(' / ', $r2->reasons));
            $this->assertSame(["ABC|1200\n", "DEF|30\n", "GHI|5\n"], file($this->tmp.'/orders.log'));
            $this->assertSame('succeeded', $states->get('K-003', 'S-005'));
        } finally {
            $browser->close();
        }
        // 使い捨てプロファイル：作られ、終了時に消える
        $this->assertSame('created', $profiles[0][0]);
        $this->assertSame('removed', $profiles[count($profiles) - 1][0]);
        $this->assertDirectoryDoesNotExist($profiles[0][1]);
    }

    /** @return array{0:IrreversibleGuard,1:int} */
    private function guardAndPort(): array
    {
        $db = Db::open(':memory:');
        $clock = fn (): string => gmdate('Y-m-d\TH:i:s\Z');
        $ledger = new Ledger($db, $clock);

        return [new IrreversibleGuard($ledger, new StepStateStore($db, $ledger, $clock), new Flags($db, $ledger), new ApprovalChecker($ledger)), $this->freePort()];
    }

    public function test_15種類の攻撃は_どれも外へ届かず_検知できるものは停止になる(): void
    {
        [$guard] = $this->guardAndPort();
        $app = $this->freePort();
        $can = $this->freePort();
        $canaryLog = $this->tmp.'/canary.log';
        touch($canaryLog);
        $verify = dirname(__DIR__, 2).'/tools/verify-network';
        $this->serve($app, $verify.'/router_app.php', ['CANARY_LOG' => $canaryLog, 'APP_LOG' => $this->tmp.'/app.log']);
        $this->serve($can, $verify.'/router_canary.php', ['CANARY_LOG' => $canaryLog, 'APP_LOG' => $this->tmp.'/app.log']);
        $cases = ['redirect' => '/redirect', 'window_open' => '/window_open.html', 'target_blank' => '/target_blank.html', 'iframe' => '/iframe.html', 'meta_refresh' => '/meta_refresh.html', 'js_location' => '/js_location.html', 'fetch' => '/fetch.html', 'xhr' => '/xhr.html', 'websocket' => '/websocket.html', 'beacon' => '/beacon.html', 'form_post' => '/form_post.html', 'download' => '/download.html', 'image' => '/image.html', 'script' => '/script.html', 'service_worker' => '/service_worker.html'];
        // エンジンが検知して、停止にできるもの（WebSocket と Service Worker は、起動時の遮断が、黙って止める）
        $mustDetect = array_values(array_diff(array_keys($cases), ['websocket', 'service_worker']));
        $base = "http://localhost:{$app}";
        foreach (['evil.test' => "http://evil.test:{$can}", 'ip' => "http://127.0.0.1:{$can}"] as $label => $evil) {
            $urls = ['start' => "{$base}/ok"];
            foreach ($cases as $c => $path) {
                $urls[$c] = $base.$path.(str_contains($path, '?') ? '&' : '?').'evil='.urlencode($evil);
            }
            $b = new ChromeBrowser($this->chrome, $this->tmp.'/profiles', new ChromeLaunchPolicy([$base]), $urls, ['x' => 'body'], $guard, 'stub', 'chrome', true);
            try {
                foreach ($cases as $c => $_) {
                    file_put_contents($canaryLog, '');
                    $detected = false;
                    try {
                        $b->openUrl($c);
                        $b->textVisible('x'); // 続けて、操作する（遅れて起きた遮断・新しいウィンドウを、検知させる）
                        usleep(700000);
                        $b->textVisible('x');
                    } catch (\Throwable) {
                        $detected = true;
                    }
                    $hits = array_filter(file($canaryLog) ?: [], fn ($l) => str_contains($l, '"case":"'.$c.'"'));
                    $this->assertCount(0, $hits, "{$label}: 攻撃 {$c} が、外へ届いた");
                    if (in_array($c, $mustDetect, true)) {
                        $this->assertTrue($detected, "{$label}: 攻撃 {$c} を、検知して停止にできなかった");
                    }
                    try {
                        $b->resetToStart();
                    } catch (\Throwable) {
                        // 次のケースの前に、画面を戻す。戻せなくても、次の openUrl が、検査する
                    }
                }
            } finally {
                $b->close();
            }
        }
    }

    public function test_登録外の画面名や要素名は拒否する(): void
    {
        [$guard, $port] = $this->guardAndPort();
        $this->serve($port, dirname(__DIR__, 2).'/stubs/web/router.php', ['ORDER_LOG' => $this->tmp.'/orders.log']);
        $b = $this->browser($guard, $port);
        try {
            foreach ([fn () => $b->openUrl('evil_page'), fn () => $b->readValue('nope')] as $f) {
                $thrown = false;
                try {
                    $f();
                } catch (\RuntimeException) {
                    $thrown = true;
                }
                $this->assertTrue($thrown);
            }
            $b->openUrl('order_page');
            $b->typeText('item_code', 'ABC');
            $this->assertSame('ABC', $b->readValue('item_code'));
            $this->expectException(\RuntimeException::class);
            $b->typeText('item_code', "x\n"); // 改行は、入力しない（Enterによる送信を防ぐ）
        } finally {
            $b->close();
        }
    }

    public function test_Chromeのデバッグ接続は_外部のOriginを受け付けない(): void
    {
        [$guard, $port] = $this->guardAndPort();
        $this->serve($port, dirname(__DIR__, 2).'/stubs/web/router.php', ['ORDER_LOG' => $this->tmp.'/orders.log']);
        $b = $this->browser($guard, $port);
        try {
            $b->openUrl('login_page');
            $ref = new \ReflectionProperty($b, 'browser');
            $uri = $ref->getValue($b)->getSocketUri();
            $u = parse_url($uri);
            $key = base64_encode(random_bytes(16));
            $try = function (string $origin) use ($u, $key): string {
                $s = stream_socket_client("tcp://{$u['host']}:{$u['port']}", $e, $m, 3);
                stream_set_timeout($s, 3);
                fwrite($s, "GET {$u['path']} HTTP/1.1\r\nHost: {$u['host']}:{$u['port']}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\nOrigin: {$origin}\r\n\r\n");
                $line = (string) fgets($s);
                fclose($s);

                return trim($line);
            };
            $this->assertStringContainsString('403', $try('http://evil.example'));
            $this->assertStringContainsString('101', $try('http://127.0.0.1'));
        } finally {
            $b->close();
        }
    }
}
