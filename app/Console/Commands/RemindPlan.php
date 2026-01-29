<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Reminders\RemindTaskPlanner;

class RemindPlan extends Command
{
    protected $signature = 'remind:plan {--days=2} {--debug=0}';
    protected $description = 'Create pending remind_tasks for coming days (JST)';

    public function handle(RemindTaskPlanner $planner): int
    {
        $days = (int) $this->option('days');
        $debug = (bool) ((int) $this->option('debug'));

        $res = $planner->plan($days, $debug, $this);

        $this->info("created={$res['created']} skipped={$res['skipped']} days={$days}");

        return self::SUCCESS;
    }
}
