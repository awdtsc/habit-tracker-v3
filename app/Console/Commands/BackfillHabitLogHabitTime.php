<?php

namespace App\Console\Commands;

use App\Models\HabitLog;
use App\Models\HabitTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillHabitLogHabitTime extends Command
{
    protected $signature = 'habit-logs:backfill-habit-time
        {--dry-run : Preview changes without applying}
        {--apply : Apply changes}';

    protected $description = 'Backfill habit_time_id on habit_logs and deduplicate by (user_id, habit_time_id, date).';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = (bool) $this->option('dry-run') || !$apply;

        if ($apply && $this->option('dry-run')) {
            $this->error('Choose either --dry-run or --apply, not both.');
            return self::FAILURE;
        }

        $mode = $apply ? 'apply' : 'dry-run';
        $this->info("habit-logs:backfill-habit-time ({$mode})");

        $backfilled = 0;
        $createdHabitTimes = 0;
        $updatedSlot = 0;

        // cache[habitId][slot] = habit_time_id
        $cache = [];

        HabitLog::query()
            ->whereNull('habit_time_id')
            ->orderBy('id')
            ->chunkById(200, function ($logs) use (
                $apply,
                $dryRun,
                &$backfilled,
                &$createdHabitTimes,
                &$updatedSlot,
                &$cache
            ) {
                foreach ($logs as $log) {
                    $habitId = (int) $log->habit_id;

                    // 旧ログ側の time_slot を尊重して対応する HabitTime を探す
                    // （NULL は 0 扱い）
                    $slot = (int) ($log->time_slot ?? 0);

                    if (!isset($cache[$habitId][$slot])) {
                        $foundId = HabitTime::query()
                            ->where('habit_id', $habitId)
                            ->where('time_slot', $slot)
                            ->value('id');

                        if ($foundId) {
                            $cache[$habitId][$slot] = (int) $foundId;
                        } else {
                            // ★重要: 見つからない場合、slot=0 に寄せない
                            // その slot の HabitTime を作る（dry-run では作らない）
                            if ($apply) {
                                $habitTime = HabitTime::firstOrCreate([
                                    'habit_id' => $habitId,
                                    'time_slot' => $slot,
                                ]);
                                $cache[$habitId][$slot] = (int) $habitTime->id;
                                $createdHabitTimes++;
                            } else {
                                // dry-run: まだ作れないので null のまま
                                $cache[$habitId][$slot] = null;
                                $createdHabitTimes++;
                            }
                        }
                    }

                    $habitTimeId = $cache[$habitId][$slot] ?? null;

                    // dry-run は「候補数のカウント」だけ進める
                    if ($dryRun) {
                        $backfilled++;
                        continue;
                    }

                    if (!$habitTimeId) {
                        // apply のはずなのにここに来るのは想定外（念のため）
                        $this->warn("Skip log id={$log->id}: cannot resolve habit_time_id (habit_id={$habitId}, slot={$slot})");
                        continue;
                    }

                    // apply: habit_time_id を埋め、time_slot は HabitTime の truth に合わせる
                    $habitTime = HabitTime::find($habitTimeId);

                    $log->habit_time_id = $habitTimeId;

                    if ($habitTime) {
                        $truthSlot = (int) ($habitTime->time_slot ?? 0);
                        if ((int) ($log->time_slot ?? 0) !== $truthSlot) {
                            $log->time_slot = $truthSlot;
                            $updatedSlot++;
                        }
                    }

                    $log->save();
                    $backfilled++;
                }
            });

        $this->info("Backfill candidates: {$backfilled}");
        $this->info("HabitTimes to create (missing slot mappings): {$createdHabitTimes}");
        if ($apply) {
            $this->info("Updated time_slot values (aligned to habit_time): {$updatedSlot}");
        }

        // dedupe: (user_id, habit_time_id, date) 単位で最新 checked_at を残す
        $deduped = 0;

        $duplicateGroups = HabitLog::query()
            ->select('user_id', 'habit_time_id', 'date', DB::raw('COUNT(*) as dup_count'))
            ->whereNotNull('habit_time_id')
            ->groupBy('user_id', 'habit_time_id', 'date')
            ->having('dup_count', '>', 1)
            ->get();

        foreach ($duplicateGroups as $group) {
            $logs = HabitLog::query()
                ->where('user_id', $group->user_id)
                ->where('habit_time_id', $group->habit_time_id)
                ->where('date', $group->date)
                ->orderBy('checked_at', 'desc')
                ->orderBy('id', 'desc')
                ->get();

            $keep = $logs->shift();
            $toDelete = $logs;

            if ($toDelete->isEmpty()) {
                continue;
            }

            $deduped += $toDelete->count();

            if ($apply) {
                HabitLog::query()
                    ->whereIn('id', $toDelete->pluck('id')->all())
                    ->delete();
            }
        }

        $this->info("Duplicate rows to delete: {$deduped}");

        if ($dryRun) {
            $this->info('Dry-run complete. Re-run with --apply to make changes.');
        } else {
            $this->info('Apply complete.');
        }

        return self::SUCCESS;
    }
}
