<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // HTML（SPA shell）にだけCSPを付ける
        $contentType = (string) $response->headers->get('Content-Type', '');
        $isHtml =
            str_contains($contentType, 'text/html') ||
            str_contains($contentType, 'application/xhtml+xml');

        if ($isHtml) {
            $response->headers->set('Content-Security-Policy', $this->buildCsp());
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        return $response;
    }

    private function buildCsp(): string
    {
        // ★本番はコード側で強制的に締める（.env事故で unsafe-* / dev origin が混入しないように）
        $isProd = app()->environment('production');

        // 追加許可（dev用）：prod では強制無効
        $extraHttp = $isProd
            ? []
            : $this->sanitizeSources((string) env('CSP_EXTRA_HTTP', '')); // 例: "http://127.0.0.1:5173 http://localhost:5173"
        $extraWs = $isProd
            ? []
            : $this->sanitizeSources((string) env('CSP_EXTRA_WS', ''));   // 例: "ws://127.0.0.1:5173 ws://localhost:5173"

        // unsafe-*：prod では強制 false
        $allowStyleInline = $isProd
            ? false
            : filter_var(env('CSP_STYLE_UNSAFE_INLINE', 'false'), FILTER_VALIDATE_BOOL);

        $allowScriptEval = $isProd
            ? false
            : filter_var(env('CSP_SCRIPT_UNSAFE_EVAL', 'false'), FILTER_VALIDATE_BOOL);

        $scriptTokens = array_merge(
            ["'self'"],
            $allowScriptEval ? ["'unsafe-eval'"] : [],
            $extraHttp
        );

        $styleTokens = array_merge(
            ["'self'"],
            $allowStyleInline ? ["'unsafe-inline'"] : [],
            $extraHttp
        );

        $connectTokens = array_merge(
            ["'self'"],
            $extraHttp,
            $extraWs
        );

        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",

            "script-src " . implode(' ', $scriptTokens),
            "script-src-elem " . implode(' ', $scriptTokens),

            "style-src " . implode(' ', $styleTokens),
            "style-src-elem " . implode(' ', $styleTokens),

            "connect-src " . implode(' ', $connectTokens),

            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "manifest-src 'self'",
            "worker-src 'self' blob:",
        ]);
    }

    /**
     * CSP source の簡易サニタイズ
     * - 空/カンマ/スペース区切りを受ける
     * - http(s) / ws(s) URL のみ残す
     */
    private function sanitizeSources(string $raw): array
    {
        $raw = str_replace(',', ' ', $raw);
        $parts = preg_split('/\s+/', trim($raw)) ?: [];

        $out = [];
        foreach ($parts as $p) {
            if ($p === '') continue;

            // 許可するトークンだけ通す（危険な文字 ; を含むものは落とす）
            if (str_contains($p, ';')) continue;

            $isHttp = preg_match('#^https?://[A-Za-z0-9\.\-\[\]:]+(:\d+)?$#', $p) === 1;
            $isWs   = preg_match('#^wss?://[A-Za-z0-9\.\-\[\]:]+(:\d+)?$#', $p) === 1;

            if ($isHttp || $isWs) {
                $out[] = $p;
            }
        }

        return array_values(array_unique($out));
    }
}
