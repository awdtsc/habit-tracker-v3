<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class PushSubscriptionController extends Controller
{
    /**
     * POST /api/v1/push/subscribe
     */
    public function subscribe(Request $request)
    {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $v = Validator::make($request->all(), [
            'endpoint' => ['required', 'string', 'max:500'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'string', 'in:aesgcm,aes128gcm'],
            'content_encoding' => ['nullable', 'string', 'in:aesgcm,aes128gcm'], // 互換
        ]);

        if ($v->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $v->errors(),
            ], 422);
        }

        // ★必要最小限だけ渡す（巨大payload混入/想定外キーを遮断）
        $sub = PushSubscription::upsertFromWebPush($userId, $request->only([
            'endpoint',
            'keys',
            'contentEncoding',
            'content_encoding',
        ]));

        // ✅ 同一ユーザー & 同一User-Agent の “古い購読” は掃除（同一端末の更新で二重通知を防ぐ）
        if (!empty($sub->user_agent)) {
            PushSubscription::query()
                ->where('user_id', $userId)
                ->where('id', '!=', $sub->id)
                ->where('user_agent', $sub->user_agent)
                ->delete();
        }

        return response()->json([
            'ok' => true,
            'id' => $sub->id,
        ]);
    }

    /**
     * POST /api/v1/push/unsubscribe
     * body: { endpoint: "..." }
     */
    public function unsubscribe(Request $request)
    {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $v = Validator::make($request->all(), [
            'endpoint' => ['required', 'string', 'max:500'],
        ]);

        if ($v->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $v->errors(),
            ], 422);
        }

        $endpoint = trim((string) $request->input('endpoint'));
        $endpointHash = strtoupper(hash('sha256', $endpoint));

        $deleted = PushSubscription::query()
            ->where('user_id', $userId)
            ->where('endpoint_hash', $endpointHash)
            ->delete();

        return response()->json([
            'ok' => true,
            'deleted' => (int) $deleted,
        ]);
    }

    /**
     * POST /api/v1/push/test
     * body: { title?, body?, url? }
     */
    public function test(Request $request)
    {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // ★本番では無効（事故防止）
        if (app()->environment('production')) {
            return response()->json(['message' => 'Disabled in production'], 403);
        }

        $title = (string) $request->input('title', 'Habit Tracker');
        $body = (string) $request->input('body', 'テスト通知です');
        $url = (string) $request->input('url', '/');

        // ★「最新の購読」から送る優先度（last_seen_at → id）
        $subs = PushSubscription::query()
            ->where('user_id', $userId)
            ->whereNotNull('endpoint_hash')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->get();

        if ($subs->isEmpty()) {
            return response()->json(['ok' => false, 'message' => 'No subscriptions'], 404);
        }

        $webpush = $this->makeWebPush();

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ], JSON_UNESCAPED_UNICODE);

        foreach ($subs as $s) {
            $subscription = Subscription::create([
                'endpoint' => $s->endpoint,
                'publicKey' => $s->p256dh,
                'authToken' => $s->auth,
                'contentEncoding' => $s->content_encoding ?: 'aesgcm',
            ]);

            // TTL は短め（テスト用途）
            $webpush->queueNotification($subscription, $payload, ['TTL' => 600]);
        }

        $sent = 0;
        $failed = 0;
        $removed = 0;
        $errors = [];

        foreach ($webpush->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;
                continue;
            }

            $failed++;

            // Report から endpoint/status をできる限り拾う（実装差を吸収）
            $endpoint = method_exists($report, 'getEndpoint') ? $report->getEndpoint() : null;

            $statusCode = null;
            if (method_exists($report, 'getResponse')) {
                $response = $report->getResponse();
                if ($response && method_exists($response, 'getStatusCode')) {
                    $statusCode = $response->getStatusCode();
                }
            }

            $errors[] = [
                'endpoint_hash' => $endpoint ? strtoupper(hash('sha256', (string) $endpoint)) : null,
                'status' => $statusCode,
                'reason' => mb_strimwidth((string) $report->getReason(), 0, 200, '…', 'UTF-8'),
            ];

            // 404/410 は無効購読として掃除（subscription garbage pile 防止）
            if ($endpoint && ($statusCode === 404 || $statusCode === 410)) {
                $removed += (int) PushSubscription::query()
                    ->where('user_id', $userId)
                    ->where('endpoint_hash', strtoupper(hash('sha256', (string) $endpoint)))
                    ->delete();
            }
        }

        // 送信試行時刻を更新（ざっくりでOK）
        PushSubscription::query()
            ->where('user_id', $userId)
            ->update(['last_used_at' => now()]);

        return response()->json([
            'ok' => true,
            'sent' => $sent,
            'failed' => $failed,
            'removed' => $removed,
            'errors' => $errors,
        ]);
    }

    private function makeWebPush(): WebPush
    {
        // ★ services.webpush.vapid ではなく webpush.vapid に統一（config/webpush.php 想定）
        $cfg = config('webpush.vapid');

        return new WebPush([
            'VAPID' => [
                'subject' => $cfg['subject'] ?? null,
                'publicKey' => $cfg['public_key'] ?? null,
                'privateKey' => $cfg['private_key'] ?? null,
            ],
        ]);
    }
}