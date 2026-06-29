<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Scheduled tasks ──────────────────────────────────────────────────
// Daily database backup at 03:00 (server time). Keeps 7 days, prunes older.
// withoutOverlapping prevents a second run starting if one is somehow still going.
Schedule::command('backup:database --keep=7')
    ->dailyAt('03:00')
    ->withoutOverlapping();