<?php
/** TEMP diagnostic — weekly dashboard basis + procurement KPI-evolution graphs.
 *  Token-guarded. Re-stubbed after. */
header('Content-Type: text/plain; charset=utf-8');
$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit; }
$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
function ln($s = '') { echo $s . "\n"; }

try {
    $tenant = App\Models\Tenant::where('slug', 'transatlanticd')->first();
    app(App\Tenancy\TenantContext::class)->set($tenant);
    $client = App\Odoo\OdooFactory::fromConfig();
    if (function_exists('ea_cache_clear')) ea_cache_clear();

    // Render-only mode: one page per call (?p=dash | ?p=proc) — swapping the
    // bound request twice in one run breaks the auth guard's request rebind.
    $mode = (string) ($_GET['p'] ?? '');
    if ($mode !== '') {
        $q = $mode === 'dash' ? ['view' => 'dashboard'] : [];
        $req = Illuminate\Http\Request::create('/t/' . $tenant->slug . '/procurement', 'GET', $q);
        $app->instance('request', $req);
        $session = $app['session']->driver(); $session->start(); $req->setLaravelSession($session);
        $u = App\Models\User::where('tenant_id', $tenant->id)->whereIn('role', ['tenant_admin', 'owner'])->first() ?? App\Models\User::first();
        if ($u) Illuminate\Support\Facades\Auth::login($u);
        $html = app(App\Http\Controllers\ProcurementController::class)->index($req)->render();
        if ($mode === 'dash') {
            ln('dashboard render: len=' . strlen($html)
                . ' · weekly-basis=' . (strpos($html, 'end of last completed week') !== false ? 'y' : 'n')
                . ' · Weekly-cadence=' . substr_count($html, '>Weekly<')
                . ' · Monthly-left=' . substr_count($html, '>Monthly<'));
        } else {
            ln('procurement render: len=' . strlen($html)
                . ' · evolution-section=' . (strpos($html, 'KPI evolution') !== false ? 'y' : 'n')
                . ' · canvases=' . substr_count($html, '<canvas id="pt-')
                . ' · chart-json=' . (strpos($html, 'po_open_60') !== false ? 'y' : 'n'));
        }
        exit;
    }

    // 1. Weekly as-at + KPI values.
    $asAt = App\Services\ProcurementDashboardService::asAtDate();
    ln('as-at (weekly) = ' . $asAt->format('Y-m-d (D)') . ' · next refresh = ' . $asAt->modify('+7 days')->format('Y-m-d'));
    $svc = new App\Services\ProcurementDashboardService($client);
    $dash = $svc->analyze($asAt);
    ln('cadences: ' . implode(', ', array_map(fn ($k) => $k['cadence'], $dash['kpis'])));
    foreach ($dash['kpis'] as $key => $k) {
        ln(sprintf('  %-14s = %s%s', $key, $k['actual'] === null ? '—' : round((float) $k['actual'], 1), $k['unit']));
    }
    ln();

    // 2. Monthly trends.
    $t0 = microtime(true);
    $tr = $svc->monthlyTrends();
    ln('monthlyTrends: ' . round(microtime(true) - $t0, 1) . 's · labels=' . implode(',', $tr['labels']));
    foreach ($tr['series'] as $key => $s) {
        ln('  ' . str_pad($key, 14) . ' = [' . implode(', ', array_map(fn ($v) => $v === null ? '—' : $v, $s)) . ']');
    }
    ln();

    // 3. Render both pages.
    $mkReq = function (array $q) use ($app, $tenant) {
        $req = Illuminate\Http\Request::create('/t/' . $tenant->slug . '/procurement', 'GET', $q);
        $app->instance('request', $req);
        $session = $app['session']->driver(); $session->start(); $req->setLaravelSession($session);
        return $req;
    };
    $u = App\Models\User::where('tenant_id', $tenant->id)->whereIn('role', ['tenant_admin', 'owner'])->first() ?? App\Models\User::first();
    if ($u) Illuminate\Support\Facades\Auth::login($u);

    $html = app(App\Http\Controllers\ProcurementController::class)->index($mkReq(['view' => 'dashboard']))->render();
    ln('dashboard render: len=' . strlen($html)
        . ' · weekly-basis=' . (strpos($html, 'end of last completed week') !== false ? 'y' : 'n')
        . ' · Weekly-cadence=' . substr_count($html, '>Weekly<')
        . ' · Monthly-left=' . substr_count($html, '>Monthly<'));

    $html2 = app(App\Http\Controllers\ProcurementController::class)->index($mkReq([]))->render();
    ln('procurement render: len=' . strlen($html2)
        . ' · evolution-section=' . (strpos($html2, 'KPI evolution') !== false ? 'y' : 'n')
        . ' · canvases=' . substr_count($html2, '<canvas id="pt-')
        . ' · chart-json=' . (strpos($html2, '"po_open_60"') !== false ? 'y' : 'n'));
} catch (\Throwable $e) {
    ln('EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
