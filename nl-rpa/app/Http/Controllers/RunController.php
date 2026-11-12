<?php

namespace App\Http\Controllers;

use App\Support\Engine;
use App\Support\PlanPresenter;
use Illuminate\Http\Request;
use Src\Runner\PlanRunner;
use Src\State\StepStateStore;

class RunController extends Controller
{
    /** @return array{run:array<string,mixed>, p:array<string,mixed>, rows:list<object>, irr:list<string>} */
    private function load(string $runId): array
    {
        $run = $this->engine->store->getRun($runId) ?? abort(404);
        $p = $this->engine->store->getPlan($run['plan_hash']) ?? abort(404);
        $rec = $this->engine->ledger->latest('run', $runId) ?? abort(404);
        // for_each_row の中の、取り消せない手順
        $irr = PlanPresenter::irreversibleIds($p['plan'], $p['inputs']);

        return ['run' => $run, 'p' => $p, 'rows' => (array) ($rec->rows ?? []), 'irr' => $irr, 'effects' => PlanPresenter::effectMap($p['plan'], $p['inputs'])];
    }

    public function show(string $runId)
    {
        $d = $this->load($runId);
        $e = $this->engine;
        $table = [];
        foreach ($d['rows'] as $i => $r) {
            $states = [];
            foreach ($d['irr'] as $sid) {
                $states[$sid] = $e->states->get($r->business_key, $d['effects'][$sid] ?? $sid);
            }
            $table[] = ['index' => $i, 'key' => $r->business_key, 'states' => $states];
        }
        $requests = [];
        foreach ($e->store->requestsOfRun($runId, 50) as $q) {
            $q['result'] = $q['result_json'] ? json_decode($q['result_json'], true) : null;
            $requests[] = $q;
        }

        // 業務キーごとの、最新の「実行した」結果（新しい要求から順に見て、skipped を除いた、最初のもの）と、最新の要求の手順ごとの結果。
        // skipped（前の呼び出しで成功済みで、今回は飛ばした行）は、読んだ値を持たないため、古い結果を消さないよう、除く
        [$latest, $readKeys] = $this->collectLatest($requests);
        $stepRows = null;
        foreach ($requests as $q) {
            if (! empty($q['result']['rows'])) {
                $stepRows = $q['result']['rows'];
                break;
            }
        }
        // 状態は、状態の表（step_states）から出す。要求の結果の status は、その時点のもので、人が「要確認」を解決したあとは、古くなる
        $stateNames = ['succeeded' => '成功', 'failed' => '失敗（再実行の対象）', 'needs_review' => '要確認', 'processing' => '処理中'];
        $rowStates = [];
        foreach ($d['rows'] as $r) {
            $parts = [];
            foreach ($d['irr'] as $sid) {
                $parts[] = $sid.'：'.($stateNames[$this->engine->states->get($r->business_key, $d['effects'][$sid] ?? $sid)] ?? '未実行');
            }
            $rowStates[$r->business_key] = implode('、', $parts);
        }

        return view('runs.show', $d + [
            'latest' => $latest,
            'readKeys' => $readKeys,
            'rowStates' => $rowStates,
            'stepRows' => $stepRows ?? [],
            'table' => $table,
            'requests' => $requests,
            'browserMode' => $e->browserMode(),
            'stubOrders' => $e->stubOrders(),
            'firstConfirmed' => $e->ledger->latest('first_row_confirmed', $runId) !== null,
            'rec' => $e->ledger->latest('run', $runId),
        ]);
    }

    /**
     * 業務キーごとの、最新の「実行した」結果と、読んだ値の項目名。
     *
     * @param list<array<string,mixed>> $requests 新しい順。各要求は、'result' を持つ
     * @return array{0:array<string,array<string,mixed>>, 1:list<string>}
     */
    private function collectLatest(array $requests): array
    {
        $latest = [];
        foreach ($requests as $q) {
            foreach ($q['result']['rows'] ?? [] as $row) {
                if (($row['status'] ?? '') === 'skipped') {
                    continue; // 飛ばした行は、結果を持たない。古い結果を、消さない
                }
                $latest[$row['business_key']] ??= $row;
            }
        }
        $keys = [];
        foreach ($latest as $row) {
            foreach (array_keys($row['reads'] ?? []) as $k) {
                $keys[$k] = true;
            }
        }

        return [$latest, array_keys($keys)];
    }

    /**
     * CSVの1つのセルを、表計算ソフトで式として実行されないようにする（SEC-14）。
     * 先頭の空白・改行・制御文字・全角の空白を飛ばした、最初の文字が「=」「+」「-」「@」（全角を含む）なら、先頭に「'」を付ける。
     * 先頭が、タブ・CR・LF の場合も、付ける。不正なUTF-8は、置き換える。
     */
    public static function csvSafe(string $v): string
    {
        $v = mb_scrub($v, 'UTF-8');
        if (preg_match('/\A[\t\r\n]/u', $v) || preg_match('/\A[\s\x00-\x1f\x{3000}\x{00a0}\x{200b}\x{feff}]*[=+\-@＝＋－＠]/u', $v)) {
            return "'".$v;
        }

        return $v;
    }

