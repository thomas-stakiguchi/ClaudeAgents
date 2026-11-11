<?php

declare(strict_types=1);

namespace Src\Plan;

/**
 * 計画ハッシュ（仕様2章、SEC-2）。実行エンジンが計算する。
 * 実行計画（plan_hashの欄は除く）と、操作許可リスト・接続先許可リスト・個別登録・画面の記録の中身から計算する。
 * 版番号ではなく、中身から計算する。
 */
final class PlanHasher
{
    /**
     * @param array<string,mixed>|\stdClass $plan   実行計画
     * @param array<string,mixed>           $inputs 許可リスト等の中身（キーは、operations / destinations / registrations / screen_record など）
     */
    public static function hash(array|\stdClass $plan, array $inputs): string
    {
        $plan = $plan instanceof \stdClass ? get_object_vars($plan) : $plan;
        unset($plan['plan_hash']);
        $doc = ['plan' => $plan, 'inputs' => $inputs];

        return 'sha256:'.hash('sha256', CanonicalJson::encode($doc));
    }

    public static function equals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }
}
