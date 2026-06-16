<?php
/** TEMP read-only probe — June 2026 scan figures (cylinder split, current vs
 *  projected, OOG proxy). Token-guarded. No writes. */
header('Content-Type: text/plain');
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

    $fromDt = new DateTimeImmutable('2026-06-01');
    $toDt   = new DateTimeImmutable('2026-06-30');
    $from   = $fromDt->format('Y-m-d 00:00:00');
    $to     = $toDt->format('Y-m-d 23:59:59');
    $scan   = [['scanned', '=', true], ['scanned_date', '>=', $from], ['scanned_date', '<=', $to]];
    ln('PERIOD: June 2026 (2026-06-01 .. 2026-06-30, scanned=true) — month-to-date, data through ~16 Jun');
    ln();

    // 1. Cylinder distribution (June)
    ln('=== 1. CYLINDER DISTRIBUTION — aw_vehicule.vehicule (scanned, June 2026) ===');
    $hist = [];
    try {
        $g = $client->callModel('aw_vehicule.vehicule', 'read_group', ['domain' => $scan, 'fields' => [], 'groupby' => ['cylinders'], 'lazy' => false]);
        foreach ((array) $g as $row) {
            $cyl = $row['cylinders'] ?? false;
            $key = ($cyl === false || $cyl === null) ? 'none' : (string) (int) $cyl;
            $hist[$key] = ($hist[$key] ?? 0) + (int) ($row['__count'] ?? 0);
        }
    } catch (\Throwable $e) { ln('  read_group ERR: ' . $e->getMessage()); }
    uksort($hist, fn ($a, $b) => (is_numeric($a) ? (int) $a : 99999) <=> (is_numeric($b) ? (int) $b : 99999));
    $totalVeh = array_sum($hist);
    foreach ($hist as $cyl => $n) ln(sprintf('  cyl %-5s : %5d   (%.1f%%)', $cyl, $n, $totalVeh ? 100 * $n / $totalVeh : 0));
    ln("  TOTAL scanned vehicles (June): {$totalVeh}");
    ln();

    // 2. Current breakdown (June)
    ln('=== 2. CURRENT scanRevenueBreakdown (June 2026) ===');
    $curTotal = 0;
    try {
        $bd = (new App\Services\OperationsService($client))->scanRevenueBreakdown($fromDt, $toDt);
        foreach (($bd['categories'] ?? []) as $key => $c) {
            if (preg_match('/voiture|camion|bulldoze|unclassified|wood/', $key)) {
                ln(sprintf('  %-22s cnt=%5d  price=%-7s rev=%s', $key, (int) ($c['count'] ?? 0), (string) ($c['price'] ?? '-'), number_format((float) ($c['revenue'] ?? 0))));
            }
        }
        $curTotal = (float) ($bd['total_revenue'] ?? 0);
        ln('  TOTAL current scan revenue (all categories): ' . number_format($curTotal) . ' XAF');
    } catch (\Throwable $e) { ln('  breakdown ERR: ' . $e->getMessage()); }
    ln();

    // 3. OOG proxy — Open-Top + Flat/Platform containers (June)
    ln('=== 3. OOG PROXY — Open-Top/Flat-Rack containers (June 2026) ===');
    $oogCount = 0;
    try {
        $g = $client->callModel('aw_cargo.equipment', 'read_group', ['domain' => $scan, 'fields' => [], 'groupby' => ['type_id'], 'lazy' => false]);
        foreach ((array) $g as $r) {
            $t = $r['type_id'] ?? false; $code = is_array($t) ? (string) ($t[1] ?? '') : '';
            if ($code !== '' && preg_match('/OT|FL|PL|P3/', $code)) {
                $n = (int) ($r['__count'] ?? 0); $oogCount += $n;
                ln(sprintf('  %-6s : %4d', $code, $n));
            }
        }
        ln("  OOG-proxy total (June): {$oogCount} containers");
    } catch (\Throwable $e) { ln('  type_id read_group ERR: ' . $e->getMessage()); }
    ln();

    // 4. Projected vehicle revenue under your rates (June)
    ln('=== 4. PROJECTED under your rates (June 2026) ===');
    $newRates = [5 => 50000, 7 => 50000, 9 => 95000, 10 => 105000, 11 => 105000];
    $existing = [4 => 50000, 6 => 50000, 8 => 95000, 12 => 105000];
    $revNew = 0; $revExisting = 0; $blank = 0; $blankList = [];
    foreach ($hist as $cyl => $n) {
        if (!is_numeric($cyl)) { $blank += $n; $blankList[] = "none×{$n}"; continue; }
        $c = (int) $cyl;
        if (isset($newRates[$c]))     { $revNew += $newRates[$c] * $n; ln(sprintf('  NEW  %2d cyl @ %6d : %4d veh = %s', $c, $newRates[$c], $n, number_format($newRates[$c] * $n))); }
        elseif (isset($existing[$c])) { $revExisting += $existing[$c] * $n; ln(sprintf('  keep %2d cyl @ %6d : %4d veh = %s', $c, $existing[$c], $n, number_format($existing[$c] * $n))); }
        else                          { $blank += $n; $blankList[] = "{$c}cyl×{$n}"; }
    }
    $oogRev = $oogCount * 105000;
    ln('  ----------------------------------------------------------');
    ln('  Already-classified (4/6/8/12):          ' . number_format($revExisting) . ' XAF');
    ln('  NEW with data (9 cyl):                  ' . number_format($revNew) . ' XAF');
    ln('  OOG proxy (' . $oogCount . ' @105k):                  ' . number_format($oogRev) . ' XAF');
    ln('  Blank/odd-cyl vehicles (no rate):       ' . $blank . ' veh  [' . implode(' ', $blankList) . ']');
    ln('    → at assumed 50k they add:             ' . number_format($blank * 50000) . ' XAF');
    ln('  ----------------------------------------------------------');
    ln('  PROJECTED vehicles, strict (blank unbilled):     ' . number_format($revExisting + $revNew) . ' XAF');
    ln('  PROJECTED vehicles, keep blank @50k:             ' . number_format($revExisting + $revNew + $blank * 50000) . ' XAF');
    ln('  + OOG proxy @105k:                               ' . number_format($oogRev) . ' XAF');
} catch (\Throwable $e) {
    ln('EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
