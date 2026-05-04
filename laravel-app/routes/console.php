<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Daily Business Insights generation.
 *
 * Runs at 06:00 server time so insights are ready for the morning
 * client meetings. `withoutOverlapping` ensures we never double-fire
 * if the previous day's run is still completing. `runInBackground`
 * frees the scheduler tick so the schedule queue is never blocked
 * by the (multi-tenant) generation pass.
 *
 * Production crontab MUST contain (one entry — Laravel's scheduler
 * decides when to invoke commands inside it):
 *
 *   * * * * * cd /path/to/laravel-app && php artisan schedule:run >> /dev/null 2>&1
 *
 * Manual: php artisan insights:generate-daily [--force] [--tenant=slug]
 */
Schedule::command('insights:generate-daily')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->runInBackground();
