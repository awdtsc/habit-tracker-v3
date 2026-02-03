<?php

namespace App\Console\Commands;

use App\Services\Reminders\RemindTaskPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class RemindPlan extends Command
{
    protected $signature = 'remind:plan {--days=2} {--debug=0}';
    protected $description = 'Create pending remind_tasks for coming days (JST)';

    public function handle(RemindTaskPlanner $planner): int
    {
        $days = (int) $this->option('days');
        $days = max(1, min(14, $days));

        $debug = (bool) ((int) $this->option('debug'));

        $lockName = 'remind_plan_lock';
        $lockAcquired = false;

        $driver = DB::getDriverName();
        $cacheLock = null;

        // TTL: 想定最大実行時間より十分長く（失効→二重起動を避ける）
        $cacheLockSeconds = 900; // 15 min

        try {
            if ($driver === 'mysql') {
                $lockRow = DB::selectOne('SELECT GET_LOCK(?, 0) as l', [$lockName]);
                $lockAcquired = isset($lockRow->l) && (int) $lockRow->l === 1;
            } else {
                try {
                    $cacheLock = Cache::lock($lockName, $cacheLockSeconds);
                    $lockAcquired = $cacheLock->get();
                } catch (Throwable $e) {
                    // lock機構そのものが壊れてる/非対応 → 見逃すと事故るのでFAIL
                    $this->error('remind:plan failed: lock provider error: ' . $e->getMessage());
                    return self::FAILURE;
                }
            }

            if (!$lockAcquired) {
                // すでに別プロセスが実行中 → 安全にスキップ（意図した挙動）
                $this->warn('remind:plan skipped: lock not acquired');
                return self::SUCCESS;
            }

            $res = $planner->plan($days, $debug, $this);

            $this->info("created={$res['created']} skipped={$res['skipped']} days={$days}");

            return self::SUCCESS;
        } finally {
            if ($lockAcquired) {
                if ($driver === 'mysql') {
                    DB::select('SELECT RELEASE_LOCK(?) as l', [$lockName]);
                } elseif ($cacheLock) {
                    $cacheLock->release();
                }
            }
        }
    }
}
