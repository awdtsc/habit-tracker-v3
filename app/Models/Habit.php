<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Habit extends Model
{
    use HasFactory;

    /**
     * NOTE:
     * - 既存コード/Factory互換のため name <-> title のブリッジを用意
     *   - setNameAttribute() で渡された name を title に保存
     *   - getNameAttribute() で title を name として参照可能
     */

    protected $fillable = [
        'user_id',
        'title',            // ← 正式フィールド
        'description',
        'frequency_type',
        'days_of_week',
        'target_times',
        'start_date',
        'end_date',
        'archived',
        'time_slot',
        'category',
        'color_tag',
        'evaluation_type',
        // ※ notify_time は Habit では持たない（habit_times へ分離）
        // 互換目的で 'name' を fillable に入れない（setterで吸収）
    ];

    protected $casts = [
        'days_of_week'    => 'array',
        'target_times'    => 'array',   // ← 重要：配列(JSON)で保持
        'start_date'      => 'date',
        'end_date'        => 'date',
        'archived'        => 'boolean',
        'time_slot'       => 'integer', // 0=終日, 1..=特定スロット
        'evaluation_type' => 'string',
    ];

    /* =========================
     |  リレーション
     * ========================= */

    // 複数通知時刻（habit_times）
    public function times()
    {
        return $this->hasMany(HabitTime::class);
    }

    // 習慣ログ
    public function logs()
    {
        return $this->hasMany(HabitLog::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /* =========================
     |  スケジュール判定
     * ========================= */

    /**
     * 指定日（必要なら time_slot も）にこの習慣が予定されているか。
     * $slot: null=日単位, 0=終日, 1..=特定スロット
     */
    public function isScheduledFor(Carbon $date, ?int $slot = null): bool
    {
        // 期間・アーカイブ
        if ($this->archived ?? false) return false;
        if ($this->start_date && $date->lt($this->start_date)) return false;
        if ($this->end_date   && $date->gt($this->end_date))   return false;

        // 頻度タイプ
        $type = strtolower((string)($this->frequency_type ?: 'weekly'));

        if ($type === 'daily') {
            // 常にOK（期間内）
        } elseif ($type === 'weekdays') {
            $dowIso = $date->dayOfWeekIso;        // 月=1..日=7
            if ($dowIso < 1 || $dowIso > 5) return false;
        } elseif ($type === 'weekends') {
            $dowIso = $date->dayOfWeekIso;        // 月=1..日=7
            if ($dowIso < 6) return false;        // 6,7のみ
        } elseif (in_array($type, ['weekly', 'custom'], true)) {
            $dowIso  = $date->dayOfWeekIso;       // 月=1..日=7
            $daysIso = $this->normalizedDaysOfWeekIso();
            if (empty($daysIso) || !in_array($dowIso, $daysIso, true)) return false;
        } else {
            // quota 等を拡張する場合はここに判定を追加
        }

        // time_slot チェック
        $ts = (int)($this->time_slot ?? 0); // 0=終日
        if ($ts === 0) return true;
        return $slot === null || $slot === $ts;
    }

    /**
     * days_of_week を ISO (1..7) に正規化。
     * 0..6（日=0）の入力があれば 7 に補正。
     */
    private function normalizedDaysOfWeekIso(): array
    {
        $raw = $this->days_of_week ?? [];
        if (!is_array($raw)) $raw = [];

        $iso = [];
        foreach ($raw as $v) {
            $n = (int)$v;
            if ($n === 0) $n = 7;    // 日(0) → 7
            if ($n >= 1 && $n <= 7) {
                $iso[$n] = true;     // 重複排除
            }
        }
        return array_keys($iso);
    }

    /* =========================
     |  ヘルパー
     * ========================= */

    /**
     * 今日の通知時刻一覧（Carbon[]）を返す。
     * ※ HabitTime::notify_time が 'H:i' 文字列で保存されている前提。
     */
    public function todayNotifyTimes(): array
    {
        // null や不正値を除外して安全に Carbon 化
        return $this->times
            ->filter(fn ($t) => !empty($t->notify_time))
            ->map(function ($t) {
                try {
                    return Carbon::createFromFormat('H:i', (string)$t->notify_time);
                } catch (\Throwable $e) {
                    return null;
                }
            })
            ->filter() // null を除外
            ->values()
            ->all();
    }

    /* =========================
     |  互換: name <-> title
     * ========================= */

    // Factoryや既存コードが name を使っていても title に保存する
    public function setNameAttribute($value): void
    {
        $this->attributes['title'] = $value;
    }

    // title を name として参照可能にする
    public function getNameAttribute(): ?string
    {
        return $this->attributes['title'] ?? null;
    }
}