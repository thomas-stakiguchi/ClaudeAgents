<?php

declare(strict_types=1);

namespace Src\Guard;

use Src\State\Ledger;
use Src\State\StepStateStore;

/**
 * 「初回は1件だけ実行して止まる」（5章の9）の判定。
 * 「初回の1件」は、その実行（run_id）で、最初に実際に実行した行。前の実行で成功した行（run_id が違う）は、数えない。
 * 行の中の、取り消せない手順は、複数あってよい（条件分岐で、通らない手順があってもよい）。「実行した」とは、
 * どれか1つでも、この実行で手を付けた（処理中・成功・要確認。「失敗＝実行されていない」は除く）こと。
 * 確認は、その行の成功「より後」に記録され、確認者（名前とOSユーザー）と対象の業務キーを持つものだけが有効。
 */
final class FirstRowGate
{
    public function __construct(private readonly Ledger $ledger, private readonly StepStateStore $states)
    {
    }

    /**
     * この実行で、すでに手を付けた行の業務キー。登録した順。
     *
     * @param array<mixed>  $rows    実行の登録の行（business_key を持つオブジェクト）
     * @param list<string>  $stepIds 取り消せない手順のID
     * @return list<string>
     */
    public function executedKeys(string $runId, array $rows, array $stepIds): array
    {
        $out = [];
        foreach ($rows as $row) {
            $key = $row->business_key ?? null;
            if (is_string($key) && $this->touched($runId, $key, $stepIds) !== []) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /** @param list<string> $stepIds @return list<string> この実行で、その行に手を付けた手順ID */
    private function touched(string $runId, string $key, array $stepIds): array
    {
        $out = [];
        foreach ($stepIds as $sid) {
            $cur = $this->states->current($key, $sid);
            if ($cur !== null && $cur['run_id'] === $runId && $cur['state'] !== StepStateStore::FAILED) {
                $out[] = $sid;
            }
        }

        return $out;
    }

    /**
     * 確認が有効か。確認の対象の行が、この実行で手を付けた手順をすべて「成功」にしていて、確認が、そのどの成功よりも後であること。
     *
     * @param array<mixed> $rows
     * @param list<string> $stepIds
     */
    public function isConfirmed(string $runId, array $rows, array $stepIds): bool
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
        $touched = $this->touched($runId, $key, $stepIds);
        if ($touched === []) {
            return false; // この実行で、何も実行していない行は、確認の対象にならない
        }
        foreach ($touched as $sid) {
            $st = $this->ledger->latestWithSeq('state', StepStateStore::subject($key, $sid));
            if ($st === null
                || ($st['payload']->state ?? null) !== StepStateStore::SUCCEEDED
                || ($st['payload']->run_id ?? null) !== $runId
                || $this->states->get($key, $sid) !== StepStateStore::SUCCEEDED
                || $c['seq'] <= $st['seq']) {
                return false;
            }
        }

        return true;
    }

    /**
     * この実行が、「初回の1件を実行して、確認待ち」の状態か。手を付けた行があり、確認がなく、まだ手を付けていない行が残っている。
     * 単独の1行だけの実行や、全部終わった実行は、確認待ちではない。
     *
     * @param array<mixed> $rows
     * @param list<string> $stepIds
     */
    public function awaitingConfirmation(string $runId, array $rows, array $stepIds): bool
    {
        if ($this->executedKeys($runId, $rows, $stepIds) === [] || $this->isConfirmed($runId, $rows, $stepIds)) {
            return false;
        }
        foreach ($rows as $row) {
            $key = $row->business_key ?? null;
            if (! is_string($key)) {
                continue;
            }
            $anyOpen = false;
            foreach ($stepIds as $sid) {
                $cur = $this->states->current($key, $sid);
                if ($cur === null || $cur['state'] === StepStateStore::FAILED) {
                    $anyOpen = true;
                }
            }
            if ($anyOpen && $this->touched($runId, $key, $stepIds) === []) {
                return true; // まだ手を付けていない行が、残っている
            }
        }

        return false;
    }
}
