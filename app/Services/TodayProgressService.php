<?php
// app/Services/TodayProgressService.php

namespace App\Services;

use App\Models\Habit;
use App\Models\HabitLog;
use Carbon\Carbon;

class TodayProgressService
{
    /**
     * 今日/任意日付の progress を「唯一の真実」として計算する（互換維持）
     * - スケジュール(days_of_week)を考慮
     * - scope(all/morning/day/evening/night)を考慮
     * - done判定：
     *   - simple: status === done
     *   - self  : rating === 4 のみ
     *
     * ※内部的には calculateAllScopes() を使い、DB取得は1回にまとめる
     */
    public function calculate(int $userId, string $date, string $scope = 'all'): array
    {
        $all = $this->calculateAllScopes($userId, $date);

        if (!isset($all[$scope])) {
            // 不正scopeはallにフォールバック
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
     * B-1用：全スコープのprogressを「1回のDB取得」でまとめて計算する
     *
     * 返り値例:
     * [
     *   'all'     => ['done'=>3,'total'=>8,'percent'=>38],
     *   'morning' => ['done'=>1,'total'=>2,'percent'=>50],
     *   'day'     => ['done'=>0,'total'=>1,'percent'=>0],
     *   'evening' => ['done'=>2,'total'=>3,'percent'=>67],
     *   'night'   => ['done'=>0,'total'=>2,'percent'=>0],
     * ]
     *
     * NOTE:
     * - 現在の仕様どおり、anytime(0)は all には含めるが、morning/day/evening/night には含めない
     * - days_of_week が空なら毎日対象
     */
    public function calculateAllScopes(int $userId, string $date): array
    {
        $tz = 'Asia/Tokyo';
        $isoWeekday = Carbon::parse($date, $tz)->isoWeekday(); // 1..7

        // 1) 今日やる対象の習慣（未アーカイブ + 曜日フィルタ）
        $habits = Habit::where('user_id', $userId)
            ->where('archived', false)
            ->orderBy('time_slot')
            ->orderBy('id')
            ->get()
            ->filter(fn (Habit $h) => $this->isHabitScheduledOn($h, $isoWeekday))
            ->values();

        $habitIds = $habits->pluck('id')->all();

        // 初期（total=0のスコープも必ず返す）
        $scopes = ['all', 'morning', 'day', 'evening', 'night'];
        $counts = [];
        foreach ($scopes as $s) {
            $counts[$s] = ['done' => 0, 'total' => 0, 'percent' => 0];
        }

        if (count($habitIds) === 0) {
            return $counts;
        }

        // 2) 当日のログを habit_id ごとに「最新1件」に潰す（checked_at desc）
        $logsByHabit = HabitLog::where('user_id', $userId)
            ->where('date', $date)
            ->whereIn('habit_id', $habitIds)
            ->orderBy('checked_at', 'desc')
            ->get()
            ->groupBy('habit_id')
            ->map(fn ($g) => $g->first());

        // 3) 1回の走査で all + 各slotのtotal/done を積む
        foreach ($habits as $habit) {
            $slot = (int) ($habit->time_slot ?? 0);
            $log  = $logsByHabit->get($habit->id);
            $isDone = $this->isDoneForHabit($habit, $log);

            // all
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
            $total = $counts[$s]['total'];
            $done  = $counts[$s]['done'];
            $counts[$s]['percent'] = ($total === 0) ? 0 : (int) round($done / $total * 100);
        }

        return $counts;
    }

    private function isDoneForHabit(Habit $habit, ?HabitLog $log): bool
    {
        if (!$log) return false;

        if (($habit->evaluation_type ?? 'simple') === 'self') {
            return ((int)($log->rating ?? -1) === 4); // ★SELFはratingのみ
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
            default => null, // 0(anytime) や不正値は null
        };
    }

    private function isHabitScheduledOn(Habit $habit, int $isoWeekday): bool
    {
        $days = $this->normalizeJsonArray($habit->days_of_week);
        return count($days) === 0 ? true : in_array($isoWeekday, $days, true);
    }

    private function normalizeJsonArray($value): array
    {
        if (is_array($value)) return $value;
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
