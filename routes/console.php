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
    ->name('sanctum:prune-expired');

// ------------------------------------------------------------
// M3: Dispatch due reminders (web push)
// - Run every minute
// - withoutOverlapping: prevent concurrent runs
//
// Notes:
// - Use schedule:work in local dev
//   php artisan schedule:work
// - Verify with:
//   php artisan schedule:list
// ------------------------------------------------------------
Schedule::command('remind:dispatch --limit=50')
    ->everyMinute()
    ->withoutOverlapping()
    ->name('remind:dispatch');
