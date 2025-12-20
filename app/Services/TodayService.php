<?php
// app/Services/TodayService.php

namespace App\Services;

use App\Models\Habit;
use App\Models\HabitLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TodayService
{
    public function __construct(
        private TodayProgressService $progressService
    ) {}

    public function buildTodayPayload(int $userId, string $scope = 'all'): array
    {
        $tz = 'Asia/Tokyo';

        $today      = Carbon::now($tz)->toDateString();
        $now        = Carbon::now($tz);
        $isoWeekday = Carbon::now($tz)->isoWeekday(); // 1..7

        $nowSlot  = $this->detectNowSlot($now);
        $nextSlot = $this->detectNextSlot($nowSlot);

        // ★曜日スケジュールで「今日やる習慣」だけ
        $habits = Habit::where('user_id', $userId)
            ->where('archived', false)
            ->orderBy('time_slot')
            ->orderBy('id')
            ->get()
            ->filter(fn (Habit $h) => $this->isHabitScheduledOn($h, $isoWeekday))
            ->values();

        /**
         * ★重要：履歴を残す設計なので「最新1件」を確実に取る
         * checked_at desc で並べて groupBy -> first が最新
         */
        $logsByHabit = HabitLog::where('user_id', $userId)
            ->where('date', $today)
            ->orderBy('checked_at', 'desc')
            ->get()
            ->groupBy('habit_id');

        $items = $habits->map(function (Habit $habit) use ($logsByHabit, $nowSlot) {
            $log = optional($logsByHabit->get($habit->id))->first();
            $logArr = $this->formatLog($log);

            return [
                'id'              => $habit->id,
                'title'           => $habit->title,
                'time_slot'       => (int) $habit->time_slot,
                'evaluation_type' => $habit->evaluation_type,
                'log'             => $logArr,

                'actionable'      => $this->isActionable($habit, $logArr, $nowSlot),
                'next_slot'       => $this->calcNextSlot($habit),
                'anytime'         => ((int) $habit->time_slot === 0),
            ];
        });

        // ★progress_by_scope を初回から全部返す（初回タブ遷移のちらつき防止）
        $progressByScope = $this->buildProgressByScopeFromItems($items);

        // 互換：従来の progress（単体）は scope に合わせて返す
        $normalizedScope = $this->normalizeScope($scope);
        $progress = $progressByScope[$normalizedScope] ?? $progressByScope['all'];

        $topPick  = $this->determineTopPick($items, $nowSlot);

        return [
            'today'     => $today,
            'now_slot'  => $nowSlot,
            'next_slot' => $nextSlot,
            'habits'    => $items,

            // 互換：単体
            'progress'  => $progress,

            // ★追加：全スコープ
            'progress_by_scope' => $progressByScope,
            'progress_updated_at' => Carbon::now($tz)->toIso8601String(),

            'top_pick'  => $topPick,
        ];
    }

    /**
     * items（= 今日やる習慣 + 最新ログ）から progress を全スコープ分まとめて計算
     * - all: 全習慣（anytime含む）
     * - morning/day/evening/night: time_slot==1..4 のみ（anytime=0は除外）
     * - done判定:
     *   - simple: status === done
     *   - self  : rating === 4 のみ
     */
    private function buildProgressByScopeFromItems(Collection $items): array
    {
        $init = fn () => ['done' => 0, 'total' => 0, 'percent' => 0];

        $out = [
            'all'     => $init(),
            'morning' => $init(),
            'day'     => $init(),
            'evening' => $init(),
            'night'   => $init(),
        ];

        foreach ($items as $it) {
            $done = $this->isDoneItem($it);
            $slot = (int)($it['time_slot'] ?? 0);

            // all（anytime含む）
            $out['all']['total']++;
            if ($done) $out['all']['done']++;

            // slot scopes（anytime=0は除外）
            if ($slot >= 1 && $slot <= 4) {
                $key = match ($slot) {
                    1 => 'morning',
                    2 => 'day',
                    3 => 'evening',
                    4 => 'night',
                };

                $out[$key]['total']++;
                if ($done) $out[$key]['done']++;
            }
        }

        foreach ($out as $k => $p) {
            $total = (int)($p['total'] ?? 0);
            $done  = (int)($p['done'] ?? 0);
            $out[$k]['percent'] = $total === 0 ? 0 : (int) round($done / $total * 100);
        }

        return $out;
    }

    private function normalizeScope(string $scope): string
    {
        return match ($scope) {
            'morning', 'day', 'evening', 'night', 'all' => $scope,
            default => 'all',
        };
    }

    /* ------------------------------
     * done 判定（SELFは rating=4 が真実）
     * ------------------------------ */
    private function isDoneItem(array $item): bool
    {
        $type = $item['evaluation_type'] ?? 'simple';
        $log  = $item['log'] ?? [];

        if ($type === 'self') {
            return (($log['rating'] ?? null) === 4);
        }
        return (($log['status'] ?? 'none') === 'done');
    }

    private function determineTopPick(Collection $items, int $nowSlot): ?array
    {
        $pending = $items->filter(fn ($it) => !$this->isDoneItem($it));
        if ($pending->isEmpty()) return null;

        $slotMatch = $pending->first(fn ($it) => (int)$it['time_slot'] === $nowSlot);
        if ($slotMatch) return $this->formatPick($slotMatch, 'slot_match');

        $future = $pending->filter(fn ($it) => (int)$it['time_slot'] > $nowSlot && (int)$it['time_slot'] !== 0)
            ->sortBy('time_slot')->first();
        if ($future) return $this->formatPick($future, 'future_slot');

        $any = $pending->first(fn ($it) => (int)$it['time_slot'] === 0);
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

    private function isActionable(Habit $habit, array $logArr, int $nowSlot): bool
    {
        if ($habit->evaluation_type === 'self') {
            if (($logArr['rating'] ?? null) === 4) return false;
        } else {
            if (($logArr['status'] ?? 'none') === 'done') return false;
        }

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

    /* =========================
     * 曜日スケジュール判定
     * ========================= */
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
