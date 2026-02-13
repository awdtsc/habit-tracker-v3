<?php

namespace App\Models;

use App\Models\Habit;
use App\Models\RemindTask;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HabitLog
 *  - 習慣の実行ログ（1日1回/時間帯など）
 *  - Reminder（remind_tasks）と紐づく場合がある（habit_log_id）
 *
 * 目的：
 *  - 保存時に最低限の整合性（owner補完 / self評価のstatus導出）を担保
 *  - N+1 的に habit を何度も参照しない（必要最小の字段だけを1回で取る）
 *  - user_id を mass-assignment から外して、将来の事故（なりすまし）を防ぐ
 */
class HabitLog extends Model
{
  use HasFactory;

  /**
   * mass assignment で受け付けるカラム
   * - user_id は ownership-sensitive なので除外（必ずサーバ側で補完/確定する）
   */
  protected $fillable = [
    'habit_id',
    'habit_time_id',
    'date',
    'time_slot',
    'status',
    'checked_at',
    'rating',
    'note',
  ];

  /**
   * DB → PHP の型変換
   * - date は Y-m-d に固定
   * - checked_at は datetime
   */
  protected $casts = [
    'date'       => 'date:Y-m-d',
    'time_slot'  => 'integer',
    'status'     => 'string',
    'checked_at' => 'datetime',
    'rating'     => 'integer',
  ];

  /**
   * 新規作成時のデフォルト
   * - status は none（未達）
   * - time_slot は 0（未指定/デフォルト枠）
   */
  protected $attributes = [
    'status'    => 'none',
    'time_slot' => 0,
  ];

  /**
   * HabitLog belongsTo Habit
   * - habit_logs.habit_id → habits.id
   */
  public function habit(): BelongsTo
  {
    return $this->belongsTo(Habit::class);
  }

  /**
   * HabitLog belongsTo HabitTime
   * - habit_logs.habit_time_id → habit_times.id
   */
  public function habitTime(): BelongsTo
  {
    return $this->belongsTo(HabitTime::class);
  }

  /**
   * HabitLog belongsTo User
   * - habit_logs.user_id → users.id
   */
  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  /**
   * HabitLog hasMany RemindTask
   * - remind_tasks.habit_log_id → habit_logs.id
   */
  public function remindTasks(): HasMany
  {
    return $this->hasMany(RemindTask::class, 'habit_log_id');
  }

  /**
   * date で絞り込むスコープ
   * @param mixed $date (Carbon|string など想定)
   */
  public function scopeForDate($query, $date)
  {
    return $query->whereDate('date', $date);
  }

  /**
   * status が done かどうか
   */
  public function isDone(): bool
  {
    return $this->status === 'done';
  }

  /**
   * Model event hooks
   *
   * saving:
   *  - self評価習慣の場合、rating から status を導出（rating が指定された時だけ）
   *    - rating >= 4 → done
   *    - rating <= 3 → none
   *  - user_id が空のときだけ、Habit.user_id から補完
   *
   * 注意：
   *  - habit を毎回 $log->habit で触ると、保存のたびに lazy-load が走りやすい
   *    → loaded relation があればそれを使い、なければ最小 select で1回だけ取る
   */
  protected static function booted(): void
  {
    static::saving(function (self $log): void {
      // Habit を「必要最小の字段だけ」一度だけ解決する
      // - 既に relation がロードされているならそれを使う（追加クエリを出さない）
      $habit = null;

      if ($log->relationLoaded('habit')) {
        $habit = $log->getRelation('habit');
      } elseif (!empty($log->habit_id)) {
        // ★ここで habit 全体を取る必要はないので select を絞る
        $habit = Habit::query()
          ->select(['id', 'user_id', 'evaluation_type'])
          ->find($log->habit_id);
      }

      // self評価（evaluation_type=self）のときだけ、status を rating から決める
      // - rating が来た時のみ上書きする（呼び出し側が status を明示したいケースの余地を残す）
      // - rating が無く status も空なら none に寄せる（整合性）
      if ($habit && (string) $habit->evaluation_type === 'self') {
        if ($log->rating !== null) {
          $log->status = ((int) $log->rating >= 4) ? 'done' : 'none';
        } elseif (empty($log->status)) {
          $log->status = 'none';
        }
      }

      // user_id は「未設定のときだけ」補完する
      // - mass assignment では受け付けない前提なので、ここで確定できる
      if (empty($log->user_id) && $habit && !empty($habit->user_id)) {
        $log->user_id = (int) $habit->user_id;
      }
    });
  }
}
