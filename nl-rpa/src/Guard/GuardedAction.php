<?php

declare(strict_types=1);

namespace Src\Guard;

/**
 * 取り消せない操作の土台。perform は final で、必ず Guard::redeem() を通してから execute を呼ぶ。
 * 接続先ごとの部品は、execute だけを書き、実際に取り消せない操作をする瞬間に、effect() を通す。
 * effect() は、Guard::assertRedeemed() で証明を照合し直してから、検査した時点の手順を渡す。
 * （execute を直接呼ぶ裏口を作られても、effect を通らない限り、操作できない作りにする）
 */
abstract class GuardedAction implements IrreversibleAction
{
    public function __construct(private readonly IrreversibleGuard $guard)
    {
    }

    final public function belongsTo(IrreversibleGuard $guard): bool
    {
        return $this->guard === $guard;
    }

    final public function perform(Permit $permit): bool
    {
        return $this->execute($this->guard->redeem($permit));
    }

    /**
     * 取り消せない操作をする瞬間に呼ぶ。証明を照合し直し、検査した時点の手順のコピーを $do に渡す。
     *
     * @template T
     * @param \Closure(\stdClass):T $do
     * @return T
     */
    final protected function effect(Redeemed $proof, \Closure $do): mixed
    {
        $this->guard->assertRedeemed($proof);

        return $do($proof->permit->step());
    }

    /** @return bool 確認条件を満たしたことを確認できたか */
    abstract protected function execute(Redeemed $proof): bool;
}
