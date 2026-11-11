<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 他のWebページからの攻撃対策（LOC-2）。
 *  - Hostヘッダが、設定したポートの localhost、127.0.0.1、[::1] 以外なら拒否（DNSリバインディング対策）
 *  - 状態を変える要求（GET・HEAD以外）は、POSTのみ。Originが必須で、ポートまで含めた完全一致
 */
class LocalRequestGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $port = (int) config('nlrpa.port');
        $host = $request->headers->get('host');
        $allowed = ["localhost:{$port}", "127.0.0.1:{$port}", "[::1]:{$port}"];
        if (! is_string($host) || ! in_array(strtolower($host), $allowed, true)) {
            return response('許可されていないHostです', 403)->header('Content-Type', 'text/plain; charset=UTF-8');
        }
        $method = $request->getMethod();
        if (! in_array($method, ['GET', 'HEAD'], true)) {
            if ($method !== 'POST') {
                return response('許可されていないメソッドです', 405)->header('Content-Type', 'text/plain; charset=UTF-8');
            }
            $origin = $request->headers->get('origin');
            if (! is_string($origin) || strtolower($origin) !== 'http://'.strtolower($host)) {
                return response('Originが一致しません', 403)->header('Content-Type', 'text/plain; charset=UTF-8');
            }
        }

        return $next($request);
    }
}
