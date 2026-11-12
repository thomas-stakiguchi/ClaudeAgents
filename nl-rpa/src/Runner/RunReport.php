<?php

declare(strict_types=1);

namespace Src\Runner;

/** 実行の結果。画面や結果CSVに出す元になる。 */
final class RunReport
{
    public const COMPLETED = 'completed';
    public const HALTED = 'halted';
    public const AWAITING_FIRST_ROW = 'awaiting_first_row_confirmation';
    public const REFUSED_TO_START = 'refused_to_start';

    /** @var list<array{business_key:string, status:string, reads:array<string,string>, steps?:list<array{id:string, action:string, verify:string, ok:bool, note:string}>}> */
    public array $rows = [];
    /** @var list<string> */
    public array $reasons = [];

    public function __construct(public string $status = self::COMPLETED)
    {
    }

    public function halt(string $reason): self
    {
        $this->status = self::HALTED;
        $this->reasons[] = $reason;

        return $this;
    }
}
