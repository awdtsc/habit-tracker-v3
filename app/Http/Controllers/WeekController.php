<?php
// app/Http/Controllers/WeekController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

use App\Models\Habit;
use App\Models\HabitLog;

class WeekController extends Controller
{
    public function show(Request $request)
    {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $tz = 'Asia/Tokyo';

        $weekParam = $request->query('week');
        $debug = $request->boolean('debug');

        $base = $weekParam
            ? Carbon::parse($weekParam, $tz)
            : Carbon::now($tz);

        $weekStart = $base->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $weekEnd   = $weekStart->copy()->addDays(6)->endOfDay();

        // habits
        $habits = Habit::query()
            ->where('user_id', $userId)
            ->where('archived', false)
            ->get();

        // logs（date が DATE/DATETIME どっちでも拾えるよう whereDate で）
        $logs = HabitLog::query()
            ->where('user_id', $userId)
            ->whereIn('habit_id', $habits->pluck('id')->all())
            ->whereDate('date', '>=', $weekStart->toDateString())
            ->whereDate('date', '<=', $weekEnd->toDateString())
            ->orderBy('checked_at', 'desc')
            ->get();

        // map：
        //  - strict: habit_id|date|time_slot
        //  - loose : habit_id|date  (time_slot がズレても拾う保険)
        $mapStrict = [];
        $mapLoose  = [];

        foreach ($logs as $log) {
            $dateStr = $this->toDateString($log->date);
            $slot = (int)($log->time_slot ?? 0);

            $kStrict = $this->keyStrict((int)$log->habit_id, $dateStr, $slot);
            $mapStrict[$kStrict] = $log;

            $kLoose = $this->keyLoose((int)$log->habit_id, $dateStr);
            // loose は “最新 checked_at” を優先（orderBy desc なので最初に入ったものが最新）
            if (!isset($mapLoose[$kLoose])) {
                $mapLoose[$kLoose] = $log;
            }
        }

        $days = [];
        $weeklyDone = 0;
        $weeklyTotal = 0;

        for ($i = 0; $i < 7; $i++) {
            $day = $weekStart->copy()->addDays($i);
            $dateStr = $day->toDateString();
            $isoWeekday = $day->isoWeekday(); // 1..7

            $dayHabits = [];
            $done = 0;
            $total = 0;

            foreach ($habits as $habit) {
                if (!$this->isHabitScheduledOn($habit, $isoWeekday)) {
                    continue;
                }

                $slot = (int)($habit->time_slot ?? 0);

                // まず strict で探す → 無ければ loose で拾う
                $kStrict = $this->keyStrict((int)$habit->id, $dateStr, $slot);
                $log = $mapStrict[$kStrict] ?? null;

                if (!$log) {
                    $kLoose = $this->keyLoose((int)$habit->id, $dateStr);
                    $log = $mapLoose[$kLoose] ?? null;
                }

                $logPayload = null;
                if ($log) {
                    $logPayload = [
                        'id'         => $log->id,
                        'habit_id'   => (int)$log->habit_id,
                        'time_slot'  => (int)($log->time_slot ?? 0),
                        'status'     => $log->status,
                        'rating'     => $log->rating,
                        'checked_at' => $log->checked_at,
                    ];
                }

                $dayHabits[] = [
                    'id'              => $habit->id,
                    'title'           => $habit->title,
                    'description'     => $habit->description,
                    'time_slot'       => (int)($habit->time_slot ?? 0),
                    'evaluation_type' => $habit->evaluation_type,
                    'target_times'    => $this->normalizeJson($habit->target_times),
                    'days_of_week'    => $this->normalizeJsonArray($habit->days_of_week),
                    'archived'        => (bool)$habit->archived,
                    'log'             => $logPayload,
                ];

                $total++;
                if ($log && $log->status === 'done') {
                    $done++;
                }
            }

            $percent = $total === 0 ? 0 : (int)round($done / $total * 100);

            $days[] = [
                'date'    => $dateStr,
                'label'   => $day->format('m/d'),
                'weekday' => $this->weekdayJa($isoWeekday),
                'habits'  => $dayHabits,
                'progress' => [
                    'done'    => $done,
                    'total'   => $total,
                    'percent' => $percent,
                ],
            ];

            $weeklyDone += $done;
            $weeklyTotal += $total;
        }

        $weeklyPercent = $weeklyTotal === 0 ? 0 : (int)round($weeklyDone / $weeklyTotal * 100);

        $payload = [
            'week_start' => $weekStart->toDateString(),
            'week_end'   => $weekEnd->toDateString(),
            'days'       => $days,
            'weekly_progress' => [
                'done'    => $weeklyDone,
                'total'   => $weeklyTotal,
                'percent' => $weeklyPercent,
            ],
        ];

        if ($debug) {
            $dbName = null;
            try {
                $dbName = DB::connection()->getDatabaseName();
            } catch (\Throwable $e) {
                $dbName = 'unknown';
            }

            // あなたの例：habit_id=1, date=2025-12-19, slot=1 が拾えてるか確認用
            $exampleStrict = $this->keyStrict(1, '2025-12-19', 1);
            $exampleLoose  = $this->keyLoose(1, '2025-12-19');

            $payload['_debug'] = [
                'auth_user_id' => $userId,
                'db' => [
                    'default' => config('database.default'),
                    'database' => $dbName,
                ],
                'range' => [$weekStart->toDateString(), $weekEnd->toDateString()],
                'habits_count' => $habits->count(),
                'logs_found' => $logs->count(),
                'example_keys' => [
                    'strict' => $exampleStrict,
                    'loose'  => $exampleLoose,
                    'strict_hit' => isset($mapStrict[$exampleStrict]),
                    'loose_hit'  => isset($mapLoose[$exampleLoose]),
                ],
                'first_log' => $logs->first() ? [
                    'id' => $logs->first()->id,
                    'habit_id' => (int)$logs->first()->habit_id,
                    'date' => $this->toDateString($logs->first()->date),
                    'time_slot' => (int)($logs->first()->time_slot ?? 0),
                    'status' => $logs->first()->status,
                ] : null,
            ];
        }

        return response()->json($payload, 200, [], JSON_UNESCAPED_UNICODE);
    }

    private function toDateString($value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }
        return (string)$value;
    }

    private function keyStrict(int $habitId, string $date, int $slot): string
    {
        return $habitId . '|' . $date . '|' . $slot;
    }

    private function keyLoose(int $habitId, string $date): string
    {
        return $habitId . '|' . $date;
    }

    private function normalizeJson($value)
    {
        if (is_array($value)) return $value;
        if (is_object($value)) return (array)$value;
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : null;
        }
        return $value;
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

    private function isHabitScheduledOn(Habit $habit, int $isoWeekday): bool
    {
        $days = $this->normalizeJsonArray($habit->days_of_week);
        return count($days) === 0 ? true : in_array($isoWeekday, $days, true);
    }

    private function weekdayJa(int $isoWeekday): string
    {
        return match ($isoWeekday) {
            1 => '月', 2 => '火', 3 => '水', 4 => '木', 5 => '金', 6 => '土', 7 => '日',
            default => '',
        };
    }
}
