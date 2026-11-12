<?php

declare(strict_types=1);

namespace Src\Guard;

use Src\Plan\CanonicalJson;
use Src\Plan\PlanHasher;
use Src\Plan\PlanValidator;
use Src\Plan\StrictJson;
use Src\Policy\DestinationLimits;
use Src\Policy\OperationPolicy;
use Src\State\Flags;
use Src\State\Ledger;
use Src\State\StepStateStore;

/**
 * 取り消せない操作を実行する、実行エンジンの中の「1か所」の関数（仕様12-1）。
 * 接続先ごとの部品（GuardedAction）は、この関数を通さずに取り消せない操作をできない。
 *
 * 実行前に必ず確認する。1つでも満たさなければ、実行せず停止し、理由を記録する（SEC-13）。
 * 最後に「処理中」を保存する。保存に失敗したら、実行しない。
 * 計画は、最初に深いコピーを取り、そのコピーだけを検査と実行に使う（照合と実行の間の差し替えを防ぐ）。
 * 呼び出し側の自己申告に頼らない：版・許可リスト・行の順番・件数・ドライランは、実行の登録（RunRegistrar）の記録から読む。
 */
final class IrreversibleGuard
{
    /** @var array<string,true> 発行した許可の合言葉（使い捨て） */
    private array $issued = [];
    /** @var array<string,Permit> 照合済みの証明の token（使い捨て） */
    private array $redeemed = [];

    public function __construct(
        private readonly Ledger $ledger,
        private readonly StepStateStore $states,
        private readonly Flags $flags,
        private readonly ApprovalChecker $approvals,
    ) {
    }

    public function run(GuardRequest $r, GuardedAction $action): GuardOutcome
    {
        try {
            $mine = $action->belongsTo($this);
        } catch (\Error) {
            $mine = false; // 親のコンストラクタを呼ばない継承など
        }
        if (! $mine) {
            $this->logRefusal($r, ['この操作は、別のGuardに結び付いています']);

            return GuardOutcome::refused(['この操作は、別のGuardに結び付いています']);
        }
        $permit = null;
        $reasons = $this->check($r, $permit);
        if ($reasons !== [] || $permit === null) {
            $this->logRefusal($r, $reasons);

            return GuardOutcome::refused($reasons);
        }

        // ここから先は、「処理中」を書いたあと。異常は、すべて「要確認」にする（10-1）
        $confirmed = false;
        $effect = EffectId::of($permit->step()); // 検査した時点の手順から決める（実行中に、計画の元のオブジェクトが書き換わっても、変わらない）
        try {
            $confirmed = $action->perform($permit) === true;
        } catch (\Throwable) {
            $confirmed = false;
        }

        try {
            $confirmed
                ? $this->states->markSucceeded($r->businessKey, $effect)
                : $this->states->markNeedsReview($r->businessKey, $effect);
        } catch (\Throwable $e) {
            // 実行後に状態を保存できなかった。「処理中」のまま残る（次の起動時に「要確認」になる。二重実行にはならない）
            $this->logPostExecFailure($r, $effect, '実行後の状態を保存できませんでした: '.$e->getMessage());

            return GuardOutcome::executedButUnsaved();
        }

        return GuardOutcome::executed($confirmed);
    }

    /**
     * GuardedAction::perform が呼ぶ。許可の合言葉（使い捨て）と、保存先の状態（「処理中」で、実行ID・計画ハッシュが一致）を
     * 照合し直し、使い捨ての証明（Redeemed）を返す。
     */
    public function redeem(Permit $p): Redeemed
    {
        if (! isset($this->issued[$p->nonce])) {
            throw new \LogicException('この許可は、Guardが発行したものではないか、使用済みです');
        }
        unset($this->issued[$p->nonce]);
        $this->assertProcessing($p);
        $token = bin2hex(random_bytes(16));
        $this->redeemed[$token] = $p;

        return Redeemed::mint($this, $p, $token);
    }

    /**
     * 本当に取り消せない操作をする瞬間に、操作の側が呼ぶ（GuardedAction::effect が呼ぶ）。証明は、1回だけ使える。
     * 証明の token と、保存先の状態を、もう一度照合する。
     */
    public function assertRedeemed(Redeemed $proof): void
    {
        $p = $this->redeemed[$proof->token] ?? null;
        if ($p === null || $p !== $proof->permit) {
            throw new \LogicException('この証明は、Guardが発行したものではないか、使用済みです');
        }
        unset($this->redeemed[$proof->token]);
        $this->assertProcessing($p);
    }

