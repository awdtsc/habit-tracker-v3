<?php

namespace App\Services\Habits;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HabitLogService
{
    /**
     * ★ユニーク (user_id, habit_time_id, date) 前提で冪等に done 化する
     * - 既に行があれば UPDATE して done にする
     * - 無ければ INSERT
     *
     * self評価のときは rating=4 を必ず入れる（フロントの done 判定と一致させる）
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

        // checked_at は JST の日時文字列
        $checkedAt = $nowJst->copy()->setTimezone($tz)->toDateTimeString();

        // ★self判定は堅牢に（大小文字や想定外表記に耐える）
        $eval = strtolower(trim((string)$evaluationType));
        $isSelf = ($eval === 'self');

        $patch = $this->buildDonePatch(
            habitId: $habitId,
            habitTimeId: $habitTimeId,
            userId: $userId,
            dateYmd: $dateYmd,
            timeSlot: $timeSlot,
            checkedAt: $checkedAt,
            nowJst: $nowJst,
            isSelf: $isSelf,
            includeCreatedAt: false
        );

        if ($existing) {
            DB::table('habit_logs')->where('id', (int)$existing->id)->update($patch);
            return (int)$existing->id;
        }

        $data = $this->buildDonePatch(
            habitId: $habitId,
            habitTimeId: $habitTimeId,
            userId: $userId,
            dateYmd: $dateYmd,
            timeSlot: $timeSlot,
            checkedAt: $checkedAt,
            nowJst: $nowJst,
            isSelf: $isSelf,
            includeCreatedAt: true
        );

        return (int) DB::table('habit_logs')->insertGetId($data);
    }

    /**
     * done 化に必要な data/patch を組み立てる（insert/update 共通）
     *
     * @return array<string,mixed>
     */
    private function buildDonePatch(
        int $habitId,
        int $habitTimeId,
        int $userId,
        string $dateYmd,
        int $timeSlot,
        string $checkedAt,
        Carbon $nowJst,
        bool $isSelf,
        bool $includeCreatedAt
    ): array {
        $out = [];

        if (Schema::hasColumn('habit_logs', 'habit_id')) $out['habit_id'] = $habitId;
        if (Schema::hasColumn('habit_logs', 'habit_time_id')) $out['habit_time_id'] = $habitTimeId;
        if (Schema::hasColumn('habit_logs', 'user_id')) $out['user_id'] = $userId;
        if (Schema::hasColumn('habit_logs', 'date')) $out['date'] = $dateYmd;

        if (Schema::hasColumn('habit_logs', 'time_slot')) $out['time_slot'] = $timeSlot;
        if (Schema::hasColumn('habit_logs', 'status')) $out['status'] = 'done';
        if (Schema::hasColumn('habit_logs', 'checked_at')) $out['checked_at'] = $checkedAt;

        // self のときは rating=4。そうでないときは null（フロントの判定と一致）
        if (Schema::hasColumn('habit_logs', 'rating')) {
            $out['rating'] = $isSelf ? 4 : null;
        }

        if ($includeCreatedAt && Schema::hasColumn('habit_logs', 'created_at')) {
            $out['created_at'] = $nowJst;
        }
        if (Schema::hasColumn('habit_logs', 'updated_at')) {
            $out['updated_at'] = $nowJst;
        }

        return $out;
    }
}