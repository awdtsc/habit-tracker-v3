<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Habit extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'frequency_type',
        'days_of_week',
        'target_times',
        'start_date',
        'end_date',
        'archived',

        // legacy互換（旧コードが触る可能性があるので残す）
        'time_slot',

        'category',
        'color_tag',
        'evaluation_type',
    ];

    protected $casts = [
        'days_of_week'    => 'array',
        'target_times'    => 'array',
        'start_date'      => 'date',
        'end_date'        => 'date',
        'archived'        => 'boolean',
        'time_slot'       => 'integer',
        'evaluation_type' => 'string',
    ];

    /* =========================
     |  リレーション
     * ========================= */

    // 複数通知時刻（habit_times）
    public function times()
    {
        // ここで並びを安定させておくと UI/集計がブレにくい
        return $this->hasMany(HabitTime::class)->orderBy('time_slot')->orderBy('id');
    }

    // 互換：古い/別コードが habitTimes を呼んでも落ちないようにする
    public function habitTimes()
    {
        return $this->times();
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

    public function isScheduledFor(Carbon $date, ?int $slot = null): bool
    {
        if ($this->archived ?? false) return false;
        if ($this->start_date && $date->lt($this->start_date)) return false;
        if ($this->end_date   && $date->gt($this->end_date))   return false;

        // --- 曜日判定（従来どおり） ---
        $type = strtolower((string) ($this->frequency_type ?: 'weekly'));

        if ($type === 'daily') {
            // OK
        } elseif ($type === 'weekdays') {
            $dowIso = $date->dayOfWeekIso;
            if ($dowIso < 1 || $dowIso > 5) return false;
        } elseif ($type === 'weekends') {
            $dowIso = $date->dayOfWeekIso;
            if ($dowIso < 6) return false;
        } elseif (in_array($type, ['weekly', 'custom'], true)) {
            $dowIso  = $date->dayOfWeekIso;
            $daysIso = $this->normalizedDaysOfWeekIso();
            if (!empty($daysIso) && !in_array($dowIso, $daysIso, true)) return false;
            // days_of_week が空なら「毎日」扱い（現仕様踏襲）
        }

        // --- スロット判定（★HabitTime を優先） ---
        // slot指定なしなら、曜日OK時点で true（スロットは表示側で決めればよい）
        if ($slot === null) {
            return true;
        }

        // HabitTime がロード済みならそれで判定（N+1回避）
        if ($this->relationLoaded('times')) {
            $times = $this->times;

            // HabitTimeがあるなら、それを真実として扱う
            if ($times && $times->count() > 0) {
                // anytime(0) が含まれるなら全スロットOK
                if ($times->contains(fn ($t) => (int) ($t->time_slot ?? 0) === 0)) {
                    return true;
                }
                return $times->contains(fn ($t) => (int) ($t->time_slot ?? 0) === (int) $slot);
            }
        }

        // times がロードされていない/存在不明の場合は、
        // DBに HabitTime が存在するかを軽く確認してから判定（ただし多用には注意）
        // ※このメソッドを大量ループで呼ぶなら、呼び出し元で times を eager load 推奨
        if ($this->times()->exists()) {
            return $this->times()
                ->where(function ($q) use ($slot) {
                    $q->where('time_slot', 0)
                      ->orWhere('time_slot', (int) $slot);
                })
                ->exists();
        }

        // HabitTime が無い（旧データ）場合は legacy habit.time_slot にフォールバック
        $ts = (int) ($this->time_slot ?? 0);
        if ($ts === 0) return true;
        return (int) $slot === $ts;
    }

    private function normalizedDaysOfWeekIso(): array
    {
        $raw = $this->days_of_week ?? [];
        if (!is_array($raw)) $raw = [];

        $iso = [];
        foreach ($raw as $v) {
            $n = (int) $v;
            if ($n === 0) $n = 7;
            if ($n >= 1 && $n <= 7) {
                $iso[$n] = true;
            }
        }
        return array_keys($iso);
    }

    public function todayNotifyTimes(): array
    {
        return $this->times
            ->filter(fn ($t) => !empty($t->notify_time))
            ->map(function ($t) {
                try {
                    return Carbon::createFromFormat('H:i', (string) $t->notify_time);
                } catch (\Throwable $e) {
                    return null;
                }
            })
            ->filter()
            ->values()
            ->all();
    }

    /* =========================
     |  互換: name <-> title
     * ========================= */

    public function setNameAttribute($value): void
    {
        $this->attributes['title'] = $value;
    }

    public function getNameAttribute(): ?string
    {
        return $this->attributes['title'] ?? null;
    }
}
