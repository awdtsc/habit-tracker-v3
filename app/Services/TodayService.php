<?php
// app/Services/TodayService.php

namespace App\Services;

use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\HabitTime;
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

        $habitTimes = HabitTime::query()
            ->whereHas('habit', function ($query) use ($userId) {
                $query->where('user_id', $userId)
                    ->where('archived', false);
            })
            ->with('habit')
            ->orderBy('time_slot')
            ->orderBy('id')
            ->get()
            ->filter(fn (HabitTime $t) => $t->habit && $this->isHabitScheduledOn($t->habit, $isoWeekday))
            ->values();

        $habitTimeIds = $habitTimes->pluck('id')->all();

        /**
         * ★同一性は (user_id, habit_time_id, date) の 1 行
         * ＝「最終状態 + checked_at」が真実。よって habit_time_id ごとに最新 1 件を使う。
         *
         * ※重要：habitTimeIds が空のとき whereIn を省略すると「今日の全ログ」を拾うので必ず空にする。
         */
        if (empty($habitTimeIds)) {
            $logsByHabitTime = collect();
        } else {
            $logsByHabitTime = HabitLog::query()
                ->where('user_id', $userId)
                ->whereDate('date', $today)
                ->whereIn('habit_time_id', $habitTimeIds)
                ->orderBy('checked_at', 'desc')
                ->orderBy('id', 'desc') // 同時刻の安定化
                ->get()
                ->groupBy('habit_time_id');
        }

        $items = $habitTimes->map(function (HabitTime $habitTime) use ($logsByHabitTime, $nowSlot) {
            $habit = $habitTime->habit;

            $log = optional($logsByHabitTime->get($habitTime->id))->first();
            $logArr = $this->formatLog($log, $habitTime);

            return [
                'id'              => $habit->id,
                'habit_time_id'   => $habitTime->id,
                'title'           => $habit->title,
                'time_slot'       => (int) $habitTime->time_slot,
                'evaluation_type' => $habit->evaluation_type,
                'log'             => $logArr,

                'actionable'      => $this->isActionable($habitTime, $logArr, $nowSlot),
                'next_slot'       => $this->calcNextSlot($habitTime),
                'anytime'         => ((int) $habitTime->time_slot === 0),
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
            $slot = (int) ($it['time_slot'] ?? 0);

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
            $total = (int) ($p['total'] ?? 0);
            $done  = (int) ($p['done'] ?? 0);
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

        $slotMatch = $pending->first(fn ($it) => (int) $it['time_slot'] === $nowSlot);
        if ($slotMatch) return $this->formatPick($slotMatch, 'slot_match');

        $future = $pending->filter(fn ($it) => (int) $it['time_slot'] > $nowSlot && (int) $it['time_slot'] !== 0)
            ->sortBy('time_slot')->first();
        if ($future) return $this->formatPick($future, 'future_slot');

        $any = $pending->first(fn ($it) => (int) $it['time_slot'] === 0);
        if ($any) return $this->formatPick($any, 'anytime');

        return $this->formatPick($pending->first(), 'fallback');
    }

    private function formatPick(array $item, string $reason): array
    {
        return [
            'habit_id'      => $item['id'],
            'habit_time_id' => $item['habit_time_id'],
            'title'         => $item['title'],
            'time_slot'     => $item['time_slot'],
            'reason'        => $reason,
        ];
    }

    private function formatLog(?HabitLog $log, HabitTime $habitTime): array
    {
        if (!$log) {
            return [
                'habit_id'      => $habitTime->habit_id,
                'habit_time_id' => $habitTime->id,
                'status'        => 'none',
                'rating'        => null,
                'checked_at'    => null,
            ];
        }

        return [
            'id'            => $log->id,
            'habit_id'      => $log->habit_id,
            'habit_time_id' => $log->habit_time_id,
            'status'        => $log->status,
            'rating'        => $log->rating,
            'checked_at'    => $log->checked_at,
        ];
    }

    private function isActionable(HabitTime $habitTime, array $logArr, int $nowSlot): bool
    {
        $type = $habitTime->habit?->evaluation_type ?? 'simple';
        if ($type === 'self') {
            if (($logArr['rating'] ?? null) === 4) return false;
        } else {
            if (($logArr['status'] ?? 'none') === 'done') return false;
        }

        $slot = (int) $habitTime->time_slot;
        if ($slot === 0) return false;

        return $slot <= $nowSlot;
    }

    private function calcNextSlot(HabitTime $habitTime): ?int
    {
        $slot = (int) $habitTime->time_slot;
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
