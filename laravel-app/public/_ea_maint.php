<?php
/** TEMP probe — generate a real Client Strategic Analysis for Kissywears. */
header('Content-Type: text/plain');
$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit; }
$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
function ln($s = '') { echo $s . "\n"; }

try {
    $tenant = App\Models\Tenant::where('name', 'like', '%KISSY%')->first();
    app(App\Tenancy\TenantContext::class)->set($tenant);

    $svc = $app->make(App\Services\StrategicAnalysisService::class);
    $t0 = microtime(true);
    $row = $svc->generate($tenant, true);
    $dt = round(microtime(true) - $t0, 1);

    ln("generate {$dt}s · status={$row->status} priority={$row->priority} model={$row->model} tokens=" . ($row->input_tokens ?? 0) . '/' . ($row->output_tokens ?? 0));
    ln('summary: ' . $row->summary);
    if ($row->error_msg) ln('error: ' . $row->error_msg);
    $snap = $row->kpis_snapshot ?? [];
    $blocks = [];
    foreach (['revenue', 'pl', 'receivables', 'reconciliation', 'treasury', 'payables', 'inventory', 'operations'] as $b) if (!empty($snap[$b])) $blocks[] = $b;
    ln('gathered blocks: ' . implode(', ', $blocks));
    ln('fetch errors: ' . json_encode($snap['errors'] ?? []));
    ln();
    ln('--- narrative_md (first 900 chars) ---');
    ln(substr((string) $row->insight_md, 0, 900));
    ln();
    ln('--- action_table (' . count($snap['action_table'] ?? []) . ' rows) ---');
    foreach (array_slice($snap['action_table'] ?? [], 0, 6) as $a) ln("  [{$a['priority']}] {$a['area']} · {$a['diagnosis']} → {$a['recommendation']}  (I:{$a['impact']}/E:{$a['effort']})");
    ln();
    ln('--- roadmap_90d ---');
    foreach ($snap['roadmap_90d'] ?? [] as $r) ln('  ' . $r['horizon'] . ' (' . $r['theme'] . '): ' . implode(' · ', $r['items']));
} catch (\Throwable $e) {
    ln('EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
