<?php

declare(strict_types=1);

namespace Src\Runner;

use Src\Browser\BrowserPort;
use Src\Guard\GuardedAction;
use Src\Guard\IrreversibleGuard;
use Src\Guard\Redeemed;

/**
 * ブラウザでの、取り消せない操作。操作そのものは、BrowserPort::performIrreversible が、証明を照合し直してから行う。
 * 操作のあと、確認条件を判定して、満たしたことを確認できたかを返す（満たさなければ「要確認」）。
 */
final class BrowserIrreversibleAction extends GuardedAction
{
    /** @param array<string,string> $rowValues */
    public function __construct(
        private readonly IrreversibleGuard $irreversibleGuard,
        private readonly BrowserPort $browser,
        private readonly Verifier $verifier,
        private readonly array $rowValues,
    ) {
        parent::__construct($irreversibleGuard);
    }

    protected function execute(Redeemed $proof): bool
    {
        $step = $proof->permit->step();
        $this->browser->performIrreversible($proof, $this->irreversibleGuard, $this->rowValues);

        return isset($step->verify) && $this->verifier->check($step->verify, $step, $this->rowValues);
    }
}
