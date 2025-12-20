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

        $habits = Habit::query()
            ->where('user_id', $userId)
            ->where('archived', false)
            ->orderBy('time_slot')
            ->orderBy('id')
            ->get();

        $logs = HabitLog::query()
            ->where('user_id', $userId)
            ->whereIn('habit_id', $habits->pluck('id')->all())
            ->whereDate('date', '>=', $weekStart->toDateString())
            ->whereDate('date', '<=', $weekEnd->toDateString())
            ->orderBy('checked_at', 'desc')
            ->get();

        // ★同一性は habit_id + date（その日の最新 checked_at を真実とする）
        $map = [];
        foreach ($logs as $log) {
            $dateStr = $this->toDateString($log->date);
            $k = $this->keyLoose((int)$log->habit_id, $dateStr);
            if (!isset($map[$k])) {
                $map[$k] = $log;
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

                $k = $this->keyLoose((int)$habit->id, $dateStr);
                $log = $map[$k] ?? null;

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

                $item = [
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

                $dayHabits[] = $item;

                $total++;

                // ★done判定：SELFは rating=4 のみ
                if ($this->isDoneForHabit($habit->evaluation_type, $log)) {
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
                    'loose'  => $exampleLoose,
                    'loose_hit'  => isset($map[$exampleLoose]),
                ],
                'first_log' => $logs->first() ? [
                    'id' => $logs->first()->id,
                    'habit_id' => (int)$logs->first()->habit_id,
                    'date' => $this->toDateString($logs->first()->date),
                    'time_slot' => (int)($logs->first()->time_slot ?? 0),
                    'status' => $logs->first()->status,
                    'rating' => $logs->first()->rating,
                ] : null,
            ];
        }

        return response()->json($payload, 200, [], JSON_UNESCAPED_UNICODE);
    }

    private function isDoneForHabit(string $evaluationType, ?HabitLog $log): bool
    {
        if (!$log) return false;

        if ($evaluationType === 'self') {
            return ((int)($log->rating ?? -1) === 4);
        }
        return ($log->status === 'done');
    }

    private function toDateString($value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }
        return (string)$value;
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
