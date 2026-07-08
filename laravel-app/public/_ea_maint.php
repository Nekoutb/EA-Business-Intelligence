<?php
/** TEMP diagnostic — Procurement Control Dashboard: enable module (TSD+kwr),
 *  service values, render check (admin), targets save round-trip. Token-guarded. */
header('Content-Type: text/plain; charset=utf-8');
$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit; }
$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
function ln($s = '') { echo $s . "\n"; }

try {
    // 1. Enable the nav module for both tenants (idempotent).
    foreach (['transatlanticd', 'kwr'] as $slug) {
        $t = App\Models\Tenant::where('slug', $slug)->first();
        if (!$t) { ln("enable: {$slug} not found"); continue; }
        $row = App\Models\TenantModule::firstOrNew(['tenant_id' => $t->id, 'module_key' => 'procurement_dashboard']);
        $row->enabled = true; $row->save();
        ln("procurement_dashboard enabled for {$slug} = " . json_encode($t->fresh()->moduleEnabled('procurement_dashboard')));
    }
    ln();

    // 2. Service values (TSD, as at last closed month-end).
    $tenant = App\Models\Tenant::where('slug', 'transatlanticd')->first();
    app(App\Tenancy\TenantContext::class)->set($tenant);
    $client = App\Odoo\OdooFactory::fromConfig();
    $asAt = App\Services\ProcurementDashboardService::asAtDate();
    ln('as-at = ' . $asAt->format('Y-m-d'));
    $t0 = microtime(true);
    $dash = (new App\Services\ProcurementDashboardService($client))->analyze($asAt);
    ln('analyze: ' . round(microtime(true) - $t0, 1) . 's · meta=' . json_encode($dash['meta']));
    foreach ($dash['kpis'] as $key => $k) {
        ln(sprintf('  %-14s = %-8s %-2s (%s)', $key, $k['actual'] === null ? '—' : round((float) $k['actual'], 1), $k['unit'], $k['sub']));
    }
    ln('  offenders: ' . count($dash['offenders']) . ' · top: ' . json_encode(array_slice($dash['offenders'], 0, 2)));
    ln();

    // 3. Targets save round-trip (change dpo target, read back, restore).
    $bs = new App\Services\BudgetService();
    $year = (int) $asAt->format('Y');
    $before = $bs->loadProcurementDash($tenant->id, $year);
    ln('targets before: dpo=' . json_encode($before['targets']['dpo']) . ' emergency=' . $before['emergency']);
    $bs->saveProcurementDash($tenant, $year, ['dpo' => ['op' => 'lte', 'value' => 45]], 3, null);
    $mid = $bs->loadProcurementDash($tenant->id, $year);
    ln('after save:     dpo=' . json_encode($mid['targets']['dpo']) . ' emergency=' . $mid['emergency']);
    $bs->saveProcurementDash($tenant, $year, ['dpo' => ['op' => 'lte', 'value' => 30]], $before['emergency'], null);
    $post = $bs->loadProcurementDash($tenant->id, $year);
    ln('restored:       dpo=' . json_encode($post['targets']['dpo']) . ' emergency=' . $post['emergency']);
    ln();

    // 4. Render /procurement?view=dashboard as an ADMIN user.
    $req = Illuminate\Http\Request::create('/t/transatlanticd/procurement', 'GET', ['view' => 'dashboard']);
    $app->instance('request', $req);
    $session = $app['session']->driver(); $session->start(); $req->setLaravelSession($session);
    $u = App\Models\User::where('tenant_id', $tenant->id)->whereIn('role', ['tenant_admin', 'owner'])->first()
      ?? App\Models\User::whereIn('role', ['super_admin'])->first()
      ?? App\Models\User::first();
    if ($u) Illuminate\Support\Facades\Auth::login($u);
    ln('render user: ' . ($u?->email ?? 'none') . ' role=' . ($u?->role ?? '—'));
    $t1 = microtime(true);
    $html = app(App\Http\Controllers\ProcurementController::class)->index($req)->render();
    ln('render ' . round(microtime(true) - $t1, 1) . 's · len=' . strlen($html)
        . ' · tiles=' . substr_count($html, 'pd-tile"')
        . ' · matrix=' . (strpos($html, 'Governance matrix') !== false ? 'y' : 'n')
        . ' · adminBtn=' . (strpos($html, 'Set targets (admin)') !== false ? 'y' : 'n')
        . ' · print=' . (strpos($html, 'Print / Export PDF') !== false ? 'y' : 'n')
        . ' · old-page-leak=' . (strpos($html, 'Reading the rate') !== false ? 'LEAK' : 'clean'));
    // Non-admin render: panel must disappear.
    $v = App\Models\User::where('tenant_id', $tenant->id)->whereNotIn('role', ['tenant_admin', 'owner', 'super_admin'])->first();
    if ($v) {
        Illuminate\Support\Facades\Auth::login($v);
        $html2 = app(App\Http\Controllers\ProcurementController::class)->index($req)->render();
        ln('non-admin (' . $v->role . '): adminBtn=' . (strpos($html2, 'Set targets (admin)') !== false ? 'VISIBLE (bad)' : 'hidden (good)'));
    } else {
        ln('non-admin user: none on tenant — skipped');
    }
    // POST /sync reachability (the save route).
    ln('POST /sync cached-route check: (curl externally shows 419 = live)');
} catch (\Throwable $e) {
    ln('EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
