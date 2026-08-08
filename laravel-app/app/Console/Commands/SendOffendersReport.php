<?php

namespace App\Console\Commands;

use App\Mail\OffendersReportMail;
use App\Models\Tenant;
use App\Odoo\OdooFactory;
use App\Services\ReceivablesService;
use App\Services\RevenueService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Email the Receivables Top-Offender Scorecard every 3 days.
 *
 * Same data path as the Receivables page (RevenueService → ReceivablesService)
 * so the email and the screen always agree. Recipients come from
 * config/daily_report.php ('offenders' map, per tenant slug), overridable
 * with --to for testing. Sends via the MailerSend API when
 * MAILERSEND_API_KEY is set (traceable message-id), else the SMTP mailer.
 *
 * Manual: php artisan reports:send-offenders [--tenant=slug] [--to=a@b.com,...]
 */
class SendOffendersReport extends Command
{
    protected $signature = 'reports:send-offenders
                            {--tenant= : Only run for this tenant slug}
                            {--to=     : Override recipients (comma-separated)}';

    protected $description = 'Email the receivables Top-Offender Scorecard (every 3 days)';

    public function handle(): int
    {
        $only  = trim((string) $this->option('tenant'));
        $toOpt = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('to')))));

        $query = Tenant::real();
        if (defined(Tenant::class . '::STATUS_SUSPENDED')) {
            $query->where('status', '!=', Tenant::STATUS_SUSPENDED);
        }
        if ($only !== '') $query->where('slug', $only);

        $sent = 0; $skipped = 0; $failed = 0;
        foreach ($query->orderBy('id')->get() as $t) {
            $recipients = $toOpt !== []
                ? $toOpt
                : array_values(array_filter(array_map('trim', (array) config('daily_report.offenders.' . $t->slug, []))));
            if ($recipients === []) { $skipped++; continue; }

            $this->line("  → {$t->slug} · offenders scorecard");
            try {
                app(TenantContext::class)->set($t);
                $client = OdooFactory::fromConfig();
                $today  = new \DateTimeImmutable('today');
                $year   = (int) $today->format('Y');

                $rev = (new RevenueService($client, (string) config_env('REVENUE_SOURCE', 'invoice')))
                    ->analyze(null, ['type' => 'ytd', 'year' => $year]);
                $ar = (new ReceivablesService($client))->analyze(
                    (float) ($rev['current']['total'] ?? 0),
                    (int)   ($rev['period']['days']  ?? 1),
                    (string)($rev['currency']        ?? 'XAF'),
                    $today,
                );

                $mail = new OffendersReportMail(
                    (string) ($t->brand ?: $t->name),
                    $today->format('Y-m-d'),
                    (array) ($ar['top_partners'] ?? []),
                    ['total_ar' => $ar['total_ar'] ?? 0, 'dso' => $ar['dso'] ?? 0],
                    (string) ($rev['currency'] ?? 'XAF'),
                );

                $apiKey = trim((string) env('MAILERSEND_API_KEY', ''));
                if ($apiKey !== '') {
                    $resp = Http::withToken($apiKey)->acceptJson()->timeout(30)
                        ->post('https://api.mailersend.com/v1/email', [
                            'from'    => ['email' => (string) config('mail.from.address'), 'name' => (string) config('mail.from.name')],
                            'to'      => array_map(fn ($e) => ['email' => $e], $recipients),
                            'subject' => (string) $mail->envelope()->subject,
                            'html'    => $mail->render(),
                        ]);
                    if (in_array($resp->status(), [200, 202], true)) {
                        $this->line('     sent via API to ' . implode(', ', $recipients)
                            . ' · message-id=' . ($resp->header('x-message-id') ?: 'n/a'));
                        $sent++;
                    } else {
                        $this->error('     API send failed: HTTP ' . $resp->status() . ' · ' . mb_substr((string) $resp->body(), 0, 300));
                        $failed++;
                    }
                } else {
                    Mail::to($recipients)->send($mail);
                    $this->line('     sent to ' . implode(', ', $recipients));
                    $sent++;
                }
            } catch (\Throwable $e) {
                $this->error('     failed: ' . $e->getMessage());
                $failed++;
            }
        }

        $this->info("Done. sent={$sent} · skipped={$skipped} · failed={$failed}");
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
