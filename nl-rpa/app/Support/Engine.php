<?php

declare(strict_types=1);

namespace App\Support;

use Src\Browser\BrowserPort;
use Src\Browser\ChromeBrowser;
use Src\Browser\ChromeLaunchPolicy;
use Src\Browser\FakeBrowser;
use Src\Guard\ApprovalChecker;
use Src\Guard\IrreversibleGuard;
use Src\Guard\RunRegistrar;
use Src\Plan\CanonicalJson;
use Src\Policy\StubRegistrations;
use Src\Runner\PlanRunner;
use Src\Runner\RunLock;
use Src\State\AppStore;
use Src\State\Db;
use Src\State\Flags;
use Src\State\Ledger;
use Src\State\StepStateStore;

/**
 * 実行エンジンの部品をまとめて作る（app → src の一方向。仕様14-3）。
 * データの置き場（NL_RPA_DATA_DIR）は、リポジトリの外の、絶対パスでなければならない。
 */
final class Engine
{
    public readonly string $dataDir;
    public readonly Db $db;
    public readonly Ledger $ledger;
    public readonly StepStateStore $states;
    public readonly Flags $flags;
    public readonly ApprovalChecker $approvals;
    public readonly IrreversibleGuard $guard;
    public readonly RunRegistrar $registrar;
    public readonly AppStore $store;

    /**
     * @param array{mode?:string, binary?:string|null, no_sandbox?:bool, allowed_major?:string|null, stub_port?:int, app_port?:int} $browser
     */
    public function __construct(?string $dataDir, string $repoRoot, private readonly string $stubDir, int $orgMaxRows, private readonly array $browser = [])
    {
        $this->dataDir = self::checkedDataDir($dataDir, $repoRoot);
        $clock = static fn (): string => gmdate('Y-m-d\TH:i:s\Z');
        $this->db = Db::open($this->dataDir.'/nlrpa.sqlite');
        $this->ledger = new Ledger($this->db, $clock);
        $this->states = new StepStateStore($this->db, $this->ledger, $clock);
        $this->flags = new Flags($this->db, $this->ledger);
        // 有効と認めるドライランの環境：偽の部品なら stub、本物のChromeなら chrome（1bに入ったら、スタブのドライランは、無効。9-2）
        $this->approvals = new ApprovalChecker($this->ledger, [$this->environmentName()]);
        $this->guard = new IrreversibleGuard($this->ledger, $this->states, $this->flags, $this->approvals);
        $this->registrar = new RunRegistrar($this->ledger, $this->approvals, $orgMaxRows, $this->states);
        $this->store = new AppStore($this->db, $clock);
    }

    /**
     * パスを正規化する。「//」「.」「..」を、文字列として整理し、存在する一番深い親は、シンボリックリンクも解決する。
     * 存在しないパスでも使える（作る前に、リポジトリの中かを判定するため）。相対パスは、null。
     */
    public static function resolvePath(string $path): ?string
    {
        // Windowsの絶対パス（C:\dir や C:/dir）。ドライブ文字を大文字にそろえ、区切りを / にする。UNC（\\server\share）は、未対応（null）
        $drive = '';
        if (preg_match('#\A([A-Za-z]):[\\\\/]#', $path, $m) === 1) {
            $drive = strtoupper($m[1]).':';
            $path = substr($path, 2);
        } elseif ($path === '' || $path[0] !== '/') {
            return null;
        }
        $parts = [];
        foreach (preg_split($drive !== '' ? '#[\\\\/]#' : '#/#', $path) ?: [] as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }
        // 存在する一番深い親を、実体のパスにする。存在しない部分は、元の順で、後ろに付ける
        $rest = [];
        while ($parts !== []) {
            $real = @realpath($drive.'/'.implode('/', $parts));
            if ($real !== false) {
                $real = $drive !== '' ? self::normalizeWindows($real) : $real;

                return rtrim($real, '/').($rest === [] ? '' : '/'.implode('/', $rest));
            }
            array_unshift($rest, array_pop($parts));
        }

        return $drive.'/'.implode('/', $rest);
    }

    /** Windowsの realpath の結果（C:\a\b）を、C:/a/b にそろえる（ドライブ文字は大文字） */
    private static function normalizeWindows(string $p): string
    {
        $p = str_replace('\\', '/', $p);

        return preg_match('#\A[a-z]:#', $p) === 1 ? strtoupper($p[0]).substr($p, 1) : $p;
    }

