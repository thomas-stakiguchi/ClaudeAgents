<?php

declare(strict_types=1);

namespace Src\Runner;

use Src\Browser\BrowserPort;
use Src\Guard\ApprovalChecker;
use Src\Guard\GuardRequest;
use Src\Guard\IrreversibleGuard;
use Src\Guard\ReadbackComparator;
use Src\Guard\RunRegistrar;
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
    private readonly \Src\Guard\FirstRowGate $gate;

    public function __construct(
        private readonly Ledger $ledger,
        private readonly StepStateStore $states,
        private readonly Flags $flags,
        private readonly IrreversibleGuard $guard,
        private readonly ApprovalChecker $approvals,
        private readonly BrowserPort $browser,
        private readonly RunLock $lock,
    ) {
        $this->gate = new \Src\Guard\FirstRowGate($ledger, $states);
    }

    /** 初回の1件の確認（人が、結果を確認したあとに記録する。5章の9） */
    public function confirmFirstRow(string $runId, string $by, string $osUser): void
    {
        $run = $this->ledger->latest('run', $runId) ?? throw new \LogicException('実行が登録されていません');
        $err = self::recordFirstRowConfirmation($this->ledger, $this->states, $runId, (array) ($run->rows ?? []), $this->firstStepIds($runId), $by, $osUser);
        if ($err !== null) {
            throw new \LogicException($err);
        }
    }

    /** @return list<string> 登録済みの計画（ledgerのrun記録）から引けないため、状態の表に載っている、この実行の手順ID */
    private function firstStepIds(string $runId): array
    {
        return $this->states->stepIdsOfRun($runId);
    }

    /**
     * 初回の1件の確認を記録する（画面からも、同じ形。確認者の名前とOSのユーザー名の両方。LOC-3）。
     * 確認の対象は、この実行で最初に手を付けた行。その行の取り消せない手順が、すべて「成功」であること。
     *
     * @param array<mixed>  $rows    実行の登録の行
     * @param list<string>  $stepIds 取り消せない手順のID
     * @return string|null 記録できなければ、理由
     */
    public static function recordFirstRowConfirmation(Ledger $ledger, StepStateStore $states, string $runId, array $rows, array $stepIds, string $by, string $osUser): ?string
    {
        $gate = new \Src\Guard\FirstRowGate($ledger, $states);
        $first = null;
        foreach ($rows as $row) {
            foreach ($stepIds as $sid) {
                if (in_array($row->business_key ?? null, $gate->executedKeys($runId, [$row], $sid), true)) {
                    $first = $row->business_key;
                    break 2;
                }
            }
        }
        if (! is_string($first)) {
            return 'この実行で、まだ実行した行がありません';
        }
        foreach ($stepIds as $sid) {
            $cur = $states->current($first, $sid);
            if ($cur === null || $cur['run_id'] !== $runId || $cur['state'] !== StepStateStore::SUCCEEDED) {
                return "最初に実行した行（{$first}）の結果が、すべて「成功」になっていないため、確認できません";
            }
        }
        $ledger->append('first_row_confirmed', $runId, (object) ['by' => $by, 'os_user' => $osUser, 'business_key' => $first]);

        return null;
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
                'environment' => $this->browser->environmentName(),
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
            // 本番の条件（9-2）を、開始時に確かめる（取り消せる手順が実行されてから、Guardに止められるのを防ぐ）
            if (! $this->approvals->hasFinalApproval($ctx->hash)) {
                return $this->refuse('この計画ハッシュの最終承認がありません');
            }
            $dry = $this->approvals->findPassedDryRun($ctx->hash, (string) ($run->dry_run_id ?? ''));
            if ($dry['error'] !== null) {
                return $this->refuse($dry['error']);
            }
            if (($run->destination_name ?? null) !== $this->browser->destinationName() || $dry['destination'] !== $this->browser->destinationName()) {
                return $this->refuse('実行の登録・ドライランの接続先が、実際の接続先と違います');
            }
            $ctx->runId = $runId;
            $ctx->registered = is_array($run->rows ?? null) ? $run->rows : [];
            if (count($rows) !== count($ctx->registered)) {
                return $this->refuse('入力データの件数が、登録した件数と違います');
            }
            // ロックを取ったあとに、異常終了で残った「処理中」を「要確認」にする（14-3の5）
            $this->states->recoverAfterCrash();

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
        // すべての手順の接続先が、実際の接続先と一致すること（登録は接続先ごと。7-1-1）
        $dest = $this->browser->destinationName();
        $bad = $this->firstStepWhere($copy->steps, static fn (\stdClass $s): bool => isset($s->destination) && $s->destination->name !== $dest);
        if ($bad !== null) {
            return "手順 {$bad} の接続先が、実際の接続先（{$dest}）と違います";
        }
        // 1aの制約：OKする確認ダイアログの自動対応は未対応。for_each_row の外の取り消せない手順も、未対応
        $okDialog = $this->firstStepWhere($copy->steps, static fn (\stdClass $s): bool => ($s->action ?? null) === 'on_dialog' && ($s->response ?? null) !== 'stop');
        if ($okDialog !== null) {
            return "1aでは、OKする確認ダイアログ（on_dialog）は未対応です: {$okDialog}";
        }
        $outside = [];
        foreach ($copy->steps as $top) {
            if (($top->action ?? null) !== 'for_each_row') {
                array_push($outside, ...$this->stepIds([$top]));
            }
        }
        $outsideIrr = array_values(array_intersect($outside, $policy->irreversibleStepIds($copy)));
        if ($outsideIrr !== []) {
            return '1aでは、for_each_row の外の取り消せない手順は未対応です: '.$outsideIrr[0];
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
            $loopIrr = array_values(array_filter($this->stepIds($top->steps), static fn (string $id): bool => in_array($id, $ctx->irreversible, true)));
            foreach ($rows as $i => $values) {
                $wasFirst = false;
                $key = (string) ($values[$ctx->keyCol] ?? '');
                if ($ctx->mode === 'real') {
                    // 登録した順番と件数に従う（呼び出し側の並びを信じない）
                    $regKey = $ctx->registered[$i]->business_key ?? null;
                    if ($regKey !== $key) {
                        return $report->halt('入力データの並びが、登録した実行と違います');
                    }
                    // 取り消せる手順が使う値も、実行の前に、登録時のハッシュと照合する
                    if (($ctx->registered[$i]->values_hash ?? null) !== RunRegistrar::rowHash($values)) {
                        return $report->halt("行の値が、登録した入力データと違います: {$key}");
                    }
                    if ($this->allSucceeded($ctx, $key, $top)) {
                        $report->rows[] = ['business_key' => $key, 'status' => 'skipped', 'reads' => []];
                        continue;
                    }
                    // 初回の1件：この実行で、先に手を付けた行があるなら、その行が成功し、確認されるまで、次へ進まない
                    $others = array_values(array_diff($this->gate->executedKeys($ctx->runId, $ctx->registered, $loopIrr[0] ?? ''), [$key]));
                    if ($others !== [] && ! $this->gate->isConfirmed($ctx->runId, $ctx->registered, $loopIrr[0] ?? '')) {
                        $report->status = RunReport::AWAITING_FIRST_ROW;
                        $report->reasons[] = '初回の1件の確認が済むまで、残りは実行しません';

                        return $report;
                    }
                    $wasFirst = $others === [];
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
                try {
                    $this->browser->resetToStart(); // 各行の終わりに、開始画面へ戻る（7-1）。戻せなければ停止
                } catch (\Throwable $e) {
                    $rowReport['status'] = 'halted';
                    $report->rows[] = $rowReport;

                    return $report->halt('画面を初期状態に戻せません: '.$e->getMessage());
                }
                $report->rows[] = $rowReport;
                if ($ctx->mode === 'real' && $wasFirst && $i < count($rows) - 1 && ! $this->gate->isConfirmed($ctx->runId, $ctx->registered, $loopIrr[0] ?? '')) {
                    $report->status = RunReport::AWAITING_FIRST_ROW;
                    $report->reasons[] = '初回は1件だけ実行して止まります。結果を確認してから、残りを実行してください';

                    return $report;
                }
            }
        }

        return $report;
    }

    /** @param array<mixed> $steps @return string|null 条件に合う最初の手順ID */
    private function firstStepWhere(array $steps, \Closure $pred): ?string
    {
        foreach ($steps as $s) {
            if ($s instanceof \stdClass) {
                if ($pred($s)) {
                    return (string) ($s->id ?? '?');
                }
                foreach (['steps', 'then', 'else'] as $k) {
                    if (isset($s->{$k}) && is_array($s->{$k}) && ($f = $this->firstStepWhere($s->{$k}, $pred)) !== null) {
                        return $f;
                    }
                }
            }
        }

        return null;
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
            if (($s->readback ?? []) === []) {
                return "取り消せない手順に、読み返し照合の項目がありません: {$s->id}"; // 本番で、Guardが必ず拒否する
            }
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
