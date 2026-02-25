<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemindTask extends Model
{
  protected $table = 'remind_tasks';

  protected $fillable = [
    'habit_log_id',
    'habit_time_id',
    'parent_task_id',
    'root_task_id',
    'remind_at',
    'sent_at',
    'reschedule',
    'status',
    'attempts',
    'claim_token',
    'last_error',
  ];

  protected $casts = [
    'remind_at' => 'datetime',
    'sent_at' => 'datetime',
    'reschedule' => 'array',
    'attempts' => 'integer',
  ];

  public function habitLog(): BelongsTo
  {
    return $this->belongsTo(HabitLog::class, 'habit_log_id');
  }

  public function habitTime(): BelongsTo
  {
    return $this->belongsTo(HabitTime::class, 'habit_time_id');
  }

  public function parent(): BelongsTo
  {
    return $this->belongsTo(self::class, 'parent_task_id');
  }

  public function root(): BelongsTo
  {
    return $this->belongsTo(self::class, 'root_task_id');
  }
}
