<?php

namespace App\Services;

use App\Models\Habit;
use App\Models\HabitLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TodayService
{
    /**
     * TodayTab 用の JSON 全体を生成。
     */
    public function buildTodayPayload(int $userId): array
    {
        $today   = Carbon::today()->toDateString();
        $nowSlot = $this->detectNowSlot(Carbon::now());

        // ------------------------------------------------------------
        // 1) ユーザーの習慣取得
        // ------------------------------------------------------------
        $habits = Habit::where('user_id', $userId)
            ->where('archived', false)
            ->get();

        // ------------------------------------------------------------
        // 2) 整形
        // ------------------------------------------------------------
        $items = $habits->map(function ($habit) use ($today, $nowSlot) {
            $log = $this->getOrNullLog($habit->id, $today);

            return [
                'id'        => $habit->id,
                'title'     => $habit->title,
                'time_slot' => (int)$habit->time_slot,

                'log'       => $this->formatLog($log),

                'actionable' => $this->isActionable($habit, $log, $nowSlot),
                'next_slot'  => $this->calcNextSlot($habit),

                // anytime は actionable ではない（修正1）
                'anytime'    => ((int)$habit->time_slot === 0),
            ];
        });

        // ------------------------------------------------------------
        // 3) progress（修正3）
        // ------------------------------------------------------------
        $progress = $this->calculateProgress($items);

        // ------------------------------------------------------------
        // 4) top_pick（修正2）
        // ------------------------------------------------------------
        $topPick = $this->determineTopPick($items, $nowSlot);

        return [
            'today'     => $today,
            'now_slot'  => $nowSlot,
            'habits'    => $items,
            'progress'  => $progress,
            'top_pick'  => $topPick,
        ];
    }


    /* ============================================================
     * progress（修正3）
     * ============================================================ */

    private function calculateProgress(Collection $items): array
    {
        // 今日の対象習慣（未来 slot も含める）
        $planned = $items->count();

        $done = $items->filter(fn($it) =>
            $it['log'] && $it['log']['status'] === 'done'
        )->count();

        return [
            'planned_count'   => $planned,
            'done_count'      => $done,
            'completion_rate' => $planned > 0
                ? round($done / $planned, 2)
                : 0,
        ];
    }


    /* ============================================================
     * top_pick（修正2：future slot を slot順で評価）
     * ============================================================ */

    private function determineTopPick(Collection $items, int $nowSlot): ?array
    {
        $pending = $items->filter(fn($it) =>
            !$it['log'] || $it['log']['status'] !== 'done'
        );

        if ($pending->isEmpty()) return null;

        // 1) nowSlot に一致（最優先）
        $slotMatch = $pending->first(fn($it) =>
            $it['time_slot'] === $nowSlot
        );
        if ($slotMatch) return $this->formatPick($slotMatch, 'slot_match');

        // 2) future slot（slot昇順に変更 → 修正2）
        $future = $pending->filter(fn($it) =>
            $it['time_slot'] > $nowSlot && $it['time_slot'] !== 0
        )->sortBy('time_slot')->first();

        if ($future) return $this->formatPick($future, 'future_slot');

        // 3) anytime（最下位）
        $any = $pending->first(fn($it) =>
            $it['time_slot'] === 0
        );
        if ($any) return $this->formatPick($any, 'anytime');

        return $this->formatPick($pending->first(), 'fallback');
    }


    private function formatPick(array $item, string $reason): array
    {
        return [
            'habit_id' => $item['id'],
            'title'    => $item['title'],
            'time_slot'=> $item['time_slot'],
            'reason'   => $reason,
        ];
    }


    /* ============================================================
     * HabitLog
     * ============================================================ */

    private function getOrNullLog(int $habitId, string $date): ?HabitLog
    {
        return HabitLog::where('habit_id', $habitId)
            ->where('date', $date)
            ->first();
    }

    private function formatLog(?HabitLog $log): ?array
    {
        if (!$log) return null;

        return [
            'id'         => $log->id,
            'status'     => $log->status,
            'rating'     => $log->rating,
            'checked_at' => $log->checked_at,
        ];
    }


    /* ============================================================
     * actionable（修正1：anytime を除外）
     * ============================================================ */

    private function isActionable(Habit $habit, ?HabitLog $log, int $nowSlot): bool
    {
        if ($log && $log->status === 'done') return false;

        $slot = (int)$habit->time_slot;

        // anytime は actionable ではない
        if ($slot === 0) return false;

        // 現在の slot 以降は actionable
        return $slot <= $nowSlot;
    }


    /* ============================================================
     * next_slot
     * ============================================================ */

    private function calcNextSlot(Habit $habit): ?int
    {
        $slot = (int)$habit->time_slot;
        if ($slot === 0) return null;
        if ($slot >= 4) return null;
        return $slot + 1;
    }


    /* ============================================================
     * nowSlot 判定
     * ============================================================ */

    private function detectNowSlot(Carbon $now): int
    {
        $h = (int)$now->format('H');

        if ($h < 10) return 1; // 朝
        if ($h < 15) return 2; // 昼
        if ($h < 19) return 3; // 夕
        return 4;             // 夜
    }
}