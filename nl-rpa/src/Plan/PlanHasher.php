<?php

declare(strict_types=1);

namespace Src\Plan;

/**
 * 計画ハッシュ（仕様2章、SEC-2）。実行エンジンが計算する。
 * 実行計画（plan_hashの欄は除く）と、操作許可リスト・接続先許可リスト・個別登録・画面の記録の「中身」から計算する。
 * 版番号ではなく、中身から計算する。
 */
final class PlanHasher
{
    /** ハッシュに含める入力の名前。どれが欠けても例外（版番号だけを渡して、承認を迂回するのを防ぐ） */
    public const REQUIRED_INPUTS = ['operations', 'destinations', 'registrations', 'screen_record'];

    /** 方式の版。正規化や構成を変えたら上げる（古いハッシュと取り違えない） */
    public const SCHEME = 'nl-rpa/plan-hash';
    public const SCHEME_VERSION = 1;

    /**
     * @param \stdClass            $plan   実行計画（json_decode の assoc=false の結果）
     * @param array<string,mixed>  $inputs REQUIRED_INPUTS をすべて含む。各値は、許可リスト等の中身（stdClass か 連番の配列）
     */
    public static function hash(\stdClass $plan, array $inputs): string
    {
        $missing = array_diff(self::REQUIRED_INPUTS, array_keys($inputs));
        if ($missing !== []) {
            throw new \InvalidArgumentException('計画ハッシュの入力が足りません: '.implode(', ', $missing));
        }
        $extra = array_diff(array_keys($inputs), self::REQUIRED_INPUTS);
        if ($extra !== []) {
            throw new \InvalidArgumentException('計画ハッシュに含めない入力です: '.implode(', ', $extra));
        }
        foreach ($inputs as $name => $value) {
            if (! ($value instanceof \stdClass || is_array($value))) {
                throw new \InvalidArgumentException("入力は中身（オブジェクトか配列）で渡してください: {$name}");
            }
        }

        $planCopy = clone $plan;       // 呼び出し元の計画は、変えない
        unset($planCopy->plan_hash);   // 一番上の plan_hash の欄だけ除く（入れ子の同名の欄は残す）

        $doc = (object) [
            'type' => self::SCHEME,
            'v' => self::SCHEME_VERSION,
            'plan' => $planCopy,
            'inputs' => (object) $inputs,
        ];

        return 'sha256:'.hash('sha256', CanonicalJson::encode($doc));
    }

    public static function equals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }
}
