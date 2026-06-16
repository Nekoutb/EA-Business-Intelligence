<?php
/** TEMP read-only probe — blank-cylinder (cyl=0) scanned vehicles: type fields,
 *  fill rates, and a sample. Token-guarded. No writes. */
header('Content-Type: text/plain');
$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit; }

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
function ln($s = '') { echo $s . "\n"; }
function v($x) { if ($x === false || $x === null || $x === '') return '—'; if (is_array($x)) return (string) ($x[1] ?? $x[0] ?? '—'); return (string) $x; }

try {
    $tenant = App\Models\Tenant::where('slug', 'transatlanticd')->first();
    app(App\Tenancy\TenantContext::class)->set($tenant);
    $client = App\Odoo\OdooFactory::fromConfig();

    $to   = (new DateTimeImmutable('today'))->format('Y-m-d 23:59:59');
    $base0 = [['scanned', '=', true], ['scanned_date', '>=', '2026-01-01 00:00:00'], ['scanned_date', '<=', $to], ['cylinders', '=', 0]];

    $total = (int) $client->searchCount('aw_vehicule.vehicule', $base0);
    ln("BLANK-CYLINDER (cylinders=0) scanned vehicles, YTD 2026: {$total}");
    ln();

    // ── A. "Type" fields — goods_group / manufacturer / state distributions ─
    foreach (['goods_group' => 'A. goods_group (the closest thing to a "type")', 'manufacturer' => 'B. manufacturer', 'state' => 'C. state'] as $field => $title) {
        ln("=== {$title} ===");
        try {
            $g = $client->callModel('aw_vehicule.vehicule', 'read_group', ['domain' => $base0, 'fields' => [], 'groupby' => [$field], 'lazy' => false]);
            // sort desc by count, show top 15
            $rows = [];
            foreach ((array) $g as $r) $rows[] = [v($r[$field] ?? false), (int) ($r['__count'] ?? 0)];
            usort($rows, fn ($a, $b) => $b[1] <=> $a[1]);
            foreach (array_slice($rows, 0, 15) as $r) ln(sprintf('  %-28s : %5d  (%.1f%%)', $r[0], $r[1], $total ? 100 * $r[1] / $total : 0));
            if (count($rows) > 15) ln('  … (' . (count($rows) - 15) . ' more values)');
        } catch (\Throwable $e) { ln('  ERR: ' . $e->getMessage()); }
        ln();
    }

    // ── D. Fill rate of identifying fields among the blank-cyl set ─────────
    ln('=== D. IDENTIFYING-FIELD FILL RATE (of ' . $total . ' blank-cyl vehicles) ===');
    foreach (['name', 'vin', 'manufacturer', 'manufacture_year', 'model_code', 'manufacturer_code', 'goods_group', 'vessel_name', 'journey_ref', 'stack_description', 'cargo_id'] as $f) {
        try {
            $n = (int) $client->searchCount('aw_vehicule.vehicule', array_merge($base0, [[$f, '!=', false]]));
            ln(sprintf('  %-20s : %5d populated  (%.0f%%)', $f, $n, $total ? 100 * $n / $total : 0));
        } catch (\Throwable $e) { ln(sprintf('  %-20s : ERR %s', $f, $e->getMessage())); }
    }
    ln();

    // ── E. Sample of 20 blank-cyl records ─────────────────────────────────
    ln('=== E. SAMPLE — 20 most-recent blank-cyl vehicles ===');
    try {
        $recs = $client->searchRead('aw_vehicule.vehicule', $base0,
            ['id', 'name', 'manufacturer', 'manufacturer_code', 'model_code', 'goods_group', 'vin', 'vessel_name', 'journey_ref', 'cargo_id', 'state'],
            ['limit' => 20, 'order' => 'scanned_date desc']);
        foreach ($recs as $r) {
            ln('  #' . v($r['id'] ?? '?')
               . ' | name=' . v($r['name'] ?? false)
               . ' | mfr=' . v($r['manufacturer'] ?? false)
               . ' | mfrcode=' . v($r['manufacturer_code'] ?? false)
               . ' | model=' . v($r['model_code'] ?? false)
               . ' | goods=' . v($r['goods_group'] ?? false)
               . ' | vin=' . v($r['vin'] ?? false)
               . ' | vessel=' . v($r['vessel_name'] ?? false)
               . ' | jref=' . v($r['journey_ref'] ?? false)
               . ' | cargo=' . v($r['cargo_id'] ?? false)
               . ' | state=' . v($r['state'] ?? false));
        }
    } catch (\Throwable $e) { ln('  sample ERR: ' . $e->getMessage()); }
} catch (\Throwable $e) {
    ln('EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
