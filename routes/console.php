<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ------------------------------------------------------------
// M2: Prune expired Sanctum tokens
// - Run daily
// - Remove tokens expired for more than 24 hours
// ------------------------------------------------------------
Schedule::command('sanctum:prune-expired --hours=24')
    ->daily()
    ->timezone('Asia/Tokyo')
    ->name('sanctum:prune-expired');

// ------------------------------------------------------------
// M3: Dispatch due reminders (web push)
// - Run every minute
// - withoutOverlapping: prevent concurrent runs
// - onOneServer: multi-host safety (requires cache lock backend)
// ------------------------------------------------------------
Schedule::command('remind:dispatch --limit=50')
    ->everyMinute()
    ->timezone('Asia/Tokyo')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('remind:dispatch');

// ------------------------------------------------------------
// M4: Plan upcoming reminders (create pending remind_tasks)
// - Run daily in JST
// - Also run periodically as a safety net
// ------------------------------------------------------------
Schedule::command('remind:plan --days=2')
    ->dailyAt('00:05')
    ->timezone('Asia/Tokyo')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('remind:plan:daily');

Schedule::command('remind:plan --days=2')
    ->everyThreeHours()
    ->timezone('Asia/Tokyo')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('remind:plan:net');

// ------------------------------------------------------------
// M5: Prune expired_grace skipped tasks
// - Keep DB lean (no backlog)
// - Run daily (JST)
// ------------------------------------------------------------
Schedule::command('remind:prune-expired-grace --days=14')
    ->dailyAt('03:10')
    ->timezone('Asia/Tokyo')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('remind:prune-expired-grace');
