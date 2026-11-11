<?php

declare(strict_types=1);

namespace App\Support;

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

    public function __construct(?string $dataDir, string $repoRoot, private readonly string $stubDir, int $orgMaxRows)
    {
        $this->dataDir = self::checkedDataDir($dataDir, $repoRoot);
        $clock = static fn (): string => gmdate('Y-m-d\TH:i:s\Z');
        $this->db = Db::open($this->dataDir.'/nlrpa.sqlite');
        $this->ledger = new Ledger($this->db, $clock);
        $this->states = new StepStateStore($this->db, $this->ledger, $clock);
        $this->flags = new Flags($this->db, $this->ledger);
        $this->approvals = new ApprovalChecker($this->ledger);
        $this->guard = new IrreversibleGuard($this->ledger, $this->states, $this->flags, $this->approvals);
        $this->registrar = new RunRegistrar($this->ledger, $this->approvals, $orgMaxRows);
        $this->store = new AppStore($this->db, $clock);
    }

    /**
     * パスを正規化する。「//」「.」「..」を、文字列として整理し、存在する一番深い親は、シンボリックリンクも解決する。
     * 存在しないパスでも使える（作る前に、リポジトリの中かを判定するため）。相対パスは、null。
     */
    public static function resolvePath(string $path): ?string
    {
        if ($path === '' || $path[0] !== '/') {
            return null;
        }
        $parts = [];
        foreach (explode('/', $path) as $seg) {
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
            $real = @realpath('/'.implode('/', $parts));
            if ($real !== false) {
                return rtrim($real, '/').($rest === [] ? '' : '/'.implode('/', $rest));
            }
            array_unshift($rest, array_pop($parts));
        }

        return '/'.implode('/', $rest);
    }

    /** $path が、$dir の中（または同じ）か。どちらも resolvePath を通した値で比べる */
    public static function isInside(string $path, string $dir): bool
    {
        $p = self::resolvePath($path);
        $d = self::resolvePath($dir);

        return $p !== null && $d !== null && ($p === $d || str_starts_with($p.'/', rtrim($d, '/').'/'));
    }

    /** データの置き場を検査して、作る（なければ）。リポジトリの中や、相対パスは拒否（LOC-4）。作る前に、判定する */
    public static function checkedDataDir(?string $dir, string $repoRoot): string
    {
        if ($dir === null || $dir === '' || ! str_starts_with($dir, '/') || self::resolvePath($dir) === null) {
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

    public function runLock(): RunLock
    {
        return new RunLock($this->dataDir.'/run.lock');
    }

    public function newBrowser(): FakeBrowser
    {
        return new FakeBrowser($this->stubDir.'/order_system.json', $this->guard);
    }

    public function newRunner(FakeBrowser $browser): PlanRunner
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
