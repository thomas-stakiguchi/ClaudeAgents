<?php

declare(strict_types=1);

namespace Src\Guard;

final class GuardOutcome
{
    private function __construct(
        public readonly bool $executed,
        public readonly bool $confirmed,
        /** @var list<string> 拒否した理由（実行していないとき） */
        public readonly array $reasons,
    ) {
    }

    /** @param list<string> $reasons */
    public static function refused(array $reasons): self
    {
        return new self(false, false, $reasons);
    }

    public static function executed(bool $confirmed): self
    {
        return new self(true, $confirmed, []);
    }
}
