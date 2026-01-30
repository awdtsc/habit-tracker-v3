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
     * 保存時に endpoint_hash を自動計算（endpoint が空の場合はそのまま）
     */
    protected static function booted(): void
    {
        static::saving(function (self $model) {
            if (isset($model->endpoint)) {
                $endpoint = trim((string) $model->endpoint);
                $model->endpoint = $endpoint;

                if ($endpoint !== '') {
                    $model->endpoint_hash = strtoupper(hash('sha256', $endpoint));
                }
            }
        });
    }

    public function setEndpointAttribute($value): void
    {
        $value = is_string($value) ? trim($value) : $value;
        $this->attributes['endpoint'] = $value;

        if ($value) {
            $this->attributes['endpoint_hash'] = strtoupper(hash('sha256', $value));
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
     */
    public static function upsertFromWebPush(int $userId, array $payload): self
    {
        $endpoint = trim((string)($payload['endpoint'] ?? ''));
        $hash = $endpoint ? strtoupper(hash('sha256', $endpoint)) : null;

        return static::updateOrCreate(
            ['endpoint_hash' => $hash],
            [
                'user_id'          => $userId,
                'endpoint'         => $endpoint,
                'p256dh'           => (string)($payload['keys']['p256dh'] ?? $payload['p256dh'] ?? ''),
                'auth'             => (string)($payload['keys']['auth'] ?? $payload['auth'] ?? ''),
                'content_encoding' => (string)($payload['contentEncoding'] ?? $payload['content_encoding'] ?? 'aes128gcm'),
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
