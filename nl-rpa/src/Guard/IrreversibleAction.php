<?php

declare(strict_types=1);

namespace Src\Guard;

/** 取り消せない操作の実体の型。接続先ごとの部品は、GuardedAction を継承して作る。 */
interface IrreversibleAction
{
    /** @return bool 操作の結果が、確認条件を満たしたことを確認できたか。false や例外は「要確認」になる */
    public function perform(Permit $permit): bool;
}
