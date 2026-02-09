<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use Illuminate\Console\Command;

class PrunePushSubscriptionsCommand extends Command
{
    protected $signature = 'push:prune {--days=30}';
    protected $description = 'Prune stale push subscriptions by last_seen_at (and legacy rows by created_at).';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        // 通常: last_seen_at がある行は last_seen_at 基準で削除
        $deletedSeen = PushSubscription::query()
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', $cutoff)
            ->delete();

        // レガシー/事故: last_seen_at が null の行は created_at 基準で削除
        $deletedLegacy = PushSubscription::query()
            ->whereNull('last_seen_at')
            ->whereNotNull('created_at')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $deleted = (int) $deletedSeen + (int) $deletedLegacy;

        $this->info(
            "Pruned {$deleted} push subscriptions older than {$days} days. (seen={$deletedSeen}, legacy={$deletedLegacy})"
        );

        return self::SUCCESS;
    }
}