<?php

declare(strict_types=1);

namespace Src\Browser;

use HeadlessChromium\Browser\ProcessAwareBrowser;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Communication\Message;
use HeadlessChromium\Page;
use Src\Guard\IrreversibleGuard;
use Src\Guard\Redeemed;

/**
 * 本物のブラウザ部品（1b。chrome-php/chrome）。HeadlessChromium を使うのは、src/Browser の中だけ（仕様14-3）。
 *
 * 安全のための作り（docs/TECH_STACK.md の7章の検証結果による）：
 *  - 起動オプションは、ChromeLaunchPolicy が作る。呼び出し側は、選べない（名前解決の失敗＋使えないプロキシ＋デバッグ接続のOrigin制限）
 *  - エンジンも、CDPのFetchで、すべての要求を検査する。許可した接続先以外への要求は、失敗にして、記録する（検知して、停止するため）
 *  - 新しいウィンドウ・タブが開いたら、検知して、閉じ、停止する（SEC-5）
 *  - 想定外の確認ダイアログは、閉じて、停止する
 *  - 実行ごとに、使い捨ての専用プロファイルで起動し、終了時に破棄する。プロファイルの場所は、呼び出し側（app）に通知する（異常終了のあとの後始末のため）
 *  - ブラウザで実行するJavaScriptは、固定の文字列だけ。値は、引数で渡す（SEC-11）。ページのJavaScriptから隔離された環境（isolated world）で
 *    実行する（ページが、document.querySelector などを差し替えて、読み取りを副作用に変えるのを防ぐ）
 *  - ダウンロードは、拒否して、検知する。使い捨てプロファイルの外（~/Downloads など）に、保存させない
 *  - 主フレームが、許可した接続先の外（about:blank、blob: などを含む）へ移ったら、停止する
 *  - 起動時に、Chromeのバージョンを記録し、許可したメジャーバージョンの外なら停止する（仕様14-2）
 *  - 取り消せない操作は、performIrreversible だけ。操作の瞬間に、自分で持つ Guard で、証明を照合し直す。対象は、検査した時点の手順から取る
 * 異常（遮断した通信、新しいウィンドウ、ダイアログ）は、例外にする。Runner は、例外を「停止」として扱う。
 */
final class ChromeBrowser implements BrowserPort
{
    private ?ProcessAwareBrowser $browser = null;
    private ?Page $page = null;
    private ?string $profileDir = null;
    private ?string $mainTargetId = null;
    /** @var list<string> 遮断した要求のURL */
    private array $violations = [];
    /** @var list<string> 開いた新しいターゲット（ウィンドウ・タブ） */
    private array $newTargets = [];
    private ?string $dialog = null;
    private ?string $chromeVersion = null;
    /** @var list<string> 閉じるときに、まだ確かめていなかった異常（最後の操作のあとに起きたもの） */
    private array $unseenAtClose = [];

    /**
     * @param array<string,string> $pageUrls  画面の記録の「画面名 => URL」。許可した接続先の中だけ。'start' が、開始画面
     * @param array<string,string> $selectors 画面の記録の「要素名 => CSSセレクタ」
     * @param (\Closure(string,string):void)|null $onProfile プロファイルの作成・破棄の通知（'created'|'removed', ディレクトリ）
     */
    public function __construct(
        private readonly string $chromeBinary,
        private readonly string $profileRoot,
        private readonly ChromeLaunchPolicy $policy,
        private readonly array $pageUrls,
        private readonly array $selectors,
        private readonly IrreversibleGuard $guard,
        private readonly string $destination = 'stub',
        private readonly string $environment = 'chrome',
        /** サンドボックスの無効化。接続先の制限とは無関係で、rootで動かす環境のためだけの指定（ChromeがrootでのSandbox起動を拒否するため） */
        private readonly bool $noSandbox = false,
        private readonly ?\Closure $onProfile = null,
        /** @var list<int>|null 許可するChromeのメジャーバージョン。null＝確認しない（1bの前に、docs/TECH_STACK.md の範囲に決める） */
        private readonly ?array $allowedMajorVersions = null,
    ) {
        foreach ($pageUrls as $name => $url) {
            if (! $policy->allows($url)) {
                throw new \InvalidArgumentException("画面 {$name} のURLが、許可した接続先の外です: {$url}");
            }
        }
        if (! isset($pageUrls['start'])) {
            throw new \InvalidArgumentException("'start'（開始画面）が必要です");
        }
    }

