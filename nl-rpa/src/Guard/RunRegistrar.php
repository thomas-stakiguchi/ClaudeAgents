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
    /** 数量・金額は、小数4桁までの固定小数（整数に直して）で数える。浮動小数点の丸めで、上限をすり抜けさせない */
    private const SCALE = 10000;

    public function __construct(
        private readonly Ledger $ledger,
        private readonly ApprovalChecker $approvals,
        /** 組織全体の件数の最大値（SEC-14）。計画の上限が、これを超えていれば、登録しない */
        private readonly ?int $orgMaxRows = null,
        /** 渡されたときは、未解消の「処理中」「要確認」が残っていれば、登録しない（10-3） */
        private readonly ?\Src\State\StepStateStore $states = null,
    ) {
    }

    /** 数値の形：符号なし、3桁区切り（または区切りなし）、小数は4桁まで、整数部は12桁まで。前後の空白は不可 */
    private static function parseDecimal(string $v, bool $allowNegative): ?int
    {
        $re = '/\A(-?)((?:[0-9]{1,12})|(?:[0-9]{1,3}(?:,[0-9]{3}){1,3}))(?:\.([0-9]{1,4}))?\z/';
        if (! preg_match($re, $v, $m) || ($m[1] === '-' && ! $allowNegative)) {
            return null;
        }
        $int = (int) str_replace(',', '', $m[2]);
        if ($int > 999999999999) {
            return null;
        }
        $frac = (int) str_pad($m[3] ?? '', 4, '0');
        $n = $int * self::SCALE + $frac;

        return $m[1] === '-' ? -$n : $n;
    }

    /**
     * 実行前のプレビュー用の集計（R7、5章の9）。数量・金額は、小数4桁までの固定小数で数え、文字列で返す。
     * 数値でない値は、集計に入れない（検査は register が行う）。
     *
     * @param array<mixed> $rows
     * @return array{rows:int, quantity:string, amount:string}
     */
    public static function summarize(\stdClass $plan, array $rows): array
    {
        $qtyCol = $amtCol = null;
        foreach ($plan->input->columns as $c) {
            ($c->role ?? null) === 'quantity' && $qtyCol = $c->name;
            ($c->role ?? null) === 'amount' && $amtCol = $c->name;
        }
        $q = $a = 0;
        $over = false;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ($qtyCol !== null && is_string($row[$qtyCol] ?? null) && ($n = self::parseDecimal($row[$qtyCol], false)) !== null) {
                $n > PHP_INT_MAX - $q ? $over = true : $q += $n;
            }
            if ($amtCol !== null && is_string($row[$amtCol] ?? null) && ($n = self::parseDecimal($row[$amtCol], false)) !== null) {
                $n > PHP_INT_MAX - $a ? $over = true : $a += $n;
            }
        }
        $fmt = static fn (int $n): string => rtrim(rtrim(sprintf('%d.%04d', intdiv($n, self::SCALE), $n % self::SCALE), '0'), '.');

        return [
            'rows' => count($rows),
            'quantity' => $qtyCol === null ? '（数量の列なし）' : ($over ? '（大きすぎて数えられません）' : $fmt($q)),
            'amount' => $amtCol === null ? '（金額の列なし）' : ($over ? '（大きすぎて数えられません）' : $fmt($a)),
        ];
    }

    /** 列の型の検査（付録A-4）。問題があれば、理由 */
    private static function typeProblem(\stdClass $c, string $v): ?string
    {
        if ($v === '') {
            return null; // 空は、required で別に検査する
        }
        switch ($c->type) {
            case 'number':
                return self::parseDecimal($v, true) === null ? '数値ではありません' : null;
            case 'date':
                $fmt = self::phpDateFormat((string) ($c->format ?? 'YYYY-MM-DD'));
                $d = $fmt === null ? false : \DateTimeImmutable::createFromFormat('!'.$fmt, $v);
                $err = \DateTimeImmutable::getLastErrors();

                return ($d === false || ($err !== false && ($err['warning_count'] > 0 || $err['error_count'] > 0)) || $d->format($fmt) !== $v)
                    ? '日付の形式が合いません' : null;
        }

        return null;
    }

    /** 付録Aの書式を、PHPの書式に直す。対応しない文字があれば null（ReadbackComparator と共通） */
    private static function phpDateFormat(string $f): ?string
    {
        return ReadbackComparator::phpDateFormat($f);
    }

    /**
     * @param array<string,mixed>              $hashInputs
     * @param list<array<string,string>>       $rows        入力データの全行（列名 => 値）
     * @return list<string> 登録できない理由。空なら、登録した
     */
    public function register(string $runId, \stdClass $plan, array $hashInputs, string $dryRunId, string $destinationName, array $rows): array
    {
        try {
            return $this->doRegister($runId, $plan, $hashInputs, $dryRunId, $destinationName, $rows);
        } catch (\Throwable $e) {
            // 想定外の形の入力でも、例外を外へ出さず、理由として返す（R8）
            return ['登録できません（想定外の入力）: '.get_debug_type($e).': '.$e->getMessage()];
        }
    }

    /**
     * @param array<string,mixed> $hashInputs
     * @param array<mixed> $rows
     * @return list<string>
     */
    private function doRegister(string $runId, \stdClass $plan, array $hashInputs, string $dryRunId, string $destinationName, array $rows): array
    {
        $errors = PlanValidator::validate($plan, $this->orgMaxRows);
        if ($errors !== []) {
            return ['計画の形式が不正です: '.$errors[0]];
        }
        if (! array_is_list($rows)) {
            return ['入力データは、行の連番の配列で渡してください'];
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
        ! $this->approvals->hasFinalApproval($hash, $dryRunId) && $reasons[] = 'この計画ハッシュの最終承認がありません';
        $dry = $this->approvals->findPassedDryRun($hash, $dryRunId);
        $dry['error'] !== null && $reasons[] = $dry['error'];
        if (! DestinationLimits::isAllowed($destinationName)) {
            $reasons[] = '接続先が、ビルド時の定数の範囲にありません';
        } elseif ($dry['destination'] !== null && $dry['destination'] !== $destinationName) {
            $reasons[] = 'ドライランの接続先が、実行の接続先と違います';
        }
        PlanValidator::hasUnresolved($plan) && $reasons[] = '「未確定」が残っています';
        if ($this->states !== null && ($un = $this->states->unresolvedAll()) !== []) {
            $reasons[] = '未解消の「処理中」「要確認」が'.count($un).'件残っています。先に解消してください（10-3）';
        }

        // 入力データの検査（R8）と、上限（R9）
        $cols = [];
        $keyCol = null;
        foreach ($plan->input->columns as $c) {
            $cols[$c->name] = $c;
            ($c->role ?? null) === 'business_key' && $keyCol = $c->name;
        }
        $rowRecords = [];
        $seen = [];
        $qty = 0;
        $amt = 0;
        $overflow = false;
        foreach ($rows as $i => $row) {
            $n = $i + 1;
            if (! is_array($row)) {
                $reasons[] = "{$n}行目: 行の形式が不正です";
                continue;
            }
            foreach ($cols as $name => $c) {
                $v = $row[$name] ?? null;
                if (! is_string($v)) {
                    $reasons[] = "{$n}行目: 列「{$name}」の値がありません";
                    continue;
                }
                if (! mb_check_encoding($v, 'UTF-8') || preg_match('/[\p{C}]/u', $v)) {
                    $reasons[] = "{$n}行目: 列「{$name}」に制御文字などがあります";
                    continue;
                }
                ($c->required ?? false) === true && $v === '' && $reasons[] = "{$n}行目: 必須の列「{$name}」が空です";
                isset($c->max_bytes) && strlen($v) > $c->max_bytes && $reasons[] = "{$n}行目: 列「{$name}」が長すぎます";
                ($p = self::typeProblem($c, $v)) !== null && $reasons[] = "{$n}行目: 列「{$name}」: {$p}";
                $role = $c->role ?? null;
                if (($role === 'quantity' || $role === 'amount') && $v !== '') {
                    $num = self::parseDecimal($v, false); // 数量・金額は、負の値を認めない（合計で上限を迂回されるため）
                    if ($num === null) {
                        $reasons[] = "{$n}行目: 列「{$name}」は、0以上の数値（小数4桁まで）にしてください";
                    } elseif ($role === 'quantity') {
                        $num > PHP_INT_MAX - $qty ? $overflow = true : $qty += $num;
                    } else {
                        $num > PHP_INT_MAX - $amt ? $overflow = true : $amt += $num;
                    }
                }
            }
            $key = $keyCol !== null && is_string($row[$keyCol] ?? null) ? $row[$keyCol] : '';
            if (($kp = BusinessKey::problem($key)) !== null) {
                $reasons[] = "{$n}行目: 業務キーが不正です（{$kp}）";
            } else {
                $folded = BusinessKey::fold($key);
                isset($seen[$folded]) && $reasons[] = "{$n}行目: 業務キーが重複しています";
                $seen[$folded] = true;
            }
            $rowRecords[] = (object) ['business_key' => $key, 'values_hash' => self::rowHash($row)];
        }
        $overflow && $reasons[] = '数量・金額の合計が大きすぎます';
        if ($rows === []) {
            $reasons[] = '入力データが0件です';
        }
        $l = $plan->limits;
        isset($l->max_rows_per_run) && count($rows) > $l->max_rows_per_run && $reasons[] = '件数の上限を超えています';
        isset($l->max_quantity_per_run) && $qty > $l->max_quantity_per_run * self::SCALE && $reasons[] = '数量の上限を超えています';
        isset($l->max_amount_per_run) && $amt > $l->max_amount_per_run * self::SCALE && $reasons[] = '金額の上限を超えています';

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

    /** @param array<mixed> $row */
    public static function rowHash(array $row): string
    {
        return 'sha256:'.hash('sha256', CanonicalJson::encode((object) $row));
    }
}
