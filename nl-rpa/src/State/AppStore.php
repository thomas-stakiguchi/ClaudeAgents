<?php

declare(strict_types=1);

namespace Src\State;

use Src\Plan\CanonicalJson;
use Src\Plan\PlanHasher;
use Src\Plan\StrictJson;

/**
 * 画面とワーカーが使う保存（計画、入力データ、実行、実行要求、ワーカー）。PDOを直接使う（仕様14-3）。
 * 承認・状態の正本は Ledger / StepStateStore。ここは、それらを引くための「索引」と、実行要求の受け渡し。
 * 計画と許可リスト等の中身は、計画ハッシュをキーに保存する（同じ内容は、同じキー）。
 */
final class AppStore
{
    /** @param \Closure():string $clock */
    public function __construct(private readonly Db $db, private readonly \Closure $clock)
    {
    }

    /**
     * 計画を保存する。キーは、計画ハッシュ（実行エンジンが計算）。
     *
     * @param array<string,mixed> $hashInputs
     */
    public function savePlan(\stdClass $plan, array $hashInputs, string $scenario, string $by, string $osUser): string
    {
        $hash = PlanHasher::hash($plan, $hashInputs);
        $st = $this->db->pdo->prepare('INSERT OR IGNORE INTO plans (hash, plan_json, inputs_json, scenario, created_by, os_user, created_at) VALUES (?,?,?,?,?,?,?)');
        $st->execute([$hash, CanonicalJson::encode($plan), CanonicalJson::encode((object) $hashInputs), $scenario, $by, $osUser, ($this->clock)()]);

        return $hash;
    }

    /** @return array{hash:string, plan:\stdClass, inputs:array<string,mixed>, scenario:string, created_by:string, os_user:string, created_at:string}|null */
    public function getPlan(string $hash): ?array
    {
        $st = $this->db->pdo->prepare('SELECT * FROM plans WHERE hash = ?');
        $st->execute([$hash]);
        $r = $st->fetch();
        if (! $r) {
            return null;
        }
        /** @var \stdClass $plan */
        $plan = StrictJson::decode($r['plan_json']);
        /** @var \stdClass $inputs */
        $inputs = StrictJson::decode($r['inputs_json']);

        // 保存した内容から、計画ハッシュを計算し直して、キーと一致することを確かめる（保存先の書き換えの検知。LOC-8：完全ではない）
        $inputsArr = get_object_vars($inputs);
        if (PlanHasher::hash($plan, $inputsArr) !== $hash) {
            throw new \RuntimeException('保存された計画の内容が、計画ハッシュと一致しません（書き換えの疑い）');
        }

        return ['hash' => $hash, 'plan' => $plan, 'inputs' => $inputsArr, 'scenario' => $r['scenario'], 'created_by' => $r['created_by'], 'os_user' => $r['os_user'], 'created_at' => $r['created_at']];
    }

    /** @return list<array<string,string>> 新しい順 */
    public function listPlans(): array
    {
        return $this->db->pdo->query('SELECT hash, created_by, created_at FROM plans ORDER BY created_at DESC, rowid DESC')->fetchAll();
    }

    /**
     * 入力データを、読み込み時に取り込んで固定する（SEC-14）。ハッシュを返す内容とともに保存する。
     *
     * @param list<array<string,string>> $rows
     */
    public function saveInputSet(array $rows, string $by): int
    {
        $json = CanonicalJson::encode(array_map(static fn (array $r): \stdClass => (object) $r, $rows));
        $st = $this->db->pdo->prepare('INSERT INTO input_sets (sha256, rows_json, created_by, created_at) VALUES (?,?,?,?)');
        $st->execute(['sha256:'.hash('sha256', $json), $json, $by, ($this->clock)()]);

        return (int) $this->db->pdo->lastInsertId();
    }

    /** @return array{id:int, sha256:string, rows:list<array<string,string>>, created_by:string, created_at:string}|null */
    public function getInputSet(int $id): ?array
    {
        $st = $this->db->pdo->prepare('SELECT * FROM input_sets WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if (! $r) {
            return null;
        }
        // 取り込んだ内容が、保存時のハッシュと変わっていないことを確かめる（SEC-14）
        if ('sha256:'.hash('sha256', $r['rows_json']) !== $r['sha256']) {
            throw new \RuntimeException('入力データが、取り込み時のハッシュと一致しません（書き換えの疑い）');
        }
        $rows = [];
        foreach ((array) StrictJson::decode($r['rows_json']) as $row) {
            $rows[] = array_map('strval', get_object_vars($row));
        }

        return ['id' => (int) $r['id'], 'sha256' => $r['sha256'], 'rows' => $rows, 'created_by' => $r['created_by'], 'created_at' => $r['created_at']];
    }

    /** @return list<array<string,mixed>> */
    public function listInputSets(): array
    {
        $out = [];
        foreach ($this->db->pdo->query('SELECT id, sha256, rows_json, created_by, created_at FROM input_sets ORDER BY id DESC')->fetchAll() as $r) {
            $out[] = ['id' => (int) $r['id'], 'sha256' => $r['sha256'], 'count' => count((array) StrictJson::decode($r['rows_json'])), 'created_by' => $r['created_by'], 'created_at' => $r['created_at']];
        }

        return $out;
    }

    public function saveRun(string $runId, string $planHash, int $inputSetId, string $dryRunId, string $by): void
    {
        $st = $this->db->pdo->prepare('INSERT INTO runs (run_id, plan_hash, input_set_id, dry_run_id, created_by, created_at) VALUES (?,?,?,?,?,?)');
        $st->execute([$runId, $planHash, $inputSetId, $dryRunId, $by, ($this->clock)()]);
    }

