<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HabitLog extends Model
{
    use HasFactory;

    /**
     * Columns
     * - habit_id       : FK to habits.id
     * - habit_time_id  : FK to habit_times.id (nullable)
     * - user_id        : 冗長参照（所有者判定や検索最適化に利用; nullableでも可）
     * - date           : 対象日 (Y-m-d)
     * - time_slot      : 0=終日, 1..=特定スロット
     * - status         : 'none' | 'done' | 'skipped'（将来拡張可）
     * - checked_at     : 最終チェック時刻
     * - rating         : 自己評価型の点数（evaluation_type=self の時に利用）
     * - note           : メモ
     */

    protected $fillable = [
        'habit_id',
        'habit_time_id',
        'user_id',
        'date',
        'time_slot',
        'status',
        'checked_at',
        'rating',
        'note',
    ];

    protected $casts = [
        'date'       => 'date:Y-m-d',
        'time_slot'  => 'integer',
        'status'     => 'string',   // enum風: 'none' | 'done' | 'skipped'
        'checked_at' => 'datetime',
        'rating'     => 'integer',
    ];

    /**
     * デフォルト値（NULL回避）
     */
    protected $attributes = [
        'status'    => 'none',
        'time_slot' => 0,
    ];

    /* =========================
     |  リレーション
     * ========================= */

    public function habit()
    {
        return $this->belongsTo(Habit::class);
    }

    public function habitTime()
    {
        return $this->belongsTo(HabitTime::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // 通知タスク（1対多）
    public function remindTasks()
    {
        return $this->hasMany(RemindTask::class);
    }

    /* =========================
     |  スコープ / ヘルパー
     * ========================= */

    public function scopeForDate($query, $date)
    {
        return $query->whereDate('date', $date);
    }

    public function isDone(): bool
    {
        return $this->status === 'done';
    }

    /* =========================
     |  モデルフック
     * ========================= */

    protected static function booted()
    {
        static::saving(function (self $log) {
            // 自己評価型の場合のみ rating→status を自動設定
            if ($log->habit && $log->habit->evaluation_type === 'self') {
                if ($log->rating !== null) {
                    if ($log->rating >= 4) {
                        $log->status = 'done';
                    } elseif ($log->rating >= 1) {
                        $log->status = 'none'; // 将来的に 'inprogress' 等へ拡張可
                    } else {
                        $log->status = 'none';
                    }
                } else {
                    $log->status = $log->status ?: 'none';
                }
            }

            // 所有者冗長参照が未設定なら補完（検索/権限判定の最適化用）
            if (!$log->user_id && $log->habit && $log->habit->user_id) {
                $log->user_id = $log->habit->user_id;
            }
        });
    }
}