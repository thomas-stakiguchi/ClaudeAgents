<?php

declare(strict_types=1);

namespace App\Support;

use Src\Plan\CanonicalJson;

/**
 * 計画の差分（R3、14：修正案の扱い）。2つの計画（と、計画ハッシュの入力）を、項目ごとに比べて、
 * 追加・削除・変更の一覧にする。承認済みの版と比べて、何が変わったかを、承認する人が見られるようにする。
 * 「重要」は、手順の追加・削除・並べ替え、操作の種類・対象・接続先・確認条件・読み返し、入力データの列、上限、許可リスト・個別登録・画面の記録、それらの版、未確定の変更（R14の、受け付けない修正案の候補）。
 * 根拠の文・ラベルなどの変更は、重要ではない。
 */
final class PlanDiff
{
    /** @var list<string> 変わると「重要」になる、手順の項目 */
    private const IMPORTANT_STEP_FIELDS = ['action', 'target', 'destination', 'verify', 'readback', 'column', 'value', 'condition', 'response', 'children'];

    /**
     * @param array<string,mixed> $oldInputs
     * @param array<string,mixed> $newInputs
     * @return list<array{kind:string, where:string, old:string, new:string, important:bool}>
     */
    public static function compare(\stdClass $old, array $oldInputs, \stdClass $new, array $newInputs): array
    {
        $a = self::flatten($old, $oldInputs);
        $b = self::flatten($new, $newInputs);
        $out = [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $path) {
            $inA = array_key_exists($path, $a);
            $inB = array_key_exists($path, $b);
            if ($inA && $inB && $a[$path]['v'] === $b[$path]['v']) {
                continue;
            }
            $meta = $b[$path] ?? $a[$path];
            [$shownOld, $shownNew] = self::excerpts($inA ? $a[$path]['v'] : '', $inB ? $b[$path]['v'] : '');
            $out[] = [
                'kind' => ! $inA ? '追加' : (! $inB ? '削除' : '変更'),
                'where' => $meta['where'],
                'old' => $shownOld,
                'new' => $shownNew,
                'important' => $meta['important'] || ! $inA && $meta['stepAdded'] || ! $inB && $meta['stepAdded'],
            ];
        }

        return $out;
    }

    /**
     * 表示用に、長い値を短くする。前と後が、長く、最初の違いが、先頭から離れている場合は、最初に違う位置の前後を出す
     * （先頭だけを切ると、前と後が、同じに見えて、どこが変わったか読めない）。
     *
     * @return array{0:string,1:string}
     */
    private static function excerpts(string $old, string $new): array
    {
        $max = 200;
        if (mb_strlen($old) <= $max && mb_strlen($new) <= $max) {
            return [$old, $new];
        }
        $n = min(mb_strlen($old), mb_strlen($new));
        $i = 0;
        while ($i < $n && mb_substr($old, $i, 1) === mb_substr($new, $i, 1)) {
            $i++;
        }
        $start = max(0, $i - 60);
        $cut = static fn (string $v): string => ($start > 0 ? '…' : '').mb_substr($v, $start, $max).(mb_strlen($v) > $start + $max ? '…' : '');

        return [$cut($old), $cut($new)];
    }

    /** @return array<string,array{v:string, where:string, important:bool, stepAdded:bool}> */
    private static function flatten(\stdClass $plan, array $inputs): array
    {
        $out = [];
        $put = static function (string $path, string $where, mixed $v, bool $important, bool $stepAdded = false) use (&$out): void {
            $out[$path] = ['v' => CanonicalJson::encode($v), 'where' => $where, 'important' => $important, 'stepAdded' => $stepAdded];
        };
        foreach (['plan_id', 'version', 'scenario_id', 'created_by_ai', 'allowlist_versions', 'screen_record_version', 'unresolved'] as $k) {
            // 許可リスト・画面の記録の版と、未確定の変更は、重要（承認の根拠が変わる）
            $put("top/{$k}", "計画 / {$k}", $plan->{$k} ?? null, in_array($k, ['unresolved', 'allowlist_versions', 'screen_record_version'], true));
        }
        // 最上位の手順の並び（並べ替えも、差分として出す）
        $put('top/order', '計画 / 最上位の手順の並び', array_map(static fn ($s) => (string) ($s->id ?? '?'), $plan->steps ?? []), true);
        foreach ($plan->input->columns ?? [] as $c) {
            $put('col/'.$c->name, '入力データの列「'.$c->name.'」', $c, true);
        }
        foreach ($plan->limits ?? new \stdClass() as $k => $v) {
            // 上限（R9、SEC-14）は、実行前の安全装置。変更は、すべて重要（上げる・消す、だけでなく、下げる場合も、見落とさない）
            $put("limit/{$k}", "上限 / {$k}", $v, true);
        }
        self::steps($plan->steps ?? [], $put, null);
        foreach ($inputs as $k => $v) {
            $put("inputs/{$k}", "許可リスト・個別登録・画面の記録 / {$k}", $v, true);
        }

        return $out;
    }

    /** @param array<mixed> $steps */
    private static function steps(array $steps, \Closure $put, ?string $parent): void
    {
        foreach ($steps as $s) {
            $id = (string) ($s->id ?? '?');
            foreach (['action', 'destination', 'target', 'column', 'value', 'verify', 'readback', 'source', 'label', 'condition', 'dialog_text', 'response', 'timeout_sec'] as $f) {
                if (property_exists($s, $f)) {
                    $put("step/{$id}/{$f}", "手順 {$id} / {$f}", $s->{$f}, in_array($f, self::IMPORTANT_STEP_FIELDS, true));
                }
            }
            $children = [];
            foreach (['steps', 'then', 'else'] as $k) {
                if (isset($s->{$k}) && is_array($s->{$k})) {
                    $children[$k] = array_map(static fn ($c) => (string) ($c->id ?? '?'), $s->{$k});
                    self::steps($s->{$k}, $put, $id);
                }
            }
            $put("step/{$id}/children", "手順 {$id} / 中の手順", (object) $children, true);
            $put("step/{$id}/exists", "手順 {$id}", $parent ?? '(最上位)', false, true);
        }
    }
}