    /** Chromeの実行ファイルを探す（chrome-php の自動検出）。見つからなければ、例外 */
    public static function discoverBinary(): string
    {
        return (new \HeadlessChromium\AutoDiscover())->guessChromeBinaryPath();
    }

    public function destinationName(): string
    {
        return $this->destination;
    }

    public function environmentName(): string
    {
        return $this->environment;
    }

    /** 使い捨てのプロファイルで、Chromeを起動する（まだ起動していなければ） */
    private function ensure(): Page
    {
        if ($this->page !== null) {
            return $this->page;
        }
        if (! is_dir($this->profileRoot) && ! @mkdir($this->profileRoot, 0700, true) && ! is_dir($this->profileRoot)) {
            throw new \RuntimeException('プロファイルの置き場を作れません');
        }
        $dir = rtrim($this->profileRoot, '/').'/profile-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $this->profileDir = $dir;
        $this->onProfile && ($this->onProfile)('created', $dir);
        try {
            $this->seedPreferences($dir);
            $this->browser = (new BrowserFactory($this->chromeBinary))->createBrowser([
                'headless' => true,
                'noSandbox' => $this->noSandbox,
                'userDataDir' => $dir,
                'startupTimeout' => 30,
                'customFlags' => $this->policy->flags(),
                // HOME・XDG をプロファイルの中へ向ける：クラッシュ報告、ダウンロードの既定の場所、設定が、使い捨てプロファイルの外（~/.config、~/Downloads）に出ない（SEC-3）
                'envVariables' => ['HOME' => $dir, 'XDG_CONFIG_HOME' => $dir.'/xdg-config', 'XDG_CACHE_HOME' => $dir.'/xdg-cache', 'XDG_DATA_HOME' => $dir.'/xdg-data'],
            ]);
            $conn = $this->browser->getConnection();
            // バージョンの記録と確認（仕様14-2）
            $ver = $conn->sendMessageSync(new Message('Browser.getVersion'), 5000);
            $this->chromeVersion = (string) $ver->getResultData('product');
            if ($this->allowedMajorVersions !== null) {
                $major = preg_match('/\/(\d+)\./', $this->chromeVersion, $m) ? (int) $m[1] : -1;
                if (! in_array($major, $this->allowedMajorVersions, true)) {
                    throw new \RuntimeException("Chromeのバージョンが、許可した範囲の外です: {$this->chromeVersion}");
                }
            }
            // ダウンロードは、拒否して、検知する（使い捨てプロファイルの外へ、保存させない）
            $conn->sendMessageSync(new Message('Browser.setDownloadBehavior', ['behavior' => 'deny', 'eventsEnabled' => true]), 5000);
            $conn->on('method:Browser.downloadWillBegin', function (array $p): void {
                $this->violations[] = 'ダウンロードを拒否しました: '.(string) ($p['url'] ?? '?');
            });
            $this->page = $this->browser->createPage();
            $this->mainTargetId = $this->page->getSession()->getTargetId();
            // これ以降に作られた「ページ」は、新しいウィンドウ・タブ（SEC-5：開いたら、停止）
            $conn->on('method:Target.targetCreated', function (array $p): void {
                $info = $p['targetInfo'] ?? [];
                if (($info['type'] ?? '') === 'page' && ($info['targetId'] ?? '') !== $this->mainTargetId) {
                    $this->newTargets[] = (string) ($info['targetId'] ?? '?');
                }
            });
            $session = $this->page->getSession();
            $session->on('method:Fetch.requestPaused', function (array $p) use ($session): void {
                if ($this->policy->allows((string) $p['request']['url'])) {
                    $session->sendMessage(new Message('Fetch.continueRequest', ['requestId' => $p['requestId']]));
                } else {
                    $this->violations[] = (string) $p['request']['url'];
                    $session->sendMessage(new Message('Fetch.failRequest', ['requestId' => $p['requestId'], 'errorReason' => 'BlockedByClient']));
                }
            });
            // 主フレームが、許可した接続先の外（about:blank、blob:、data: などを含む）へ移ったら、停止する
            $session->on('method:Page.frameNavigated', function (array $p): void {
                $f = $p['frame'] ?? [];
                if (! isset($f['parentId']) && ! $this->policy->allows((string) ($f['url'] ?? ''))) {
                    $this->violations[] = '許可していない画面へ移りました: '.(string) ($f['url'] ?? '?');
                }
            });
            $session->on('method:Page.javascriptDialogOpening', function (array $p) use ($session): void {
                $this->dialog = (string) ($p['message'] ?? '');
                $session->sendMessage(new Message('Page.handleJavaScriptDialog', ['accept' => false])); // 閉じる（OKはしない）
            });
            $session->sendMessageSync(new Message('Fetch.enable', ['patterns' => [['urlPattern' => '*']]]));
        } catch (\Throwable $e) {
            $this->close();
            throw $e;
        }

        return $this->page;
    }

