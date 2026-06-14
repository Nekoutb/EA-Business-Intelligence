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

try {
    $slug   = isset($_GET['slug']) ? preg_replace('/[^a-z0-9]/i', '', (string) $_GET['slug']) : 'transatlanticd';
    $tenant = App\Models\Tenant::where('slug', $slug)->first();
    echo 'tenant: ' . ($tenant ? ('#' . $tenant->id . ' ' . $tenant->slug) : 'NOT FOUND') . "\n";

    // Bind a request + session to the container BEFORE any auth/session access.
    $request = Illuminate\Http\Request::create('/t/' . ($tenant->slug ?? 'x') . '/profit-loss', 'GET', ['view' => 'profitability']);
    $app->instance('request', $request);
    $session = $app['session']->driver();
    $session->start();
    $request->setLaravelSession($session);

    if ($tenant) {
        app(App\Tenancy\TenantContext::class)->set($tenant);
        $u = App\Models\User::query()->where('tenant_id', $tenant->id)->first() ?? App\Models\User::query()->first();
        if ($u) {
            Illuminate\Support\Facades\Auth::login($u);
            echo 'auth user: #' . $u->id . "\n";
        }
    }

    foreach ([['n' => 'P&L (normal)', 'q' => []], ['n' => 'Profitability', 'q' => ['view' => 'profitability']]] as $case) {
        try {
            $req = Illuminate\Http\Request::create('/t/' . ($tenant->slug ?? 'x') . '/profit-loss', 'GET', $case['q']);
            $req->setLaravelSession($session);
            $app->instance('request', $req);
            $resp = app(App\Http\Controllers\ProfitLossController::class)->index($req);
            $html = (is_object($resp) && method_exists($resp, 'render')) ? $resp->render() : '';
            echo $case['n'] . ': RENDER OK, length=' . strlen((string) $html) . "\n";
        } catch (\Throwable $ex) {
            echo $case['n'] . ': FAILED -> ' . get_class($ex) . ': ' . $ex->getMessage() . ' @ ' . basename($ex->getFile()) . ':' . $ex->getLine() . "\n";
        }
    }
} catch (\Throwable $e) {
    echo "\n=== EXCEPTION ===\n";
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
    echo 'at ' . $e->getFile() . ':' . $e->getLine() . "\n\n";
    $tr = explode("\n", $e->getTraceAsString());
    echo implode("\n", array_slice($tr, 0, 22)) . "\n";
}
