<?php
/** TEMP diagnostic — treasury liquidity trend + payables + renders. Token-guarded. Re-stubbed after. */
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
    $req = Illuminate\Http\Request::create('/t/transatlanticd/treasury', 'GET');
    $app->instance('request', $req);
    $session = $app['session']->driver(); $session->start(); $req->setLaravelSession($session);
    $u = App\Models\User::query()->first(); if ($u) Illuminate\Support\Facades\Auth::login($u);

    $client = App\Odoo\OdooFactory::fromConfig();

    echo "=== LIQUIDITY TREND RAW QUERIES ===\n";
    try {
        $accounts = $client->searchRead('account.account', ['|','|','|','|',['code','=like','3%'],['code','=like','4%'],['code','=like','5%'],['code','=like','6%'],['code','=like','7%']], ['id','code'], []);
        echo 'accounts(3-7): ' . count($accounts) . "\n";
        $ids = array_map(fn ($a) => (int) $a['id'], $accounts);
        $rows = $client->callModel('account.move.line', 'read_group', [
            'domain'  => [['parent_state','=','posted'],['date','<=',date('Y-m-d')],['account_id','in',$ids]],
            'fields'  => ['debit:sum','credit:sum'],
            'groupby' => ['account_id','date:month'],
            'lazy'    => false,
        ]);
        echo 'read_group rows: ' . count($rows) . "\n";
        if (!empty($rows)) {
            echo 'row0 keys: ' . json_encode(array_keys($rows[0])) . "\n";
            echo 'row0: ' . json_encode($rows[0]) . "\n";
            echo 'account_id is_array: ' . json_encode(is_array($rows[0]['account_id'] ?? null)) . "\n";
        }
    } catch (\Throwable $e) { echo 'RAW EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n"; }
    echo "liquidityTrend points: " . count((new App\Services\WorkingCapitalService($client))->liquidityTrend(12)['series'] ?? []) . "\n";

    echo "\n=== PAYABLES ===\n";
    try {
        $plc = (new App\Services\ProfitLossService($client))->analyze();
        $exp = (float) ($plc['current']['cogs'] ?? 0) + (float) ($plc['current']['opex'] ?? 0);
        $ap  = (new App\Services\PayablesService($client))->analyze($exp, 365, 'XAF');
        echo 'total_expenses_ytd(M): ' . round($exp / 1e6, 1) . "\n";
        echo 'kpis: ' . json_encode($ap['kpis'] ?? 'none') . "\n";
        echo 'top_creditors: ' . count($ap['top_creditors'] ?? []) . ' · open_bills: ' . ($ap['open_bill_count'] ?? '?') . "\n";
        echo 'aging: ' . json_encode(array_map(fn ($b) => round(($b['amount'] ?? 0) / 1e6, 1), $ap['aging'] ?? [])) . "\n";
    } catch (\Throwable $e) { echo 'EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n"; }

    echo "\n=== RENDERS ===\n";
    try { $rq = Illuminate\Http\Request::create('/t/transatlanticd/treasury', 'GET'); $rq->setLaravelSession($session); $app->instance('request', $rq); $r = app(App\Http\Controllers\TreasuryController::class)->index($rq); echo '/treasury: OK len=' . strlen($r->render()) . "\n"; }
    catch (\Throwable $e) { echo '/treasury: FAIL ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n"; }
    try { $r = app(App\Http\Controllers\PayablesController::class)->index(); echo '/payables: OK len=' . strlen($r->render()) . "\n"; }
    catch (\Throwable $e) { echo '/payables: FAIL ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n"; }
} catch (\Throwable $e) {
    echo 'TOP EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
}
