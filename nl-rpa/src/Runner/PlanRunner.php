<?php

declare(strict_types=1);

namespace Src\Runner;

use Src\Browser\BrowserPort;
use Src\Guard\ApprovalChecker;
use Src\Guard\GuardRequest;
use Src\Guard\IrreversibleGuard;
use Src\Guard\ReadbackComparator;
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
 * 実行の流れ（仕様3章、9-2、14-3「実行の流れ」）。実行中にAIは判断しない。計画に書かれた手順を、書かれたとおりに実行する。
 *  - dryRun：取り消せない操作の手前まで実行し、各行の終わりに画面を初期状態へ戻す。取り消せない操作は実行しない
 *  - run：取り消せない操作は、IrreversibleGuard（12-1）だけが通す。初回は1件だけ実行して止まる
 * 計画は、最初に深いコピーを取り、そのコピーだけを実行する。想定外のことが起きたら、すべて「停止」。
 */
final class PlanRunner
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly StepStateStore $states,
        private readonly Flags $flags,
        private readonly IrreversibleGuard $guard,
        private readonly ApprovalChecker $approvals,
        private readonly BrowserPort $browser,
        private readonly RunLock $lock,
    ) {
    }

    /** 初回の1件の確認（人が、結果を確認したあとに記録する。5章の9） */
    public function confirmFirstRow(string $runId, string $by, string $osUser): void
    {
        $this->ledger->append('first_row_confirmed', $runId, (object) ['by' => $by, 'os_user' => $osUser]);
    }

    /**
     * ドライラン。結果は、ledger に dry_run として記録する（計画ハッシュに結び付く）。
     *
     * @param array<string,mixed>        $hashInputs
     * @param list<array<string,string>> $rows       ドライランに使う入力データ（ダミーでよい）
     */
    public function dryRun(\stdClass $plan, array $hashInputs, array $rows, string $dryRunId): RunReport
    {
        if (! $this->lock->acquire()) {
            return $this->refuse('別の実行が動いています（二重起動の防止）');
        }
        try {
            $ctx = $this->prepare($plan, $hashInputs, 'dry');
            if (is_string($ctx)) {
                return $this->refuse($ctx);
            }
            if (! $this->approvals->hasPlanApproval($ctx->hash)) {
                return $this->refuse('この計画ハッシュの承認がありません');
            }
            if ($rows === [] || $dryRunId === '') {
                return $this->refuse('ドライランに使う入力データか、ドライランIDがありません');
            }
            $report = $this->execute($ctx, $rows, null);
            $this->ledger->append('dry_run', $ctx->hash, (object) [
                'dry_run_id' => $dryRunId,
                'result' => $report->status === RunReport::COMPLETED ? 'passed' : 'failed',
                'environment' => 'stub',
                'destination_name' => $this->browser->destinationName(),
                'reasons' => $report->reasons,
            ]);

            return $report;
        } finally {
            $this->lock->release();
        }
    }

    /**
     * 本番実行（1aでは、スタブ相手。9-2により、ドライラン扱い）。実行の登録（RunRegistrar）が済んでいること。
     *
     * @param array<string,mixed>        $hashInputs
     * @param list<array<string,string>> $rows       登録した入力データと同じ内容（Guardが、登録時のハッシュと照合する）
     */
    public function run(\stdClass $plan, array $hashInputs, string $runId, array $rows): RunReport
    {
        if (! $this->lock->acquire()) {
            return $this->refuse('別の実行が動いています（二重起動の防止）');
        }
        try {
            $ctx = $this->prepare($plan, $hashInputs, 'real');
            if (is_string($ctx)) {
                return $this->refuse($ctx);
            }
            $run = $this->ledger->latest('run', $runId);
            if ($run === null) {
                return $this->refuse('実行が登録されていません');
            }
            if (($run->plan_hash ?? null) !== $ctx->hash) {
                return $this->refuse('実行の登録時の計画ハッシュと違います（計画・許可リストが変わった可能性）');
            }
            $ctx->runId = $runId;
            $ctx->registered = is_array($run->rows ?? null) ? $run->rows : [];

            return $this->execute($ctx, $rows, $run);
        } finally {
            $this->lock->release();
        }
    }

    private function refuse(string $reason): RunReport
    {
        $r = new RunReport(RunReport::REFUSED_TO_START);
        $r->reasons[] = $reason;

        return $r;
    }

    /** @return \stdClass|string 準備できた文脈。できなければ理由 */
    private function prepare(\stdClass $plan, array $hashInputs, string $mode): \stdClass|string
    {
        try {
            $copy = StrictJson::decode(CanonicalJson::encode($plan)); // 計画は、メモリに1回だけ読み込み、そのコピーだけを実行する
            $errors = $copy instanceof \stdClass ? PlanValidator::validate($copy) : ['計画の形式が不正です'];
            if ($errors !== []) {
                return '計画の形式が不正です: '.$errors[0];
            }
            /** @var \stdClass $copy */
            $hash = PlanHasher::hash($copy, $hashInputs);
            $policy = OperationPolicy::fromRegistrations($hashInputs['registrations']);
        } catch (\Throwable $e) {
            return '計画を準備できません: '.$e->getMessage();
        }
        if (PlanValidator::hasUnresolved($copy)) {
            return '「未確定」が残っています';
        }
        if (! DestinationLimits::isAllowed($this->browser->destinationName())) {
            return '接続先が、ビルド時の定数の範囲にありません';
        }
        $ctx = new \stdClass();
        $ctx->mode = $mode;
        $ctx->plan = $copy;
        $ctx->hashInputs = $hashInputs;
        $ctx->hash = $hash;
        $ctx->irreversible = $policy->irreversibleStepIds($copy);
        $ctx->runId = '';
        $ctx->registered = [];
        $keyCol = null;
        foreach ($copy->input->columns as $c) {
            ($c->role ?? null) === 'business_key' && $keyCol = $c->name;
        }
        $ctx->keyCol = $keyCol;

        return $ctx;
    }

    /**
     * @param list<array<string,string>> $rows
     */
    private function execute(\stdClass $ctx, array $rows, ?\stdClass $run): RunReport
    {
        $report = new RunReport();
        $rowIndex = 0;
        foreach ($ctx->plan->steps as $top) {
            if (($top->action ?? null) !== 'for_each_row') {
                if (($why = $this->step($top, $ctx, [], '', $report)) !== null) {
                    return $report->halt($why);
                }
                continue;
            }
            foreach ($rows as $i => $values) {
                $key = (string) ($values[$ctx->keyCol] ?? '');
                if ($ctx->mode === 'real') {
                    // 登録した順番と件数に従う（呼び出し側の並びを信じない）
                    $regKey = $ctx->registered[$i]->business_key ?? null;
                    if ($regKey !== $key) {
                        return $report->halt('入力データの並びが、登録した実行と違います');
                    }
                    if ($this->allSucceeded($ctx, $key, $top)) {
                        $report->rows[] = ['business_key' => $key, 'status' => 'skipped', 'reads' => []];
                        continue;
                    }
                    if ($i >= 1 && $this->ledger->latest('first_row_confirmed', $ctx->runId) === null) {
                        $report->status = RunReport::AWAITING_FIRST_ROW;
                        $report->reasons[] = '初回の1件の確認が済むまで、残りは実行しません';

                        return $report;
                    }
                }
                $reads = [];
                $rowReport = ['business_key' => $key, 'status' => 'done', 'reads' => &$reads];
                $stop = false;
                foreach ($top->steps as $s) {
                    if (($why = $this->step($s, $ctx, $values, $key, $report, $reads, $stop)) !== null) {
                        $rowReport['status'] = 'halted';
                        $report->rows[] = $rowReport;
                        $this->safeReset();

                        return $report->halt($why);
                    }
                    if ($stop) {
                        $rowReport['status'] = 'dry_run_stopped_before_irreversible';
                        break;
                    }
                }
                $this->safeReset(); // 各行の終わりに、開始画面へ戻る（7-1）
                $report->rows[] = $rowReport;
                if ($ctx->mode === 'real' && $i === 0 && $this->ledger->latest('first_row_confirmed', $ctx->runId) === null && count($rows) > 1) {
                    $report->status = RunReport::AWAITING_FIRST_ROW;
                    $report->reasons[] = '初回は1件だけ実行して止まります。結果を確認してから、残りを実行してください';

                    return $report;
                }
            }
        }

        return $report;
    }

    private function safeReset(): void
    {
        try {
            $this->browser->resetToStart();
        } catch (\Throwable) {
        }
    }

    /** その行の、取り消せない手順がすべて「成功」か */
    private function allSucceeded(\stdClass $ctx, string $key, \stdClass $loop): bool
    {
        $ids = array_values(array_filter($this->stepIds($loop->steps), static fn (string $id): bool => in_array($id, $ctx->irreversible, true)));
        if ($ids === []) {
            return false;
        }
        foreach ($ids as $id) {
            if ($this->states->get($key, $id) !== StepStateStore::SUCCEEDED) {
                return false;
            }
        }

        return true;
    }

    /** @param array<mixed> $steps @return list<string> */
    private function stepIds(array $steps): array
    {
        $ids = [];
        foreach ($steps as $s) {
            isset($s->id) && $ids[] = $s->id;
            foreach (['steps', 'then', 'else'] as $k) {
                isset($s->{$k}) && array_push($ids, ...$this->stepIds($s->{$k}));
            }
        }

        return $ids;
    }

    /**
     * 1つの手順を実行する。続けられなければ、停止の理由を返す。
     *
     * @param array<string,string> $values
     * @param array<string,string> $reads
     */
    private function step(\stdClass $s, \stdClass $ctx, array $values, string $key, RunReport $report, array &$reads = [], bool &$stop = false): ?string
    {
        // 各手順の前に、停止の旗を見る（14-3の4。ブラウザの待ちの間は、読めない）
        if ($this->flags->isSet(Flags::KILL_SWITCH)) {
            return 'キルスイッチが発動しています';
        }
        if ($this->flags->isSet(Flags::STOP)) {
            return '停止の旗が立っています';
        }
        try {
            if ($this->browser->pendingDialog() !== null) {
                return '想定外の確認ダイアログが開いています（on_dialog の自動対応は、1aでは未対応）';
            }
            $action = $s->action;
            switch ($action) {
                case 'on_dialog':
                    return null; // 宣言。1aでは、ダイアログの自動対応はしない（出たら停止）
                case 'if':
                    $verifier = new Verifier($this->browser);
                    $branch = $verifier->check($s->condition, $s, $values) ? $s->then : ($s->else ?? null);
                    if ($branch === null) {
                        return "条件に合わず、else がないため停止: {$s->id}"; // 書かれていない分岐は、すべて停止
                    }
                    foreach ($branch as $b) {
                        if (($why = $this->step($b, $ctx, $values, $key, $report, $reads, $stop)) !== null || $stop) {
                            return $why;
                        }
                    }

                    return null;
                case 'for_each_row':
                    return 'for_each_row は、入れ子にできません';
            }
            if (in_array($s->id, $ctx->irreversible, true)) {
                return $this->irreversible($s, $ctx, $values, $key, $stop);
            }

            return $this->reversible($s, $values, $reads);
        } catch (\Throwable $e) {
            return "手順 {$s->id} で例外: ".$e->getMessage();
        }
    }

    /** @param array<string,string> $values @param array<string,string> $reads */
    private function reversible(\stdClass $s, array $values, array &$reads): ?string
    {
        switch ($s->action) {
            case 'open_url':
                $this->browser->openUrl($s->target);
                break;
            case 'read_text':
                $reads[$s->target] = $this->browser->readValue($s->target);
                break;
            case 'type_text':
                $v = isset($s->column) ? ($values[$s->column] ?? null) : ($s->value ?? null);
                if (! is_string($v)) {
                    return "入力する値がありません: {$s->id}";
                }
                $this->browser->typeText($s->target, $v);
                break;
            case 'clear_text':
                $this->browser->clearText($s->target);
                break;
            case 'wait_for':
            case 'write_result':
                break; // 1aの偽の部品は、待たなくてよい。確認条件だけ判定する
            default:
                return "この操作は、1aでは未対応です: {$s->action}";
        }
        if (! (new Verifier($this->browser))->check($s->verify, $s, $values)) {
            return "確認条件を満たしません: {$s->id}";
        }

        return null;
    }

    /** @param array<string,string> $values */
    private function irreversible(\stdClass $s, \stdClass $ctx, array $values, string $key, bool &$stop): ?string
    {
        $raw = [];
        foreach ($s->readback ?? [] as $item) {
            $raw[$item->target] = $this->browser->readValue($item->target);
        }
        if ($ctx->mode === 'dry') {
            // ドライラン：取り消せない操作は実行しない。手前で、読み返し照合だけ確かめて、この行を終える
            foreach ($s->readback ?? [] as $item) {
                $expected = isset($item->column) ? ($values[$item->column] ?? '') : (string) $item->value;
                if (! ReadbackComparator::equal($expected, $raw[$item->target], $item->rule ?? null)) {
                    return "読み返しが一致しません（ドライラン）: {$item->target}";
                }
            }
            $stop = true;

            return null;
        }
        $verifier = new Verifier($this->browser);
        $outcome = $this->guard->run(
            new GuardRequest($ctx->plan, $ctx->hashInputs, $s->id, $key, $ctx->runId, $this->browser->destinationName(), $values, $raw),
            new BrowserIrreversibleAction($this->guard, $this->browser, $verifier, $values),
        );
        if (! $outcome->executed) {
            return "取り消せない操作を実行しませんでした: {$s->id}: ".implode(' / ', $outcome->reasons);
        }
        if (! $outcome->confirmed) {
            return "結果を確認できません（要確認）: {$s->id} 業務キー {$key}";
        }

        return null;
    }
}
