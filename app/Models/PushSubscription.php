<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PushSubscription
 *
 * Web Push の購読情報（端末ごとに 1 件を想定）
 * - 重複防止は endpoint_hash(sha256) の UNIQUE で担保
 * - endpoint_hash は大文字HEXに統一
 */
class PushSubscription extends Model
{
    protected $table = 'push_subscriptions';

    protected $fillable = [
        'user_id',
        'endpoint',
        'endpoint_hash',
        'p256dh',
        'auth',
        'content_encoding',
        'device',
        'user_agent',
        'last_used_at',
        'last_seen_at',
        'device_hint',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /**
     * endpoint から endpoint_hash を作る（Controller互換のため public static で提供）
     * - trim してから sha256
     * - 大文字HEX
     */
    public static function hashEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return '';
        }
        return strtoupper(hash('sha256', $endpoint));
    }

    /**
     * 保存時に endpoint_hash を自動計算（endpoint が空の場合はそのまま）
     */
    protected static function booted(): void
    {
        static::saving(function (self $model) {
            if (isset($model->endpoint)) {
                $endpoint = trim((string) $model->endpoint);
                $model->endpoint = $endpoint;

                if ($endpoint !== '') {
                    $model->endpoint_hash = static::hashEndpoint($endpoint);
                }
            }
        });
    }

    public function setEndpointAttribute($value): void
    {
        $value = is_string($value) ? trim($value) : $value;
        $this->attributes['endpoint'] = $value;

        if ($value) {
            $this->attributes['endpoint_hash'] = static::hashEndpoint((string) $value);
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOfUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * subscribe() の payload から Upsert
     *
     * ★重要: endpoint_hash のみで他ユーザーの行を奪えないようにガードする
     * - 既存行の user_id が別なら、keys(p256dh/auth) が一致する場合のみ「同一端末」扱いで移管を許可
     * - 一致しない場合は衝突として例外（呼び出し側で 409 に変換推奨）
     */
    public static function upsertFromWebPush(int $userId, array $payload): self
    {
        $endpoint = trim((string)($payload['endpoint'] ?? ''));
        if ($endpoint === '') {
            throw new \InvalidArgumentException('endpoint is required');
        }

        $hash = static::hashEndpoint($endpoint);

        $p256dh = (string)($payload['keys']['p256dh'] ?? $payload['p256dh'] ?? '');
        $auth   = (string)($payload['keys']['auth'] ?? $payload['auth'] ?? '');

        $contentEncoding = (string)($payload['contentEncoding'] ?? $payload['content_encoding'] ?? 'aes128gcm');

        $existing = static::query()
            ->where('endpoint_hash', $hash)
            ->first();

        if ($existing && (int)$existing->user_id !== (int)$userId) {
            $sameKeys = hash_equals((string)$existing->p256dh, (string)$p256dh)
                && hash_equals((string)$existing->auth, (string)$auth);

            if (!$sameKeys) {
                throw new \RuntimeException('SUBSCRIPTION_CONFLICT');
            }
        }

        return static::updateOrCreate(
            ['endpoint_hash' => $hash],
            [
                'user_id'          => $userId,
                'endpoint'         => $endpoint,
                'p256dh'           => $p256dh,
                'auth'             => $auth,
                'content_encoding' => $contentEncoding,
                'device'           => $payload['device'] ?? null,
                'device_hint'      => $payload['device_hint'] ?? null,
                'user_agent'       => $payload['userAgent'] ?? $payload['user_agent'] ?? request()->userAgent(),
                'last_used_at'     => now(),
                'last_seen_at'     => now(),
            ]
        );
    }

    public function markUsed(): void
    {
        $this->last_used_at = now();
        $this->saveQuietly();
    }
}