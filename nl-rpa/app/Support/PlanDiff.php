<?php

declare(strict_types=1);

namespace App\Support;

use Src\Plan\CanonicalJson;

/**
 * 計画の差分（R3、14：修正案の扱い）。2つの計画（と、計画ハッシュの入力）を、項目ごとに比べて、
 * 追加・削除・変更の一覧にする。承認済みの版と比べて、何が変わったかを、承認する人が見られるようにする。
 * 「重要」は、取り消せない操作・確認条件・対象・接続先・許可リスト・入力データの列の変更（R14の、受け付けない修正案の候補）。
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
            $out[] = [
                'kind' => ! $inA ? '追加' : (! $inB ? '削除' : '変更'),
                'where' => $meta['where'],
                'old' => $inA ? self::short($a[$path]['v']) : '',
                'new' => $inB ? self::short($b[$path]['v']) : '',
                'important' => $meta['important'] || ! $inA && $meta['stepAdded'] || ! $inB && $meta['stepAdded'],
            ];
        }

        return $out;
    }

    private static function short(string $v): string
    {
        return mb_strlen($v) > 200 ? mb_substr($v, 0, 200).'…' : $v;
    }

    /** @return array<string,array{v:string, where:string, important:bool, stepAdded:bool}> */
    private static function flatten(\stdClass $plan, array $inputs): array
    {
        $out = [];
        $put = static function (string $path, string $where, mixed $v, bool $important, bool $stepAdded = false) use (&$out): void {
            $out[$path] = ['v' => CanonicalJson::encode($v), 'where' => $where, 'important' => $important, 'stepAdded' => $stepAdded];
        };
        foreach (['plan_id', 'version', 'scenario_id', 'created_by_ai', 'allowlist_versions', 'screen_record_version', 'unresolved'] as $k) {
            $put("top/{$k}", "計画 / {$k}", $plan->{$k} ?? null, $k === 'unresolved');
        }
        foreach ($plan->input->columns ?? [] as $c) {
            $put('col/'.$c->name, '入力データの列「'.$c->name.'」', $c, true);
        }
        foreach ($plan->limits ?? new \stdClass() as $k => $v) {
            $put("limit/{$k}", "上限 / {$k}", $v, false);
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
