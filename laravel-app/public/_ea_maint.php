<?php
/** TEMP read-only probe — June 2026 scanned vehicles classified by goods_group
 *  (HS-code) into car/truck/tractor/bus/trailer. Token-guarded. No writes. */
header('Content-Type: text/plain');
$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit; }

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
function ln($s = '') { echo $s . "\n"; }

/** Classify a goods_group value into a billing bucket. */
function classify($gg): string {
    if ($gg === false || $gg === null || trim((string) $gg) === '') return 'blank';
    $s   = strtoupper(trim((string) $gg));
    $p4  = substr(preg_replace('/[^0-9]/', '', $s), 0, 4);
    if ($p4 === '8703') return 'car';
    if ($p4 === '8704') return 'truck';
    if ($p4 === '8701') return 'tractor';
    if ($p4 === '8702') return 'bus';
    if (in_array($p4, ['8716', '8705', '8709', '8706'], true)) return 'trailer';
    if (strpos($s, 'TRUCK') !== false || strpos($s, 'CAMION') !== false) return 'truck';
    if (strpos($s, 'TRAILER') !== false || strpos($s, 'TIPPER') !== false || strpos($s, 'SEMI') !== false || strpos($s, 'FLATED') !== false) return 'trailer';
    if (strpos($s, 'BUS') !== false || strpos($s, 'COASTER') !== false) return 'bus';
    if (strpos($s, 'VAN') !== false) return 'van';
    if ($s === 'CAR' || strpos($s, 'VOITURE') !== false) return 'car';
    return 'other';
}

try {
    $tenant = App\Models\Tenant::where('slug', 'transatlanticd')->first();
    app(App\Tenancy\TenantContext::class)->set($tenant);
    $client = App\Odoo\OdooFactory::fromConfig();

    $scan = [['scanned', '=', true], ['scanned_date', '>=', '2026-06-01 00:00:00'], ['scanned_date', '<=', '2026-06-30 23:59:59']];
    ln('=== JUNE 2026 — scanned vehicles by goods_group (HS code) ===');
    ln('PERIOD: 2026-06-01 .. 2026-06-30 (scanned=true). Read-only; indicative rates by analogy to the tier grid.');
    ln();

    $g = $client->callModel('aw_vehicule.vehicule', 'read_group', ['domain' => $scan, 'fields' => [], 'groupby' => ['goods_group'], 'lazy' => false]);
    $buckets = ['car' => 0, 'van' => 0, 'truck' => 0, 'tractor' => 0, 'bus' => 0, 'trailer' => 0, 'other' => 0, 'blank' => 0];
    $otherVals = [];
    foreach ((array) $g as $r) {
        $gg = $r['goods_group'] ?? false; $n = (int) ($r['__count'] ?? 0);
        $b = classify($gg);
        $buckets[$b] += $n;
        if ($b === 'other') $otherVals[(string) $gg] = $n;
    }
    $total = array_sum($buckets);

    $rate = ['car' => 50000, 'van' => 50000, 'truck' => 95000, 'tractor' => 95000, 'bus' => 95000, 'trailer' => 105000, 'other' => 50000, 'blank' => 50000];
    $label = [
        'car' => 'Car (HS 8703 / "CAR")', 'van' => 'Van (SVAN)', 'truck' => 'Truck (HS 8704 / "TRUCK")',
        'tractor' => 'Road tractor (HS 8701)', 'bus' => 'Bus / Coaster (HS 8702)', 'trailer' => 'Trailer / tipper / special',
        'other' => 'Other / model-name text (unmapped)', 'blank' => 'No goods_group',
    ];
    $note = ['other' => '  ← assumed car 50k', 'blank' => '  ← assumed car 50k'];

    ln(sprintf('  %-34s %6s  %9s  %16s', 'Bucket', 'Count', 'Rate', 'Indic. revenue'));
    ln('  ' . str_repeat('-', 72));
    $rev = 0;
    foreach ($buckets as $b => $n) {
        if ($n === 0) continue;
        $r = $rate[$b] * $n; $rev += $r;
        ln(sprintf('  %-34s %6d  %9s  %16s%s', $label[$b], $n, number_format($rate[$b]), number_format($r), $note[$b] ?? ''));
    }
    ln('  ' . str_repeat('-', 72));
    ln(sprintf('  %-34s %6d  %9s  %16s', 'TOTAL vehicles', $total, '', number_format($rev)));
    ln();
    ln('  For comparison — current CYLINDERS-based June vehicle revenue: ~112,170,000 XAF');
    ln('  (Voiture 52.10M + Camion 31.35M + Bulldoze 2.52M + Other-assumed 26.20M)');
    ln();

    arsort($otherVals);
    ln('  Top "Other / unmapped" goods_group values (likely cars by model name):');
    $i = 0;
    foreach ($otherVals as $val => $n) { if ($i++ >= 15) break; ln('    ' . $val . ' : ' . $n); }
    if (count($otherVals) > 15) ln('    … (' . (count($otherVals) - 15) . ' more)');
} catch (\Throwable $e) {
    ln('EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
