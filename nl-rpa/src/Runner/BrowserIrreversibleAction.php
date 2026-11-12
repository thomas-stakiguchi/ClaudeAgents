<?php

declare(strict_types=1);

namespace Src\Runner;

use Src\Browser\BrowserPort;
use Src\Guard\GuardedAction;
use Src\Guard\IrreversibleGuard;
use Src\Guard\ReadbackComparator;
use Src\Guard\Redeemed;

/**
 * ブラウザでの、取り消せない操作。操作そのものは、BrowserPort::performIrreversible が、証明を照合し直してから行う。
 * 操作のあと、確認条件を判定して、満たしたことを確認できたかを返す（満たさなければ「要確認」）。
 */
final class BrowserIrreversibleAction extends GuardedAction
{
    /** @param array<string,string> $rowValues */
    public function __construct(
        IrreversibleGuard $guard,
        private readonly BrowserPort $browser,
        private readonly Verifier $verifier,
        private readonly array $rowValues,
    ) {
        parent::__construct($guard);
    }

    protected function execute(Redeemed $proof): bool
    {
        $step = $proof->permit->step();
        // 操作の直前に、読み返し照合の項目を、もう一度、読み直す（検査から操作までの間に、画面が変わっていないことを確かめる。9-3）。
        // 一致しなければ、操作せず、例外にする（Guardが「要確認」にする。人が「未実行」と判定できる）
        foreach ($step->readback ?? [] as $item) {
            $expected = isset($item->column) ? ($this->rowValues[$item->column] ?? null) : ($item->value ?? null);
            if (! is_string($expected) || ! ReadbackComparator::equal($expected, $this->browser->readValue($item->target), $item->rule ?? null)) {
                throw new \RuntimeException("操作の直前の読み直しで、値が一致しません: {$item->target}");
            }
        }
        $this->browser->performIrreversible($proof, $this->rowValues);

        return isset($step->verify) && $this->verifier->check($step->verify, $step, $this->rowValues);
    }
}
