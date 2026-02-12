<?php

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
), fn ($v) => $v !== ''));

if ((string) env('APP_ENV', 'production') === 'production') {
    $origins = array_values(array_filter($origins, function ($o) {
        $o = strtolower(trim($o));

        if ($o === '*') return false;
        if (str_contains($o, 'localhost')) return false;
        if (str_contains($o, '127.0.0.1')) return false;

        return true;
    }));
}

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,

    // Sanctum stateful/cookie auth を使う環境で事故らないよう env で制御
    // (cross-origin SPA は true が必要。same-origin only 運用でも true で問題なし)
    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', true),
];