<?php
/** TEMP diagnostic — net-margin swap + running cumulative DSO/DPO. Token-guarded. */
header('Content-Type: text/plain');
$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit; }

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();

try {
    $tenant = App\Models\Tenant::where('slug', 'transatlanticd')->first();
    app(App\Tenancy\TenantContext::class)->set($tenant);
    $req = Illuminate\Http\Request::create('/t/transatlanticd/', 'GET');
    $app->instance('request', $req);
    $session = $app['session']->driver(); $session->start(); $req->setLaravelSession($session);
    $u = App\Models\User::query()->first(); if ($u) Illuminate\Support\Facades\Auth::login($u);

    $client = App\Odoo\OdooFactory::fromConfig();
    $period = ['type' => 'ytd', 'year' => 2026, 'month' => 6];

    // Headlines (mirror DashboardController::index).
    $data = (new App\Services\RevenueService($client, (string) config_env('REVENUE_SOURCE', 'invoice')))->analyze(null, $period);
    $pl   = (new App\Services\ProfitLossService($client))->analyze(null, $period);
    $ccy  = (string) ($data['currency'] ?? 'XAF');
    $totalRev = (float) ($data['current']['total'] ?? 0);
    $totalExp = max(1.0, (float) ($pl['current']['cogs'] ?? 0) + (float) ($pl['current']['opex'] ?? 0) + (float) ($pl['current']['d_and_a'] ?? 0));
    $rec = (new App\Services\ReceivablesService($client))->analyze($totalRev, (int) ($data['period']['days'] ?? 180), $ccy);
    $totalAr = (float) ($rec['kpis']['total_ar'] ?? $rec['total_ar'] ?? 0);
    $ap = (new App\Services\PayablesService($client))->analyze($totalExp, 365, $ccy);
    $totalAp = (float) ($ap['kpis']['total_ap'] ?? 0);
    echo "headlines: rev=" . round($totalRev) . " exp=" . round($totalExp) . " AR=" . round($totalAr) . " AP=" . round($totalAp) . "\n";
    echo "headline DPO=" . round($totalAp / $totalExp * 365, 1) . "d  DSO=" . round($totalAr / $totalRev * 365, 1) . "d\n";

    $dpoSer = (new App\Services\PayablesService($client))->dpoRunningSeries($totalAp, $totalExp, 365, 2026);
    $dsoSer = (new App\Services\ReceivablesService($client))->dsoRunningSeries($totalAr, $totalRev, 365, 2026);
    echo "DPO running: " . json_encode(array_map(fn ($p) => $p['dpo'], $dpoSer)) . "\n";
    echo "DSO running: " . json_encode(array_map(fn ($p) => $p['dso'], $dsoSer)) . "\n";

    // buildExtraKpis — confirm net_margin replaced ebit_margin.
    $ctrl = app(App\Http\Controllers\DashboardController::class);
    $mB = new ReflectionMethod($ctrl, 'buildExtraKpis'); $mB->setAccessible(true);
    $k = $mB->invoke($ctrl, $client, 2026);
    echo "KPI labels: " . implode(' | ', array_map(fn ($v) => $v['label'], $k)) . "\n";
    $nm = $k['net_margin'] ?? null;
    echo "net_margin: val=" . json_encode($nm['value'] ?? null) . " prev=" . json_encode($nm['prev'] ?? null) . "\n";

    // Render — confirm label swap + DSO/DPO series are the cumulative ones.
    $t = microtime(true);
    $html = app(App\Http\Controllers\DashboardController::class)->index($req)->render();
    $extract = function ($key) use ($html) {
        if (preg_match('/"' . $key . '":\{[^}]*?"series":\[([^\]]*)\]/', $html, $m)) {
            $vals = array_filter(array_map('trim', explode(',', $m[1])), fn ($x) => $x !== '' && $x !== 'null');
            return empty($vals) ? '—' : end($vals);
        }
        return '?';
    };
    echo "render " . round(microtime(true) - $t, 1) . "s · NetMargin=" . (strpos($html, 'Net profit margin') !== false ? 'y' : 'n')
       . " · EBITgone=" . (strpos($html, 'EBIT margin') === false ? 'y' : 'n')
       . " · chart dpo last=" . $extract('dpo') . " · chart dso last=" . $extract('dso') . "\n";
} catch (\Throwable $e) {
    echo 'EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
}
