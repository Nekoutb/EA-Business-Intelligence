<?php
/** TEMP diagnostic — render admin Strategic Analysis show page for Kissywears. */
header('Content-Type: text/plain');
$TOKEN = 'k7Qz9_R2mVtX4pLs8wNcB3hJ6yD0fAeU';
if (!hash_equals($TOKEN, (string) ($_GET['t'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit; }
$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();

try {
    $tenant = App\Models\Tenant::where('name', 'like', '%KISSY%')->first();
    $req = Illuminate\Http\Request::create('/admin/business-insights/' . $tenant->id, 'GET');
    $app->instance('request', $req);
    $session = $app['session']->driver(); $session->start(); $req->setLaravelSession($session);

    $t = microtime(true);
    $html = app(App\Http\Controllers\Admin\BusinessInsightsController::class)->show($tenant->id)->render();
    echo 'render ' . round(microtime(true) - $t, 1) . 's · len=' . strlen($html) . "\n";
    foreach ([
        'title Client Strategic Analysis' => 'Client Strategic Analysis',
        'advisor narrative'               => 'Advisor narrative',
        'action plan table'               => 'Prioritized action plan',
        '90-day roadmap'                  => '90-day roadmap',
        'download PDF button'             => 'Download report (PDF)',
        'a real action row'               => 'Recommended action',
        'a roadmap horizon'               => '0-30 days',
    ] as $label => $needle) {
        echo '  ' . str_pad($label, 26) . ' : ' . (strpos($html, $needle) !== false ? 'y' : 'n') . "\n";
    }
} catch (\Throwable $e) {
    echo 'EXC: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
}
