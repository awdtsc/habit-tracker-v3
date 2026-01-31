<?php

return [
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', 'mailto:example@example.com'),
        'public_key' => env('VAPID_PUBLIC_KEY', ''),
        'private_key' => env('VAPID_PRIVATE_KEY', ''),
    ],

    // ★no-backlog: pushサービス側で期限切れ破棄させるTTL（秒）
    // Dispatchのgrace window（例: 10分）に揃えるのが基本
    'ttl_seconds' => (int) env('WEBPUSH_TTL_SECONDS', 600),
];