    private function assertProcessing(Permit $p): void
    {
        $cur = $this->states->current($p->businessKey, EffectId::of($p->step()));
        if ($cur === null || $cur['state'] !== StepStateStore::PROCESSING || $cur['run_id'] !== $p->runId || $cur['plan_hash'] !== $p->planHash) {
            throw new \LogicException('保存先の状態が、許可と一致しません');
        }
    }

    /**
     * @return list<string> 拒否の理由。空なら合格し、$permit に許可オブジェクトが入る（「処理中」も保存済み）
     */
    private function check(GuardRequest $r, ?Permit &$permit): array
    {
        $reasons = [];

        // 0. 計画の深いコピー（以降は、コピーだけを使う）
        try {
            $plan = StrictJson::decode(CanonicalJson::encode($r->plan));
        } catch (\Throwable $e) {
            return ['計画をコピーできません: '.$e->getMessage()];
        }
        if (! $plan instanceof \stdClass) {
            return ['計画の形式が不正です'];
        }

        // 1. 停止
        $this->flags->isSet(Flags::KILL_SWITCH) && $reasons[] = 'キルスイッチが発動しています';
        $this->flags->isSet(Flags::STOP) && $reasons[] = '停止の旗が立っています';

        // 2. 計画の形式、計画ハッシュ、承認
        $formErrors = PlanValidator::validate($plan);
        if ($formErrors !== []) {
            return [...$reasons, '計画の形式が不正です: '.$formErrors[0]];
        }
        try {
            $hash = PlanHasher::hash($plan, $r->hashInputs);
        } catch (\InvalidArgumentException $e) {
            return [...$reasons, '計画ハッシュを計算できません: '.$e->getMessage()];
        }
        

        // 3. 実行の登録（ドライラン、接続先、各行のハッシュ）
        $run = $this->ledger->latest('run', $r->runId);
        if ($run === null) {
            return [...$reasons, '実行が登録されていません'];
        }
        ($run->plan_hash ?? null) !== $hash && $reasons[] = '実行の登録時の計画ハッシュと違います（計画・許可リストが変わった可能性）';
        // 最終承認は、計画ハッシュと「実行の登録のドライランID」に結び付く（9-2）。環境が変わったら、無効
        ! $this->approvals->hasFinalApproval($hash, (string) ($run->dry_run_id ?? '')) && $reasons[] = 'この計画ハッシュと、ドライランに結び付いた、最終承認がありません（計画が承認後に変わった、または環境が変わった可能性）';
        $dry = $this->approvals->findPassedDryRun($hash, (string) ($run->dry_run_id ?? ''));
        $dry['error'] !== null && $reasons[] = $dry['error'];

        // 4. 未確定
        PlanValidator::hasUnresolved($plan) && $reasons[] = '「未確定」が残っています';

        // 5. 手順の特定。取り消せない手順だけが、この関数を通る。
        //    取り消し可否は、承認済みの個別登録（計画ハッシュに含まれる hashInputs['registrations']）から計算する
        $step = self::findStep($plan->steps, $r->stepId);
        if ($step === null) {
            return [...$reasons, "手順IDが計画にありません: {$r->stepId}"];
        }
        try {
            $policy = OperationPolicy::fromRegistrations($r->hashInputs['registrations']);
        } catch (\InvalidArgumentException $e) {
            return [...$reasons, '個別登録の形式が不正です: '.$e->getMessage()];
        }
        if (! in_array($r->stepId, $policy->irreversibleStepIds($plan), true)) {
            $reasons[] = '取り消せる手順は、この関数を通しません';
        }
        ! isset($step->verify) && $reasons[] = '確認条件が定義されていません';

        // 6. 接続先（計画・ドライラン・実行の登録・実際の接続先が、すべて同じで、定数の範囲内）
        $destName = $step->destination->name ?? null;
        if (! is_string($destName) || ! DestinationLimits::isAllowed($destName)) {
            $reasons[] = '接続先が、ビルド時の定数の範囲にありません';
        } elseif ($destName !== $r->actualDestinationName || $destName !== ($run->destination_name ?? null)) {
            $reasons[] = '実際の接続先が、計画・実行の登録の接続先と違います';
        }
        $dry['destination'] !== null && $dry['destination'] !== $destName && $reasons[] = 'ドライランの接続先が、計画の接続先と違います';

        ($kp = BusinessKey::problem($r->businessKey)) !== null && $reasons[] = "業務キーが不正です（{$kp}）";

        // 7. 行（登録した入力データと照合）
        $index = $this->rowIndex($run, $r, $plan, $reasons);

        // 8. 読み返し照合（関数の中で、生の値と入力データの値を比べる。9-3）
        $this->checkReadback($step, $r, $reasons);

        // 9. 二重実行の防止（9-4、10-3）と、状態の表の突き合わせ
        $this->checkStates($r, $reasons);

        // 10. 上限（件数は、登録時に検査済み。ここでは計画との食い違いだけ見る）と、初回1件
        $l = $plan->limits;
        $total = is_array($run->rows ?? null) ? count($run->rows) : 0;
        (! isset($l->max_rows_per_run) || $total > $l->max_rows_per_run) && $reasons[] = '件数の上限を超えます（または、上限が計画にありません）';
        // 初回の1件：この実行で、すでに別の行に手を付けているなら、その初回の1件が成功し、確認されていること
        $gate = new FirstRowGate($this->ledger, $this->states);
        $rowsRec = is_array($run->rows ?? null) ? $run->rows : [];
        $irrIds = [];
        foreach ($policy->irreversibleStepIds($plan) as $sid) {
            ($st = self::findStep($plan->steps, $sid)) !== null && $irrIds[] = EffectId::of($st);
        }
        $irrIds = array_values(array_unique($irrIds)); // 状態の表の識別子（9-4）
        $others = array_values(array_diff($gate->executedKeys($r->runId, $rowsRec, $irrIds), [$r->businessKey]));
        if ($others !== [] && ! $gate->isConfirmed($r->runId, $rowsRec, $irrIds)) {
            $reasons[] = '初回の1件の確認が済んでいません（この実行で先に実行した行が、成功し、確認されるまで、次へ進めません）';
        }
        // 同じ計画の、ほかの実行が、初回の1件の確認待ちなら、新しい実行で、確認なしに1行ずつ進めない
        foreach ($this->ledger->latestPerSubject('run') as $other) {
            if ($other['subject'] === $r->runId) {
                continue;
            }
            $o = $other['payload'];
            if (($o->plan_hash ?? null) === $hash && is_array($o->rows ?? null)
                && $gate->awaitingConfirmation($other['subject'], $o->rows, $irrIds)) {
                $reasons[] = "別の実行（{$other['subject']}）の初回の1件が、確認されていません。先に確認してください";
                break;
            }
        }

        if ($reasons !== []) {
            return $reasons;
        }

        // 11. 最後に、「処理中」の保存（確認と同じトランザクションで、状態と停止の旗を確かめ直す）。失敗したら、実行しない
        try {
            $this->states->markProcessing($r->businessKey, EffectId::of($step), $hash, $r->runId, function (): void {
                if ($this->flags->isSet(Flags::KILL_SWITCH) || $this->flags->isSet(Flags::STOP)) {
                    throw new \LogicException('停止の旗が立ちました');
                }
            });
        } catch (\Throwable $e) {
            return ['「処理中」を保存できませんでした: '.$e->getMessage()];
        }
        $nonce = bin2hex(random_bytes(16));
        $this->issued[$nonce] = true;
        $permit = Permit::mint($this, $hash, $r->stepId, $r->businessKey, $r->runId, $nonce, CanonicalJson::encode($step));

        return [];
    }

