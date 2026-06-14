<?php
/** TEMP diagnostic — dump Profitability data shape (trend forecast + monthly scans). Token-guarded. Re-stubbed after. */
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

    $client = App\Odoo\OdooFactory::fromConfig();
    $assum  = app(App\Services\BudgetService::class)->loadAssumptions($tenant->id, $year);
    echo "year: $year\n";
    echo 'assumptions: ' . ($assum ? ('YES rev=' . json_encode($assum['revenue'] ?? [])) : 'NONE') . "\n";

    $d = (new App\Services\ProfitabilityService($client))->analyze($year, $assum);
    $mm = fn ($a) => json_encode(array_map(fn ($v) => round(((float) $v) / 1e6, 1), $a));
    echo 'thru_month: ' . $d['trend']['thru_month'] . "\n";
    echo 'revenue(M): ' . $mm($d['trend']['revenue']) . "\n";
    echo 'ebitda(M):  ' . $mm($d['trend']['ebitda']) . "\n";
    echo 'ebit(M):    ' . $mm($d['trend']['ebit']) . "\n";
    echo 'scans total: ' . $d['scans']['total'] . ' (cont ' . $d['scans']['containers'] . ', veh ' . $d['scans']['vehicles'] . ', wood ' . $d['scans']['wood'] . ")\n";

    echo "per-month scans (Jan..current):\n";
    $now = new DateTimeImmutable('today');
    $maxM = ($year < (int) $now->format('Y')) ? 12 : (int) $now->format('n');
    for ($m = 1; $m <= $maxM; $m++) {
        $from = sprintf('%04d-%02d-01 00:00:00', $year, $m);
        $to   = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m)))->modify('last day of this month')->format('Y-m-d 23:59:59');
        $w = [['scanned', '=', true], ['scanned_date', '>=', $from], ['scanned_date', '<=', $to]];
        $c = 0; $v = 0; $wood = 0;
        try { $c = $client->searchCount('aw_cargo.equipment', $w); } catch (\Throwable $e) {}
        try { $v = $client->searchCount('aw_vehicule.vehicule', $w); } catch (\Throwable $e) {}
        try { $g = $client->callModel('aw_bois.bois', 'read_group', ['domain' => $w, 'fields' => [], 'groupby' => ['cargo_id'], 'lazy' => false]); $wood = count(array_filter((array) $g, fn ($r) => !empty($r['cargo_id']) && $r['cargo_id'] !== false)); } catch (\Throwable $e) {}
        echo "  m$m: cont=$c veh=$v wood=$wood total=" . ($c + $v + $wood) . "\n";
    }
} catch (\Throwable $e) {
    echo "\nEXCEPTION: " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
}
