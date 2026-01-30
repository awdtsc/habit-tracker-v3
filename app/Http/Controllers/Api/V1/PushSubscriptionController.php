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
     *
     * body:
     * {
     *   endpoint: "...",
     *   keys: { p256dh: "...", auth: "..." },
     *   contentEncoding: "aesgcm" | "aes128gcm" (optional)
     * }
     */
    public function subscribe(Request $request)
    {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $v = Validator::make($request->all(), [
            'endpoint' => ['required', 'string'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'contentEncoding' => ['nullable', 'string', 'in:aesgcm,aes128gcm'],
            'content_encoding' => ['nullable', 'string', 'in:aesgcm,aes128gcm'], // 互換
        ]);

        if ($v->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $v->errors(),
            ], 422);
        }

        $sub = PushSubscription::upsertFromWebPush($userId, $request->all());

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

        $title = (string)($request->input('title', 'Habit Tracker'));
        $body  = (string)($request->input('body',  'テスト通知です'));
        $url   = (string)($request->input('url',   '/'));

        $subs = PushSubscription::query()
            ->where('user_id', $userId)
            ->whereNotNull('endpoint_hash')
            ->get();

        if ($subs->isEmpty()) {
            return response()->json(['ok' => false, 'message' => 'No subscriptions'], 404);
        }

        $webpush = $this->makeWebPush();

        $payload = json_encode([
            'title' => $title,
            'body'  => $body,
            'url'   => $url,
        ], JSON_UNESCAPED_UNICODE);

        foreach ($subs as $s) {
            $subscription = Subscription::create([
                'endpoint' => $s->endpoint,
                'publicKey' => $s->p256dh,
                'authToken' => $s->auth,
                'contentEncoding' => $s->content_encoding ?: 'aesgcm',
            ]);

            $webpush->queueNotification($subscription, $payload);
        }

        $sent = 0;
        $failed = 0;
        $errors = [];

        foreach ($webpush->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;
            } else {
                $failed++;
                $errors[] = [
                    'endpoint' => $report->getRequest()?->getUri()?->__toString(),
                    'reason'   => $report->getReason(),
                ];
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
            'errors' => $errors,
        ]);
    }

    private function makeWebPush(): WebPush
    {
        $cfg = config('services.webpush.vapid');

        return new WebPush([
            'VAPID' => [
                'subject'    => $cfg['subject'] ?? null,
                'publicKey'  => $cfg['public_key'] ?? null,
                'privateKey' => $cfg['private_key'] ?? null,
            ],
        ]);
    }
}
