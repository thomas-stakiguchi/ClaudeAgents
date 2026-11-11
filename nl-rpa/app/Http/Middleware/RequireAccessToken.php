<?php

namespace App\Http\Middleware;

use App\Support\AccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * アクセストークン（LOC-3）。セッションに持つのは、トークンのハッシュ。保存されているハッシュと、毎回 hash_equals で比べる。
 * 再起動でトークンが変わると、古いセッションは無効になる。
 */
class RequireAccessToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $current = app(AccessToken::class)->currentHash();
        $mine = $request->session()->get('nlrpa_token_hash');
        if ($current === null || ! is_string($mine) || ! hash_equals($current, $mine)) {
            $request->session()->forget('nlrpa_token_hash');

            return redirect('/login');
        }

        return $next($request);
    }
}
