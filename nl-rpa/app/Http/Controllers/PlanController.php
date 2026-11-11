<?php

namespace App\Http\Controllers;

use App\Support\PlanPresenter;
use Illuminate\Http\Request;
use Src\Ai\AiPlanGate;
use Src\Ai\StubAiClient;
use Src\Guard\RunRegistrar;
use Src\Policy\StubRegistrations;

class PlanController extends Controller
{
    /** 「実行計画を作る」。1aのAIはスタブ（固定の計画を返す）。出力は、信頼しない入力として、ゲートを通す */
    public function create(Request $request)
    {
        $by = $this->operator($request);
        $data = $request->validate([
            'scenario' => ['required', 'string', 'max:4000', 'regex:/\A[^\x00-\x08\x0b\x0c\x0e-\x1f\x7f]+\z/u'],
        ], [], ['scenario' => 'シナリオ']);
        $ai = new StubAiClient((string) file_get_contents(config('nlrpa.stub_dir').'/order_plan.json'));
        $out = $ai->createPlan($data['scenario'], ['商品コード', '数量', '伝票番号'], ['login_page' => 'page', 'order_page' => 'page', 'item_code' => 'input', 'quantity' => 'input', 'submit_button' => 'button', 'order_number' => 'text']);
        $r = AiPlanGate::accept(
            $out,
            scenario: $data['scenario'],
            expectedColumns: [
                '商品コード' => ['type' => 'text', 'required' => true],
                '数量' => ['type' => 'number', 'role' => 'quantity', 'required' => true],
                '伝票番号' => ['type' => 'text', 'role' => 'business_key', 'required' => true],
            ],
            elementNames: ['login_page', 'order_page', 'item_code', 'quantity', 'submit_button', 'order_number'],
            orgMaxRows: (int) config('nlrpa.org_max_rows'),
        );
        if ($r['plan'] === null) {
            return back()->withErrors(['scenario' => '実行計画を受け入れられません: '.implode(' / ', array_slice($r['errors'], 0, 3))]);
        }
        $hash = $this->engine->store->savePlan($r['plan'], StubRegistrations::hashInputs(), $data['scenario'], $by, $this->osUser());

        return redirect('/plans/'.$hash)->with('ok', '実行計画を作りました。内容を確認してください');
    }

    public function show(string $hash)
    {
        $e = $this->engine;
        $p = $e->store->getPlan($hash) ?? abort(404);
        $dry = [];
        foreach ($e->ledger->all('dry_run', $hash) as $d) {
            $dry[] = (array) $d;
        }

        return view('plans.show', [
            'p' => $p,
            'steps' => PlanPresenter::steps($p['plan'], $p['inputs']),
            'aiSupplied' => PlanPresenter::aiSuppliedIrreversible($p['plan'], $p['inputs']),
            'planApproved' => $e->approvals->hasPlanApproval($hash),
            'finalApproved' => $e->approvals->hasFinalApproval($hash),
            'dryRuns' => array_reverse($dry),
            'passedDryRuns' => array_values(array_filter($dry, fn ($d) => ($d['result'] ?? null) === 'passed')),
            'requests' => $e->store->requestsOf($hash),
            'runs' => $e->store->runsOfPlan($hash),
            'inputSets' => $e->store->listInputSets(),
        ]);
    }

    /** 計画の承認（ドライランに進むための承認）。AIが補った取り消せない手順は、1つずつ確認しないと承認できない */
    public function approvePlan(Request $request, string $hash)
    {
        $by = $this->operator($request);
        $p = $this->engine->store->getPlan($hash) ?? abort(404);
        $need = PlanPresenter::aiSuppliedIrreversible($p['plan'], $p['inputs']);
        $acked = array_values(array_filter((array) $request->input('ack', []), 'is_string'));
        $missing = array_values(array_diff($need, $acked));
        if ($missing !== []) {
            return back()->withErrors(['ack' => '「AIが補った」取り消せない手順を、1つずつ確認してください: '.implode('、', $missing)]);
        }
        $this->engine->ledger->append('approval', $hash, (object) ['stage' => 'plan', 'approver' => $by, 'os_user' => $this->osUser(), 'acked_steps' => $need]);

        return back()->with('ok', '計画を承認しました。ドライランに進めます');
    }

