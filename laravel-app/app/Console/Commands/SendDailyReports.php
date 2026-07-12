<?php

namespace App\Console\Commands;

use App\Mail\DailyReportMail;
use App\Models\Tenant;
use App\Models\User;
use App\Odoo\OdooFactory;
use App\Services\ReportsService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Email each client owner the prior-day activity report (sales cash/credit,
 * inventory movements at standard cost, expenses day + cumulative MTD).
 *
 * Scheduled daily at 08:00 Africa/Douala (routes/console.php). Only tenants
 * with the hidden `daily_report` module enabled receive it. Recipients resolve
 * in order: --to override → config/daily_report.php (per tenant slug) → the
 * tenant's active admin/owner users (fallback).
 *
 * Manual: php artisan reports:send-daily [--date=YYYY-MM-DD] [--tenant=slug] [--to=a@b.com,...]
 */
class SendDailyReports extends Command
{
    protected $signature = 'reports:send-daily
                            {--date=   : Report date YYYY-MM-DD (default: yesterday, Africa/Douala)}
                            {--tenant= : Only run for this tenant slug}
                            {--to=     : Override recipients (comma-separated emails)}';

    protected $description = 'Email client owners the prior-day activity report (sales, stock, expenses)';

    public function handle(): int
    {
        // "Yesterday" in the client's local day, not the server's UTC day.
        $tz   = new \DateTimeZone('Africa/Douala');
        $date = trim((string) $this->option('date'));
        if ($date === '') {
            $date = (new \DateTimeImmutable('now', $tz))->modify('-1 day')->format('Y-m-d');
        }
        try {
            $date = (new \DateTimeImmutable($date))->format('Y-m-d');
        } catch (\Throwable $e) {
            $this->error("Invalid --date '{$date}' (expected YYYY-MM-DD).");
            return self::FAILURE;
        }

        $only  = trim((string) $this->option('tenant'));
        $toOpt = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('to')))));

        $query = Tenant::real();
        if (defined(Tenant::class . '::STATUS_SUSPENDED')) {
            $query->where('status', '!=', Tenant::STATUS_SUSPENDED);
        }
        if ($only !== '') {
            $query->where('slug', $only);
        }
        $tenants = $query->orderBy('id')->get();

        $sent = 0; $skipped = 0; $failed = 0;
        foreach ($tenants as $t) {
            // Opt-in per tenant via the hidden daily_report module.
            if (!$t->moduleEnabled('daily_report')) { $skipped++; continue; }

            $this->line("  → {$t->slug} · report for {$date}");
            try {
                app(TenantContext::class)->set($t);

                // Recipients: --to override → config/daily_report.php → admin/owner users.
                $recipients = $toOpt;
                if (empty($recipients)) {
                    $recipients = array_values(array_filter(array_map(
                        'trim',
                        (array) config('daily_report.recipients.' . $t->slug, [])
                    )));
                }
                if (empty($recipients)) {
                    $recipients = User::query()
                        ->where('tenant_id', $t->id)
                        ->whereIn('role', [User::ROLE_TENANT_ADMIN, User::ROLE_OWNER])
                        ->where('status', User::STATUS_ACTIVE)
                        ->whereNotNull('email')
                        ->pluck('email')->unique()->values()->all();
                }
                if (empty($recipients)) {
                    $this->warn('     no configured recipients — skipped');
                    $skipped++;
                    continue;
                }

                $svc = new ReportsService(OdooFactory::fromConfig());
                $mail = new DailyReportMail(
                    (string) ($t->brand ?: $t->name),
                    $date,
                    $svc->dailySales($date),
                    $svc->dailyInventory($date),
                    $svc->dailyExpenses($date),
                    $svc->currency(),
                );
                Mail::to($recipients)->send($mail);
                $this->line('     sent to ' . implode(', ', $recipients));
                $sent++;
            } catch (\Throwable $e) {
                $this->error('     failed: ' . $e->getMessage());
                $failed++;
            }
        }

        $this->info("Done. sent={$sent} · skipped={$skipped} · failed={$failed}");
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
