<?php
/**
 * TEMP diagnostic — reproduces the Profitability render server-side to capture
 * the real exception (production hides it). Token-guarded. Re-stubbed after use.
 */
header('Content-Type: text/plain');

$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app    = require $base . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

echo 'PHP ' . PHP_VERSION . "\n";
echo 'ProfitabilityController: ' . (class_exists(App\Http\Controllers\ProfitabilityController::class) ? 'OK' : 'MISSING') . "\n";
echo 'ProfitabilityService: '   . (class_exists(App\Services\ProfitabilityService::class) ? 'OK' : 'MISSING') . "\n";

try {
    $slug   = isset($_GET['slug']) ? preg_replace('/[^a-z0-9]/i', '', (string) $_GET['slug']) : 'transatlanticd';
    $tenant = App\Models\Tenant::where('slug', $slug)->first();
    echo 'tenant: ' . ($tenant ? ('#' . $tenant->id . ' ' . $tenant->slug) : 'NOT FOUND') . "\n";
    if ($tenant) {
        app(App\Tenancy\TenantContext::class)->set($tenant);
    }
    $u = App\Models\User::query()->first();
    if ($u) {
        Illuminate\Support\Facades\Auth::login($u);
        echo 'auth user: #' . $u->id . "\n";
    }

    $request = Illuminate\Http\Request::create('/t/' . ($tenant->slug ?? 'x') . '/profit-loss', 'GET', ['view' => 'profitability']);
    $app->instance('request', $request);

    $resp = app(App\Http\Controllers\ProfitLossController::class)->index($request);

    if ($resp instanceof Illuminate\View\View) {
        $html = $resp->render();
        echo 'RENDER OK, length=' . strlen($html) . "\n";
    } elseif (is_object($resp) && method_exists($resp, 'render')) {
        $html = $resp->render();
        echo 'RESP(' . get_class($resp) . ') render len=' . strlen((string) $html) . "\n";
    } else {
        echo 'RESP type: ' . (is_object($resp) ? get_class($resp) : gettype($resp)) . "\n";
    }
} catch (\Throwable $e) {
    echo "\n=== EXCEPTION ===\n";
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
    echo 'at ' . $e->getFile() . ':' . $e->getLine() . "\n\n";
    $tr = explode("\n", $e->getTraceAsString());
    echo implode("\n", array_slice($tr, 0, 20)) . "\n";
}
