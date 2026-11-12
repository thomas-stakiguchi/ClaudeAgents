<?php

namespace App\Http\Controllers;

use Src\State\Flags;

class HomeController extends Controller
{
    public function index()
    {
        $e = $this->engine;
        $plans = [];
        foreach ($e->store->listPlans() as $p) {
            $plans[] = $p + [
                'plan_approved' => $e->approvals->hasPlanApproval($p['hash']),
                'final_approved' => $e->approvals->hasAnyFinalApproval($p['hash']),
            ];
        }

        return view('home', [
            'plans' => $plans,
            'inputSets' => $e->store->listInputSets(),
            'stopFlag' => $e->flags->isSet(Flags::STOP),
            'kill' => $e->flags->isSet(Flags::KILL_SWITCH),
            'defaultScenario' => "発注管理システムにログインする。\n入力データの各行について、発注画面を開き、商品コード・数量・納品日を入力する。\n商品コード・数量・納品日を入力する。\n登録ボタンを押して、表示された発注番号を結果に記録する。",
        ]);
    }
}
