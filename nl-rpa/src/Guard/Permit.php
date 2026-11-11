<?php

declare(strict_types=1);

namespace Src\Guard;

/**
 * 許可オブジェクト（14-3）。取り消せない操作のメソッドは、これを引数に取る。
 * コンストラクタは private。作れるのは IrreversibleGuard だけ（PHPの言語機能だけでは、完全には強制できない。
 * リフレクションなどの迂回は、テストで確認する）。取り消せない操作の側でも、中身を保存先と照合し直すこと。
 */
final class Permit
{
    private function __construct(
        public readonly string $planHash,
        public readonly string $stepId,
        public readonly string $businessKey,
        public readonly string $runId,
    ) {
    }

    /** @internal IrreversibleGuard からだけ呼ぶ */
    public static function mint(IrreversibleGuard $issuer, string $planHash, string $stepId, string $businessKey, string $runId): self
    {
        return new self($planHash, $stepId, $businessKey, $runId);
    }
}
