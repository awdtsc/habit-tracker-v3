<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RemindPruneExpiredGrace extends Command
{
    protected $signature = 'remind:prune-expired-grace {--days=14} {--chunk=1000} {--dry-run=0}';

    protected $description = 'Prune remind_tasks with status=skipped and last_error=expired_grace older than N days (JST).';

    public function handle(): int
    {
        $tz = 'Asia/Tokyo';

        $days = (int) $this->option('days');
        $days = max(1, min(365, $days));

        $chunk = (int) $this->option('chunk');
        $chunk = max(100, min(10000, $chunk));

        $dryRun = (bool) ((int) $this->option('dry-run'));

        $cutoff = now($tz)->subDays($days);

        $totalRoots = 0;
        $totalDeleted = 0;

        // root候補（id）をchunkで拾う
        DB::table('remind_tasks')
            ->select('id')
            ->where('status', 'skipped')
            ->where('last_error', 'expired_grace')
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById($chunk, function ($rows) use (&$totalRoots, &$totalDeleted, $dryRun, $cutoff) {

                $ids = [];
                foreach ($rows as $r) {
                    $ids[] = (int) $r->id;
                }
                if (empty($ids)) {
                    return;
                }

                $totalRoots += count($ids);

                // ★重要：削除対象も expired_grace 条件を再適用する（過剰削除を防ぐ）
                $q = DB::table('remind_tasks')
                    ->where('status', 'skipped')
                    ->where('last_error', 'expired_grace')
                    ->where('updated_at', '<', $cutoff)
                    ->where(function ($w) use ($ids) {
                        $w->whereIn('id', $ids)
                          ->orWhereIn('root_task_id', $ids)
                          ->orWhereIn('parent_task_id', $ids);
                    });

                if ($dryRun) {
                    $would = (int) $q->count();
                    $totalDeleted += $would;
                    return;
                }

                $deleted = (int) $q->delete();
                $totalDeleted += $deleted;
            });

        $this->info(sprintf(
            'remind:prune-expired-grace done | cutoff=%s (JST) | roots=%d | %s=%d',
            $cutoff->format('Y-m-d H:i:s'),
            $totalRoots,
            $dryRun ? 'would_delete' : 'deleted',
            $totalDeleted
        ));

        return Command::SUCCESS;
    }
}
