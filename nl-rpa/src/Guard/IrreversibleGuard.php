<?php

declare(strict_types=1);

namespace Src\Guard;

use Src\Plan\PlanHasher;
use Src\Plan\PlanValidator;
use Src\Policy\DestinationLimits;
use Src\Policy\OperationPolicy;
use Src\State\Flags;
use Src\State\Ledger;
use Src\State\StepStateStore;

/**
 * 取り消せない操作を実行する、実行エンジンの中の「1か所」の関数（仕様12-1）。
 * 接続先ごとの部品は、この関数を通さずに取り消せない操作をできない（Permit を作れるのは、ここだけ）。
 *
 * 実行前に必ず確認する。1つでも満たさなければ、実行せず停止し、理由を記録する（SEC-13）。
 * 最後に「処理中」を保存する。保存に失敗したら、実行しない。
 */
final class IrreversibleGuard
{
    /** @param list<string> $acceptedDryRunEnvironments 有効と認めるドライランの環境。1aは stub のみ（9-2。1bに入ったら、stubは無効） */
    public function __construct(
        private readonly Ledger $ledger,
        private readonly StepStateStore $states,
        private readonly Flags $flags,
        private readonly OperationPolicy $policy,
        private readonly array $acceptedDryRunEnvironments = ['stub'],
    ) {
    }

    public function run(GuardRequest $r, IrreversibleAction $action): GuardOutcome
    {
        $permit = null;
        $reasons = $this->check($r, $permit);
        if ($reasons !== [] || $permit === null) {
            $this->logRefusal($r, $reasons);

            return GuardOutcome::refused($reasons);
        }

        // ここから先は、「処理中」を書いたあと。異常は、すべて「要確認」にする（10-1）
        try {
            $confirmed = $action->perform($permit);
        } catch (\Throwable) {
            $this->states->markNeedsReview($r->businessKey, $r->stepId);

            return GuardOutcome::executed(false);
        }
        if ($confirmed === true) {
            $this->states->markSucceeded($r->businessKey, $r->stepId);

            return GuardOutcome::executed(true);
        }
        $this->states->markNeedsReview($r->businessKey, $r->stepId);

        return GuardOutcome::executed(false);
    }

    /**
     * @return list<string> 拒否の理由。空なら合格し、$permit に許可オブジェクトが入る（「処理中」も保存済み）
     */
    private function check(GuardRequest $r, ?Permit &$permit): array
    {
        $reasons = [];

        // 1. 停止
        $this->flags->isSet(Flags::KILL_SWITCH) && $reasons[] = 'キルスイッチが発動しています';
        $this->flags->isSet(Flags::STOP) && $reasons[] = '停止の旗が立っています';

        // 2. 計画の形式と、計画ハッシュ、承認
        $formErrors = PlanValidator::validate($r->plan);
        if ($formErrors !== []) {
            return [...$reasons, '計画の形式が不正です: '.$formErrors[0]];
        }
        try {
            $hash = PlanHasher::hash($r->plan, $r->hashInputs);
        } catch (\InvalidArgumentException $e) {
            return [...$reasons, '計画ハッシュを計算できません: '.$e->getMessage()];
        }
        $approval = $this->ledger->latest('approval', $hash);
        if ($approval === null || ($approval->stage ?? null) !== 'final') {
            $reasons[] = 'この計画ハッシュの最終承認がありません（計画が承認後に変わった可能性）';
        }

        // 3. 有効なドライラン
        $dry = null;
        foreach ($this->ledger->all('dry_run', $hash) as $d) {
            if (($d->dry_run_id ?? null) === $r->dryRunId) {
                $dry = $d;
            }
        }
        if ($dry === null || ($dry->result ?? null) !== 'passed') {
            $reasons[] = 'この計画ハッシュに結び付いた、合格したドライランがありません';
        } elseif (! in_array($dry->environment ?? null, $this->acceptedDryRunEnvironments, true)) {
            $reasons[] = 'ドライランの環境が、有効な範囲ではありません';
        }

        // 4. 未確定
        PlanValidator::hasUnresolved($r->plan) && $reasons[] = '「未確定」が残っています';

        // 5. 手順の特定。取り消せない手順だけが、この関数を通る
        $step = self::findStep($r->plan->steps, $r->stepId);
        if ($step === null) {
            return [...$reasons, "手順IDが計画にありません: {$r->stepId}"];
        }
        if (! in_array($r->stepId, $this->policy->irreversibleStepIds($r->plan), true)) {
            $reasons[] = '取り消せる手順は、この関数を通しません';
        }
        if (! isset($step->verify)) {
            $reasons[] = '確認条件が定義されていません';
        }

        // 6. 接続先
        $destName = $step->destination->name ?? null;
        if (! is_string($destName) || ! DestinationLimits::isAllowed($destName)) {
            $reasons[] = '接続先が、ビルド時の定数の範囲にありません';
        } elseif ($destName !== $r->actualDestinationName) {
            $reasons[] = '実際の接続先が、計画の接続先と違います';
        }
        if (isset($dry->destination_name) && $dry->destination_name !== $destName) {
            $reasons[] = 'ドライランの接続先が、計画の接続先と違います';
        }

        // 7. 読み返し照合（関数の中で、生の値と入力データの値を比べる。9-3）
        $this->checkReadback($step, $r, $reasons);

        // 8. 業務キー（二重実行の防止。9-4、10-3）
        if ($r->businessKey === '' || ! $this->isBusinessKeyOfRow($r)) {
            $reasons[] = '業務キーが、この行の業務キー列の値と一致しません';
        }
        if ($this->states->get($r->businessKey, $r->stepId) === StepStateStore::SUCCEEDED) {
            $reasons[] = 'この業務キー・手順は、すでに成功しています';
        }
        if (($un = $this->states->unresolvedFor($r->businessKey)) !== []) {
            $reasons[] = 'この業務キーに、未解消の「処理中」「要確認」があります: '.implode(', ', $un);
        }

        // 9. 上限と、初回1件
        $this->checkLimits($r, $reasons);
        if ($r->rowIndexInRun >= 1 && $this->ledger->latest('first_row_confirmed', $r->runId) === null) {
            $reasons[] = '初回の1件の確認が済んでいません';
        }

        if ($reasons !== []) {
            return $reasons;
        }

        // 10. 最後に、「処理中」の保存。失敗したら、実行しない
        try {
            $this->states->markProcessing($r->businessKey, $r->stepId, $hash, $r->runId);
        } catch (\Throwable $e) {
            return ['「処理中」を保存できませんでした: '.$e->getMessage()];
        }
        $permit = Permit::mint($this, $hash, $r->stepId, $r->businessKey, $r->runId);

        return [];
    }

