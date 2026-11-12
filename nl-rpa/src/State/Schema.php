<?php

declare(strict_types=1);

namespace Src\State;

final class Schema
{
    public static function migrate(\PDO $pdo): void
    {
        // 記録のチェーン（追記のみ）。UPDATE・DELETE は、トリガーで拒否する（SEC-2。ただしローカルでは、DBファイルを直接書き換えられる。LOC-8）
        $pdo->exec('CREATE TABLE IF NOT EXISTS ledger (
            seq INTEGER PRIMARY KEY,
            kind TEXT NOT NULL,
            subject TEXT NOT NULL,
            payload TEXT NOT NULL,
            at TEXT NOT NULL,
            prev_hash TEXT NOT NULL,
            hash TEXT NOT NULL UNIQUE
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS ledger_kind_subject ON ledger(kind, subject)');
        $pdo->exec("CREATE TRIGGER IF NOT EXISTS ledger_no_update BEFORE UPDATE ON ledger BEGIN SELECT RAISE(ABORT, 'ledgerは追記のみです'); END");
        $pdo->exec("CREATE TRIGGER IF NOT EXISTS ledger_no_delete BEFORE DELETE ON ledger BEGIN SELECT RAISE(ABORT, 'ledgerは追記のみです'); END");

        // 行 × 取り消せない手順 の状態（10章）
        $pdo->exec("CREATE TABLE IF NOT EXISTS step_states (
            business_key TEXT NOT NULL,
            step_id TEXT NOT NULL,
            state TEXT NOT NULL CHECK (state IN ('processing','succeeded','failed','needs_review')),
            plan_hash TEXT NOT NULL,
            run_id TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            PRIMARY KEY (business_key, step_id)
        )");

        $pdo->exec("CREATE TRIGGER IF NOT EXISTS step_states_no_delete BEFORE DELETE ON step_states BEGIN SELECT RAISE(ABORT, 'step_statesは削除できません'); END");

        // 停止の旗（キルスイッチ、通常停止）。変更は、ledgerにも記録する
        $pdo->exec('CREATE TABLE IF NOT EXISTS flags (
            name TEXT PRIMARY KEY,
            value INTEGER NOT NULL
        )');
        $pdo->exec("CREATE TRIGGER IF NOT EXISTS flags_no_delete BEFORE DELETE ON flags BEGIN SELECT RAISE(ABORT, 'flagsは削除できません'); END");

        // 画面とワーカーが使う表（計画、入力データ、実行、実行要求、ワーカー）。承認・状態の正本は、上の ledger と step_states
        $pdo->exec('CREATE TABLE IF NOT EXISTS plans (
            hash TEXT PRIMARY KEY,
            plan_json TEXT NOT NULL,
            inputs_json TEXT NOT NULL,
            scenario TEXT NOT NULL,
            created_by TEXT NOT NULL,
            os_user TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS input_sets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sha256 TEXT NOT NULL,
            rows_json TEXT NOT NULL,
            created_by TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS runs (
            run_id TEXT PRIMARY KEY,
            plan_hash TEXT NOT NULL REFERENCES plans(hash),
            input_set_id INTEGER NOT NULL REFERENCES input_sets(id),
            dry_run_id TEXT NOT NULL,
            created_by TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
        $pdo->exec("CREATE TABLE IF NOT EXISTS requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kind TEXT NOT NULL CHECK (kind IN ('dry_run','run')),
            plan_hash TEXT NOT NULL REFERENCES plans(hash),
            input_set_id INTEGER REFERENCES input_sets(id),
            run_id TEXT,
            dry_run_id TEXT,
            status TEXT NOT NULL CHECK (status IN ('queued','running','done')),
            result_json TEXT,
            created_by TEXT NOT NULL,
            created_at TEXT NOT NULL,
            started_at TEXT,
            finished_at TEXT
        )");
        $pdo->exec('CREATE TABLE IF NOT EXISTS workers (
            pid INTEGER PRIMARY KEY,
            request_id INTEGER NOT NULL,
            started_at TEXT NOT NULL,
            proc_start TEXT
        )');
        // 既存のDBには、列がないので、足す（プロセスの開始時刻。PIDの再利用で、別のプロセスを終了しないため）
        $cols = array_column($pdo->query('PRAGMA table_info(workers)')->fetchAll(), 'name');
        in_array('proc_start', $cols, true) || self::addColumn($pdo, 'workers', 'proc_start TEXT');

        // ブラウザの使い捨てプロファイル（異常終了のあと、残ったプロファイルと、孤児になったChromeを、回収するための記録）
        $pdo->exec('CREATE TABLE IF NOT EXISTS browser_profiles (
            dir TEXT PRIMARY KEY,
            worker_pid INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            proc_start TEXT
        )');
        $pcols = array_column($pdo->query('PRAGMA table_info(browser_profiles)')->fetchAll(), 'name');
        in_array('proc_start', $pcols, true) || self::addColumn($pdo, 'browser_profiles', 'proc_start TEXT');
    }

    /** 列を足す。別のプロセスが、同時に足していた場合（duplicate column）は、無視する */
    private static function addColumn(\PDO $pdo, string $table, string $definition): void
    {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$definition}");
        } catch (\PDOException $e) {
            if (! str_contains($e->getMessage(), 'duplicate column name')) {
                throw $e;
            }
        }
    }
}
