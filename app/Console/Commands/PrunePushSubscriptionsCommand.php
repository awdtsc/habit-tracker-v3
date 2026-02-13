<?php
// app/Console/Commands/PrunePushSubscriptionsCommand.php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use Illuminate\Console\Command;

class PrunePushSubscriptionsCommand extends Command
{
  /**
   * Safety design:
   * - Always supports --dry-run
   * - Hard limit per run (max 5000)
   * - If candidates exceed threshold, require explicit --confirm
   * - Optional strict confirm value: --confirm=123 (must match candidates)
   */
  protected $signature = 'push:prune
        {--days=30 : Stale threshold in days}
        {--limit=1000 : Max rows to delete per run (clamped to 1..5000)}
        {--dry-run : Show candidates only (no delete)}
        {--threshold=200 : Require --confirm if candidates exceed this number}
        {--confirm= : Confirmation gate. If empty: just acknowledges. If numeric: must equal candidates.}';

  protected $description = 'Prune stale push subscriptions by last_seen_at (and legacy rows by created_at), with safety guards.';

  public function handle(): int
  {
    $days = max(1, (int) $this->option('days'));
    $cutoff = now()->subDays($days);

    $limit = (int) ($this->option('limit') ?? 1000);
    $limit = max(1, min(5000, $limit));

    $dryRun = (bool) ($this->option('dry-run') ?? false);

    $threshold = (int) ($this->option('threshold') ?? 200);
    $threshold = max(0, $threshold);

    $confirmOpt = $this->option('confirm'); // string|null

    $seenQuery = PushSubscription::query()
      ->whereNotNull('last_seen_at')
      ->where('last_seen_at', '<', $cutoff);

    $legacyQuery = PushSubscription::query()
      ->whereNull('last_seen_at')
      ->whereNotNull('created_at')
      ->where('created_at', '<', $cutoff);

    $seenCount = (clone $seenQuery)->count();
    $legacyCount = (clone $legacyQuery)->count();
    $candidates = (int) $seenCount + (int) $legacyCount;

    $this->info(
      "Candidates={$candidates} (seen={$seenCount}, legacy={$legacyCount}), days={$days}, cutoff={$cutoff->toDateTimeString()}, limit={$limit}, threshold={$threshold}"
    );

    if ($dryRun) {
      $this->info('Dry run: no rows deleted.');
      return self::SUCCESS;
    }

    // Confirmation gate if too many candidates
    if ($threshold > 0 && $candidates > $threshold) {
      if ($confirmOpt === null || $confirmOpt === '') {
        $this->error("Refusing to prune: candidates ({$candidates}) exceed threshold ({$threshold}). Re-run with --confirm.");
        $this->line("Tip: use --confirm={$candidates} to require exact match.");
        return self::FAILURE;
      }

      // If confirm is numeric, require exact match to candidates
      if (is_string($confirmOpt) && preg_match('/^\d+$/', $confirmOpt)) {
        $expected = (int) $confirmOpt;
        if ($expected !== $candidates) {
          $this->error("Refusing to prune: --confirm={$expected} does not match candidates={$candidates}.");
          $this->line("Re-run with --confirm={$candidates} (or set a higher --threshold).");
          return self::FAILURE;
        }
      }
    }

    $deletedSeen = 0;
    $deletedLegacy = 0;

    // Delete seen rows first (older first), then legacy rows up to remaining limit
    $seenIds = (clone $seenQuery)
      ->orderBy('last_seen_at')
      ->limit($limit)
      ->pluck('id')
      ->all();

    if (!empty($seenIds)) {
      $deletedSeen = (int) PushSubscription::query()
        ->whereIn('id', $seenIds)
        ->delete();
    }

    $remaining = max(0, $limit - $deletedSeen);

    if ($remaining > 0) {
      $legacyIds = (clone $legacyQuery)
        ->orderBy('created_at')
        ->limit($remaining)
        ->pluck('id')
        ->all();

      if (!empty($legacyIds)) {
        $deletedLegacy = (int) PushSubscription::query()
          ->whereIn('id', $legacyIds)
          ->delete();
      }
    }

    $deleted = $deletedSeen + $deletedLegacy;

    $this->info(
      "Pruned {$deleted} push subscriptions older than {$days} days (limit={$limit}). candidates={$candidates} (seen_deleted={$deletedSeen}, legacy_deleted={$deletedLegacy})"
    );

    return self::SUCCESS;
  }
}
