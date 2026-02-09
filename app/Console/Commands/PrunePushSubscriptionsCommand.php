<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use Illuminate\Console\Command;

class PrunePushSubscriptionsCommand extends Command
{
    protected $signature = 'push:prune {--days=30}';
    protected $description = 'Prune stale push subscriptions by last_seen_at.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $deleted = PushSubscription::query()
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} push subscriptions older than {$days} days.");
        return self::SUCCESS;
    }
}