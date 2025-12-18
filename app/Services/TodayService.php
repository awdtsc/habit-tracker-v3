<?php
// app/Services/TodayService.php

namespace App\Services;

use App\Models\Habit;
use App\Models\HabitLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TodayService
{
    /**
     * TodayTab 用の JSON 全体を生成。
     * scope: morning / day / evening / night / all
     */
    public function buildTodayPayload(int $userId, string $scope = 'all'): array
    {
        $today   = Carbon::today()->toDateString();
        $now     = Carbon::now();
        $nowSlot = $this->detectNowSlot($now);
        $nextSlot = $this->detectNextSlot($nowSlot); // ★ root next_slot

        // 1) 習慣取得
        $habits = Habit::where('user_id', $userId)
            ->where('archived', false)
            ->orderBy('time_slot')
            ->orderBy('id')
            ->get();

        // 2) 今日のログ取得
        $logsByHabit = HabitLog::where('user_id', $userId)
            ->where('date', $today)
            ->get()
            ->groupBy('habit_id');

        // 3) Habit × Log
        $items = $habits->map(function (Habit $habit) use ($logsByHabit, $nowSlot) {
            $log = optional($logsByHabit->get($habit->id))->first();

            return [
                'id'              => $habit->id,
                'title'           => $habit->title,
                'time_slot'       => (int) $habit->time_slot,
                'evaluation_type' => $habit->evaluation_type,
                'log'             => $this->formatLog($log),

                // backend flags（将来フロントが信じられるように残す）
                'actionable'      => $this->isActionable($habit, $log, $nowSlot),
                'next_slot'       => $this->calcNextSlot($habit),
                'anytime'         => ((int) $habit->time_slot === 0),
            ];
        });

        // 4) progress（scope 連動・正）
        $progress = $this->calculateProgress($items, $scope);

        // 5) top_pick
        $topPick = $this->determineTopPick($items, $nowSlot);

        return [
            'today'     => $today,
            'now_slot'  => $nowSlot,
            'next_slot' => $nextSlot,   // ★ 追加
            'habits'    => $items,
            'progress'  => $progress,
            'top_pick'  => $topPick,
        ];
    }

    /* progress */
    private function calculateProgress(Collection $items, string $scope): array
    {
        if ($scope === 'all') {
            $targets = $items; // all は anytime(0)含む
        } else {
            $slot = $this->scopeToSlot($scope);
            $targets = $items->filter(fn ($it) => $it['time_slot'] === $slot); // 特定スロットのみ
        }

        $total = $targets->count();

        $done = $targets->filter(
            fn ($it) => ($it['log']['status'] ?? 'none') === 'done'
        )->count();

        return [
            'scope'   => $scope,
            'done'    => $done,
            'total'   => $total,
            'percent' => $total > 0 ? (int) round($done / $total * 100) : 0,
        ];
    }

    private function scopeToSlot(string $scope): int
    {
        return match ($scope) {
            'morning' => 1,
            'day'     => 2,
            'evening' => 3,
            'night'   => 4,
            default   => 0,
        };
    }

    /* top_pick */
    private function determineTopPick(Collection $items, int $nowSlot): ?array
    {
        $pending = $items->filter(fn ($it) => ($it['log']['status'] ?? 'none') !== 'done');
        if ($pending->isEmpty()) return null;

        $slotMatch = $pending->first(fn ($it) => $it['time_slot'] === $nowSlot);
        if ($slotMatch) return $this->formatPick($slotMatch, 'slot_match');

        $future = $pending->filter(fn ($it) => $it['time_slot'] > $nowSlot && $it['time_slot'] !== 0)
            ->sortBy('time_slot')->first();
        if ($future) return $this->formatPick($future, 'future_slot');

        $any = $pending->first(fn ($it) => $it['time_slot'] === 0);
        if ($any) return $this->formatPick($any, 'anytime');

        return $this->formatPick($pending->first(), 'fallback');
    }

    private function formatPick(array $item, string $reason): array
    {
        return [
            'habit_id'  => $item['id'],
            'title'     => $item['title'],
            'time_slot' => $item['time_slot'],
            'reason'    => $reason,
        ];
    }

    /* log */
    private function formatLog(?HabitLog $log): array
    {
        if (!$log) {
            return [
                'status'     => 'none',
                'rating'     => null,
                'checked_at' => null,
            ];
        }

        return [
            'id'         => $log->id,
            'status'     => $log->status,
            'rating'     => $log->rating,
            'checked_at' => $log->checked_at,
        ];
    }

    /* actionable */
    private function isActionable(Habit $habit, ?HabitLog $log, int $nowSlot): bool
    {
        if ($log && $log->status === 'done') return false;

        $slot = (int) $habit->time_slot;
        if ($slot === 0) return false;

        return $slot <= $nowSlot;
    }

    private function calcNextSlot(Habit $habit): ?int
    {
        $slot = (int) $habit->time_slot;
        if ($slot === 0 || $slot >= 4) return null;
        return $slot + 1;
    }

    private function detectNowSlot(Carbon $now): int
    {
        $h = (int) $now->format('H');
        if ($h < 10) return 1;
        if ($h < 15) return 2;
        if ($h < 19) return 3;
        return 4;
    }

    private function detectNextSlot(int $nowSlot): ?int
    {
        if ($nowSlot >= 4) return null;
        return $nowSlot + 1;
    }
}
