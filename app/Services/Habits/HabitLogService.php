<?php

namespace App\Services\Habits;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HabitLogService
{
    /**
     * ★ユニーク (user_id, habit_time_id, date) 前提で冪等に done 化する
     * - 既に行があれば UPDATE して done にする（insertしない）
     * - 無ければ INSERT
     */
    public function ensureDoneLog(
        int $habitId,
        int $habitTimeId,
        int $userId,
        string $dateYmd,
        int $timeSlot,
        string $evaluationType,
        Carbon $nowJst,
        string $tz = 'Asia/Tokyo'
    ): ?int {
        if (!Schema::hasTable('habit_logs')) return null;

        $existing = DB::table('habit_logs')
            ->where('habit_time_id', $habitTimeId)
            ->where('user_id', $userId)
            ->where('date', $dateYmd)
            ->first();

        // checked_at はJSTの日時文字列
        $checkedAt = $nowJst->copy()->setTimezone($tz)->toDateTimeString();

        $patch = [];
        if (Schema::hasColumn('habit_logs', 'habit_id')) $patch['habit_id'] = $habitId;
        if (Schema::hasColumn('habit_logs', 'time_slot')) $patch['time_slot'] = $timeSlot;
        if (Schema::hasColumn('habit_logs', 'status')) $patch['status'] = 'done';
        if (Schema::hasColumn('habit_logs', 'checked_at')) $patch['checked_at'] = $checkedAt;

        if (Schema::hasColumn('habit_logs', 'rating')) {
            $patch['rating'] = ($evaluationType === 'self') ? 4 : null;
        }

        if (Schema::hasColumn('habit_logs', 'updated_at')) $patch['updated_at'] = $nowJst;

        if ($existing) {
            DB::table('habit_logs')->where('id', (int)$existing->id)->update($patch);
            return (int)$existing->id;
        }

        $data = [];
        if (Schema::hasColumn('habit_logs', 'habit_id')) $data['habit_id'] = $habitId;
        if (Schema::hasColumn('habit_logs', 'habit_time_id')) $data['habit_time_id'] = $habitTimeId;
        if (Schema::hasColumn('habit_logs', 'user_id')) $data['user_id'] = $userId;
        if (Schema::hasColumn('habit_logs', 'date')) $data['date'] = $dateYmd;
        if (Schema::hasColumn('habit_logs', 'time_slot')) $data['time_slot'] = $timeSlot;
        if (Schema::hasColumn('habit_logs', 'status')) $data['status'] = 'done';
        if (Schema::hasColumn('habit_logs', 'rating')) $data['rating'] = ($evaluationType === 'self') ? 4 : null;
        if (Schema::hasColumn('habit_logs', 'checked_at')) $data['checked_at'] = $checkedAt;
        if (Schema::hasColumn('habit_logs', 'created_at')) $data['created_at'] = $nowJst;
        if (Schema::hasColumn('habit_logs', 'updated_at')) $data['updated_at'] = $nowJst;

        return (int)DB::table('habit_logs')->insertGetId($data);
    }
}
