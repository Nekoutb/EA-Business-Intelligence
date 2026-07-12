<?php
/** TEMP diagnostic — Kissywears daily report test (Friday 12 June 2026):
 *  enable daily_report for kwr, report mail transport, render the digest
 *  (preview the figures), then run reports:send-daily to the two recipients.
 *  Token-guarded. Re-stubbed after. */
header('Content-Type: text/plain; charset=utf-8');
$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit; }
$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
function ln($s = '') { echo $s . "\n"; }

$DATE = '2026-06-12';
$TO   = 'Kissywears1@gmail.com,contact@cm-ea.com';
try {
    $tenant = App\Models\Tenant::where('slug', 'kwr')->first();
    if (!$tenant) { ln('kwr tenant not found'); exit; }
    app(App\Tenancy\TenantContext::class)->set($tenant);

    // 1. Mail transport (creds not printed).
    ln('=== mail transport ===');
    ln('  mailer = ' . json_encode(config('mail.default')));
    $host = (string) config('mail.mailers.smtp.host');
    ln('  smtp host set = ' . ($host !== '' && $host !== '127.0.0.1' && $host !== 'localhost' ? 'yes' : 'no (→ log/local only)'));
    ln('  from = ' . json_encode(config('mail.from.address')));
    ln('  → if mailer=log, the test renders to storage/logs/laravel.log (NOT delivered to inboxes).');
    ln();

    // 2. Recipient config resolves?
    ln('=== recipients ===');
    ln('  config kwr = ' . json_encode((array) config('daily_report.recipients.kwr', [])));
    ln('  test --to  = ' . $TO);
    ln();

    // 3. Enable the daily_report module for kwr (idempotent).
    $row = App\Models\TenantModule::firstOrNew(['tenant_id' => $tenant->id, 'module_key' => 'daily_report']);
    $row->enabled = true; $row->save();
    ln('=== module ===');
    ln('  daily_report enabled for kwr = ' . json_encode($tenant->fresh()->moduleEnabled('daily_report')));
    ln();

    // 4. Preview the figures the report will show for 12 Jun 2026.
    ln('=== report data · Friday 12 June 2026 ===');
    try {
        $svc = new App\Services\ReportsService(App\Odoo\OdooFactory::fromConfig());
        $s = $svc->dailySales($DATE); $i = $svc->dailyInventory($DATE); $x = $svc->dailyExpenses($DATE);
        ln('  SALES  invoices=' . (int) ($s['invoice_count'] ?? 0) . ' outfits=' . (int) ($s['outfits'] ?? 0)
            . ' total=' . number_format((float) ($s['total'] ?? 0))
            . ' cash=' . number_format((float) ($s['cash_total'] ?? 0))
            . ' credit=' . number_format((float) ($s['credit_total'] ?? 0)));
        foreach (array_slice((array) ($s['rows'] ?? []), 0, 8) as $r) {
            ln('     · ' . substr((string) ($r['customer'] ?? '—'), 0, 28) . '  ' . number_format((float) ($r['amount'] ?? 0)) . '  ' . ($r['type'] ?? ''));
        }
        ln('  STOCK  in=' . number_format((float) ($i['inward']['value'] ?? 0))
            . ' out=' . number_format((float) ($i['outward']['value'] ?? 0))
            . ' on_hand=' . number_format((float) ($i['on_hand_value'] ?? 0)));
        ln('  EXPEN  day=' . number_format((float) ($x['day_total'] ?? 0)) . ' MTD=' . number_format((float) ($x['mtd_total'] ?? 0)) . ' (' . ($x['month_label'] ?? '') . ')');
    } catch (\Throwable $e) { ln('  data ERR: ' . $e->getMessage()); }
    ln();

    // 5. Run the actual send command to the two recipients.
    ln('=== reports:send-daily --tenant=kwr --date=' . $DATE . ' --to=' . $TO . ' ===');
    $code = Illuminate\Support\Facades\Artisan::call('reports:send-daily', ['--tenant' => 'kwr', '--date' => $DATE, '--to' => $TO]);
    ln(rtrim(Illuminate\Support\Facades\Artisan::output()));
    ln('  exit=' . $code);
    ln();

    // 6. If log mailer, confirm the message rendered.
    if ((string) config('mail.default') === 'log') {
        $log = storage_path('logs/laravel.log');
        if (is_file($log)) {
            $tail = substr((string) file_get_contents($log), -12000);
            ln('=== log-mailer check ===');
            ln('  "Daily Report" subject in log : ' . (strpos($tail, 'Daily Report') !== false ? 'yes' : 'no'));
            ln('  recipients in log             : Kissywears1 ' . (stripos($tail, 'Kissywears1@gmail.com') !== false ? 'y' : 'n')
                . ' · contact ' . (stripos($tail, 'contact@cm-ea.com') !== false ? 'y' : 'n'));
            ln('  → mailer=log: rendered to the log, NOT delivered. Configure SMTP for real inbox delivery.');
        }
    } else {
        ln('=== non-log mailer: the command attempted real delivery to both addresses (see result above) ===');
    }
} catch (\Throwable $e) {
    ln('EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
