<?php

namespace App\Http\Controllers;

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

        return ['run' => $run, 'p' => $p, 'rows' => (array) ($rec->rows ?? []), 'irr' => $irr];
    }

    public function show(string $runId)
    {
        $d = $this->load($runId);
        $e = $this->engine;
        $table = [];
        foreach ($d['rows'] as $i => $r) {
            $states = [];
            foreach ($d['irr'] as $sid) {
                $states[$sid] = $e->states->get($r->business_key, $sid);
            }
            $table[] = ['index' => $i, 'key' => $r->business_key, 'states' => $states];
        }
        $requests = [];
        foreach ($e->store->requestsOfRun($runId) as $q) {
            $q['result'] = $q['result_json'] ? json_decode($q['result_json'], true) : null;
            $requests[] = $q;
        }

        return view('runs.show', $d + [
            'table' => $table,
            'requests' => $requests,
            'firstConfirmed' => $e->ledger->latest('first_row_confirmed', $runId) !== null,
            'rec' => $e->ledger->latest('run', $runId),
        ]);
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
        $id = $this->engine->store->createRequest('run', $d['run']['plan_hash'], (int) $d['run']['input_set_id'], $runId, $d['run']['dry_run_id'], $by);

        return back()->with('ok', "実行の要求を作りました（要求番号 {$id}）。コンソールで php artisan nlrpa:worker {$id} を実行してください");
    }

    /** 初回の1件の確認。1件目の取り消せない手順が、すべて成功していることが条件 */
    public function confirmFirst(Request $request, string $runId)
    {
        $by = $this->operator($request);
        $d = $this->load($runId);
        $first = $d['rows'][0]->business_key ?? null;
        $ok = $first !== null && $d['irr'] !== [];
        foreach ($d['irr'] as $sid) {
            $ok = $ok && $this->engine->states->get((string) $first, $sid) === StepStateStore::SUCCEEDED;
        }
        if (! $ok) {
            return back()->withErrors(['confirm' => '1件目の結果が「成功」になっていないため、確認できません']);
        }
        PlanRunner::recordFirstRowConfirmation($this->engine->ledger, $runId, $by, $this->osUser());

        return back()->with('ok', '初回の1件を確認しました。残りを実行できます');
    }

    /** 「要確認」「処理中」の解消（10-2）。人が対象システムで確認して、「実行済み」か「未実行」を記入する */
    public function resolve(Request $request, string $runId)
    {
        $by = $this->operator($request);
        $d = $this->load($runId);
        $key = (string) $request->input('business_key', '');
        $step = (string) $request->input('step_id', '');
        $verdict = (string) $request->input('verdict', '');
        $keys = array_map(fn ($r) => $r->business_key, $d['rows']);
        if (! in_array($key, $keys, true) || ! in_array($step, $d['irr'], true) || ! in_array($verdict, ['executed', 'not_executed'], true)) {
            return back()->withErrors(['resolve' => '対象が正しくありません']);
        }
        if (! in_array($this->engine->states->get($key, $step), [StepStateStore::NEEDS_REVIEW, StepStateStore::PROCESSING], true)) {
            return back()->withErrors(['resolve' => '「要確認」「処理中」ではありません']);
        }
        $this->engine->states->resolve($key, $step, $verdict === 'executed', $by.' ('.$this->osUser().')');

        return back()->with('ok', '判定を記録しました');
    }
}
