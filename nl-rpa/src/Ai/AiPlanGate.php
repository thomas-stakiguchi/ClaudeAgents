<?php

declare(strict_types=1);

namespace Src\Ai;

use Src\Plan\PlanValidator;
use Src\Plan\StrictJson;

/**
 * AIの出力を、信頼しない入力として受け入れる門（仕様3-2、SEC-7）。
 * 厳密に読み、形式を検査する。通らないものは受け入れない。
 * AIが書いた plan_hash は捨てる（実行エンジンが計算する）。
 */
final class AiPlanGate
{
    /**
     * @return array{plan: \stdClass|null, errors: list<string>}
     */
    public static function accept(string $aiOutput): array
    {
        try {
            $plan = StrictJson::decode($aiOutput);
        } catch (\InvalidArgumentException $e) {
            return ['plan' => null, 'errors' => [$e->getMessage()]];
        }
        $errors = PlanValidator::validate($plan);
        if ($errors !== []) {
            return ['plan' => null, 'errors' => $errors];
        }
        /** @var \stdClass $plan */
        unset($plan->plan_hash);

        return ['plan' => $plan, 'errors' => []];
    }
}
