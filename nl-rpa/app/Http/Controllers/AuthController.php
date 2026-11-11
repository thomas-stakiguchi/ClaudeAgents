<?php

namespace App\Http\Controllers;

use App\Support\AccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function show()
    {
        return view('login');
    }

    public function login(Request $request, AccessToken $tokens)
    {
        $key = 'nlrpa-login:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, (int) config('nlrpa.login_max_attempts'))) {
            return back()->withErrors(['token' => '失敗が続いたため、しばらく入力できません']);
        }
        $token = $this->str($request, 'token');
        if ($token === '' || ! $tokens->verify($token)) {
            RateLimiter::hit($key, 300);

            return back()->withErrors(['token' => 'アクセストークンが違います']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('nlrpa_token_hash', $tokens->currentHash());

        return redirect('/');
    }

    public function logout(Request $request)
    {
        $request->session()->invalidate();

        return redirect('/login');
    }
}
