<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Src\State\Flags;

/** 画面の停止ボタンは、停止の旗を立てる「通常の停止」（協調停止）。緊急停止ではない（R12） */
class StopController extends Controller
{
    public function stop(Request $request)
    {
        $by = $this->operator($request);
        $this->engine->flags->set(Flags::STOP, true, $by.' ('.$this->osUser().')');

        return back()->with('ok', '停止の旗を立てました。実行中の手順が終わった時点で止まります');
    }

    public function clear(Request $request)
    {
        $by = $this->operator($request);
        $this->engine->flags->set(Flags::STOP, false, $by.' ('.$this->osUser().')');

        return back()->with('ok', '停止の旗を下ろしました');
    }
}
