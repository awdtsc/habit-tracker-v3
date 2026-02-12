<?php

namespace App\Services;

use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    /**
     * @return array{
     *   ok:bool,
     *   queued:int,
     *   sent:int,
     *   failed:int,
     *   skipped:int,
     *   removed:int,
     *   message:string,
     *   failures:array<int,array<string,mixed>>
     * }
     */
    public function sendToUser(int $userId, array $payload): array
    {
        $subs = PushSubscription::query()
            ->where('user_id', $userId)
            ->orderByDesc('last_seen_at')
            ->get();

        if ($subs->isEmpty()) {
            return [
                'ok' => false,
                'queued' => 0,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'removed' => 0,
                'message' => 'No subscriptions',
                'failures' => [],
            ];
        }

        $vapid = [
            'subject' => config('webpush.vapid.subject'),
            'publicKey' => config('webpush.vapid.public_key'),
            'privateKey' => config('webpush.vapid.private_key'),
        ];

        if (!$vapid['publicKey'] || !$vapid['privateKey']) {
            return [
                'ok' => false,
                'queued' => 0,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'removed' => 0,
                'message' => 'Missing VAPID keys (check .env + config/webpush.php)',
                'failures' => [],
            ];
        }

        // ★「PC閉じてる間に溜めない」: TTL を短くする（秒）
        // 推奨: grace window と同じ 10分 (=600)
        $ttlSeconds = (int) config('webpush.ttl_seconds', 600);
        $ttlSeconds = max(60, min(3600, $ttlSeconds)); // 安全クランプ: 1分〜1時間

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return [
                'ok' => false,
                'queued' => 0,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'removed' => 0,
                'message' => 'json_encode failed',
                'failures' => [],
            ];
        }

        $queued = 0;
        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $removed = 0;
        $failures = [];

        try {
            $webPush = new WebPush(['VAPID' => $vapid]);

            foreach ($subs as $sub) {
                $endpoint = (string) ($sub->endpoint ?? '');
                $p256dh = (string) ($sub->p256dh ?? '');
                $authToken = (string) ($sub->auth ?? '');

                // legacy columns も拾う（存在するDBの場合）
                if ($p256dh === '' && !empty($sub->public_key)) {
                    $p256dh = (string) $sub->public_key;
                }
                if ($authToken === '' && !empty($sub->auth_token)) {
                    $authToken = (string) $sub->auth_token;
                }

                if ($endpoint === '' || $p256dh === '' || $authToken === '') {
                    $skipped++;
                    $failures[] = [
                        'endpoint_hash' => $endpoint !== '' ? PushSubscription::hashEndpoint($endpoint) : ($sub->endpoint_hash ?? null),
                        'status' => null,
                        'reason' => $this->safeReason('missing endpoint/p256dh/auth in DB row'),
                        'has_endpoint' => $endpoint !== '',
                        'has_p256dh' => $p256dh !== '',
                        'has_auth' => $authToken !== '',
                    ];
                    continue;
                }

                $subscription = Subscription::create([
                    'endpoint' => $endpoint,
                    'publicKey' => $p256dh,
                    'authToken' => $authToken,
                    'contentEncoding' => $sub->content_encoding ?: 'aes128gcm',
                ]);

                $webPush->queueNotification($subscription, $json, [
                    'TTL' => $ttlSeconds,
                ]);
                $queued++;

                // 足跡（成功/失敗に関係なく「使おうとした」記録）
                $sub->last_used_at = now();
                $sub->last_seen_at = now();
                $sub->saveQuietly();
            }

            foreach ($webPush->flush() as $report) {
                $endpoint = method_exists($report, 'getEndpoint') ? (string) $report->getEndpoint() : '';
                $endpointHash = $endpoint !== '' ? PushSubscription::hashEndpoint($endpoint) : null;

                if ($report->isSuccess()) {
                    $sent++;
                    continue;
                }

                $failed++;
                $reason = method_exists($report, 'getReason') ? (string) $report->getReason() : 'unknown';

                $statusCode = null;
                if (method_exists($report, 'getResponse')) {
                    $resp = $report->getResponse();
                    if ($resp && method_exists($resp, 'getStatusCode')) {
                        $statusCode = $resp->getStatusCode();
                    }
                }

                $failures[] = [
                    'endpoint_hash' => $endpointHash,
                    'status' => $statusCode,
                    'reason' => $this->safeReason($reason),
                ];

                // ★410/404 は購読が死んでいるので自動削除（運用事故防止）
                if ($endpointHash && ($statusCode === 410 || $statusCode === 404)) {
                    $deleted = PushSubscription::query()
                        ->where('user_id', $userId)
                        ->where('endpoint_hash', $endpointHash)
                        ->delete();

                    $removed += (int) $deleted;
                }
            }
        } catch (\Throwable $e) {
            // ★例外で落とさず、必ず契約形の配列で返す（caller の運用事故防止）
            $failed = max(1, $failed);
            $failures[] = [
                'endpoint_hash' => null,
                'status' => null,
                'reason' => $this->safeReason($e->getMessage()),
            ];

            return [
                'ok' => false,
                'queued' => $queued,
                'sent' => $sent,
                'failed' => $failed,
                'skipped' => $skipped,
                'removed' => $removed,
                'message' => 'webpush transport error',
                'failures' => array_slice($failures, 0, 5),
            ];
        }

        // 安全側（完全成功のみ ok）
        $ok = ($queued > 0 && $failed === 0);

        return [
            'ok' => $ok,
            'queued' => $queued,
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'removed' => $removed,
            'message' => $ok ? 'done' : 'partial failure',
            'failures' => array_slice($failures, 0, 5),
        ];
    }

    private function safeReason(string $reason): string
    {
        $reason = preg_replace('~https?://\S+~u', '[url]', $reason) ?? $reason;
        $reason = preg_replace('~([A-Za-z0-9_\-]{40,})~u', '[redacted]', $reason) ?? $reason;
        return mb_strimwidth($reason, 0, 200, '…', 'UTF-8');
    }
}