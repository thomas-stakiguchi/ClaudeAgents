<?php

declare(strict_types=1);

namespace Src\State;

use Src\Plan\CanonicalJson;

/**
 * 追記のみの記録（SEC-2）。各記録に、前の記録のハッシュを含める（チェーン）。
 * 承認、許可リストの変更、状態、判定、ドライラン、拒否の理由を、ここに書く。
 *
 * 限界（LOC-8）：同じプロセス・同じアカウントから書ける。外部への退避もない。
 * 「改ざんを検知できる」とは言わず、「部分的な改ざんのみ検知できる（未検証）」とする。
 */
final class Ledger
{
    public const GENESIS = 'sha256:0000000000000000000000000000000000000000000000000000000000000000';

    /** @param \Closure():string $clock 現在時刻（ISO 8601）を返す */
    public function __construct(private readonly Db $db, private readonly \Closure $clock)
    {
    }

    /** @param \stdClass $payload JSONにできる中身（stdClass・連番の配列・数値・文字列など） */
    public function append(string $kind, string $subject, \stdClass $payload): int
    {
        return $this->db->immediate(function () use ($kind, $subject, $payload): int {
            $row = $this->db->pdo->query('SELECT seq, hash FROM ledger ORDER BY seq DESC LIMIT 1')->fetch();
            $seq = $row ? (int) $row['seq'] + 1 : 1;
            $prev = $row ? (string) $row['hash'] : self::GENESIS;
            $at = ($this->clock)();
            $json = CanonicalJson::encode($payload);
            $hash = self::hashOf($seq, $kind, $subject, $json, $at, $prev);
            $st = $this->db->pdo->prepare('INSERT INTO ledger (seq, kind, subject, payload, at, prev_hash, hash) VALUES (?,?,?,?,?,?,?)');
            $st->execute([$seq, $kind, $subject, $json, $at, $prev, $hash]);

            return $seq;
        });
    }

    private static function hashOf(int $seq, string $kind, string $subject, string $payloadJson, string $at, string $prev): string
    {
        $doc = (object) ['type' => 'nl-rpa/ledger', 'v' => 1, 'seq' => $seq, 'kind' => $kind, 'subject' => $subject, 'payload' => $payloadJson, 'at' => $at, 'prev' => $prev];

        return 'sha256:'.hash('sha256', CanonicalJson::encode($doc));
    }

    /** 最新の1件（kind と subject が一致するもの）。なければ null */
    public function latest(string $kind, string $subject): ?\stdClass
    {
        $st = $this->db->pdo->prepare('SELECT payload FROM ledger WHERE kind = ? AND subject = ? ORDER BY seq DESC LIMIT 1');
        $st->execute([$kind, $subject]);
        $r = $st->fetch();

        return $r ? json_decode((string) $r['payload'], false, 512, JSON_THROW_ON_ERROR) : null;
    }

    /**
     * 同じ種類の記録の、subject ごとの最新を返す（古い順）。実行の登録（run）の一覧などに使う。
     *
     * @return list<array{subject:string, payload:\stdClass}>
     */
    public function latestPerSubject(string $kind): array
    {
        $st = $this->db->pdo->prepare('SELECT subject, payload FROM ledger WHERE kind = ? AND seq IN (SELECT max(seq) FROM ledger WHERE kind = ? GROUP BY subject) ORDER BY seq');
        $st->execute([$kind, $kind]);

        return array_map(static fn (array $r): array => ['subject' => (string) $r['subject'], 'payload' => json_decode((string) $r['payload'], false, 512, JSON_THROW_ON_ERROR)], $st->fetchAll());
    }

    /** 最新の1件を、連番つきで返す。なければ null */
    public function latestWithSeq(string $kind, string $subject): ?array
    {
        $st = $this->db->pdo->prepare('SELECT seq, payload FROM ledger WHERE kind = ? AND subject = ? ORDER BY seq DESC LIMIT 1');
        $st->execute([$kind, $subject]);
        $r = $st->fetch();

        return $r ? ['seq' => (int) $r['seq'], 'payload' => json_decode((string) $r['payload'], false, 512, JSON_THROW_ON_ERROR)] : null;
    }

    /** @return list<\stdClass> 古い順 */
    public function all(string $kind, string $subject): array
    {
        $st = $this->db->pdo->prepare('SELECT payload FROM ledger WHERE kind = ? AND subject = ? ORDER BY seq');
        $st->execute([$kind, $subject]);

        return array_map(static fn (array $r): \stdClass => json_decode((string) $r['payload'], false, 512, JSON_THROW_ON_ERROR), $st->fetchAll());
    }

    /** チェーンの先頭（最新）のハッシュ。外部への退避に使う（退避の仕組みは未実装） */
    public function head(): string
    {
        $r = $this->db->pdo->query('SELECT hash FROM ledger ORDER BY seq DESC LIMIT 1')->fetch();

        return $r ? (string) $r['hash'] : self::GENESIS;
    }

    /**
     * チェーン全体を検証する。壊れていれば、最初に見つかった問題を返す。
     *
     * @return string|null 問題がなければ null
     */
    public function verify(): ?string
    {
        $prev = self::GENESIS;
        $expectSeq = 1;
        foreach ($this->db->pdo->query('SELECT * FROM ledger ORDER BY seq') as $r) {
            if ((int) $r['seq'] !== $expectSeq) {
                return "連番が飛んでいます（期待{$expectSeq}、実際{$r['seq']}）";
            }
            if ($r['prev_hash'] !== $prev) {
                return "seq {$r['seq']}: 前の記録のハッシュが合いません";
            }
            $h = self::hashOf((int) $r['seq'], $r['kind'], $r['subject'], $r['payload'], $r['at'], $r['prev_hash']);
            if ($h !== $r['hash']) {
                return "seq {$r['seq']}: 記録の中身とハッシュが合いません";
            }
            $prev = $r['hash'];
            $expectSeq++;
        }

        return null;
    }
}
