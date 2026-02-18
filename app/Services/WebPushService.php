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

    // ---------------------------------------------------------------------
    // ★stale prune（bounded / user-scoped）
    // - config(webpush.stale_prune_days) が >0 のときだけ実行
    // - last_seen_at が古い購読を最大500件まで削除（古い順）
    // - 失敗しても送信結果に影響させない（運用可用性優先）
    // ---------------------------------------------------------------------
    $stalePruneDays = (int) config('webpush.stale_prune_days', 0);
    if ($stalePruneDays > 0) {
      try {
        $cutoff = now()->subDays($stalePruneDays);

        $staleIds = PushSubscription::query()
          ->where('user_id', $userId)
          ->whereNotNull('last_seen_at')
          ->where('last_seen_at', '<', $cutoff)
          ->orderBy('last_seen_at')
          ->limit(500)
          ->pluck('id')
          ->all();

        if (!empty($staleIds)) {
          $removedNow = PushSubscription::query()
            ->where('user_id', $userId)
            ->whereIn('id', $staleIds)
            ->delete();

          $removed += (int) $removedNow;
        }
      } catch (\Throwable) {
        // failures には入れない（送信失敗と誤認されるため）
      }

      // prune 後に取り直す（購読が全消えしてたらここで終わる）
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
          'removed' => $removed,
          'message' => 'No subscriptions',
          'failures' => [],
        ];
      }
    }

    // ★ここからが codex ❌(E) 修正の核：
    // - queue を 1購読ごとに try/catch で隔離
    // - flush は transport 層として別 try/catch
    $webPush = null;

    try {
      $webPush = new WebPush(['VAPID' => $vapid]);
    } catch (\Throwable $e) {
      // WebPush の初期化が死んだら、ここは全体失敗でOK
      $failed = max(1, $failed);
      $failures[] = [
        'endpoint_hash' => null,
        'status' => null,
        'code' => 'TRANSPORT_ERROR',
        'reason' => $this->safeReason((string) $e->getMessage()),
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

    // queue phase (per-subscription isolated)
    foreach ($subs as $sub) {
      try {
        $endpoint = trim((string) ($sub->endpoint ?? ''));
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
            'code' => 'ROW_INVALID',
            'reason' => $this->safeReason('missing endpoint/p256dh/auth in DB row'),
            'has_endpoint' => $endpoint !== '',
            'has_p256dh' => $p256dh !== '',
            'has_auth' => $authToken !== '',
          ];
          continue;
        }

        // ★contentEncoding は Model の唯一の真実で正規化
        $contentEncoding = PushSubscription::normalizeContentEncoding($sub->content_encoding ?? null);

        $subscription = Subscription::create([
          'endpoint' => $endpoint,
          'publicKey' => $p256dh,
          'authToken' => $authToken,
          'contentEncoding' => $contentEncoding,
        ]);

        $webPush->queueNotification($subscription, $json, [
          'TTL' => $ttlSeconds,
        ]);
        $queued++;

        // 足跡（成功/失敗に関係なく「使おうとした」記録）
        try {
          $sub->last_used_at = now();
          $sub->last_seen_at = now();
          $sub->saveQuietly();
        } catch (\Throwable) {
          // 足跡更新失敗は送信可用性を落とさない
        }
      } catch (\Throwable $e) {
        // ★ここが修正点：1件の壊れ購読で全体を止めない
        $failed++;
        $failures[] = [
          'endpoint_hash' => !empty($sub->endpoint)
            ? PushSubscription::hashEndpoint((string) $sub->endpoint)
            : ($sub->endpoint_hash ?? null),
          'status' => null,
          'code' => 'QUEUE_BUILD_FAILED',
          'reason' => $this->safeReason((string) $e->getMessage()),
        ];
        continue;
      }
    }

    // queued がゼロなら flush しても意味がない（transport層例外も避ける）
    if ($queued === 0) {
      $failed = max($failed, 1);

      return [
        'ok' => false,
        'queued' => 0,
        'sent' => 0,
        'failed' => $failed,
        'skipped' => $skipped,
        'removed' => $removed,
        'message' => 'No valid subscriptions queued',
        'failures' => array_slice($failures, 0, 5),
      ];
    }

    // flush phase (transport isolated)
    try {
      foreach ($webPush->flush() as $report) {
        $endpoint = method_exists($report, 'getEndpoint') ? (string) $report->getEndpoint() : '';
        $endpointHash = $endpoint !== '' ? PushSubscription::hashEndpoint($endpoint) : null;

        $isSuccess = false;
        if (method_exists($report, 'isSuccess')) {
          $isSuccess = (bool) $report->isSuccess();
        }

        if ($isSuccess) {
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

        $failures[] = [
          'endpoint_hash' => $endpointHash,
          'status' => $statusCode,
          'code' => $this->classifyFailureCode($statusCode, $reason),
          'reason' => $this->safeReason($reason),
        ];

        // ★410/404 は購読が死んでいるので自動削除（運用事故防止）
        if ($endpointHash && ($statusCode === 410 || $statusCode === 404)) {
          try {
            $deleted = PushSubscription::query()
              ->where('user_id', $userId)
              ->where('endpoint_hash', $endpointHash)
              ->delete();

            $removed += (int) $deleted;
          } catch (\Throwable $e) {
            // 削除失敗は送信可用性に影響させない（ただし原因は failures に残す）
            $failures[] = [
              'endpoint_hash' => $endpointHash,
              'status' => null,
              'code' => 'PRUNE_DELETE_FAILED',
              'reason' => $this->safeReason('failed to delete expired subscription: ' . $e->getMessage()),
            ];
          }
        }
      }
    } catch (\Throwable $e) {
      // ★transport 層例外のみここで扱う（queue段階の1件エラーでは来ない）
      $failed = max(1, $failed);
      $failures[] = [
        'endpoint_hash' => null,
        'status' => null,
        'code' => 'TRANSPORT_INIT_FAILED',
        'reason' => $this->safeReason((string) $e->getMessage()),
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

    // ★安全側（完全成功のみ ok）
    // - failed: 送信失敗 / transport 失敗 / queue 構築失敗
    // - skipped: DB行不備で queue できなかった（運用上は「届いてない」なので ok にはしない）
    $ok = ($queued > 0 && $failed === 0 && $skipped === 0);

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

  private function classifyFailureCode(?int $statusCode, string $reason): string
  {
    if ($statusCode === 404 || $statusCode === 410) {
      return 'SUBSCRIPTION_GONE';
    }
    if ($statusCode === 429) {
      return 'PUSH_RATE_LIMITED';
    }
    if ($statusCode !== null && $statusCode >= 500) {
      return 'PUSH_PROVIDER_ERROR';
    }

    $r = strtolower($reason);
    if (str_contains($r, 'vapid')) {
      return 'VAPID_CONFIG_ERROR';
    }
    if (
      str_contains($r, 'connection')
      || str_contains($r, 'dns')
      || str_contains($r, 'timeout')
      || str_contains($r, 'transport')
    ) {
      return 'TRANSPORT_ERROR';
    }

    return 'PUSH_SEND_FAILED';
  }

  private function safeReason(string $reason): string
  {
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
  }
}
