<?php
// app/Services/TodayProgressService.php

namespace App\Services;

use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\HabitTime;
use Carbon\Carbon;

class TodayProgressService
{
    /**
     * 同一リクエスト内の重複計算を避けるキャッシュ
     * key: "{$userId}|{$date}"
     */
    private array $allScopesCache = [];

    /**
     * 今日/任意日付の progress を「唯一の真実」として計算する（互換維持）
     * - スケジュール(days_of_week)を考慮
     * - scope(all/morning/day/evening/night)を考慮
     * - done判定：
     *   - simple: status === done
     *   - self  : rating === 4 のみ
     *
     * ※内部的には calculateAllScopes() を使う（同一リクエスト内はキャッシュ）
     */
    public function calculate(int $userId, string $date, string $scope = 'all'): array
    {
        $all = $this->calculateAllScopes($userId, $date);

        if (!isset($all[$scope])) {
            $scope = 'all';
        }

        return [
            'scope'   => $scope,
            'done'    => $all[$scope]['done'],
            'total'   => $all[$scope]['total'],
            'percent' => $all[$scope]['percent'],
        ];
    }

    /**
     * B-1用：全スコープのprogressをまとめて計算する
     *
     * NOTE:
     * - anytime(0)は all には含めるが、morning/day/evening/night には含めない
     * - days_of_week が空なら毎日対象
     */
    public function calculateAllScopes(int $userId, string $date): array
    {
        $cacheKey = $userId . '|' . $date;
        if (isset($this->allScopesCache[$cacheKey])) {
            return $this->allScopesCache[$cacheKey];
        }

        $tz = 'Asia/Tokyo';
        $dateCarbon = Carbon::parse($date, $tz);

        // 初期（total=0のスコープも必ず返す）
        $scopes = ['all', 'morning', 'day', 'evening', 'night'];
        $counts = [];
        foreach ($scopes as $s) {
            $counts[$s] = ['done' => 0, 'total' => 0, 'percent' => 0];
        }

        // 1) 対象 habit_times（未アーカイブ + スケジュール判定）
        $habitTimes = HabitTime::query()
            ->whereHas('habit', function ($query) use ($userId) {
                $query->where('user_id', $userId)
                    ->where('archived', false);
            })
            ->with('habit')
            ->orderBy('time_slot')
            ->orderBy('id')
            ->get()
            ->filter(fn (HabitTime $t) => $t->habit && $this->isHabitScheduledOn($t->habit, $dateCarbon))
            ->values();

        $habitTimeIds = $habitTimes->pluck('id')->all();

        if (count($habitTimeIds) === 0) {
            return $this->allScopesCache[$cacheKey] = $counts;
        }

        // 2) 当日のログを habit_time_id ごとに「最新1件」に潰す
        //    checked_at desc（同時刻は id desc）で安定化
        $logsByHabitTime = HabitLog::query()
            ->where('user_id', $userId)
            ->whereDate('date', $date)
            ->whereIn('habit_time_id', $habitTimeIds)
            ->orderBy('checked_at', 'desc')
            ->orderBy('id', 'desc')
            ->get()
            ->groupBy('habit_time_id')
            ->map(fn ($g) => $g->first());

        // 3) 1回の走査で all + 各slotのtotal/done を積む
        foreach ($habitTimes as $habitTime) {
            $slot  = (int) ($habitTime->time_slot ?? 0);
            $habit = $habitTime->habit;

            $log   = $logsByHabitTime->get($habitTime->id);
            $isDone = $habit ? $this->isDoneForHabit($habit, $log) : false;

            // all（anytime含む）
            $counts['all']['total']++;
            if ($isDone) $counts['all']['done']++;

            // slot scopes（anytime=0は除外、1..4のみ）
            $scope = $this->slotToScope($slot);
            if ($scope !== null) {
                $counts[$scope]['total']++;
                if ($isDone) $counts[$scope]['done']++;
            }
        }

        // 4) percent を確定
        foreach ($scopes as $s) {
            $total = (int) $counts[$s]['total'];
            $done  = (int) $counts[$s]['done'];
            $counts[$s]['percent'] = ($total === 0) ? 0 : (int) round($done / $total * 100);
        }

        return $this->allScopesCache[$cacheKey] = $counts;
    }

    private function isDoneForHabit(Habit $habit, ?HabitLog $log): bool
    {
        if (!$log) return false;

        if (($habit->evaluation_type ?? 'simple') === 'self') {
            return ((int) ($log->rating ?? -1) === 4); // ★SELFはratingのみ
        }
        return (($log->status ?? 'none') === 'done');
    }

    private function slotToScope(int $slot): ?string
    {
        return match ($slot) {
            1 => 'morning',
            2 => 'day',
            3 => 'evening',
            4 => 'night',
            default => null,
        };
    }

    private function isHabitScheduledOn(Habit $habit, Carbon $date): bool
    {
        return $habit->isScheduledFor($date);
    }
}
