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
// Client Strategic Analysis — generated monthly (1st of the month, 06:00).
// The service is idempotent per calendar month, so this produces one audit
// per client per month; admins can force a fresh run any time from the UI.
Schedule::command('insights:generate-daily')
    ->monthlyOn(1, '06:00')
    ->withoutOverlapping()
    ->runInBackground();

// Daily prior-day activity email to client owners (sales cash/credit,
// inventory movements at standard cost, expenses day + MTD). Gated per
// tenant by the hidden `daily_report` module. 08:00 in the client's
// timezone (Douala) — the schedule entry pins the tz explicitly since
// the app default is UTC.
//
// Manual: php artisan reports:send-daily [--date=YYYY-MM-DD] [--tenant=slug] [--to=a@b.com]
Schedule::command('reports:send-daily')
    ->dailyAt('08:00')
    ->timezone('Africa/Douala')
    ->withoutOverlapping()
    ->runInBackground();
