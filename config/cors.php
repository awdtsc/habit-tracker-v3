<?php

// env からカンマ区切りで取得
$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
), fn ($v) => $v !== ''));

// 本番だけ “事故りやすい値” を強制排除（安全側に倒す）
if ((string) env('APP_ENV', 'production') === 'production') {
    $origins = array_values(array_filter($origins, function ($o) {
        $o = strtolower(trim($o));

        // 全許可は禁止（事故防止）
        if ($o === '*') return false;

        // localhost/127.0.0.1 系は本番では禁止（事故防止）
        if (str_contains($o, 'localhost')) return false;
        if (str_contains($o, '127.0.0.1')) return false;

        return true;
    }));
}

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
    | 重要: allowed_origins が空なら「許可なし」＝安全側
    | - 本番(APP_ENV=production)では、'*' や localhost は強制的に除外する
    */
    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
