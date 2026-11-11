<?php

declare(strict_types=1);

namespace Src\Guard;

use Src\State\Ledger;
use Src\State\StepStateStore;

/**
 * 「初回は1件だけ実行して止まる」（5章の9）の判定。
 * 「初回の1件」は、その実行（run_id）で、最初に実際に実行した行。前の実行で成功した行（run_id が違う）は、数えない。
 * 確認は、その行の成功「より後」に記録され、確認者（名前とOSユーザー）と対象の業務キーを持つものだけが有効。
 */
final class FirstRowGate
{
    public function __construct(private readonly Ledger $ledger, private readonly StepStateStore $states)
    {
    }

    /**
     * この実行で、すでに手を付けた行（処理中・成功・要確認。「失敗＝実行されていない」は除く）の業務キー。登録した順。
     *
     * @param array<mixed> $rows 実行の登録の行（business_key を持つオブジェクト）
     * @return list<string>
     */
    public function executedKeys(string $runId, array $rows, string $stepId): array
    {
        $out = [];
        foreach ($rows as $row) {
            $key = $row->business_key ?? null;
            if (! is_string($key)) {
                continue;
            }
            $cur = $this->states->current($key, $stepId);
            if ($cur !== null && $cur['run_id'] === $runId && $cur['state'] !== StepStateStore::FAILED) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /** @param array<mixed> $rows */
    public function isConfirmed(string $runId, array $rows, string $stepId): bool
    {
        $c = $this->ledger->latestWithSeq('first_row_confirmed', $runId);
        if ($c === null) {
            return false;
        }
        $p = $c['payload'];
        $key = $p->business_key ?? null;
        if (! is_string($key) || ! is_string($p->by ?? null) || $p->by === '' || ! is_string($p->os_user ?? null) || $p->os_user === '') {
            return false;
        }
        if (! in_array($key, array_map(static fn ($r) => $r->business_key ?? null, $rows), true)) {
            return false;
        }
        $st = $this->ledger->latestWithSeq('state', StepStateStore::subject($key, $stepId));

        return $st !== null
            && ($st['payload']->state ?? null) === StepStateStore::SUCCEEDED
            && ($st['payload']->run_id ?? null) === $runId
            && $this->states->get($key, $stepId) === StepStateStore::SUCCEEDED
            && $c['seq'] > $st['seq'];
    }
}
