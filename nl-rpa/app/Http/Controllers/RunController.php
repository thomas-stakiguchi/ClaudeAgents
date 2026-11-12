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
            'browserMode' => $e->browserMode(),
            'stubOrders' => $e->stubOrders(),
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
        $err = PlanRunner::recordFirstRowConfirmation($this->engine->ledger, $this->engine->states, $runId, $d['rows'], $d['irr'], $by, $this->osUser());
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
        if ($this->engine->states->get($key, $step) !== StepStateStore::NEEDS_REVIEW) {
            return back()->withErrors(['resolve' => '「要確認」ではありません（「処理中」は、ワーカーが止まっていることを確かめて、php artisan nlrpa:recover を実行してから判定してください）']);
        }
        $this->engine->states->resolve($key, $step, $verdict === 'executed', $by.' ('.$this->osUser().')');

        return back()->with('ok', '判定を記録しました');
    }
}
