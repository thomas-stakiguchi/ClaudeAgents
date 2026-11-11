<?php

declare(strict_types=1);

namespace Src\Guard;

/** 取り消せない操作の実体。接続先ごとの部品が実装する。IrreversibleGuard::run からだけ呼ばれる。 */
interface IrreversibleAction
{
    /** @return bool 操作の結果が、確認条件を満たしたことを確認できたか。false や例外は「要確認」になる */
    public function perform(Permit $permit): bool;
}