    /**
     * 使い捨てプロファイルの設定を、起動の前に書く（SEC-3）。
     *  - WebRTC：プロキシを通らないUDPを、禁止する（起動オプションだけでは、UDPが外へ届いた）
     *  - パスワードの保存・自動入力・セーフブラウジングを、無効にする
     *  - ダウンロードの保存先は、プロファイルの中にする（既定の ~/Downloads に、保存させない。拒否も、別に行う）
     */
    private function seedPreferences(string $dir): void
    {
        $default = $dir.'/Default';
        mkdir($default, 0700);
        $prefs = [
            'webrtc' => ['ip_handling_policy' => 'disable_non_proxied_udp', 'multiple_routes_enabled' => false, 'nonproxied_udp_enabled' => false],
            'credentials_enable_service' => false,
            'profile' => ['password_manager_enabled' => false],
            'autofill' => ['profile_enabled' => false, 'credit_card_enabled' => false],
            'safebrowsing' => ['enabled' => false],
            'download' => ['prompt_for_download' => false, 'default_directory' => $dir.'/downloads'],
            'savefile' => ['default_directory' => $dir.'/downloads'],
        ];
        file_put_contents($default.'/Preferences', json_encode($prefs, JSON_THROW_ON_ERROR));
        @chmod($default.'/Preferences', 0600);
    }

    /** イベントを処理しながら、待つ。chrome-php は、読み取りのときに、イベントを配る */
    private function pump(float $sec): void
    {
        $conn = $this->browser?->getConnection();
        $end = microtime(true) + $sec;
        while ($conn !== null && microtime(true) < $end) {
            try {
                $conn->readData();
            } catch (\Throwable) {
            }
            usleep(20000);
        }
    }

    /** 遮断した通信・新しいウィンドウ・ダイアログがあれば、片付けて、例外にする（＝停止） */
    private function assertClean(): void
    {
        $this->pump(0.05);
        if ($this->newTargets !== []) {
            $ids = $this->newTargets;
            $this->newTargets = [];
            foreach ($ids as $id) { // 開いてしまった新しいウィンドウは、閉じる
                try {
                    $this->browser?->getConnection()->sendMessageSync(new Message('Target.closeTarget', ['targetId' => $id]), 3000);
                } catch (\Throwable) {
                }
            }
            throw new \RuntimeException('新しいウィンドウ・タブが開きました（SEC-5：停止）');
        }
        if ($this->violations !== []) {
            $u = $this->violations[0];
            $this->violations = [];
            throw new \RuntimeException('許可していない接続先への通信を遮断しました（SEC-5：停止）: '.$u);
        }
        if ($this->dialog !== null) {
            $d = $this->dialog;
            $this->dialog = null;
            throw new \RuntimeException('想定外の確認ダイアログが開きました（閉じました）: '.$d);
        }
    }