    /** $path が、$dir の中（または同じ）か。どちらも resolvePath を通した値で比べる（Windowsのパスは、大文字小文字を区別しない） */
    public static function isInside(string $path, string $dir): bool
    {
        $p = self::resolvePath($path);
        $d = self::resolvePath($dir);
        if ($p === null || $d === null) {
            return false;
        }
        if (preg_match('#\A[A-Z]:#', $p) === 1) {
            $p = strtolower($p);
            $d = strtolower($d);
        }

        return $p === $d || str_starts_with($p.'/', rtrim($d, '/').'/');
    }

    /** データの置き場を検査して、作る（なければ）。リポジトリの中や、相対パスは拒否（LOC-4）。作る前に、判定する */
    public static function checkedDataDir(?string $dir, string $repoRoot): string
    {
        if ($dir === null || $dir === '' || self::resolvePath($dir) === null) {
            throw new \RuntimeException('NL_RPA_DATA_DIR に、リポジトリの外の絶対パスを指定してください');
        }
        if (self::isInside($dir, $repoRoot)) {
            throw new \RuntimeException('データの置き場は、リポジトリの外にしてください（LOC-4）');
        }
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException("データの置き場を作れません: {$dir}");
        }
        $real = realpath($dir);
        if ($real === false || self::isInside($real, $repoRoot)) {
            throw new \RuntimeException('データの置き場は、リポジトリの外にしてください（LOC-4）');
        }
        @chmod($real, 0700);

