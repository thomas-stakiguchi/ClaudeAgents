<?php

declare(strict_types=1);

namespace Src\Runner;

/**
 * 二重起動の防止（14-3の6）。ファイルロック（flock）。待たずに、取れなければ失敗にする。
 * ロックは、プロセスが死ねば、OSが解放する。
 */
final class RunLock
{
    /** @var resource|null */
    private $fh = null;

    public function __construct(private readonly string $path)
    {
    }

    public function acquire(): bool
    {
        if ($this->fh !== null) {
            return false; // すでに、このオブジェクトで取っている
        }
        $fh = @fopen($this->path, 'c');
        if ($fh === false) {
            return false;
        }
        if (! flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);

            return false;
        }
        $this->fh = $fh;

        return true;
    }

    public function release(): void
    {
        if ($this->fh !== null) {
            flock($this->fh, LOCK_UN);
            fclose($this->fh);
            $this->fh = null;
        }
    }
}
