<?php

declare(strict_types=1);

namespace Src\Guard;

use Src\State\Ledger;

/**
 * 承認とドライランの記録を、形まで検査して読む。
 * 形が欠けた記録（承認者のない承認、接続先のないドライランなど）は、無効として扱う。
 */
final class ApprovalChecker
{
    /** @param list<string> $acceptedDryRunEnvironments 有効と認めるドライランの環境。1aは stub のみ（9-2。1bに入ったら、stubは無効） */
    public function __construct(private readonly Ledger $ledger, private readonly array $acceptedDryRunEnvironments = ['stub'])
    {
    }

    private static function nonEmpty(mixed $v): bool
    {
        return is_string($v) && $v !== '';
    }

    public function hasFinalApproval(string $planHash): bool
    {
        return $this->hasApproval($planHash, ['final']);
    }

    /** ドライランに進むための承認（計画の承認）。最終承認でも、可 */
    public function hasPlanApproval(string $planHash): bool
    {
        return $this->hasApproval($planHash, ['plan', 'final']);
    }

    /** @param list<string> $stages */
    private function hasApproval(string $planHash, array $stages): bool
    {
        foreach ($this->ledger->all('approval', $planHash) as $a) {
            if (in_array($a->stage ?? null, $stages, true) && self::nonEmpty($a->approver ?? null) && self::nonEmpty($a->os_user ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 合格したドライランを探す。見つかれば、その接続先名を返す。なければ、理由を返す。
     *
     * @return array{destination: string|null, error: string|null}
     */
    public function findPassedDryRun(string $planHash, string $dryRunId): array
    {
        foreach ($this->ledger->all('dry_run', $planHash) as $d) {
            if (($d->dry_run_id ?? null) !== $dryRunId) {
                continue;
            }
            if (($d->result ?? null) !== 'passed') {
                return ['destination' => null, 'error' => 'ドライランが合格していません'];
            }
            if (! in_array($d->environment ?? null, $this->acceptedDryRunEnvironments, true)) {
                return ['destination' => null, 'error' => 'ドライランの環境が、有効な範囲ではありません'];
            }
            if (! self::nonEmpty($d->destination_name ?? null)) {
                return ['destination' => null, 'error' => 'ドライランの記録に接続先がありません'];
            }

            return ['destination' => $d->destination_name, 'error' => null];
        }

        return ['destination' => null, 'error' => 'この計画ハッシュに結び付いた、合格したドライランがありません'];
    }
}
