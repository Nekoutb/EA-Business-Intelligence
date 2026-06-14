<?php
/** TEMP diagnostic — verify income tax / empty-scan / render. Token-guarded. Re-stubbed after. */
header('Content-Type: text/plain');
$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit; }

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();

try {
    $year   = (int) ($_GET['year'] ?? 2026);
    $tenant = App\Models\Tenant::where('slug', 'transatlanticd')->first();
    app(App\Tenancy\TenantContext::class)->set($tenant);
    $req = Illuminate\Http\Request::create('/t/transatlanticd/pl-forecast', 'GET');
    $app->instance('request', $req);
    $session = $app['session']->driver(); $session->start(); $req->setLaravelSession($session);
    $u = App\Models\User::query()->first(); if ($u) Illuminate\Support\Facades\Auth::login($u);

    $client = App\Odoo\OdooFactory::fromConfig();
    $assum  = app(App\Services\BudgetService::class)->loadAssumptions($tenant->id, $year);
    echo 'tax assumption: ' . json_encode($assum['tax'] ?? 'none') . "\n";
    echo 'revenue method: ' . ($assum['revenue']['method'] ?? '?') . "\n";

    echo 'revenue assumption: ' . json_encode($assum['revenue'] ?? '?') . "\n";
    echo 'gross_margin: ' . ($assum['gross_margin_pct'] ?? '?') . ' band [' . ($assum['gross_margin_lower_pct'] ?? 0) . ',' . ($assum['gross_margin_upper_pct'] ?? 0) . "]\n";
    echo 'opex: ' . json_encode($assum['opex'] ?? '?') . ' · net_fin/mo: ' . ($assum['net_financial_monthly'] ?? '?') . "\n";
    $pl = (new App\Services\PLForecastService($client))->analyze($year, null, $assum);
    foreach (($pl['lines'] ?? []) as $line) {
        $k = $line['key'] ?? '';
        if (in_array($k, ['revenue', 'ebitda', 'pbt', 'income_tax', 'net_result'], true)) {
            echo str_pad($k, 12) . json_encode(array_map(fn ($v) => round(((float) $v) / 1e6, 2), array_values($line['monthly']))) . "\n";
        }
    }

    $prof = (new App\Services\ProfitabilityService($client))->analyze($year, $assum);
    $s = $prof['scans'];
    echo "scans: total={$s['total']} empty={$s['empty_containers']} ex_empty={$s['total_ex_empty']}\n";
    $mps = $prof['monthly_per_scan'];
    $r = fn ($a) => json_encode(array_map(fn ($v) => $v === null ? null : (int) round($v), $a));
    echo 'rev/scan (all):      ' . $r($mps['revenue']) . "\n";
    echo 'rev/scan (ex-empty): ' . $r($mps['revenue_ex_empty']) . "\n";

    foreach ([['/pl-forecast', []], ['/profit-loss', ['view' => 'profitability']]] as $case) {
        try {
            $rq = Illuminate\Http\Request::create('/t/transatlanticd' . $case[0], 'GET', $case[1]);
            $rq->setLaravelSession($session); $app->instance('request', $rq);
            $ctrl = $case[0] === '/pl-forecast' ? App\Http\Controllers\PLForecastController::class : App\Http\Controllers\ProfitLossController::class;
            $resp = app($ctrl)->index($rq);
            $html = (is_object($resp) && method_exists($resp, 'render')) ? $resp->render() : '';
            echo "RENDER {$case[0]}: OK len=" . strlen((string) $html) . "\n";
        } catch (\Throwable $e) {
            echo "RENDER {$case[0]}: FAIL " . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        }
    }
} catch (\Throwable $e) {
    echo 'EXCEPTION: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
}