    /**
     * @param list<string> $reasons
     */
    private function rowIndex(\stdClass $run, GuardRequest $r, \stdClass $plan, array &$reasons): ?int
    {
        $rows = $run->rows ?? null;
        if (! is_array($rows)) {
            $reasons[] = '実行の登録に、行の記録がありません';

            return null;
        }
        foreach ($rows as $i => $row) {
            if (($row->business_key ?? null) === $r->businessKey) {
                if (($row->values_hash ?? null) !== RunRegistrar::rowHash($r->rowValues)) {
                    $reasons[] = '行の値が、登録した入力データと違います';
                }
                $keyCol = null;
                foreach ($plan->input->columns as $c) {
                    ($c->role ?? null) === 'business_key' && $keyCol = $c->name;
                }
                ($keyCol === null || ($r->rowValues[$keyCol] ?? null) !== $r->businessKey) && $reasons[] = '業務キーが、この行の業務キー列の値と一致しません';

                return $i;
            }
        }
        $reasons[] = 'この業務キーは、登録した入力データにありません';

        return null;
    }

    /** @param list<string> $reasons */
    private function checkReadback(\stdClass $step, GuardRequest $r, array &$reasons): void
    {
        $items = $step->readback ?? null;
        if (! is_array($items) || $items === []) {
            $reasons[] = '読み返し照合の項目が、この手順に指定されていません（9-3）';

            return;
        }
        foreach (self::uncoveredColumns($r->plan, $step) as $col) {
            $reasons[] = "入力する列「{$col}」が、読み返し照合に含まれていません";
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

    /**
     * 画面に入力する列のうち、この手順の読み返し照合に含まれていないもの（入力した値の一部しか確かめないまま、実行しない。9-3）。
     * ドライランも、同じ関数で検査する。
     *
     * @return list<string>
     */
    public static function uncoveredColumns(\stdClass $plan, \stdClass $step): array
    {
        $covered = [];
        foreach ($step->readback ?? [] as $item) {
            isset($item->column) && is_string($item->column) && $covered[$item->column] = true;
        }

        return array_values(array_filter(self::typedColumns($plan->steps ?? [], (string) ($step->id ?? '')), static fn (string $c): bool => ! isset($covered[$c])));
    }

    /**
     * この手順より前に、画面に入力する列（type_text の column。計画の記述の順）。この手順自身と、後の手順は、含めない。
     *
     * @param array<mixed> $steps
     * @param bool         $reached 手順 $stopId に、すでに着いたか（参照渡し。着いたら、以降は集めない）
     * @return list<string>
     */
    private static function typedColumns(array $steps, string $stopId, bool &$reached = false): array
    {
        $out = [];
        foreach ($steps as $s) {
            if ($reached) {
                break;
            }
            if (! $s instanceof \stdClass) {
                continue;
            }
            if (($s->id ?? null) === $stopId) {
                $reached = true;
                break;
            }
            if (($s->action ?? null) === 'type_text' && isset($s->column) && is_string($s->column)) {
                $out[$s->column] = true;
            }
            foreach (['steps', 'then', 'else'] as $k) {
                if (isset($s->{$k}) && is_array($s->{$k})) {
                    foreach (self::typedColumns($s->{$k}, $stopId, $reached) as $c) {
                        $out[$c] = true;
                    }
                }
            }
        }

        return array_keys($out);
    }

    /** @param list<string> $reasons */
    private function checkStates(GuardRequest $r, array &$reasons): void
    {
        $effect = $this->effectOf($r);
        $cur = $this->states->current($r->businessKey, $effect);
        // 状態の表が、記録（ledger）の最新と食い違っていれば、拒否（表の書き換えの検知。LOC-8：完全ではない）
        $last = $this->ledger->latest('state', StepStateStore::subject($r->businessKey, $this->effectOf($r)));
        if (($cur['state'] ?? null) !== ($last->state ?? null)) {
            $reasons[] = '状態の表が、記録と食い違っています（書き換えの疑い）';
        }
        if (($cur['state'] ?? null) === StepStateStore::SUCCEEDED) {
            $reasons[] = 'この業務キー・手順は、すでに成功しています';
        }
        if (($un = $this->states->unresolvedFor($r->businessKey)) !== []) {
            $reasons[] = 'この業務キーに、未解消の「処理中」「要確認」があります: '.implode(', ', $un);
        }
    }

    /** 状態の表に使う識別子（接続先・操作・対象から、エンジンが計算する。AIが振る手順IDは、使わない。9-4） */
    private function effectOf(GuardRequest $r): string
    {
        $step = self::findStep($r->plan->steps ?? [], $r->stepId);

        return $step !== null ? EffectId::of($step) : $r->stepId;
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

    private function logPostExecFailure(GuardRequest $r, string $effect, string $message): void
    {
        try {
            $this->ledger->append('post_exec_failure', StepStateStore::subject($r->businessKey, $effect), (object) ['run_id' => $r->runId, 'message' => $message]);
        } catch (\Throwable) {
        }
    }

    /** @param list<string> $reasons */
    private function logRefusal(GuardRequest $r, array $reasons): void
    {
        try {
            $this->ledger->append('refusal', StepStateStore::subject($r->businessKey, $this->effectOf($r)), (object) ['run_id' => $r->runId, 'reasons' => $reasons]);
        } catch (\Throwable) {
            // 記録に失敗しても、実行しないことは変わらない
        }
    }
}
