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
     */
    public function buildTodayPayload(int $userId): array
    {
        $today   = Carbon::today()->toDateString();
        $now     = Carbon::now();
        $nowSlot = $this->detectNowSlot($now);

        // ------------------------------------------------------------
        // 1) ユーザーの習慣をまとめて取得
        // ------------------------------------------------------------
        $habits = Habit::where('user_id', $userId)
            ->where('archived', false)
            ->orderBy('time_slot')
            ->orderBy('id')
            ->get();

        // ------------------------------------------------------------
        // 2) 今日の HabitLog を一括取得（N+1 解消）
        //    key: habit_id => Collection<HabitLog>
        // ------------------------------------------------------------
        $logsByHabit = HabitLog::where('user_id', $userId)
            ->where('date', $today)
            ->get()
            ->groupBy('habit_id');

        // ------------------------------------------------------------
        // 3) Habit × 今日の HabitLog を統合してフロント用配列に整形
        // ------------------------------------------------------------
        $items = $habits->map(function (Habit $habit) use ($logsByHabit, $nowSlot) {

            /** @var HabitLog|null $log */
            $log = optional($logsByHabit->get($habit->id))->first();

            return [
                'id'              => $habit->id,
                'title'           => $habit->title,
                'time_slot'       => (int) $habit->time_slot,
                'evaluation_type' => $habit->evaluation_type,  // ★ self / simple をフロントへ

                // ★ status 'none' / rating null を含む統一ログ形式
                'log'        => $this->formatLog($log),

                'actionable' => $this->isActionable($habit, $log, $nowSlot),
                'next_slot'  => $this->calcNextSlot($habit),

                // anytime 判定（slot 0）
                'anytime'    => ((int) $habit->time_slot === 0),
            ];
        });

        // ------------------------------------------------------------
        // 4) progress（今日の達成率）
        // ------------------------------------------------------------
        $progress = $this->calculateProgress($items);

        // ------------------------------------------------------------
        // 5) top_pick（今日のおすすめ習慣）
        // ------------------------------------------------------------
        $topPick = $this->determineTopPick($items, $nowSlot);

        return [
            'today'    => $today,
            'now_slot' => $nowSlot,
            'habits'   => $items,
            'progress' => $progress,
            'top_pick' => $topPick,
        ];
    }



    /* ============================================================
     * progress
     * ============================================================ */
    /**
     * Progress 計算（★ anytime＝slot 0 は除外）
     */
    private function calculateProgress(Collection $items): array
    {
        // 対象となる習慣（slot 1〜4 のみ）
        $targets = $items->filter(
            fn ($it) => $it['time_slot'] !== 0
        );

        $planned = $targets->count();

        // slot 1〜4 の中で完了しているもの
        $done = $targets->filter(
            fn ($it) => $it['log']['status'] === 'done'
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
     * top_pick（最優先でやるべき習慣）
     * ============================================================ */
    private function determineTopPick(Collection $items, int $nowSlot): ?array
    {
        // 未完了の習慣
        $pending = $items->filter(
            fn ($it) => $it['log']['status'] !== 'done'
        );

        if ($pending->isEmpty()) {
            return null;
        }

        // 1) 今の時間帯と同じ slot
        $slotMatch = $pending->first(
            fn ($it) => $it['time_slot'] === $nowSlot
        );
        if ($slotMatch) {
            return $this->formatPick($slotMatch, 'slot_match');
        }

        // 2) 今より後の slot（anytime は除外）
        $future = $pending->filter(
            fn ($it) => $it['time_slot'] > $nowSlot && $it['time_slot'] !== 0
        )->sortBy('time_slot')->first();

        if ($future) {
            return $this->formatPick($future, 'future_slot');
        }

        // 3) anytime
        $any = $pending->first(
            fn ($it) => $it['time_slot'] === 0
        );
        if ($any) {
            return $this->formatPick($any, 'anytime');
        }

        // 4) fallback（最初の未完了）
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



    /* ============================================================
     * HabitLog → フロント用の一貫した形式に変換
     * ============================================================ */
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



    /* ============================================================
     * actionable
     * ============================================================ */
    private function isActionable(Habit $habit, ?HabitLog $log, int $nowSlot): bool
    {
        // すでに完了なら actionable ではない
        if ($log && $log->status === 'done') {
            return false;
        }

        $slot = (int) $habit->time_slot;

        // anytime は actionable には載せない
        if ($slot === 0) {
            return false;
        }

        // 現在の slot まで来ていれば actionable
        return $slot <= $nowSlot;
    }



    /* ============================================================
     * next_slot
     * ============================================================ */
    private function calcNextSlot(Habit $habit): ?int
    {
        $slot = (int) $habit->time_slot;

        if ($slot === 0) {
            return null; // anytime
        }
        if ($slot >= 4) {
            return null; // 夜の次はない
        }

        return $slot + 1;
    }



    /* ============================================================
     * 現在の時間帯 now_slot
     * ============================================================ */
    private function detectNowSlot(Carbon $now): int
    {
        $h = (int) $now->format('H');

        if ($h < 10) {
            return 1;  // 朝
        }
        if ($h < 15) {
            return 2;  // 昼
        }
        if ($h < 19) {
            return 3;  // 夕
        }

        return 4;      // 夜
    }
}