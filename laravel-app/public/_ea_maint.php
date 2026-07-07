<?php
/** TEMP diagnostic — daily-report email: enable module for Kissywears, run the
 *  send command for 2026-06-13 to the platform owner, report transport + cron
 *  liveness. Token-guarded. Re-stubbed after. */
header('Content-Type: text/plain; charset=utf-8');
$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit; }
$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
function ln($s = '') { echo $s . "\n"; }

try {
    // 1. Mail transport in use (creds not printed).
    ln('=== mail transport ===');
    ln('  mailer = ' . json_encode(config('mail.default')));
    ln('  host set = ' . (config('mail.mailers.smtp.host') && config('mail.mailers.smtp.host') !== '127.0.0.1' ? 'yes (' . parse_url('//' . config('mail.mailers.smtp.host'), PHP_URL_HOST) . ')' : 'no/localhost'));
    ln('  from = ' . json_encode(config('mail.from.address')));
    ln();

    // 2. Cron liveness — the monthly strategic run should have fired 1 Jul 06:00.
    ln('=== cron liveness (schedule:run) ===');
    try {
        $bi = App\Models\BusinessInsight::query()->orderByDesc('created_at')->first();
        ln('  latest BusinessInsight created_at = ' . ($bi?->created_at ?? 'none'));
    } catch (\Throwable $e) { ln('  check failed: ' . $e->getMessage()); }
    ln();

    // 3. Enable the daily_report module for Kissywears (idempotent).
    $tenant = App\Models\Tenant::where('name', 'like', '%KISSY%')->first();
    ln('=== enable daily_report for ' . $tenant->slug . ' ===');
    $row = App\Models\TenantModule::firstOrNew(['tenant_id' => $tenant->id, 'module_key' => 'daily_report']);
    $row->enabled = true;
    $row->save();
    ln('  daily_report enabled = ' . json_encode($tenant->fresh()->moduleEnabled('daily_report')));
    // Recipients that the scheduled run would use:
    $recips = App\Models\User::where('tenant_id', $tenant->id)
        ->whereIn('role', [App\Models\User::ROLE_TENANT_ADMIN, App\Models\User::ROLE_OWNER])
        ->where('status', App\Models\User::STATUS_ACTIVE)->pluck('email')->all();
    ln('  default recipients (admin/owner users): ' . (empty($recips) ? 'NONE — set one up or the daily send will skip' : implode(', ', $recips)));
    ln();

    // 4. Full pipeline test — send the 2026-06-13 report to the platform owner.
    ln('=== reports:send-daily --tenant=kwr --date=2026-06-13 --to=nekoutboma100@gmail.com ===');
    $code = Illuminate\Support\Facades\Artisan::call('reports:send-daily', [
        '--tenant' => $tenant->slug, '--date' => '2026-06-13', '--to' => 'nekoutboma100@gmail.com',
    ]);
    ln(rtrim(Illuminate\Support\Facades\Artisan::output()));
    ln('  exit=' . $code);
    ln();

    // 5. If the mailer is 'log', prove the message rendered.
    if ((string) config('mail.default') === 'log') {
        $log = storage_path('logs/laravel.log');
        if (is_file($log)) {
            $tail = substr((string) file_get_contents($log), -6000);
            ln('=== log-mailer check ===');
            ln('  subject present in log: ' . (strpos($tail, 'Daily Report') !== false ? 'yes' : 'no'));
            ln('  summary table present:  ' . (strpos($tail, 'Daily Business Report') !== false ? 'yes' : 'no'));
        }
    }
} catch (\Throwable $e) {
    ln('EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
