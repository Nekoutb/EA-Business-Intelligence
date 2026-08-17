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
//
// NOT REGISTERED HERE — deliberately. This report is driven by its OWN
// crontab entry on the server, which redirects to /var/log/kwr-daily-report.log
// and is the reason its delivery is verifiable day by day.
//
// Measured 17 Aug 2026: Laravel's scheduler is NOT running on this box.
// `schedule:run` is absent from cron, so every task registered in this file
// has never fired — the offenders scorecard, the warehouse sync, the nightly
// reconcile. Only the two commands with dedicated crontab entries work: this
// report and bms:warm.
//
// When `schedule:run` is added to cron, everything below comes alive. If this
// entry were still registered it would then fire from BOTH places at the same
// minute and clients could receive the digest twice. withoutOverlapping()
// might absorb that, but "might" is not good enough for mail leaving the
// building, so the duplicate is removed instead.
//
// If the dedicated crontab entry is ever retired, re-register it here:
//   Schedule::command('reports:send-daily')->dailyAt('08:00')
//       ->timezone('Africa/Douala')->withoutOverlapping()->runInBackground();

// Receivables Top-Offender Scorecard — DAILY at 08:15 Douala, 15 minutes
// after the daily digest so the two never contend for the same minute.
// Recipients live in config/daily_report.php ('offenders').
//
// Was every third day-of-month; the client asked for it every day (17 Aug
// 2026). The old cron also read '15 7' (07:15) while the comment claimed
// 08:15 — dailyAt() now states the intended time once, in one place.
//
// Output is captured. Previously this ran with runInBackground() and no
// output target, which discards stdout entirely: there was no way to tell
// whether a send had happened, succeeded or failed, and the log had nothing
// in it to check. The daily digest is verifiable precisely because its cron
// entry redirects to a file, so this one gets the same treatment.
Schedule::command('reports:send-offenders')
    ->dailyAt('08:15')
    ->timezone('Africa/Douala')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/offenders-report.log'));

// P2 — warehouse sync. Incremental: each model resumes from its watermark,
// so a run only carries what changed. Every 15 minutes, offset from the
// page warmer so the two never compete for the same minute, and given a
// 10-minute budget after which it stops cleanly and resumes next tick.
Schedule::command('bms:warehouse-sync --seconds=600')
    ->cron('7,22,37,52 * * * *')
    ->withoutOverlapping(30)
    ->runInBackground();

// Nightly: re-check GL line state and existence for the current year.
//
// The incremental sync upserts by the LINE's write_date, which leaves two
// blind spots it can never close on its own — a move deleted upstream takes
// its lines with it and an upsert removes nothing, and parent_state changes
// with the parent move without reliably bumping the line's write_date. Both
// show up as the warehouse reporting MORE posted rows than Odoo (measured
// 13 Aug 2026: 12 stale rows on kwr alone) and neither is corrected by
// syncing harder.
//
// Separate from the 15-minute tick because it re-reads a year of ids. 02:40
// Douala, clear of the daily report and the offenders scorecard.
Schedule::command('bms:warehouse-sync --reconcile --seconds=900')
    ->dailyAt('02:40')
    ->timezone('Africa/Douala')
    ->withoutOverlapping(60)
    ->runInBackground();

// Async-data worker: pre-computes every Odoo-heavy page for every tenant
// (config/warm.php) so web requests only ever serve cached data — no more
// inline multi-minute rebuilds behind Cloudflare's ~100s ceiling. A sweep
// longer than the interval makes the next tick skip via the lock; the
// direct-crontab fallback (flock + `php artisan bms:warm`) behaves the same.
Schedule::command('bms:warm')
    ->everyThreeMinutes()
    ->withoutOverlapping(30)
    ->runInBackground();