    private function selector(string $element): string
    {
        return $this->selectors[$element] ?? throw new \RuntimeException("画面の記録にない要素です: {$element}");
    }

    /**
     * 固定のJavaScriptを、値を引数で渡して、実行する（文字列を連結して作らない。SEC-11）。
     * ページのJavaScriptから隔離された環境で実行する（ページが差し替えた関数は、見えない）。前後で、異常がないかを確かめる。
     */
    private function call(string $fixedFunction, array $args = []): mixed
    {
        $this->ensure();
        $this->assertClean(); // 実行の前にも、確かめる（溜まった異常を、持ち越して、操作しない）
        $r = $this->isolated($fixedFunction, $args);
        $this->assertClean();

        return $r;
    }

    private function isolated(string $fixedFunction, array $args): mixed
    {
        $page = $this->ensure();
        $session = $page->getSession();
        $frameId = $page->getFrameManager()->getMainFrame()->getFrameId();
        $ctx = $session->sendMessageSync(new Message('Page.createIsolatedWorld', ['frameId' => $frameId, 'worldName' => 'nlrpa-engine', 'grantUniveralAccess' => false]), 10000)->getResultData('executionContextId');
        $resp = $session->sendMessageSync(new Message('Runtime.callFunctionOn', [
            'functionDeclaration' => $fixedFunction,
            'arguments' => array_map(static fn ($a) => ['value' => $a], $args),
            'executionContextId' => $ctx,
            'awaitPromise' => false,
            'returnByValue' => true,
            'userGesture' => true,
        ]), 10000);
        $ex = $resp->getResultData('exceptionDetails');
        if (is_array($ex)) {
            throw new \RuntimeException('JavaScriptの実行に失敗しました: '.(string) ($ex['exception']['description'] ?? $ex['text'] ?? '?'));
        }

        return $resp->getResultData('result')['value'] ?? null;
    }

    private function waitLoaded(): void
    {
        $end = microtime(true) + 8.0;
        $this->pump(0.4);
        while (microtime(true) < $end) {
            try {
                if ($this->isolated('function () { return document.readyState; }', []) === 'complete') {
                    break;
                }
            } catch (\Throwable) {
            }
            $this->pump(0.1);
        }
        $this->assertClean();
    }

    public function openUrl(string $page): void
    {
        $url = $this->pageUrls[$page] ?? throw new \RuntimeException("画面の記録にない画面です: {$page}");
        $p = $this->ensure();
        try {
            $p->navigate($url)->waitForNavigation(Page::LOAD, 15000);
        } catch (\Throwable $e) {
            $this->assertClean(); // 遮断・新しいウィンドウが原因なら、そちらの例外にする
            throw $e;
        }
        $this->assertClean();
        $now = $p->getCurrentUrl();
        if (! $this->policy->allows((string) $now)) {
            throw new \RuntimeException('許可していない接続先の画面が開いています: '.$now);
        }
    }

    public function currentPage(): string
    {
        $now = (string) $this->ensure()->getCurrentUrl();
        $now = (string) preg_replace('/#.*\z/', '', $now);
        foreach ($this->pageUrls as $name => $url) {
            if ($url === $now) {
                return $name;
            }
        }

        return '(不明: '.$now.')';
    }

    public function readValue(string $element): string
    {
        return (string) $this->call(
            'function (sel) { var e = document.querySelector(sel); if (!e) { throw new Error("要素がありません"); } return ("value" in e && e.value !== undefined && e.tagName !== "BUTTON" && e.tagName !== "LI") ? String(e.value) : String(e.textContent || ""); }',
            [$this->selector($element)],
        );
    }

