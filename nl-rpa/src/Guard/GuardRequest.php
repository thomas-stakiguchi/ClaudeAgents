<?php

declare(strict_types=1);

namespace Src\Guard;

/**
 * 12-1の関数への入力。
 * 計画（$plan）は、呼び出し側が、メモリに1回だけ読み込んだものを渡す（照合と実行の間の差し替えを防ぐ）。
 * 読み返した値（$readbackRaw）は、照合の結果ではなく、画面から読んだ生の値そのもの。
 */
final class GuardRequest
{
    /**
     * @param \stdClass            $plan          メモリに読み込んだ実行計画
     * @param array<string,mixed>  $hashInputs    計画ハッシュの入力（PlanHasher::REQUIRED_INPUTS）
     * @param array<string,string> $rowValues     この行の入力データ（列名 => 値）
     * @param array<string,string> $readbackRaw   画面から読み返した生の値（要素名 => 値）
     */
    public function __construct(
        public readonly \stdClass $plan,
        public readonly array $hashInputs,
        public readonly string $stepId,
        public readonly string $businessKey,
        public readonly string $runId,
        public readonly string $dryRunId,
        public readonly string $actualDestinationName,
        public readonly array $rowValues,
        public readonly array $readbackRaw,
        public readonly int $rowIndexInRun,
        public readonly int $quantityTotalInRun = 0,
        public readonly int $amountTotalInRun = 0,
    ) {
    }
}
