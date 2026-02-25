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

    // ★optional: stale subscription prune（日）
    // 0: 無効（削除しない）
    // 例: 45 → last_seen_at が 45日より古い購読を送信前に掃除（上限500件/回）
    'stale_prune_days' => (int) env('WEBPUSH_STALE_PRUNE_DAYS', 0),
];