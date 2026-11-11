<?php

declare(strict_types=1);

namespace Src\Guard;

use Src\Plan\CanonicalJson;
use Src\Plan\PlanHasher;
use Src\Plan\PlanValidator;
use Src\Policy\DestinationLimits;
use Src\State\Ledger;

/**
 * 実行の登録（実行前の検査と、入力データの固定）。9章、R8、R9、SEC-14。
 * 登録時に、件数・数量・金額の上限を検査し、「実行ID → 計画ハッシュ・ドライランID・接続先・各行の値のハッシュ」を
 * 記録（ledger）に固定する。12-1の関数は、この記録を信じ、呼び出し側が渡す行の値は、ハッシュで照合する。
 * （呼び出し側が、行の順番・件数・合計を偽って、上限や「初回1件」を迂回できないようにするため）
 */
final class RunRegistrar
{
    public function __construct(private readonly Ledger $ledger, private readonly ApprovalChecker $approvals)
    {
    }

    /**
     * @param array<string,mixed>              $hashInputs
     * @param list<array<string,string>>       $rows        入力データの全行（列名 => 値）
     * @return list<string> 登録できない理由。空なら、登録した
     */
    public function register(string $runId, \stdClass $plan, array $hashInputs, string $dryRunId, string $destinationName, array $rows): array
    {
        $errors = PlanValidator::validate($plan);
        if ($errors !== []) {
            return ['計画の形式が不正です: '.$errors[0]];
        }
        if ($runId === '' || $this->ledger->latest('run', $runId) !== null) {
            return ['実行IDが空か、すでに登録されています'];
        }
        try {
            $hash = PlanHasher::hash($plan, $hashInputs);
        } catch (\InvalidArgumentException $e) {
            return ['計画ハッシュを計算できません: '.$e->getMessage()];
        }
        $reasons = [];
        ! $this->approvals->hasFinalApproval($hash) && $reasons[] = 'この計画ハッシュの最終承認がありません';
        $dry = $this->approvals->findPassedDryRun($hash, $dryRunId);
        $dry['error'] !== null && $reasons[] = $dry['error'];
        if (! DestinationLimits::isAllowed($destinationName)) {
            $reasons[] = '接続先が、ビルド時の定数の範囲にありません';
        } elseif ($dry['destination'] !== null && $dry['destination'] !== $destinationName) {
            $reasons[] = 'ドライランの接続先が、実行の接続先と違います';
        }
        PlanValidator::hasUnresolved($plan) && $reasons[] = '「未確定」が残っています';

        // 入力データの検査（R8）と、上限（R9）
        $cols = [];
        $keyCol = null;
        foreach ($plan->input->columns as $c) {
            $cols[$c->name] = $c;
            ($c->role ?? null) === 'business_key' && $keyCol = $c->name;
        }
        $rowRecords = [];
        $seen = [];
        $qty = 0.0;
        $amt = 0.0;
        foreach ($rows as $i => $row) {
            $n = $i + 1;
            foreach ($cols as $name => $c) {
                $v = $row[$name] ?? null;
                if (! is_string($v)) {
                    $reasons[] = "{$n}行目: 列「{$name}」の値がありません";
                    continue;
                }
                if (preg_match('/[\x00-\x1f\x7f]/', $v)) {
                    $reasons[] = "{$n}行目: 列「{$name}」に制御文字があります";
                }
                ($c->required ?? false) === true && $v === '' && $reasons[] = "{$n}行目: 必須の列「{$name}」が空です";
                isset($c->max_bytes) && strlen($v) > $c->max_bytes && $reasons[] = "{$n}行目: 列「{$name}」が長すぎます";
                $role = $c->role ?? null;
                if (($role === 'quantity' || $role === 'amount') && $v !== '') {
                    $num = str_replace([',', '，'], '', $v);
                    if (! is_numeric($num)) {
                        $reasons[] = "{$n}行目: 列「{$name}」が数値ではありません";
                    } elseif ($role === 'quantity') {
                        $qty += (float) $num;
                    } else {
                        $amt += (float) $num;
                    }
                }
            }
            $key = $keyCol !== null ? ($row[$keyCol] ?? '') : '';
            if ($key === '' || isset($seen[$key])) {
                $reasons[] = "{$n}行目: 業務キーが空か、重複しています";
            }
            $seen[$key] = true;
            $rowRecords[] = (object) ['business_key' => $key, 'values_hash' => self::rowHash($row)];
        }
        if ($rows === []) {
            $reasons[] = '入力データが0件です';
        }
        $l = $plan->limits;
        isset($l->max_rows_per_run) && count($rows) > $l->max_rows_per_run && $reasons[] = '件数の上限を超えています';
        isset($l->max_quantity_per_run) && $qty > $l->max_quantity_per_run && $reasons[] = '数量の上限を超えています';
        isset($l->max_amount_per_run) && $amt > $l->max_amount_per_run && $reasons[] = '金額の上限を超えています';

        if ($reasons !== []) {
            return $reasons;
        }
        $this->ledger->append('run', $runId, (object) [
            'plan_hash' => $hash,
            'dry_run_id' => $dryRunId,
            'destination_name' => $destinationName,
            'rows' => $rowRecords,
        ]);

        return [];
    }

    /** @param array<string,string> $row */
    public static function rowHash(array $row): string
    {
        return 'sha256:'.hash('sha256', CanonicalJson::encode((object) $row));
    }
}
