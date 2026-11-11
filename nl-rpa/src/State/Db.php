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
        $pdo = new \PDO('sqlite:'.$path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');
        if ($path !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = FULL');
        }
        $db = new self($pdo);
        Schema::migrate($pdo);

        return $db;
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
            $this->depth++;
            try {
                return $fn();
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
