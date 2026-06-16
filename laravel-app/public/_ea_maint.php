<?php
/** TEMP read-only probe #2 — OOG hunt (equipment_type/type_id), full vehicle
 *  field list, wider-window cylinder histogram. Token-guarded. No writes. */
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

    $to    = (new DateTimeImmutable('today'))->format('Y-m-d 23:59:59');
    $scan  = fn ($from) => [['scanned', '=', true], ['scanned_date', '>=', $from . ' 00:00:00'], ['scanned_date', '<=', $to]];

    // ── A. equipment_type selection options + usage (OOG candidate) ────────
    ln('=== A. aw_cargo.equipment.equipment_type — options + scanned-YTD usage ===');
    try {
        $fg = $client->callModel('aw_cargo.equipment', 'fields_get', ['allfields' => ['equipment_type', 'type_id'], 'attributes' => ['string', 'type', 'selection']]);
        ln('  equipment_type options: ' . json_encode($fg['equipment_type']['selection'] ?? null));
    } catch (\Throwable $e) { ln('  fields_get ERR: ' . $e->getMessage()); }
    try {
        $g = $client->callModel('aw_cargo.equipment', 'read_group', ['domain' => $scan('2026-01-01'), 'fields' => [], 'groupby' => ['equipment_type'], 'lazy' => false]);
        foreach ((array) $g as $r) ln('  equipment_type ' . json_encode($r['equipment_type'] ?? false) . ' : ' . (int) ($r['__count'] ?? 0));
    } catch (\Throwable $e) { ln('  equipment_type read_group ERR: ' . $e->getMessage()); }
    ln();

    // ── B. type_id (many2one "Type") usage ────────────────────────────────
    ln('=== B. aw_cargo.equipment.type_id — scanned-YTD usage (name : count) ===');
    try {
        $g = $client->callModel('aw_cargo.equipment', 'read_group', ['domain' => $scan('2026-01-01'), 'fields' => [], 'groupby' => ['type_id'], 'lazy' => false]);
        foreach ((array) $g as $r) {
            $t = $r['type_id'] ?? false;
            $name = is_array($t) ? ($t[1] ?? '?') : json_encode($t);
            ln('  ' . $name . ' : ' . (int) ($r['__count'] ?? 0));
        }
    } catch (\Throwable $e) { ln('  type_id read_group ERR: ' . $e->getMessage()); }
    ln();

    // ── C. Full field list on aw_vehicule.vehicule (what else is available) ─
    ln('=== C. aw_vehicule.vehicule — ALL stored fields (name:type) ===');
    try {
        $fields = $client->callModel('aw_vehicule.vehicule', 'fields_get', ['attributes' => ['string', 'type', 'store']]);
        $names = [];
        foreach ((array) $fields as $fname => $meta) {
            if (in_array($fname, ['activity_ids', 'message_ids', 'message_follower_ids', 'activity_type_id', 'activity_type_icon'], true)) continue;
            if (str_starts_with($fname, 'message_') || str_starts_with($fname, 'activity_') || str_starts_with($fname, '__last')) continue;
            $names[] = $fname . ':' . ($meta['type'] ?? '?');
        }
        ln('  ' . implode('  ', $names));
    } catch (\Throwable $e) { ln('  fields_get ERR: ' . $e->getMessage()); }
    ln();

    // ── D. Cylinder histogram over a WIDER window (2024-01-01 .. today) ────
    ln('=== D. CYLINDER DISTRIBUTION — wider window 2024-01-01 .. today ===');
    try {
        $g = $client->callModel('aw_vehicule.vehicule', 'read_group', ['domain' => $scan('2024-01-01'), 'fields' => [], 'groupby' => ['cylinders'], 'lazy' => false]);
        $hist = [];
        foreach ((array) $g as $r) {
            $cyl = $r['cylinders'] ?? false;
            $key = ($cyl === false || $cyl === null) ? 'none' : (string) (int) $cyl;
            $hist[$key] = ($hist[$key] ?? 0) + (int) ($r['__count'] ?? 0);
        }
        uksort($hist, fn ($a, $b) => (is_numeric($a) ? (int) $a : 99999) <=> (is_numeric($b) ? (int) $b : 99999));
        $tot = array_sum($hist);
        foreach ($hist as $cyl => $n) ln(sprintf('  cyl %-5s : %7d   (%.1f%%)', $cyl, $n, $tot ? 100 * $n / $tot : 0));
        ln("  TOTAL scanned vehicles 2024-26: {$tot}");
        $present = array_filter([5, 7, 10, 11], fn ($c) => isset($hist[(string) $c]));
        ln('  Of your requested 5/7/10/11 cyl: ' . (empty($present) ? 'NONE present in any period' : 'present: ' . implode(',', $present)));
    } catch (\Throwable $e) { ln('  read_group ERR: ' . $e->getMessage()); }
} catch (\Throwable $e) {
    ln('EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
