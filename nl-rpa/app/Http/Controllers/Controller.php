<?php

namespace App\Http\Controllers;

use App\Support\Engine;
use Illuminate\Http\Request;

abstract class Controller
{
    public function __construct(protected Engine $engine)
    {
    }

    /** 操作者の名前（承認者・確認者・判定者）。画面で入力する（自己申告。LOC-3）。OSのユーザー名とは別に記録する */
    protected function operator(Request $request): string
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:50', 'regex:/\A[^\p{C}\p{Z}][^\p{C}]*\z/u'],
        ], [], ['name' => 'お名前'])['name'];
    }

    protected function osUser(): string
    {
        return Engine::osUser();
    }
}
