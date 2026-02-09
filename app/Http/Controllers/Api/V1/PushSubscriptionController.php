<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;

class PushSubscriptionController extends Controller
{
    public function subscribe(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'string', 'max:32'],
            'content_encoding' => ['nullable', 'string', 'max:32'],
        ]);

        $endpoint = $data['endpoint'];
        $endpointHash = PushSubscription::hashEndpoint($endpoint);

        $p256dh = $data['keys']['p256dh'];
        $auth = $data['keys']['auth'];

        $contentEncoding = $data['contentEncoding']
            ?? $data['content_encoding']
            ?? 'aesgcm';

        $existing = PushSubscription::query()
            ->where('endpoint_hash', $endpointHash)
            ->first();

        if ($existing && (int) $existing->user_id !== (int) $user->id) {
            $sameKeys = hash_equals((string) $existing->p256dh, (string) $p256dh)
                && hash_equals((string) $existing->auth, (string) $auth);

            if (!$sameKeys) {
                return response()->json([
                    'message' => 'Subscription endpoint is already registered to another user.',
                    'code' => 'SUBSCRIPTION_CONFLICT',
                ], 409);
            }
        }

        $sub = PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => $endpointHash],
            [
                'user_id' => $user->id,
                'endpoint' => $endpoint,
                'p256dh' => $p256dh,
                'auth' => $auth,
                'content_encoding' => $contentEncoding,
                'last_seen_at' => now(),
            ]
        );

        return response()->json([
            'ok' => true,
            'id' => $sub->id,
        ], 200);
    }

    public function unsubscribe(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
        ]);

        $endpointHash = PushSubscription::hashEndpoint($data['endpoint']);

        $deleted = PushSubscription::query()
            ->where('user_id', $user->id)
            ->where('endpoint_hash', $endpointHash)
            ->delete();

        return response()->json([
            'ok' => true,
            'deleted' => $deleted,
        ], 200);
    }

    public function test(Request $request)
    {
        if (app()->environment('production')) {
            return response()->json(['message' => 'Disabled in production.'], 403);
        }

        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:500'],
            'url' => ['required', 'string', 'max:2048'],
        ]);

        $cfg = config('webpush.vapid');
        if (empty($cfg['public_key']) || empty($cfg['private_key'])) {
            return response()->json([
                'ok' => false,
                'sent' => 0,
                'failed' => 0,
                'removed' => 0,
                'errors' => [['reason' => 'Missing VAPID keys']],
            ], 200);
        }

        $subs = PushSubscription::query()
            ->where('user_id', $user->id)
            ->whereNotNull('endpoint_hash')
            ->orderByDesc('last_seen_at')
            ->get();

        if ($subs->isEmpty()) {
            return response()->json(['message' => 'No subscriptions.'], 404);
        }

        $webPush = $this->makeWebPush();

        $sent = 0;
        $failed = 0;
        $removed = 0;
        $errors = [];

        foreach ($subs as $subRow) {
            try {
                $subscription = Subscription::create([
                    'endpoint' => $subRow->endpoint,
                    'publicKey' => $subRow->p256dh,
                    'authToken' => $subRow->auth,
                    'contentEncoding' => $subRow->content_encoding ?: 'aesgcm',
                ]);

                $payload = json_encode([
                    'title' => $data['title'],
                    'body' => $data['body'] ?? '',
                    'url' => $data['url'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $webPush->queueNotification($subscription, $payload);
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = [
                    'endpoint_hash' => $subRow->endpoint_hash,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        try {
            foreach ($webPush->flush() as $report) {
                if (method_exists($report, 'isSuccess') && $report->isSuccess()) {
                    $sent++;
                    continue;
                }

                $failed++;

                $reason = method_exists($report, 'getReason')
                    ? (string) $report->getReason()
                    : 'failed';

                $statusCode = null;
                if (method_exists($report, 'getResponse')) {
                    $resp = $report->getResponse();
                    if ($resp && method_exists($resp, 'getStatusCode')) {
                        $statusCode = $resp->getStatusCode();
                    }
                }

                $endpoint = method_exists($report, 'getEndpoint')
                    ? (string) $report->getEndpoint()
                    : '';

                // ★重要: DBと同一のハッシュ関数で統一
                $endpointHash = $endpoint !== ''
                    ? PushSubscription::hashEndpoint($endpoint)
                    : null;

                $errors[] = [
                    'endpoint_hash' => $endpointHash,
                    'status' => $statusCode,
                    'reason' => $reason,
                ];

                if ($endpointHash && ($statusCode === 404 || $statusCode === 410)) {
                    $deleted = PushSubscription::query()
                        ->where('user_id', $user->id)
                        ->where('endpoint_hash', $endpointHash)
                        ->delete();

                    $removed += (int) $deleted;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('push/test flush failed', ['e' => $e->getMessage()]);
            return response()->json([
                'ok' => false,
                'sent' => 0,
                'failed' => 1,
                'removed' => 0,
                'errors' => [['reason' => $e->getMessage()]],
            ], 200);
        }

        return response()->json([
            'ok' => true,
            'sent' => $sent,
            'failed' => $failed,
            'removed' => $removed,
            'errors' => $errors,
        ], 200);
    }

    protected function makeWebPush()
    {
        $cfg = config('webpush.vapid');

        return new \Minishlink\WebPush\WebPush([
            'VAPID' => [
                'subject' => $cfg['subject'] ?? null,
                'publicKey' => $cfg['public_key'] ?? null,
                'privateKey' => $cfg['private_key'] ?? null,
            ],
        ]);
    }
}