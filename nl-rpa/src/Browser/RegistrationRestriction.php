<?php

declare(strict_types=1);

namespace Src\Browser;

use Src\Policy\OperationPolicy;

/**
 * ブラウザ部品の、取り消せる操作（openUrl、typeText、clearText）を、承認済みの個別登録に限る（多重の防御。12-1、SEC-11）。
 * 取り消し可否の判定は、Runner だけでなく、部品の中でも行う。登録にない対象への操作は、例外にする。
 * restrictTo を呼ぶまでは、制限しない（試験用）。実行エンジン（PlanRunner）は、実行のたびに必ず呼ぶ。
 * 取り消せない操作（performIrreversible）と、開始画面への復帰（resetToStart）は、Guard の証明で守られるため、対象外。
 */
trait RegistrationRestriction
{
    private ?OperationPolicy $operationPolicy = null;

    public function restrictTo(OperationPolicy $policy): void
    {
        $this->operationPolicy = $policy;
    }

    private function assertRegistered(string $action, string $target): void
    {
        if ($this->operationPolicy === null) {
            return;
        }
        $step = (object) ['action' => $action, 'target' => $target, 'destination' => (object) ['kind' => 'browser', 'name' => $this->destinationName()]];
        if (! $this->operationPolicy->isReversible($step)) {
            throw new \RuntimeException("個別登録にない対象への操作です（取り消せない操作は、12-1の関数だけが通せます）: {$action} {$target}");
        }
    }
}
