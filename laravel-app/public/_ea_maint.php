<?php
/** TEMP diagnostic — why /inventory?view=reports 404s for Kissywears:
 *  config-cache state, live module url, and module-enabled status. */
header('Content-Type: text/plain');
$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit; }
$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
function ln($s = '') { echo $s . "\n"; }

try {
    ln('=== caches on disk ===');
    foreach (['config.php', 'routes-v7.php', 'packages.php', 'services.php'] as $f) {
        $p = $base . '/bootstrap/cache/' . $f;
        ln('  ' . $f . ' : ' . (file_exists($p) ? 'EXISTS (' . date('Y-m-d H:i', filemtime($p)) . ')' : 'absent'));
    }
    ln();

    ln('=== live config(modules) — reports entry ===');
    $mods = config('modules.modules', []);
    $found = false;
    foreach ($mods as $m) {
        if (($m['key'] ?? '') === 'reports') { $found = true; ln('  reports.url = ' . json_encode($m['url'] ?? null)); }
    }
    if (!$found) ln('  reports module NOT in live config (config cache is stale!)');
    ln('  total modules in live config: ' . count($mods));
    ln();

    ln('=== Kissywears module-enabled status ===');
    $tenant = App\Models\Tenant::where('name', 'like', '%KISSY%')->first();
    app(App\Tenancy\TenantContext::class)->set($tenant);
    foreach (['dashboard', 'inventory', 'reports', 'receivables', 'billing', 'revenue', 'profit_loss', 'treasury', 'payables'] as $k) {
        $en = method_exists($tenant, 'moduleEnabled') ? $tenant->moduleEnabled($k) : '??';
        ln('  ' . str_pad($k, 14) . ' : ' . ($en === true ? 'ENABLED' : ($en === false ? 'disabled' : $en)));
    }
} catch (\Throwable $e) {
    ln('EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
