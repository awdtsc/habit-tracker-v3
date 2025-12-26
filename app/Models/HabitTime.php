<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HabitTime extends Model
{
    use HasFactory;

    protected $fillable = [
        'habit_id',
        'time_slot',
        'notify_time',
        'label',
    ];

    protected $casts = [
        'habit_id' => 'integer',
        'time_slot' => 'integer',
        'notify_time' => 'string', // 例: "08:00"（nullableでもcastは害なし）
    ];

    public function habit()
    {
        return $this->belongsTo(Habit::class);
    }

    public function logs()
    {
        // 外部キーを明示（習慣ログの同一性は habit_time_id 前提）
        return $this->hasMany(HabitLog::class, 'habit_time_id');
    }
}