        return $real;
    }

    /**
     * 計画と一緒に保存した許可リスト・個別登録・画面の記録が、今の内容と同じか（R5、12-1「版が承認時と同じ」）。
     * 変わっていれば、古い承認は使えない。計画を作り直して、再承認する。
     *
     * @param array<string,mixed> $inputs
     */
    public static function inputsAreCurrent(array $inputs): bool
    {
        return CanonicalJson::encode((object) $inputs) === CanonicalJson::encode((object) StubRegistrations::hashInputs());
    }

    /** スタブの画面（Web版）が、発注の登録を記録するファイル */
    public function stubOrdersLog(): string
    {
        return $this->dataDir.'/stub-orders.log';
    }

    /** @return list<string> スタブの画面に記録された発注（新しい順に、最大20件。画面に出す用） */
    public function stubOrders(): array
    {
        $lines = @file($this->stubOrdersLog(), FILE_IGNORE_NEW_LINES) ?: [];

        return array_slice(array_reverse($lines), 0, 20);
    }

    /** 使い捨てのブラウザプロファイルの置き場（データの置き場の中） */
    public function profileRoot(): string
    {
        return $this->dataDir.'/profiles';
    }

    /**
     * ブラウザ部品に渡す、プロファイルの作成・破棄の通知。記録しておき、異常終了のあとに、回収できるようにする。
     *
     * @return \Closure(string,string):void
     */
    public function profileNotifier(): \Closure
    {
        $pid = getmypid();
        $start = ProcessInfo::startTime($pid);

        return function (string $event, string $dir) use ($pid, $start): void {
            $event === 'created' ? $this->store->registerProfile($dir, $pid, $start) : $this->store->unregisterProfile($dir);
        };
    }

    public function runLock(): RunLock
    {
        return new RunLock($this->dataDir.'/run.lock');
    }

    public function browserMode(): string
    {
        $m = (string) ($this->browser['mode'] ?? 'fake');
        if (! in_array($m, ['fake', 'chrome'], true)) {
            throw new \RuntimeException("NL_RPA_BROWSER は、fake か chrome にしてください（{$m}）");
        }

        if ($m === 'chrome' && PHP_OS_FAMILY === 'Windows') {
            // Windowsでは、残ったChromeの回収・緊急停止が、未対応（確かめられていない）。回収できないまま、本物のChromeを動かさない
            throw new \RuntimeException('Windowsでは、本物のChromeでの実行は、まだ未対応です。NL_RPA_BROWSER=fake にしてください');
        }

        return $m;
    }

    /**
     * 有効と認めるドライランの環境の名前。偽の部品は stub。本物のChromeは、接続先（スキーム・ホスト・ポート）を含める
     * （接続先を変えたら、環境が変わり、古いドライランは、無効になる。9-2）。
     */
    public function environmentName(): string
    {
        return $this->browserMode() === 'chrome' ? 'chrome@'.$this->stubOrigin() : 'stub';
    }

    /** スタブの画面（Web版）の、許可する接続先（アプリ自身のポートとは、別でなければならない） */
    public function stubOrigin(): string
    {
        $port = (int) ($this->browser['stub_port'] ?? 8081);
        $app = (int) ($this->browser['app_port'] ?? 8080);
        if ($port < 1 || $port > 65535 || $port === $app) {
            throw new \RuntimeException('NL_RPA_STUB_PORT は、1〜65535 で、アプリ自身のポートとは別にしてください（14-4）');
        }

        if (! \Src\Policy\DestinationLimits::isAllowedStubPort($port)) {
            throw new \RuntimeException("NL_RPA_STUB_PORT は、許可された範囲（8081、または18300〜18399）にしてください（SEC-6。{$port}）");
        }

        return 'http://localhost:'.$port;
    }

    /** ブラウザ部品を作る（設定による）。本物のChromeは、許可した接続先（スタブの画面）だけに通信できる */
    public function newBrowser(): BrowserPort
    {
        if ($this->browserMode() === 'fake') {
            return new FakeBrowser($this->stubDir.'/order_system.json', $this->guard);
        }
        $origin = $this->stubOrigin();
        $raw = trim((string) ($this->browser['allowed_major'] ?? ''));
        if ($raw === '') {
            throw new \RuntimeException('本物のChromeを使うときは、NL_RPA_CHROME_MAJOR（許可するメジャーバージョン。例：153）が必要です（14-2。範囲外のChromeでは動かさない）');
        }
        if (! preg_match('/\A[1-9][0-9]*(,[1-9][0-9]*)*\z/', $raw)) {
            throw new \RuntimeException('NL_RPA_CHROME_MAJOR は、メジャーバージョンの数字を、カンマ区切りで指定してください（例：153,154）');
        }
        $major = array_map('intval', explode(',', $raw));

        return new ChromeBrowser(
            (string) (($this->browser['binary'] ?? null) ?: ChromeBrowser::discoverBinary()),
            $this->profileRoot(),
            new ChromeLaunchPolicy([$origin]),
            StubRegistrations::pageUrls($origin),
            StubRegistrations::SELECTORS,
            $this->guard,
            'stub',
            $this->environmentName(),
            (bool) ($this->browser['no_sandbox'] ?? false),
            $this->profileNotifier(),
            $major,
        );
    }

    /**
     * 動いていないワーカーが残した、使い捨てプロファイルと、孤児のChromeを回収する（14-3の5）。
     * 動いているワーカー（PIDと開始時刻が一致）のものには、触らない。
     *
     * @return array{processes:int,dirs:int}
     */
    public function reclaimDeadProfiles(): array
    {
        $dead = array_values(array_filter($this->store->profiles(), static function (array $p): bool {
            return ! ($p['proc_start'] !== null && ProcessInfo::startTime($p['worker_pid']) === $p['proc_start'] && ProcessInfo::isAlive($p['worker_pid']));
        }));
        if ($dead === []) {
            return ['processes' => 0, 'dirs' => 0];
        }
        $r = ProfileCleaner::clean($this->profileRoot(), array_column($dead, 'dir'));
        foreach ($dead as $p) {
            in_array($p['dir'], $r['skipped'], true) || $this->store->unregisterProfile($p['dir']);
        }

        return ['processes' => $r['processes'], 'dirs' => $r['dirs']];
    }

    public function newRunner(BrowserPort $browser): PlanRunner
    {
        return new PlanRunner($this->ledger, $this->states, $this->flags, $this->guard, $this->approvals, $browser, $this->runLock());
    }

    /** 画面の操作者（OSのログインユーザー）。承認者の名前とは別に、記録する（LOC-3） */
    public static function osUser(): string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $pw = posix_getpwuid(posix_geteuid());
            if (is_array($pw) && isset($pw['name'])) {
                return (string) $pw['name'];
            }
        }

        return get_current_user() ?: 'unknown';
    }
}
