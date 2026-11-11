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
    }
}