    /** ドライランの要求を作る（実行そのものは、ワーカーがコンソールで行う。14-3の1・3） */
    public function dryRun(Request $request, string $hash)
    {
        $by = $this->operator($request);
        $this->engine->store->getPlan($hash) ?? abort(404);
        if (! $this->engine->approvals->hasPlanApproval($hash)) {
            return back()->withErrors(['dry' => '先に、計画を承認してください']);
        }
        $inputId = $request->filled('input_set_id') ? (int) $request->input('input_set_id') : null;
        if ($inputId !== null && $this->engine->store->getInputSet($inputId) === null) {
            return back()->withErrors(['dry' => '入力データがありません']);
        }
        $dryRunId = 'DR-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
        $id = $this->engine->store->createRequest('dry_run', $hash, $inputId, null, $dryRunId, $by);

        return back()->with('ok', "ドライランの要求を作りました（要求番号 {$id}）。コンソールで php artisan nlrpa:worker {$id} を実行してください");
    }

    /** 最終承認（ドライランの結果を見たうえでの承認）。第1段階はテスト環境のみのため、作成者が承認してよい（記録に残す） */
    public function approveFinal(Request $request, string $hash)
    {
        $by = $this->operator($request);
        $p = $this->engine->store->getPlan($hash) ?? abort(404);
        $dryRunId = (string) $request->input('dry_run_id', '');
        $res = $this->engine->approvals->findPassedDryRun($hash, $dryRunId);
        if ($res['error'] !== null) {
            return back()->withErrors(['final' => '合格したドライランを選んでください: '.$res['error']]);
        }
        $this->engine->ledger->append('approval', $hash, (object) [
            'stage' => 'final', 'approver' => $by, 'os_user' => $this->osUser(), 'dry_run_id' => $dryRunId,
            'same_person_as_author' => $by === $p['created_by'],
        ]);

        return back()->with('ok', '最終承認しました');
    }

    /** 実行前のプレビュー（件数・数量・金額）。件数を入力して確認する（5章の9、R7） */
    public function preview(Request $request, string $hash)
    {
        $p = $this->engine->store->getPlan($hash) ?? abort(404);
        $set = $this->engine->store->getInputSet((int) $request->query('input_set_id')) ?? abort(404);
        $res = $this->engine->approvals->findPassedDryRun($hash, (string) $request->query('dry_run_id', ''));
        if (! $this->engine->approvals->hasFinalApproval($hash) || $res['error'] !== null) {
            return redirect('/plans/'.$hash)->withErrors(['final' => '最終承認と、合格したドライランが必要です']);
        }

        return view('plans.preview', [
            'p' => $p, 'set' => $set, 'dryRunId' => (string) $request->query('dry_run_id'),
            'sum' => RunRegistrar::summarize($p['plan'], $set['rows']),
            'limits' => $p['plan']->limits,
        ]);
    }

    /** 実行の登録。入力した件数が、入力データの件数と一致しなければ、登録しない */
    public function register(Request $request, string $hash)
    {
        $by = $this->operator($request);
        $p = $this->engine->store->getPlan($hash) ?? abort(404);
        $set = $this->engine->store->getInputSet((int) $request->input('input_set_id')) ?? abort(404);
        $count = trim((string) $request->input('count', ''));
        if (! preg_match('/\A[0-9]+\z/', $count) || (int) $count !== count($set['rows'])) {
            return back()->withErrors(['count' => '件数が一致しません。プレビューの件数を、数字で入力してください']);
        }
        $dryRunId = (string) $request->input('dry_run_id', '');
        $runId = 'RUN-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
        $errors = $this->engine->registrar->register($runId, $p['plan'], $p['inputs'], $dryRunId, 'stub', $set['rows']);
        if ($errors !== []) {
            return back()->withErrors(['register' => '登録できません: '.implode(' / ', array_slice($errors, 0, 5))]);
        }
        $this->engine->store->saveRun($runId, $hash, $set['id'], $dryRunId, $by);

        return redirect('/runs/'.$runId)->with('ok', '実行を登録しました');
    }
}
