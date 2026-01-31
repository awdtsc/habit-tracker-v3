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

        $webPush = new WebPush(['VAPID' => $vapid]);

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

        foreach ($subs as $sub) {
            $endpoint = (string)($sub->endpoint ?? '');
            $p256dh = (string)($sub->p256dh ?? '');
            $authToken = (string)($sub->auth ?? '');

            // legacy columns も拾う（存在するDBの場合）
            if ($p256dh === '' && !empty($sub->public_key)) {
                $p256dh = (string)$sub->public_key;
            }
            if ($authToken === '' && !empty($sub->auth_token)) {
                $authToken = (string)$sub->auth_token;
            }

            if ($endpoint === '' || $p256dh === '' || $authToken === '') {
                $skipped++;
                $failures[] = [
                    'endpoint_hash' => $sub->endpoint_hash ?? $this->endpointHash($endpoint),
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

            $webPush->queueNotification($subscription, $json);
            $queued++;

            // 足跡（成功/失敗に関係なく「使おうとした」記録）
            $sub->last_used_at = now();
            $sub->last_seen_at = now();
            $sub->saveQuietly();
        }

        foreach ($webPush->flush() as $report) {
            $endpoint = method_exists($report, 'getEndpoint') ? $report->getEndpoint() : null;
            $endpointHash = $this->endpointHash($endpoint);

            if ($report->isSuccess()) {
                $sent++;
                continue;
            }

            $failed++;
            $reason = method_exists($report, 'getReason') ? $report->getReason() : 'unknown';
            $statusCode = null;

            // v9でも response が取れることがある（取れない場合はnull）
            if (method_exists($report, 'getResponse')) {
                $resp = $report->getResponse();
                if ($resp && method_exists($resp, 'getStatusCode')) {
                    $statusCode = $resp->getStatusCode();
                }
            }

            $failures[] = [
                'endpoint_hash' => $endpointHash,
                'status' => $statusCode,
                'reason' => $this->safeReason((string)$reason),
            ];

            // ★410/404 は購読が死んでいるので自動削除（運用事故防止）
            if ($endpoint && ($statusCode === 410 || $statusCode === 404)) {
                $hash = $endpointHash ?? strtoupper(hash('sha256', (string)$endpoint));

                $deleted = PushSubscription::query()
                    ->where('user_id', $userId)
                    ->where('endpoint_hash', $hash)
                    ->delete();

                $removed += (int)$deleted;
            }
        }

        $ok = ($queued > 0 && $sent > 0);

        return [
            'ok' => $ok,
            'queued' => $queued,
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'removed' => $removed,
            'message' => 'done',
            // 出しすぎ防止：先頭5件だけ返す（必要なら増やす）
            'failures' => array_slice($failures, 0, 5),
        ];
    }

    private function safeReason(string $reason): string
    {
        $reason = preg_replace('~https?://\S+~u', '[url]', $reason) ?? $reason;
        $reason = preg_replace('~([A-Za-z0-9_\-]{40,})~u', '[redacted]', $reason) ?? $reason;
        return mb_strimwidth($reason, 0, 200, '…', 'UTF-8');
    }

    private function endpointHash(?string $endpoint): ?string
    {
        if (!$endpoint) {
            return null;
        }

        return strtoupper(hash('sha256', (string)$endpoint));
    }
}
