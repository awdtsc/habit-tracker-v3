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
        'remind_offset', // ★追加
    ];

    protected $casts = [
        'habit_id' => 'integer',
        'time_slot' => 'integer',
        'notify_time' => 'string',
        'remind_offset' => 'integer', // ★追加
    ];

    public function habit()
    {
        return $this->belongsTo(Habit::class);
    }

    public function logs()
    {
        return $this->hasMany(HabitLog::class, 'habit_time_id');
    }
}