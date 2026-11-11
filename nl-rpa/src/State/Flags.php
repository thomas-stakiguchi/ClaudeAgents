<?php

declare(strict_types=1);

namespace Src\State;

/** 停止の旗。kill_switch（全実行の一斉停止。SEC-12）と、stop（通常の停止。R12）。変更は記録する。 */
final class Flags
{
    public const KILL_SWITCH = 'kill_switch';
    public const STOP = 'stop';

    public function __construct(private readonly Db $db, private readonly Ledger $ledger)
    {
    }

    public function isSet(string $name): bool
    {
        $st = $this->db->pdo->prepare('SELECT value FROM flags WHERE name = ?');
        $st->execute([$name]);
        $r = $st->fetch();
        if ($r !== false && (int) $r['value'] === 1) {
            return true;
        }
        // 表の値が書き換えられても、記録（ledger）の最新が「立っている」なら、立っているものとして扱う（安全側）
        $last = $this->ledger->latest('flag', $name);

        return $last !== null && ($last->value ?? false) === true;
    }

    public function set(string $name, bool $on, string $by): void
    {
        if (! in_array($name, [self::KILL_SWITCH, self::STOP], true)) {
            throw new \InvalidArgumentException("知らない旗です: {$name}");
        }
        $this->db->immediate(function () use ($name, $on, $by): void {
            $st = $this->db->pdo->prepare('INSERT INTO flags (name, value) VALUES (?, ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value');
            $st->execute([$name, $on ? 1 : 0]);
            $this->ledger->append('flag', $name, (object) ['value' => $on, 'by' => $by]);
        });
    }
}
