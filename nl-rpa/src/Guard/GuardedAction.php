<?php

declare(strict_types=1);

namespace Src\Guard;

/**
 * 取り消せない操作の土台。perform は final で、必ず Guard::redeem() を通してから execute を呼ぶ。
 * 接続先ごとの部品は、execute だけを書く。
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
        $this->guard->redeem($permit);

        return $this->execute($permit);
    }

    /** @return bool 確認条件を満たしたことを確認できたか */
    abstract protected function execute(Permit $permit): bool;
}