    /**
     * 結果のCSV（5章の10）。業務キー、手順ごとの状態、画面から読んだ値。値のエスケープは csvSafe（SEC-14）。
     * 注意：読んだ値は、personal_info のマスクの対象外で、そのまま出る（SEC-9の方針は、1bの前に決める）。
     */
    public function csv(string $runId)
    {
        $d = $this->load($runId);
        $requests = [];
        foreach ($this->engine->store->requestsOfRun($runId, 50) as $q) {
            $q['result'] = $q['result_json'] ? json_decode($q['result_json'], true) : null;
            $requests[] = $q;
        }
        [$latest, $readKeys] = $this->collectLatest($requests);
        $names = ['succeeded' => '成功', 'failed' => '失敗', 'needs_review' => '要確認', 'processing' => '処理中'];
        $safe = static fn (string $v): string => self::csvSafe($v);
        $header = array_merge(['業務キー'], array_map(fn ($sid) => "状態 {$sid}", $d['irr']), array_map(fn ($k) => "読んだ値 {$k}", $readKeys));
        $lines = [$header];
        foreach ($d['rows'] as $r) {
            $row = [$r->business_key];
            foreach ($d['irr'] as $sid) {
                $row[] = $names[$this->engine->states->get($r->business_key, $d['effects'][$sid] ?? $sid)] ?? '未実行';
            }
            foreach ($readKeys as $k) {
                $row[] = (string) ($latest[$r->business_key]['reads'][$k] ?? '');
            }
            $lines[] = $row;
        }

        return response()->streamDownload(function () use ($lines, $safe): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // Excelで、UTF-8として開けるように
            foreach ($lines as $line) {
                fputcsv($out, array_map(fn ($v) => $safe((string) $v), $line), ',', '"', '');
            }
            fclose($out);
        }, $runId.'_result.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** 実行の要求を作る（実行そのものは、ワーカーがコンソールで行う）。同じ実行に、待ち・実行中の要求があれば、作らない */
    public function start(Request $request, string $runId)
    {
        $by = $this->operator($request);
        $d = $this->load($runId);
        foreach ($this->engine->store->requestsOfRun($runId) as $q) {
            if (in_array($q['status'], ['queued', 'running'], true)) {
                return back()->withErrors(['start' => 'この実行には、待ち・実行中の要求がすでにあります']);
            }
        }
        if (! Engine::inputsAreCurrent($d['p']['inputs'])) {
            return back()->withErrors(['start' => '許可リスト・個別登録が、承認のときから変わっています。計画を作り直して、再承認してください']);
        }
        $id = $this->engine->store->createRequest('run', $d['run']['plan_hash'], (int) $d['run']['input_set_id'], $runId, $d['run']['dry_run_id'], $by);
        $this->audit('request_created', 'request:'.$id, $by, ['kind' => 'run', 'run_id' => $runId, 'plan_hash' => $d['run']['plan_hash']]);

        return back()->with('ok', "実行の要求を作りました（要求番号 {$id}）。コンソールで php artisan nlrpa:worker {$id} を実行してください");
    }

    /**
     * 初回の1件の確認。確認の対象は、この実行で最初に実行した行。その行の取り消せない手順が、すべて「成功」であることが条件。
     * 前の実行で成功した行は、対象にならない（この実行では、まだ何も実行していないかもしれない）。
     */
    public function confirmFirst(Request $request, string $runId)
    {
        $by = $this->operator($request);
        $d = $this->load($runId);
        $err = PlanRunner::recordFirstRowConfirmation($this->engine->ledger, $this->engine->states, $runId, $d['rows'], array_values($d['effects']), $by, $this->osUser());
        if ($err !== null) {
            return back()->withErrors(['confirm' => $err]);
        }

        return back()->with('ok', '初回の1件を確認しました。残りを実行できます');
    }

    /** 「要確認」「処理中」の解消（10-2）。人が対象システムで確認して、「実行済み」か「未実行」を記入する */
    public function resolve(Request $request, string $runId)
    {
        $by = $this->operator($request);
        $d = $this->load($runId);
        $key = $this->str($request, 'business_key');
        $step = $this->str($request, 'step_id');
        $verdict = $this->str($request, 'verdict');
        $keys = array_map(fn ($r) => $r->business_key, $d['rows']);
        if (! in_array($key, $keys, true) || ! in_array($step, $d['irr'], true) || ! in_array($verdict, ['executed', 'not_executed'], true)) {
            return back()->withErrors(['resolve' => '対象が正しくありません']);
        }
        // 判定できるのは「要確認」だけ。「処理中」は、ワーカーが動いている間かもしれない。先に nlrpa:recover で「要確認」にする
        $effect = $d['effects'][$step] ?? $step;
        if ($this->engine->states->get($key, $effect) !== StepStateStore::NEEDS_REVIEW) {
            return back()->withErrors(['resolve' => '「要確認」ではありません（「処理中」は、ワーカーが止まっていることを確かめて、php artisan nlrpa:recover を実行してから判定してください）']);
        }
        $this->engine->states->resolve($key, $effect, $verdict === 'executed', $by.' ('.$this->osUser().')');

        return back()->with('ok', '判定を記録しました');
    }
}
