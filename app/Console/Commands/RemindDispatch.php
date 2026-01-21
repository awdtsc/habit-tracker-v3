<?php

namespace App\Console\Commands;

use App\Services\Reminders\ReminderDispatchRunner;
use Illuminate\Console\Command;

class RemindDispatch extends Command
{
    protected $signature = 'remind:dispatch
        {--limit=50 : Max tasks per run}
        {--debug : Show debug output}
        {--rescue-minutes=15 : Rescue "sending" tasks older than N minutes back to pending}
        {--delay-before-claim=0 : Sleep N seconds before claim (race test)}';

    protected $description = 'Dispatch due remind_tasks as web push notifications';

    public function handle(ReminderDispatchRunner $runner): int
    {
        $limit = max(1, (int)$this->option('limit'));
        $debug = (bool)$this->option('debug');
        $rescueMinutes = max(1, (int)$this->option('rescue-minutes'));
        $delayBeforeClaim = max(0, (int)$this->option('delay-before-claim'));

        $result = $runner->run([
            'limit' => $limit,
            'debug' => $debug,
            'rescue_minutes' => $rescueMinutes,
            'delay_before_claim' => $delayBeforeClaim,
        ], $this);

        $this->info("done sent={$result['sent']} skipped={$result['skipped']} error={$result['error']}");
        return ($result['error'] === 0) ? self::SUCCESS : self::FAILURE;
    }
}
