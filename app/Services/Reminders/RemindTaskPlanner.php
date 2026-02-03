<?php

namespace App\Services\Reminders;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RemindTaskPlanner
{
    /**
     * @return array{created:int, skipped:int, ignored_no_notify:int}
     */
    public function plan(int $days = 2, bool $debug = false, $console = null): array
    {
        $days = max(1, min(14, $days));
        $tz = 'Asia/Tokyo';

        $now = now($tz);
        $today = $now->toDateString();

        // ★この planning 範囲で既存 remind_tasks を先読みし、重複生成を抑止
        $rangeStart = $now->copy()->startOfDay();
        $rangeEnd = $now->copy()->addDays($days - 1)->endOfDay();

        $existing = DB::table('remind_tasks')
            ->select(['habit_time_id', 'remind_at'])
            ->whereBetween('remind_at', [
                $rangeStart->toDateTimeString(),
                $rangeEnd->toDateTimeString(),
            ])
            ->get();

        $existingMap = [];
        foreach ($existing as $row) {
            $habitTimeId = (int) $row->habit_time_id;
            $remindAt = (string) $row->remind_at;
            $existingMap[$habitTimeId][$remindAt] = true;
        }

        // ★notify_time がある habit_time だけ対象にする
        $habitTimes = DB::table('habit_times as ht')
            ->select([
                'ht.id as habit_time_id',
                'ht.notify_time as notify_time',
                'ht.remind_offset as remind_offset',
            ])
            ->whereNotNull('ht.notify_time')
            ->where('ht.notify_time', '!=', '')
            ->get();

        $created = 0;
        $skipped = 0;

        // 参考：全体のうち notify_time が無い数（デバッグ用）
        $ignoredNoNotify = (int) DB::table('habit_times')
            ->whereNull('notify_time')
            ->orWhere('notify_time', '=', '')
            ->count();

        for ($i = 0; $i < $days; $i++) {
            $date = $now->copy()->addDays($i)->toDateString();

            $rows = [];
            foreach ($habitTimes as $ht) {
                $time = $this->normalizeTimeString($ht->notify_time);
                if ($time === null) {
                    // 形式が壊れてたら除外（安全側）
                    continue;
                }

                $remindAt = Carbon::parse($date . ' ' . $time, $tz);

                $offsetMin = (int) ($ht->remind_offset ?? 0);
                if ($offsetMin !== 0) {
                    $remindAt = $remindAt->copy()->addMinutes($offsetMin);
                }

                $remindAtString = $remindAt->toDateTimeString();
                $habitTimeId = (int) $ht->habit_time_id;

                // 今日分は「過去時刻」を作らない
                if ($date === $today && $remindAt->lessThanOrEqualTo($now)) {
                    $skipped++;
                    continue;
                }

                // ★既存 or 同一run内での重複をスキップ
                if (isset($existingMap[$habitTimeId][$remindAtString])) {
                    $skipped++;
                    continue;
                }

                // ★同一run内の重複抑止（候補に入れた時点でマーク）
                $existingMap[$habitTimeId][$remindAtString] = true;

                $rows[] = [
                    'habit_time_id' => $habitTimeId,
                    'status' => 'pending',
                    'remind_at' => $remindAtString,
                    'attempts' => 0,
                    'claim_token' => null,
                    'last_error' => null,
                    'parent_task_id' => null,
                    'root_task_id' => null,
                    'created_at' => $now->toDateTimeString(),
                    'updated_at' => $now->toDateTimeString(),
                ];
            }

            if (empty($rows)) {
                continue;
            }

            // ★DB側でも ignore して二重を最終防衛（ユニークキー前提 or ignore運用）
            $inserted = DB::table('remind_tasks')->insertOrIgnore($rows);
            $created += (int) $inserted;

            if ($debug && $console) {
                $console->line("plan date={$date} inserted={$inserted} candidates=" . count($rows));
            }
        }

        if ($debug && $console) {
            $console->line("ignored_no_notify_time={$ignoredNoNotify}");
        }

        return ['created' => $created, 'skipped' => $skipped, 'ignored_no_notify' => $ignoredNoNotify];
    }

    private function normalizeTimeString($notifyTime): ?string
    {
        if ($notifyTime === null) {
            return null;
        }

        $s = trim((string) $notifyTime);
        if ($s === '') {
            return null;
        }

        if (preg_match('/^\d{1,2}:\d{2}$/', $s)) {
            [$h, $m] = explode(':', $s);
            $h = str_pad($h, 2, '0', STR_PAD_LEFT);
            return "{$h}:{$m}:00";
        }

        if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $s)) {
            [$h, $m, $sec] = explode(':', $s);
            $h = str_pad($h, 2, '0', STR_PAD_LEFT);
            return "{$h}:{$m}:{$sec}";
        }

        return null;
    }
}
