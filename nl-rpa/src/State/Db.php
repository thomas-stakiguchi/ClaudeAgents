<?php

declare(strict_types=1);

namespace Src\State;

/**
 * SQLite（PDOを直接使う。仕様14-3）。WALモード、busy_timeout。
 * 書き込みは、BEGIN IMMEDIATE を明示して直列にする（14-3の7）。
 */
final class Db
{
    private int $depth = 0;

    private function __construct(public readonly \PDO $pdo)
    {
    }

    /** @param string $path ファイルのパス、または ':memory:' */
    public static function open(string $path): self
    {
        // 記録のファイルは、所有者だけが読み書きできる（0600）にする（SEC-9）。WALの補助ファイルも、同じ権限で作られる
        $oldUmask = $path === ':memory:' ? null : umask(0077);
        try {
            return self::openInner($path);
        } finally {
            $oldUmask !== null && umask($oldUmask);
            if ($path !== ':memory:' && is_file($path) && (fileperms($path) & 0077) !== 0) {
                @chmod($path, 0600); // 以前に、広い権限で作ったファイルも、絞る
            }
        }
    }

    private static function openInner(string $path): self
    {
        $pdo = new \PDO('sqlite:'.$path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        // 新しいファイルを、複数のプロセスが同時に開くと、WALへの切り替えや表の作成が、「database is locked」で失敗することがある
        // （busy_timeout が効かない場面がある）。短い間隔で、繰り返す。
        // 初期化の間だけ、1回あたりの待ちを短くして（0.3秒）、何度も試す。最悪でも、合計で約17秒。終わったら、通常の5秒に戻す
        $pdo->exec('PRAGMA busy_timeout = 300');
        self::retryWhileLocked(static function () use ($pdo, $path): void {
            if ($path !== ':memory:') {
                $pdo->exec('PRAGMA journal_mode = WAL');
                $pdo->exec('PRAGMA synchronous = FULL');
            }
            Schema::migrate($pdo);
        });
        $pdo->exec('PRAGMA busy_timeout = 5000');
        return new self($pdo);
    }

    /** @param callable():void $fn ロックされている間（最大40回。0.3秒の待ち＋0.03〜0.12秒の間隔。合計で、最悪でも約17秒）、繰り返す。ほかの失敗は、そのまま投げる */
    private static function retryWhileLocked(callable $fn): void
    {
        for ($i = 0;; $i++) {
            try {
                $fn();

                return;
            } catch (\PDOException $e) {
                $locked = str_contains($e->getMessage(), 'database is locked') || str_contains($e->getMessage(), 'database table is locked') || ($e->errorInfo[1] ?? 0) === 5 || ($e->errorInfo[1] ?? 0) === 6;
                if (! $locked || $i >= 40) {
                    throw $e;
                }
                usleep(random_int(30, 120) * 1000);
            }
        }
    }

    /**
     * BEGIN IMMEDIATE で直列化して実行する。入れ子の呼び出しは、外側の中で実行される。
     * 例外が出たら、ロールバックして、例外を再度投げる。
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function immediate(callable $fn): mixed
    {
        if ($this->depth > 0) {
            // 入れ子は、セーブポイントで包む。内側が失敗して外側が例外を握りつぶしても、内側の書き込みは残らない
            $sp = 'sp_'.$this->depth;
            $this->pdo->exec('SAVEPOINT '.$sp);
            $this->depth++;
            try {
                $r = $fn();
                $this->pdo->exec('RELEASE '.$sp);

                return $r;
            } catch (\Throwable $e) {
                try {
                    $this->pdo->exec('ROLLBACK TO '.$sp);
                    $this->pdo->exec('RELEASE '.$sp);
                } catch (\Throwable) {
                }
                throw $e;
            } finally {
                $this->depth--;
            }
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->depth = 1;
        try {
            $r = $fn();
            $this->pdo->exec('COMMIT');

            return $r;
        } catch (\Throwable $e) {
            // PDO::inTransaction() は、手で実行した BEGIN IMMEDIATE を認識しない（PHP 8.3.6で確認）。
            // 自分で管理している深さを信じて、必ずロールバックする。
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\Throwable) {
                // すでに終了している場合（SQLiteが自動でロールバックした場合など）
            }
            throw $e;
        } finally {
            $this->depth = 0;
        }
    }
}
