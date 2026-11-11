<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * セキュリティヘッダ（LOC-2）。枠の中への表示を禁止し、CSPを付け、CORSのヘッダは返さない。
 * 画面には、インラインのJavaScript・スタイルを使わない（CSPで禁止）。
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->remove('Access-Control-Allow-Origin');
        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