    /** @param list<string> $reasons */
    private function checkReadback(\stdClass $step, GuardRequest $r, array &$reasons): void
    {
        $items = $step->readback ?? null;
        if (! is_array($items) || $items === []) {
            $reasons[] = '読み返し照合の項目が、この手順に指定されていません（9-3）';

            return;
        }
        foreach ($items as $item) {
            $target = $item->target;
            if (! array_key_exists($target, $r->readbackRaw) || ! is_string($r->readbackRaw[$target])) {
                $reasons[] = "読み返した値がありません: {$target}";
                continue;
            }
            if (isset($item->column)) {
                if (! array_key_exists($item->column, $r->rowValues) || ! is_string($r->rowValues[$item->column])) {
                    $reasons[] = "入力データに、この行の値がありません: {$item->column}";
                    continue;
                }
                $expected = $r->rowValues[$item->column];
            } else {
                $expected = (string) $item->value; // AIが補った値・固定値は、計画に書かれた値
            }
            if (! ReadbackComparator::equal($expected, $r->readbackRaw[$target], $item->rule ?? null)) {
                $reasons[] = "読み返した値が、入力データの値と一致しません: {$target}";
            }
        }
    }

    private function isBusinessKeyOfRow(GuardRequest $r): bool
    {
        foreach ($r->plan->input->columns as $c) {
            if (($c->role ?? null) === 'business_key') {
                return ($r->rowValues[$c->name] ?? null) === $r->businessKey;
            }
        }

        return false;
    }

    /** @param list<string> $reasons */
    private function checkLimits(GuardRequest $r, array &$reasons): void
    {
        $l = $r->plan->limits;
        if (! isset($l->max_rows_per_run)) {
            $reasons[] = '件数の上限が、計画にありません（R9）';
        } elseif ($r->rowIndexInRun + 1 > $l->max_rows_per_run) {
            $reasons[] = '件数の上限を超えます';
        }
        if (isset($l->max_quantity_per_run) && $r->quantityTotalInRun > $l->max_quantity_per_run) {
            $reasons[] = '数量の上限を超えます';
        }
        if (isset($l->max_amount_per_run) && $r->amountTotalInRun > $l->max_amount_per_run) {
            $reasons[] = '金額の上限を超えます';
        }
    }

    /** @param array<mixed> $steps */
    private static function findStep(array $steps, string $id): ?\stdClass
    {
        foreach ($steps as $s) {
            if (($s->id ?? null) === $id) {
                return $s;
            }
            foreach (['steps', 'then', 'else'] as $k) {
                if (isset($s->{$k}) && ($f = self::findStep($s->{$k}, $id)) !== null) {
                    return $f;
                }
            }
        }

        return null;
    }

    /** @param list<string> $reasons */
    private function logRefusal(GuardRequest $r, array $reasons): void
    {
        try {
            $this->ledger->append('refusal', $r->businessKey."\n".$r->stepId, (object) ['run_id' => $r->runId, 'reasons' => $reasons]);
        } catch (\Throwable) {
            // 記録に失敗しても、実行しないことは変わらない
        }
    }
}
