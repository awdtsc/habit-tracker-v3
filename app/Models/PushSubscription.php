<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PushSubscription
 *
 * Web Push の購読情報（端末ごとに 1 件を想定）
 * - DBの重複防止は endpoint の UNIQUE が主（環境差分を吸収しやすい）
 * - endpoint_hash は endpoint の sha256（大文字HEX）で補助キー
 *
 * 運用事故防止（重要）:
 * - content_encoding の既定値がコードパスでブレると「送れたり送れなかったり」事故の温床
 *   => ここを唯一の真実として統一・正規化する
 */
class PushSubscription extends Model
{
    protected $table = 'push_subscriptions';

    /**
     * ★唯一の真実: アプリ標準の content encoding
     * - 現代ブラウザの主流は aes128gcm
     */
    public const DEFAULT_CONTENT_ENCODING = 'aes128gcm';

    /**
     * 受け入れる encoding のホワイトリスト
     * - 旧来互換として aesgcm も許可（必要なら移行期間用）
     */
    public const ALLOWED_CONTENT_ENCODINGS = ['aes128gcm', 'aesgcm'];

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

    public static function hashEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') return '';
        return strtoupper(hash('sha256', $endpoint));
    }

    /**
     * content_encoding を正規化（唯一の真実）
     * - null/空/未知値は DEFAULT に寄せる
     * - 許可値は whitelist のみ
     */
    public static function normalizeContentEncoding(?string $value): string
    {
        $v = strtolower(trim((string) $value));
        if ($v === '') {
            return self::DEFAULT_CONTENT_ENCODING;
        }
        if (in_array($v, self::ALLOWED_CONTENT_ENCODINGS, true)) {
            return $v;
        }
        return self::DEFAULT_CONTENT_ENCODING;
    }

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            if (!isset($model->endpoint)) return;

            // endpoint 正規化（trim）
            $endpoint = trim((string) $model->endpoint);
            $model->endpoint = $endpoint;

            // endpoint_hash 再生成（正規化ドリフト防止）
            if ($endpoint !== '') {
                $model->endpoint_hash = static::hashEndpoint($endpoint);
            }

            // content_encoding 正規化（どの保存経路でも統一）
            $model->content_encoding = static::normalizeContentEncoding($model->content_encoding ?? null);
        });
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
     * subscribe() payload から Upsert（DBのUNIQUE endpoint と一致させる）
     *
     * ★重要: endpoint を他ユーザーから奪えないようにガード
     * - 既存行の user_id が別なら、keys(p256dh/auth) が一致する場合のみ「同一端末」扱いで移管を許可
     * - 一致しない場合は衝突として例外（呼び出し側で 409 に変換推奨）
     */
    public static function upsertFromWebPush(int $userId, array $payload): self
    {
        $endpoint = trim((string)($payload['endpoint'] ?? ''));
        if ($endpoint === '') {
            throw new \InvalidArgumentException('endpoint is required');
        }

        $p256dh = (string)($payload['keys']['p256dh'] ?? $payload['p256dh'] ?? '');
        $auth   = (string)($payload['keys']['auth'] ?? $payload['auth'] ?? '');

        // ★どの経路でも同じ正規化ルールに統一
        $contentEncoding = static::normalizeContentEncoding(
            $payload['contentEncoding'] ?? $payload['content_encoding'] ?? null
        );

        // user agent は request() が無い文脈（CLI等）でも落ちないようにする
        $ua = $payload['userAgent'] ?? $payload['user_agent'] ?? null;
        if (!is_string($ua) || $ua === '') {
            try {
                $ua = app('request')->userAgent();
            } catch (\Throwable) {
                $ua = null;
            }
        }

        $existing = static::query()
            ->where('endpoint', $endpoint) // ★DBの一意制約と一致
            ->first();

        if ($existing && (int)$existing->user_id !== (int)$userId) {
            $sameKeys = hash_equals((string)$existing->p256dh, (string)$p256dh)
                && hash_equals((string)$existing->auth, (string)$auth);

            if (!$sameKeys) {
                throw new \RuntimeException('SUBSCRIPTION_CONFLICT');
            }
        }

        // ★upsert も endpoint をキーに（DBと一致）
        return static::updateOrCreate(
            ['endpoint' => $endpoint],
            [
                'user_id'          => $userId,
                'endpoint_hash'    => static::hashEndpoint($endpoint),
                'p256dh'           => $p256dh,
                'auth'             => $auth,
                'content_encoding' => $contentEncoding,
                'device'           => $payload['device'] ?? null,
                'device_hint'      => $payload['device_hint'] ?? null,
                'user_agent'       => $ua,
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