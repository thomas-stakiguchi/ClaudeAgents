<?php

declare(strict_types=1);

namespace Src\Guard;

/**
 * 12-1の関数への入力。
 * 計画の版・許可リストの中身・行の順番・件数・合計・ドライランは、実行の登録（RunRegistrar）で固定した記録から読む。
 * ここで渡す計画と行の値は、記録のハッシュと照合する。
 * 読み返した値（$readbackRaw）は、照合の結果ではなく、画面から読んだ生の値そのもの。
 */
final class GuardRequest
{
    /**
     * @param \stdClass            $plan        メモリに読み込んだ実行計画（Guardは、深いコピーを取ってから使う）
     * @param array<string,mixed>  $hashInputs  計画ハッシュの入力（PlanHasher::REQUIRED_INPUTS）
     * @param array<string,string> $rowValues   この行の入力データ（列名 => 値）。登録時のハッシュと照合する
     * @param array<string,string> $readbackRaw 画面から読み返した生の値（要素名 => 値）
     */
    public function __construct(
        public readonly \stdClass $plan,
        public readonly array $hashInputs,
        public readonly string $stepId,
        public readonly string $businessKey,
        public readonly string $runId,
        public readonly string $actualDestinationName,
        public readonly array $rowValues,
        public readonly array $readbackRaw,
    ) {
    }
}
