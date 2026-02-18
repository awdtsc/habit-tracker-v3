<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\WebPushService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PushSubscriptionController extends Controller
{
  /**
   * 方針A（厳格）:
   * - endpoint は「全ユーザーで一意」（UNIQUE(endpoint) 前提）
   * - 既に別 user_id に存在する endpoint が来たら常に 409（移管しない）
   * - 自分の endpoint なら冪等に update（行は増やさない）
   *
   * 事故防止:
   * - endpoint max は DB の varchar(500) に揃える
   * - keys はサイズ/形式制約を付ける（DoS/異常データ抑止）
   * - レースで UNIQUE(endpoint) に当たった場合も 409 に正規化
   *
   * C対策（入力バリデーション強化）:
   * - keys.p256dh / keys.auth に max + base64url系の形式チェック + min を追加
   *
   * F対策（情報最小化）:
   * - 成功時に内部DB id を返さない
   */
  public function subscribe(Request $request)
  {
    $user = $request->user();
    if (!$user) {
      return response()->json(['ok' => false, 'message' => 'Unauthenticated.'], 401);
    }

    // base64url っぽい形式（WebPush鍵は base64url 系が普通）
    // ※厳密なデコード検証まではしない（入口の軽量ガード）
    $b64url = 'regex:/^[A-Za-z0-9\-_]+$/';

    $data = $request->validate([
      // DB: varchar(500)
      'endpoint' => ['required', 'string', 'max:500'],

      'keys' => ['required', 'array'],
      // DoS/異常データ抑止: 長さ上限 + 形式 + 最低長（極端に短い値も弾く）
      'keys.p256dh' => ['required', 'string', 'min:20', 'max:255', $b64url],
      'keys.auth'   => ['required', 'string', 'min:8',  'max:255', $b64url],

      'contentEncoding' => ['nullable', 'string', 'max:32'],
      'content_encoding' => ['nullable', 'string', 'max:32'],
    ]);

    // ★subscribe/unsubscribe/Model saving と同じ正規化（trim）
    $endpoint = trim((string) $data['endpoint']);
    if ($endpoint === '') {
      return response()->json(['ok' => false, 'message' => 'Invalid endpoint.'], 422);
    }

    $endpointHash = PushSubscription::hashEndpoint($endpoint);

    $p256dh = (string) $data['keys']['p256dh'];
    $auth = (string) $data['keys']['auth'];

    // ★camel/snake どちらでも受ける。既定値は Model の唯一の真実へ寄せる
    $contentEncoding = PushSubscription::normalizeContentEncoding(
      $data['contentEncoding'] ?? $data['content_encoding'] ?? null
    );

    // まず endpoint で衝突判定（方針A: endpoint は全ユーザーで一意）
    $existingByEndpoint = PushSubscription::query()
      ->where('endpoint', $endpoint)
      ->first();

    if ($existingByEndpoint && (int) $existingByEndpoint->user_id !== (int) $user->id) {
      return response()->json([
        'ok' => false,
        'message' => 'Subscription endpoint is already registered to another user.',
        'code' => 'SUBSCRIPTION_CONFLICT',
      ], 409);
    }

    try {
      // 冪等更新: endpoint をキーにする（DB UNIQUE(endpoint) と一致）
      PushSubscription::query()->updateOrCreate(
        ['endpoint' => $endpoint],
        [
          'user_id' => $user->id,
          'endpoint_hash' => $endpointHash,
          'p256dh' => $p256dh,
          'auth' => $auth,
          'content_encoding' => $contentEncoding,
          'last_seen_at' => now(),
        ]
      );
    } catch (QueryException $e) {
      // レース等で UNIQUE(endpoint) / UNIQUE(user_id,endpoint) に当たった場合を 409 に正規化
      // MySQL duplicate key: SQLSTATE[23000] / errorInfo[1] = 1062
      $err = $e->errorInfo[1] ?? null;
      if ((string) $e->getCode() === '23000' || (int) $err === 1062) {
        // もう一度 endpoint の所有者を確定して 409 へ
        $owner = PushSubscription::query()
          ->where('endpoint', $endpoint)
          ->first();

        if ($owner && (int) $owner->user_id !== (int) $user->id) {
          return response()->json([
            'ok' => false,
            'message' => 'Subscription endpoint is already registered to another user.',
            'code' => 'SUBSCRIPTION_CONFLICT',
          ], 409);
        }
      }

      Log::warning('push/subscribe failed', [
        'user_id' => $user->id,
        'code' => $e->getCode(),
        'err' => $err,
      ]);

      return response()->json([
        'ok' => false,
        'message' => 'Failed to persist subscription.',
        'code' => 'SUBSCRIPTION_PERSIST_FAILED',
      ], 500);
    }

    // F対策: 成功時のレスポンスを最小化（内部IDなど返さない）
    return response()->json([
      'ok' => true,
    ], 200);
  }

  public function unsubscribe(Request $request)
  {
    $user = $request->user();
    if (!$user) {
      return response()->json(['ok' => false, 'message' => 'Unauthenticated.'], 401);
    }

    $data = $request->validate([
      // DB: varchar(500)
      'endpoint' => ['required', 'string', 'max:500'],
    ]);

    // subscribe/save 側と同じ正規化（trim）を適用してミスマッチ削減
    $endpoint = trim((string) $data['endpoint']);
    if ($endpoint === '') {
      return response()->json(['ok' => false, 'message' => 'Invalid endpoint.'], 422);
    }

    $deleted = PushSubscription::query()
      ->where('user_id', (int) $user->id)
      ->where('endpoint', $endpoint)
      ->delete();

    return response()->json([
      'ok' => true,
      'deleted' => (int) $deleted,
    ], 200);
  }

  /**
   * Backend VAPID public key for parity check.
   * - auth必須（運用情報なので一応隠す）
   * - 無い場合は 503
   */
  public function vapidPublic(Request $request)
  {
    $user = $request->user();
    if (!$user) {
      return response()->json(['ok' => false, 'message' => 'Unauthenticated.'], 401);
    }

    $cfg = config('webpush.vapid');
    $pub = (string) ($cfg['public_key'] ?? '');

    if ($pub === '') {
      return response()->json([
        'ok' => false,
        'message' => 'Missing backend VAPID public key',
        'code' => 'VAPID_MISSING',
      ], 503);
    }

    return response()->json([
      'ok' => true,
      'public_key' => $pub,
    ], 200);
  }

  public function test(Request $request)
  {
    if (app()->environment('production')) {
      return response()->json(['ok' => false, 'message' => 'Disabled in production.'], 403);
    }

    $user = $request->user();
    if (!$user) {
      return response()->json(['ok' => false, 'message' => 'Unauthenticated.'], 401);
    }

    $data = $request->validate([
      'title' => ['required', 'string', 'max:120'],
      'body' => ['nullable', 'string', 'max:500'],
      'url' => ['required', 'string', 'max:2048'],
    ]);

    // sanitize helper（レスポンス/ログ両方の最終防波堤）
    $safeReason = static function (string $reason): string {
      $reason = trim($reason);
      if ($reason === '') {
        return 'push error';
      }

      // URL を潰す
      $reason = preg_replace('~https?://\S+~u', '[url]', $reason) ?? $reason;

      // token / 長い識別子を潰す（短めも含めて守る）
      $reason = preg_replace('~[A-Za-z0-9_\-]{30,}~u', '[redacted]', $reason) ?? $reason;

      // 改行などを潰す
      $reason = preg_replace("~[\r\n\t]+~u", ' ', $reason) ?? $reason;

      return mb_strimwidth(trim($reason), 0, 200, '…', 'UTF-8');
    };

    // stale cleanup (運用安全弁): N日見てない購読は削除（0なら無効）
    try {
      $days = (int) config('webpush.stale_prune_days', 0);
      if ($days > 0) {
        $staleIds = PushSubscription::query()
          ->where('user_id', $user->id)
          ->whereNotNull('last_seen_at')
          ->where('last_seen_at', '<', now()->subDays($days))
          ->orderBy('last_seen_at')
          ->limit(500)
          ->pluck('id')
          ->all();

        if (!empty($staleIds)) {
          PushSubscription::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $staleIds)
            ->delete();
        }
      }
    } catch (\Throwable $e) {
      // cleanup失敗は致命ではないので継続（ただしログは sanitize）
      Log::warning('push/test stale cleanup failed', [
        'user_id' => (int) $user->id,
        'reason' => $safeReason((string) $e->getMessage()),
      ]);
    }

    $payload = [
      'title' => $data['title'],
      'body' => $data['body'] ?? '',
      'url' => $data['url'],
    ];

    $result = app(WebPushService::class)->sendToUser((int) $user->id, $payload);

    $errors = array_map(
      static fn(array $f): array => [
        'code' => (string) ($f['code'] ?? 'PUSH_SEND_FAILED'),
        'status' => $f['status'] ?? null,
        'reason' => $safeReason((string) ($f['reason'] ?? 'push error')),
      ],
      $result['failures'] ?? []
    );

    $status = 207;
    if (($result['ok'] ?? false) === true) {
      $status = 200;
    } elseif (($result['message'] ?? '') === 'No subscriptions') {
      $status = 404;
    } elseif (str_starts_with((string) ($result['message'] ?? ''), 'Missing VAPID keys')) {
      $status = 503;
    } elseif (($result['message'] ?? '') === 'webpush transport error') {
      $status = 502;
    }

    return response()->json([
      'ok' => (bool) ($result['ok'] ?? false),
      'sent' => (int) ($result['sent'] ?? 0),
      'failed' => (int) ($result['failed'] ?? 0),
      'removed' => (int) ($result['removed'] ?? 0),
      'errors' => $errors,
    ], $status);
  }
}
