<?php
/** TEMP read-only probe — vehicle cylinder distribution + OOG fields + projected
 *  scan revenue under the new rate grid. Token-guarded. No writes. */
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

    $fromDt = new DateTimeImmutable('2026-01-01');
    $toDt   = new DateTimeImmutable('today');
    $from   = $fromDt->format('Y-m-d 00:00:00');
    $to     = $toDt->format('Y-m-d 23:59:59');
    $scan   = [['scanned', '=', true], ['scanned_date', '>=', $from], ['scanned_date', '<=', $to]];
    ln("PERIOD: 2026-01-01 .. {$toDt->format('Y-m-d')}  (scanned = true)");
    ln();

    // ── 1. Cylinder distribution on scanned vehicles ──────────────────────
    ln('=== 1. CYLINDER DISTRIBUTION — aw_vehicule.vehicule (scanned, YTD 2026) ===');
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
    foreach ($hist as $cyl => $n) ln(sprintf('  cyl %-5s : %6d   (%.1f%%)', $cyl, $n, $totalVeh ? 100 * $n / $totalVeh : 0));
    ln("  TOTAL scanned vehicles: {$totalVeh}");
    ln();

    // ── 2. Field discovery — look for an OOG / gauge / type marker ─────────
    ln('=== 2. FIELD DISCOVERY (oog | gauge | gabarit | hors | type | genre | categ | size | cylind) ===');
    foreach (['aw_vehicule.vehicule', 'aw_cargo.equipment', 'aw_cargo.cargo'] as $model) {
        try {
            $fields = $client->callModel($model, 'fields_get', ['attributes' => ['string', 'type']]);
            $hits = [];
            foreach ((array) $fields as $fname => $meta) {
                $label = (string) ($meta['string'] ?? '');
                if (preg_match('/oog|gauge|gabarit|hors|\btype\b|genre|categ|size|cylind/i', $fname . ' ' . $label)) {
                    $hits[] = "{$fname}(" . ($meta['type'] ?? '?') . ",\"{$label}\")";
                }
            }
            ln("  {$model}:");
            ln('    ' . (empty($hits) ? '(no candidate fields)' : implode('  ', $hits)));
        } catch (\Throwable $e) { ln("  {$model} fields_get ERR: " . $e->getMessage()); }
    }
    ln();

    // ── 3. Equipment SIZE codes (does 'OOG' show up as a container size?) ──
    ln('=== 3. SIZE CODES — aw_cargo.equipment (scanned, YTD 2026) ===');
    try {
        $g = $client->callModel('aw_cargo.equipment', 'read_group', ['domain' => $scan, 'fields' => [], 'groupby' => ['size'], 'lazy' => false]);
        foreach ((array) $g as $row) {
            $sz = $row['size'] ?? false;
            ln('  size ' . json_encode($sz) . ' : ' . (int) ($row['__count'] ?? 0));
        }
    } catch (\Throwable $e) { ln('  size read_group ERR: ' . $e->getMessage()); }
    ln();

    // ── 4. Current scan revenue breakdown (the canonical figures today) ────
    ln('=== 4. CURRENT scanRevenueBreakdown (YTD 2026) ===');
    try {
        $bd = (new App\Services\OperationsService($client))->scanRevenueBreakdown($fromDt, $toDt);
        foreach (($bd['categories'] ?? []) as $key => $c) {
            if (preg_match('/voiture|camion|bulldoze|unclassified|wood/', $key)) {
                ln(sprintf('  %-22s cnt=%6d  price=%-7s rev=%s', $key, (int) ($c['count'] ?? 0), (string) ($c['price'] ?? '-'), number_format((float) ($c['revenue'] ?? 0))));
            }
        }
        ln('  TOTAL current scan revenue (all categories): ' . number_format((float) ($bd['total_revenue'] ?? 0)) . ' XAF');
    } catch (\Throwable $e) { ln('  breakdown ERR: ' . $e->getMessage()); }
    ln();

    // ── 5. Projected vehicle revenue under YOUR rate grid ─────────────────
    ln('=== 5. PROJECTED vehicle scan revenue under your rates ===');
    $newRates = [5 => 50000, 7 => 50000, 9 => 95000, 10 => 105000, 11 => 105000];  // your NEW buckets
    $existing = [4 => 50000, 6 => 50000, 8 => 95000, 12 => 105000];                // already classified
    $revNew = 0; $revExisting = 0; $unbillable = 0; $unbillableCyls = [];
    foreach ($hist as $cyl => $n) {
        if (!is_numeric($cyl)) { $unbillable += $n; $unbillableCyls[] = "none×{$n}"; continue; }
        $c = (int) $cyl;
        if (isset($newRates[$c]))      { $r = $newRates[$c] * $n; $revNew += $r; ln(sprintf('  NEW   %2d cyl @ %6d : %5d veh = %s', $c, $newRates[$c], $n, number_format($r))); }
        elseif (isset($existing[$c]))  { $revExisting += $existing[$c] * $n; ln(sprintf('  keep  %2d cyl @ %6d : %5d veh = %s', $c, $existing[$c], $n, number_format($existing[$c] * $n))); }
        else                           { $unbillable += $n; $unbillableCyls[] = "{$c}cyl×{$n}"; }
    }
    ln('  ----------------------------------------------------------');
    ln('  Already-classified (4/6/8/12):      ' . number_format($revExisting) . ' XAF');
    ln('  NEW buckets (5/7/9/10/11):          ' . number_format($revNew) . ' XAF');
    ln('  Vehicle total (existing + new):     ' . number_format($revExisting + $revNew) . ' XAF');
    ln("  Still unbillable (cyl 0/none/other): {$unbillable} veh  [" . implode(' ', $unbillableCyls) . ']');
    ln('  (OOG not included above — pending the field discovery in section 2.)');
} catch (\Throwable $e) {
    ln('EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