    /** @return array<string,mixed>|null */
    public function getRun(string $runId): ?array
    {
        $st = $this->db->pdo->prepare('SELECT * FROM runs WHERE run_id = ?');
        $st->execute([$runId]);

        return $st->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function runsOfPlan(string $planHash): array
    {
        $st = $this->db->pdo->prepare('SELECT * FROM runs WHERE plan_hash = ? ORDER BY created_at DESC, rowid DESC');
        $st->execute([$planHash]);

        return $st->fetchAll();
    }

    /** 実行要求を作る（画面から。実行そのものはワーカーが行う。14-3の1）。ID を返す */
    public function createRequest(string $kind, string $planHash, ?int $inputSetId, ?string $runId, ?string $dryRunId, string $by): int
    {
        $st = $this->db->pdo->prepare("INSERT INTO requests (kind, plan_hash, input_set_id, run_id, dry_run_id, status, created_by, created_at) VALUES (?,?,?,?,?, 'queued', ?, ?)");
        $st->execute([$kind, $planHash, $inputSetId, $runId, $dryRunId, $by, ($this->clock)()]);

        return (int) $this->db->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function getRequest(int $id): ?array
    {
        $st = $this->db->pdo->prepare('SELECT * FROM requests WHERE id = ?');
        $st->execute([$id]);

        return $st->fetch() ?: null;
    }

    /** 待ちの要求を、実行中にする。取れたら true（同じ要求を2つのワーカーが取らないよう、1つの更新で行う） */
    public function claimRequest(int $id): bool
    {
        return $this->db->immediate(function () use ($id): bool {
            $st = $this->db->pdo->prepare("UPDATE requests SET status = 'running', started_at = ? WHERE id = ? AND status = 'queued'");
            $st->execute([($this->clock)(), $id]);

            return $st->rowCount() === 1;
        });
    }

    /** @param array<string,mixed> $result */
    public function finishRequest(int $id, array $result): void
    {
        $st = $this->db->pdo->prepare("UPDATE requests SET status = 'done', result_json = ?, finished_at = ? WHERE id = ?");
        $st->execute([json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), ($this->clock)(), $id]);
    }

    /** 異常終了で「実行中」のまま残った要求を、「完了（異常終了）」にする。件数を返す */
    public function failStaleRunning(): int
    {
        $st = $this->db->pdo->prepare("UPDATE requests SET status = 'done', result_json = ?, finished_at = ? WHERE status = 'running'");
        $st->execute([json_encode(['status' => 'aborted', 'reasons' => ['ワーカーが異常終了した可能性があります']], JSON_UNESCAPED_UNICODE), ($this->clock)()]);

        return $st->rowCount();
    }

    /** @return list<array<string,mixed>> 新しい順 */
    public function requestsOf(string $planHash, int $limit = 20): array
    {
        $st = $this->db->pdo->prepare('SELECT * FROM requests WHERE plan_hash = ? ORDER BY id DESC LIMIT ?');
        $st->bindValue(1, $planHash);
        $st->bindValue(2, $limit, \PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function requestsOfRun(string $runId, int $limit = 20): array
    {
        $st = $this->db->pdo->prepare('SELECT * FROM requests WHERE run_id = ? ORDER BY id DESC LIMIT ?');
        $st->bindValue(1, $runId);
        $st->bindValue(2, $limit, \PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll();
    }

    /** @param string|null $procStart プロセスの開始時刻（/proc の starttime）。緊急停止が、PIDの再利用を見分けるのに使う */
    public function registerWorker(int $pid, int $requestId, ?string $procStart = null): void
    {
        $st = $this->db->pdo->prepare('INSERT OR REPLACE INTO workers (pid, request_id, started_at, proc_start) VALUES (?,?,?,?)');
        $st->execute([$pid, $requestId, ($this->clock)(), $procStart]);
    }

    public function unregisterWorker(int $pid): void
    {
        $st = $this->db->pdo->prepare('DELETE FROM workers WHERE pid = ?');
        $st->execute([$pid]);
    }

    /** @return list<array{pid:int, request_id:int, started_at:string, proc_start:string|null}> */
    public function workers(): array
    {
        return array_map(
            static fn (array $r): array => ['pid' => (int) $r['pid'], 'request_id' => (int) $r['request_id'], 'started_at' => $r['started_at'], 'proc_start' => $r['proc_start'] ?? null],
            $this->db->pdo->query('SELECT * FROM workers')->fetchAll(),
        );
    }

    public function registerProfile(string $dir, int $workerPid): void
    {
        $st = $this->db->pdo->prepare('INSERT OR REPLACE INTO browser_profiles (dir, worker_pid, created_at) VALUES (?,?,?)');
        $st->execute([$dir, $workerPid, ($this->clock)()]);
    }

    public function unregisterProfile(string $dir): void
    {
        $st = $this->db->pdo->prepare('DELETE FROM browser_profiles WHERE dir = ?');
        $st->execute([$dir]);
    }

    /** @return list<array{dir:string, worker_pid:int}> */
    public function profiles(?int $workerPid = null): array
    {
        $rows = $workerPid === null
            ? $this->db->pdo->query('SELECT dir, worker_pid FROM browser_profiles ORDER BY created_at')->fetchAll()
            : (function () use ($workerPid): array {
                $st = $this->db->pdo->prepare('SELECT dir, worker_pid FROM browser_profiles WHERE worker_pid = ? ORDER BY created_at');
                $st->execute([$workerPid]);

                return $st->fetchAll();
            })();

        return array_map(static fn (array $r): array => ['dir' => (string) $r['dir'], 'worker_pid' => (int) $r['worker_pid']], $rows);
    }
}
