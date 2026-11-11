<?php

declare(strict_types=1);

namespace Src\Guard;

/**
 * 許可オブジェクト（14-3）。取り消せない操作は、これを受け取って実行する。
 *
 * 作れるのは IrreversibleGuard だけ（コンストラクタは private）。ただし、PHPの言語機能だけでは、
 * リフレクションなどによる偽造を完全には止められない。そのため、本当の防御は、次の2つ：
 *  - 許可には、Guardだけが知る使い捨ての合言葉（nonce）が入っている。操作の側（GuardedAction）は、実行の直前に
 *    Guard::redeem() で、合言葉と、保存先の状態（「処理中」で、実行ID・計画ハッシュが一致）を照合し直す（多重の防御）
 *  - 複製・直列化は禁止
 * 実行する手順の中身（stepJson）は、検査した時点のコピー。操作の側は、これだけを使う（検査のあとの差し替えを防ぐ）。
 */
final class Permit
{
    private function __construct(
        public readonly string $planHash,
        public readonly string $stepId,
        public readonly string $businessKey,
        public readonly string $runId,
        public readonly string $nonce,
        private readonly string $stepJson,
    ) {
    }

    /** @internal IrreversibleGuard からだけ呼ぶ */
    public static function mint(IrreversibleGuard $issuer, string $planHash, string $stepId, string $businessKey, string $runId, string $nonce, string $stepJson): self
    {
        return new self($planHash, $stepId, $businessKey, $runId, $nonce, $stepJson);
    }

    /** 検査した時点の手順（正規化したJSONのコピー）。呼ぶたびに新しいオブジェクトを返す */
    public function step(): \stdClass
    {
        /** @var \stdClass */
        return json_decode($this->stepJson, false, 512, JSON_THROW_ON_ERROR);
    }

    private function __clone()
    {
    }

    public function __serialize(): array
    {
        throw new \LogicException('許可オブジェクトは直列化できません');
    }

    public function __unserialize(array $data): void
    {
        throw new \LogicException('許可オブジェクトは復元できません');
    }
}
