<?php

declare(strict_types=1);

namespace Src\Guard;

/**
 * Guard が、許可（Permit）の合言葉を照合し終えたことを表す、使い捨ての証明。GuardedAction::execute が受け取る。
 * 本当に取り消せない操作をする場所（ブラウザの部品など）は、操作の瞬間に Guard::assertRedeemed() を呼び、
 * この証明を照合し直すこと。証明の中の token は、Guard だけが知っているので、
 * 継承やリフレクションで偽物を作っても、assertRedeemed で止まる。
 */
final class Redeemed
{
    private function __construct(public readonly Permit $permit, public readonly string $token)
    {
    }

    /** @internal IrreversibleGuard からだけ呼ぶ */
    public static function mint(IrreversibleGuard $issuer, Permit $permit, string $token): self
    {
        return new self($permit, $token);
    }

    private function __clone()
    {
    }

    public function __serialize(): array
    {
        throw new \LogicException('証明は直列化できません');
    }

    public function __unserialize(array $data): void
    {
        throw new \LogicException('証明は復元できません');
    }
}
