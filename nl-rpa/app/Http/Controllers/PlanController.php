<?php

namespace App\Http\Controllers;

use App\Support\Engine;
use App\Support\PlanDiff;
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
            // 監査の記録は、AIの自己申告ではなく、実際に呼んだAIと、実行エンジンの持つ版にする
            engineFacts: $ai->identity() + [
                'operations' => (int) StubRegistrations::hashInputs()['operations']->version,
                'destinations' => (int) StubRegistrations::hashInputs()['destinations']->version,
                'screen_record_version' => (int) StubRegistrations::hashInputs()['screen_record']->version,
            ],
        );
        if ($r['plan'] === null) {
            return back()->withErrors(['scenario' => '実行計画を受け入れられません: '.implode(' / ', array_slice($r['errors'], 0, 3))]);
        }
        $hash = $this->engine->store->savePlan($r['plan'], StubRegistrations::hashInputs(), $data['scenario'], $by, $this->osUser());
        $this->audit('plan_created', $hash, $by, ['ai_model' => $r['plan']->created_by_ai->model, 'ai_prompt_version' => $r['plan']->created_by_ai->prompt_version, 'ai_sent_hash' => 'sha256:'.hash('sha256', $data['scenario'])]);

        return redirect('/plans/'.$hash)->with('ok', '実行計画を作りました。内容を確認してください');
    }

    /** 許可リスト・個別登録が、承認のときから変わっていれば、操作を止める（再承認が必要）。変わっていなければ null */
    private function staleError(array $p): ?string
    {
        return Engine::inputsAreCurrent($p['inputs'])
            ? null
            : '許可リスト・個別登録が、この計画を作ったときから変わっています。計画を作り直して、あらためて承認してください';
    }

    /**
     * 比べる相手の既定：同じ plan_id の、古い計画のうち、最新の「最終承認がある」もの。なければ、同じ plan_id の、直前の古い計画。
     * 同じ plan_id がなければ、別の計画の中から、同じ基準で選ぶ（その場合、$sameId は false。画面に、その旨を出す）。
     *
     * @return array{0:string|null, 1:bool} [計画ハッシュ, 同じ plan_id か]
     */
    private function previousPlan(string $hash): array
    {
        $all = $this->engine->store->listPlans(); // 新しい順
        $idx = null;
        foreach ($all as $i => $row) {
            if ($row['hash'] === $hash) {
                $idx = $i;
                break;
            }
        }
        $me = $idx !== null ? $this->engine->store->getPlan($hash) : null;
        if ($me === null) {
            return [null, true];
        }
        $older = array_slice($all, $idx + 1, 100);
        $same = [];
        foreach ($older as $row) {
            $p = $this->engine->store->getPlan($row['hash']);
            if ($p !== null && ($p['plan']->plan_id ?? null) === ($me['plan']->plan_id ?? null)) {
                $same[] = $row['hash'];
            }
        }
        foreach ([[$same, true], [array_column($older, 'hash'), false]] as [$cands, $sameId]) {
            foreach ($cands as $h) {
                if ($this->engine->approvals->hasAnyFinalApproval($h)) {
                    return [$h, $sameId];
                }
            }
            if ($cands !== []) {
                return [$cands[0], $sameId];
            }
        }

        return [null, true];
    }

    /** 2つの計画の差分（R3）。比べる相手は、?against= で指定（既定は、前の計画） */
    public function diff(Request $request, string $hash)
    {
        $new = $this->engine->store->getPlan($hash) ?? abort(404);
        $againstQ = $request->query('against');
        $sameId = true;
        if (is_string($againstQ) && preg_match('/\Asha256:[0-9a-f]{64}\z/', $againstQ)) {
            $against = $againstQ;
        } else {
            [$against, $sameId] = $this->previousPlan($hash);
        }
        $old = $against !== null ? $this->engine->store->getPlan($against) : null;
        $sameId = $sameId && ($old === null || ($old['plan']->plan_id ?? null) === ($new['plan']->plan_id ?? null));
        $changes = $old !== null ? PlanDiff::compare($old['plan'], $old['inputs'], $new['plan'], $new['inputs']) : [];

        return view('plans.diff', [
            'p' => $new, 'old' => $old, 'changes' => $changes, 'sameId' => $sameId,
            'oldApproved' => $old !== null && $this->engine->approvals->hasAnyFinalApproval($old['hash']),
            'others' => array_values(array_filter($this->engine->store->listPlans(), fn ($r) => $r['hash'] !== $hash)),
        ]);
    }

    public function show(string $hash)
    {
        $e = $this->engine;
        $p = $e->store->getPlan($hash) ?? abort(404);
        $dry = [];
        foreach ($e->ledger->all('dry_run', $hash) as $d) {
            $dry[] = (array) $d;
        }

        [$prev, $prevSameId] = $this->previousPlan($hash);
        $prevPlan = $prev !== null ? $e->store->getPlan($prev) : null;
        $changes = $prevPlan !== null ? PlanDiff::compare($prevPlan['plan'], $prevPlan['inputs'], $p['plan'], $p['inputs']) : [];
        $requests = [];
        foreach ($e->store->requestsOf($hash) as $q) {
            $q['result'] = $q['result_json'] ? json_decode($q['result_json'], true) : null;
            $requests[] = $q;
        }

        return view('plans.show', [
            'columns' => $p['plan']->input->columns,
            'prevHash' => $prev,
            'prevSameId' => $prevSameId,
            'prevApproved' => $prev !== null && $e->approvals->hasAnyFinalApproval($prev),
            'changeCount' => count($changes),
            'importantCount' => count(array_filter($changes, fn ($c) => $c['important'])),
            'stale' => $this->staleError($p),
            'p' => $p,
            'steps' => PlanPresenter::steps($p['plan'], $p['inputs']),
            'aiSupplied' => PlanPresenter::aiSuppliedIrreversible($p['plan'], $p['inputs']),
            'planApproved' => $e->approvals->hasPlanApproval($hash),
            'finalApproved' => $e->approvals->hasAnyFinalApproval($hash),
            'dryRuns' => array_reverse($dry),
            'passedDryRuns' => array_values(array_filter($dry, fn ($d) => ($d['result'] ?? null) === 'passed')),
            'requests' => $requests,
            'runs' => $e->store->runsOfPlan($hash),
            'inputSets' => $e->store->listInputSets(),
        ]);
    }

    /** 計画の承認（ドライランに進むための承認）。AIが補った取り消せない手順は、1つずつ確認しないと承認できない */
    public function approvePlan(Request $request, string $hash)
    {
        $by = $this->operator($request);
        $p = $this->engine->store->getPlan($hash) ?? abort(404);
        if (($e = $this->staleError($p)) !== null) {
            return back()->withErrors(['stale' => $e]);
        }
        $need = PlanPresenter::aiSuppliedIrreversible($p['plan'], $p['inputs']);
        $acked = array_values(array_filter((array) $request->input('ack', []), 'is_string'));
        $missing = array_values(array_diff($need, $acked));
        if ($missing !== []) {
            return back()->withErrors(['ack' => '「AIが補った」取り消せない手順を、1つずつ確認してください: '.implode('、', $missing)]);
        }
        $this->engine->ledger->append('approval', $hash, (object) ['stage' => 'plan', 'approver' => $by, 'os_user' => $this->osUser(), 'acked_steps' => $need, 'same_person_as_author' => $by === $p['created_by'], 'host' => gethostname() ?: 'unknown']);

        return back()->with('ok', '計画を承認しました。ドライランに進めます');
    }

    /** ドライランの要求を作る（実行そのものは、ワーカーがコンソールで行う。14-3の1・3） */
    public function dryRun(Request $request, string $hash)
    {
        $by = $this->operator($request);
        $p = $this->engine->store->getPlan($hash) ?? abort(404);
        if (($e = $this->staleError($p)) !== null) {
            return back()->withErrors(['stale' => $e]);
        }
        if (! $this->engine->approvals->hasPlanApproval($hash)) {
            return back()->withErrors(['dry' => '先に、計画を承認してください']);
        }
        $inputId = $request->filled('input_set_id') ? (int) $this->str($request, 'input_set_id') : null;
        if ($inputId !== null && $this->engine->store->getInputSet($inputId) === null) {
            return back()->withErrors(['dry' => '入力データがありません']);
        }
        $dryRunId = 'DR-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
        $id = $this->engine->store->createRequest('dry_run', $hash, $inputId, null, $dryRunId, $by);
        $this->audit('request_created', 'request:'.$id, $by, ['kind' => 'dry_run', 'plan_hash' => $hash, 'dry_run_id' => $dryRunId, 'input_set_id' => $inputId]);

        return back()->with('ok', "ドライランの要求を作りました（要求番号 {$id}）。コンソールで php artisan nlrpa:worker {$id} を実行してください");
    }

    /** 最終承認（ドライランの結果を見たうえでの承認）。第1段階はテスト環境のみのため、作成者が承認してよい（記録に残す） */
    public function approveFinal(Request $request, string $hash)
    {
        $by = $this->operator($request);
        $p = $this->engine->store->getPlan($hash) ?? abort(404);
        if (($e = $this->staleError($p)) !== null) {
            return back()->withErrors(['stale' => $e]);
        }
        $dryRunId = $this->str($request, 'dry_run_id');
        $res = $this->engine->approvals->findPassedDryRun($hash, $dryRunId);
        if ($res['error'] !== null) {
            return back()->withErrors(['final' => '合格したドライランを選んでください: '.$res['error']]);
        }
        $this->engine->ledger->append('approval', $hash, (object) [
            'stage' => 'final', 'approver' => $by, 'os_user' => $this->osUser(), 'dry_run_id' => $dryRunId,
            'same_person_as_author' => $by === $p['created_by'], 'host' => gethostname() ?: 'unknown',
        ]);

        return back()->with('ok', '最終承認しました');
    }

    /** 実行前のプレビュー（件数・数量・金額）。件数を入力して確認する（5章の9、R7） */
    public function preview(Request $request, string $hash)
    {
        $p = $this->engine->store->getPlan($hash) ?? abort(404);
        $set = $this->engine->store->getInputSet((int) (is_string($request->query('input_set_id')) ? $request->query('input_set_id') : 0)) ?? abort(404);
        $res = $this->engine->approvals->findPassedDryRun($hash, (is_string($request->query('dry_run_id')) ? $request->query('dry_run_id') : ''));
        if (! $this->engine->approvals->hasFinalApproval($hash, (is_string($request->query('dry_run_id')) ? $request->query('dry_run_id') : '')) || $res['error'] !== null) {
            return redirect('/plans/'.$hash)->withErrors(['final' => '最終承認と、合格したドライランが必要です']);
        }

        return view('plans.preview', [
            'p' => $p, 'set' => $set, 'dryRunId' => (is_string($request->query('dry_run_id')) ? $request->query('dry_run_id') : ''),
            'sum' => RunRegistrar::summarize($p['plan'], $set['rows']),
            'keyColumn' => collect($p['plan']->input->columns)->first(fn ($c) => ($c->role ?? null) === 'business_key')->name ?? '',
            'limits' => $p['plan']->limits,
        ]);
    }

    /** 実行の登録。入力した件数が、入力データの件数と一致しなければ、登録しない */
    public function register(Request $request, string $hash)
    {
        $by = $this->operator($request);
        $p = $this->engine->store->getPlan($hash) ?? abort(404);
        if (($e = $this->staleError($p)) !== null) {
            return back()->withErrors(['stale' => $e]);
        }
        $set = $this->engine->store->getInputSet((int) $this->str($request, 'input_set_id')) ?? abort(404);
        $count = trim($this->str($request, 'count'));
        if (! preg_match('/\A[0-9]+\z/', $count) || (int) $count !== count($set['rows'])) {
            return back()->withErrors(['count' => '件数が一致しません。プレビューの件数を、数字で入力してください']);
        }
        $dryRunId = $this->str($request, 'dry_run_id');
        $runId = 'RUN-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
        $errors = $this->engine->registrar->register($runId, $p['plan'], $p['inputs'], $dryRunId, 'stub', $set['rows']);
        if ($errors !== []) {
            return back()->withErrors(['register' => '登録できません: '.implode(' / ', array_slice($errors, 0, 5))]);
        }
        $this->engine->store->saveRun($runId, $hash, $set['id'], $dryRunId, $by);
        $this->audit('run_registered', $runId, $by, ['plan_hash' => $hash, 'input_set_id' => $set['id'], 'input_sha256' => $set['sha256'], 'dry_run_id' => $dryRunId, 'rows' => count($set['rows'])]);

        return redirect('/runs/'.$runId)->with('ok', '実行を登録しました');
    }
}