    public function typeText(string $element, string $value): void
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new \RuntimeException('改行・タブ・制御文字は入力しません（A-2）');
        }
        $this->call('function (sel) { var e = document.querySelector(sel); if (!e) { throw new Error("要素がありません"); } e.focus(); if (e.select) { e.select(); } return true; }', [$this->selector($element)]);
        $this->ensure()->keyboard()->typeText($value);
        $this->assertClean();
    }

    public function clearText(string $element): void
    {
        $this->call('function (sel) { var e = document.querySelector(sel); if (!e) { throw new Error("要素がありません"); } e.value = ""; e.dispatchEvent(new Event("input", {bubbles: true})); return true; }', [$this->selector($element)]);
    }

    public function textVisible(string $text): bool
    {
        return (bool) $this->call('function (t) { return !!document.body && document.body.innerText.indexOf(t) >= 0; }', [$text]);
    }

    public function elementExists(string $element): bool
    {
        return (bool) $this->call('function (sel) { return document.querySelector(sel) !== null; }', [$this->selector($element)]);
    }

    public function pendingDialog(): ?string
    {
        $this->pump(0.05);
        $d = $this->dialog;
        $this->dialog = null;

        return $d;
    }

    public function resetToStart(): void
    {
        $this->openUrl('start');
    }

    public function performIrreversible(Redeemed $proof, array $rowValues): void
    {
        $this->ensure();
        $this->assertClean(); // 取り消せない操作の直前に、溜まった異常（遅れて開いたウィンドウ・遮断した通信）がないかを確かめる
        $this->guard->assertRedeemed($proof); // 操作の瞬間に、自分で持つ Guard で、証明を照合し直す（使い捨て）
        $step = $proof->permit->step();       // 検査した時点の手順。呼び出し側は、対象を指定できない
        $target = (string) ($step->target ?? '');
        switch ($step->action ?? null) {
            case 'click':
                $this->call('function (sel) { var e = document.querySelector(sel); if (!e) { throw new Error("要素がありません"); } e.click(); return true; }', [$this->selector($target)]);
                $this->waitLoaded();

                return;
            case 'type_text':
                $value = isset($step->column) ? ($rowValues[$step->column] ?? null) : ($step->value ?? null);
                if (! is_string($value)) {
                    throw new \RuntimeException('入力する値がありません');
                }
                $this->typeText($target, $value);

                return;
            case 'open_url':
                $this->openUrl($target);

                return;
        }
        throw new \RuntimeException('この操作は、まだ未対応です: '.($step->action ?? '?'));
    }

    public function close(): void
    {
        $this->pump(0.05);
        $unseen = [...$this->violations, ...array_map(static fn (string $t): string => '新しいウィンドウ:'.$t, $this->newTargets)];
        $this->unseenAtClose = $unseen;
        try {
            $this->browser?->close();
        } catch (\Throwable) {
        }
        $this->browser = null;
        $this->page = null;
        // 使い捨てのプロファイルを、破棄する（SEC-3）。置き場の中にあるものだけを消す
        $dir = $this->profileDir;
        $this->profileDir = null;
        if ($dir !== null && str_starts_with($dir, rtrim($this->profileRoot, '/').'/profile-') && is_dir($dir)) {
            self::removeDir($dir);
            $this->onProfile && ($this->onProfile)('removed', $dir);
        }
    }

    /** 起動したChromeのバージョン（例：HeadlessChrome/153.0.8010.12）。起動前は null */
    public function chromeVersion(): ?string
    {
        return $this->chromeVersion;
    }

    /** @return list<string> close() の時点で、確かめていなかった異常。呼び出し側が、記録に残せる */
    public function unseenAtClose(): array
    {
        return $this->unseenAtClose;
    }

    public function profileDir(): ?string
    {
        return $this->profileDir;
    }

    private static function removeDir(string $d): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($d, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isLink() || ! $f->isDir() ? @unlink($f->getPathname()) : @rmdir($f->getPathname());
        }
        @rmdir($d);
    }
}
