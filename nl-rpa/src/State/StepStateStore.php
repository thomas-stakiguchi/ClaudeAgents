<?php

declare(strict_types=1);

namespace Src\State;

/**
 * 「行（業務キー） × 取り消せない手順（手順ID）」の状態（仕様10章）。
 * 遷移は、ここに書いたものだけ。すべて、ledger への記録と同じトランザクションで行う。
 *
 *   （なし/失敗）→ 処理中 → 成功 ／ 要確認
 *   要確認・処理中 → 人の判定 → 成功（実行済み）／ 失敗（未実行）
 */
final class StepStateStore
{
    public const PROCESSING = 'processing';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const NEEDS_REVIEW = 'needs_review';

    /** @param \Closure():string $clock */
    public function __construct(private readonly Db $db, private readonly Ledger $ledger, private readonly \Closure $clock)
    {
    }

    public function get(string $businessKey, string $stepId): ?string
    {
        $st = $this->db->pdo->prepare('SELECT state FROM step_states WHERE business_key = ? AND step_id = ?');
        $st->execute([$businessKey, $stepId]);
        $r = $st->fetch();

        return $r ? (string) $r['state'] : null;
    }

    /** 同じ業務キーに、未解消（処理中・要確認）がある手順IDの一覧（別の版・別のシナリオも含む。9-4、10-3） */
    public function unresolvedFor(string $businessKey): array
    {
        $st = $this->db->pdo->prepare("SELECT step_id FROM step_states WHERE business_key = ? AND state IN ('processing','needs_review') ORDER BY step_id");
        $st->execute([$businessKey]);

        return array_map(static fn (array $r): string => (string) $r['step_id'], $st->fetchAll());
    }

    /** 取り消せない操作の直前に書く。保存に失敗したら、例外（＝取り消せない操作は実行しない。10-1） */
    public function markProcessing(string $businessKey, string $stepId, string $planHash, string $runId): void
    {
        $this->db->immediate(function () use ($businessKey, $stepId, $planHash, $runId): void {
            $cur = $this->get($businessKey, $stepId);
            if ($cur !== null && $cur !== self::FAILED) {
                throw new \LogicException("状態が {$cur} のため、処理中にできません");
            }
            $this->write($businessKey, $stepId, self::PROCESSING, $planHash, $runId);
        });
    }

    /** 処理中 → 成功 */
    public function markSucceeded(string $businessKey, string $stepId): void
    {
        $this->transition($businessKey, $stepId, [self::PROCESSING], self::SUCCEEDED);
    }

    /** 処理中 → 要確認（「処理中」を書いた後の異常は、すべてこれ。10-1） */
    public function markNeedsReview(string $businessKey, string $stepId): void
    {
        $this->transition($businessKey, $stepId, [self::PROCESSING], self::NEEDS_REVIEW);
    }

    /** 人の判定（10-2）。「実行済み」→ 成功、「未実行」→ 失敗（再実行の対象） */
    public function resolve(string $businessKey, string $stepId, bool $executed, string $judge): void
    {
        $this->transition($businessKey, $stepId, [self::NEEDS_REVIEW, self::PROCESSING], $executed ? self::SUCCEEDED : self::FAILED, $judge);
    }

    /** 異常終了のあと、次の起動時に、「処理中」を「要確認」に変える（14-3の5）。変えた件数を返す */
    public function recoverAfterCrash(): int
    {
        return $this->db->immediate(function (): int {
            $rows = $this->db->pdo->query("SELECT business_key, step_id, plan_hash, run_id FROM step_states WHERE state = 'processing'")->fetchAll();
            foreach ($rows as $r) {
                $this->write($r['business_key'], $r['step_id'], self::NEEDS_REVIEW, $r['plan_hash'], $r['run_id'], 'crash_recovery');
            }

            return count($rows);
        });
    }

    /** @param list<string> $from */
    private function transition(string $businessKey, string $stepId, array $from, string $to, ?string $by = null): void
    {
        $this->db->immediate(function () use ($businessKey, $stepId, $from, $to, $by): void {
            $st = $this->db->pdo->prepare('SELECT state, plan_hash, run_id FROM step_states WHERE business_key = ? AND step_id = ?');
            $st->execute([$businessKey, $stepId]);
            $r = $st->fetch();
            if (! $r || ! in_array($r['state'], $from, true)) {
                throw new \LogicException('この状態からは、'.$to.' にできません（現在: '.($r['state'] ?? 'なし').'）');
            }
            $this->write($businessKey, $stepId, $to, $r['plan_hash'], $r['run_id'], $by);
        });
    }

    private function write(string $businessKey, string $stepId, string $state, string $planHash, string $runId, ?string $by = null): void
    {
        $st = $this->db->pdo->prepare('INSERT INTO step_states (business_key, step_id, state, plan_hash, run_id, updated_at) VALUES (?,?,?,?,?,?)
            ON CONFLICT(business_key, step_id) DO UPDATE SET state = excluded.state, plan_hash = excluded.plan_hash, run_id = excluded.run_id, updated_at = excluded.updated_at');
        $st->execute([$businessKey, $stepId, $state, $planHash, $runId, ($this->clock)()]);
        $this->ledger->append('state', $businessKey."\n".$stepId, (object) ['state' => $state, 'plan_hash' => $planHash, 'run_id' => $runId, 'by' => $by]);
    }
}
