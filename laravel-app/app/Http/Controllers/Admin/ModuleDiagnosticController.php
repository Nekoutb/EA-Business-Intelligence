<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\URL;

/**
 * Self-service diagnostic for "why is module X returning 404 / 403
 * for tenant Y" issues. Browser-accessible at:
 *
 *   /admin/diagnostics/module/{moduleKey}/tenant/{tenantId}
 *
 * Reports the exact state of every gate the request has to pass:
 *   1. Is the route registered in Laravel's resolver right now?
 *   2. Is the bootstrap/cache/routes-v7.php file fresh, or older
 *      than the routes/web.php source file? (this is the #1 cause
 *      of 404 after a deploy)
 *   3. Is the parent service enabled in tenant_services?
 *   4. Is the module enabled in tenant_modules?
 *   5. Is the module marked live or coming_soon in config?
 *
 * The page surfaces each as a green/red traffic light + the exact
 * fix command for any red row. Saves the operator from having to
 * SSH into the box and grep through cache files.
 */
class ModuleDiagnosticController extends Controller
{
    public function show(Request $request, string $moduleKey, int $tenantId)
    {
        $tenant = Tenant::findOrFail($tenantId);
        $moduleCfg = collect(config('modules.modules', []))->firstWhere('key', $moduleKey);
        $serviceKey = $moduleCfg['service'] ?? null;
        $serviceCfg = $serviceKey
            ? collect(config('modules.services', []))->firstWhere('key', $serviceKey)
            : null;

        // 1. Route registration check — iterate Laravel's resolver
        //    and find any route whose URI ends with the module URL.
        $expectedUri = $moduleCfg['url'] ?? "/{$moduleKey}";
        $routeRegistered = false;
        $routeMethod = null;
        $routeMiddleware = [];
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            $uri = '/' . ltrim($route->uri(), '/');
            if (str_ends_with($uri, ltrim($expectedUri, '/'))
                && in_array('GET', $route->methods(), true)
                && str_starts_with($uri, '/t/')) {
                $routeRegistered = true;
                $routeMethod = implode('|', $route->methods());
                $routeMiddleware = $route->gatherMiddleware();
                break;
            }
        }

        // 2. Bootstrap route-cache file freshness — the file
        //    Laravel reads from after route:cache. If older than
        //    routes/web.php the cache is stale and routes added
        //    after the cache was written are missing.
        $routeCachePath = base_path('bootstrap/cache/routes-v7.php');
        $routeCacheExists = file_exists($routeCachePath);
        $routeCacheAge = $routeCacheExists ? filemtime($routeCachePath) : null;
        $routeSourcePath = base_path('routes/web.php');
        $routeSourceAge = file_exists($routeSourcePath) ? filemtime($routeSourcePath) : null;
        $routeCacheStale = $routeCacheExists && $routeSourceAge && $routeCacheAge < $routeSourceAge;

        // 3. Service enablement
        $serviceEnabled = $serviceKey ? $tenant->serviceEnabled($serviceKey) : true;

        // 4. Module enablement
        $moduleEnabled = $tenant->moduleEnabled($moduleKey);

        // 5. Live status in config
        $moduleStatus = $moduleCfg['status'] ?? 'unknown';

        $checks = [
            [
                'label'  => 'Route registered',
                'pass'   => $routeRegistered,
                'detail' => $routeRegistered
                    ? "{$routeMethod} /t/{tenant}{$expectedUri} → middleware: " . implode(', ', $routeMiddleware)
                    : 'Laravel does not have a route matching this URL. Either route:cache is stale or the source file was never deployed.',
                'fix'    => $routeRegistered
                    ? null
                    : 'On the server, run: rm -f bootstrap/cache/routes-v7.php && php artisan route:clear',
            ],
            [
                'label'  => 'Route cache file fresh',
                'pass'   => !$routeCacheStale,
                'detail' => $routeCacheExists
                    ? 'Cache: ' . date('Y-m-d H:i:s', $routeCacheAge) . ' · Source: ' . date('Y-m-d H:i:s', $routeSourceAge ?: 0)
                    : 'No cache file (good — runtime resolves routes on every request)',
                'fix'    => $routeCacheStale
                    ? 'rm -f bootstrap/cache/routes-v7.php && php artisan route:clear'
                    : null,
            ],
            [
                'label'  => 'Module status in config = live',
                'pass'   => $moduleStatus === 'live',
                'detail' => "config/modules.php → status: {$moduleStatus}",
                'fix'    => $moduleStatus !== 'live'
                    ? 'Edit config/modules.php and set status to "live" for this module.'
                    : null,
            ],
            [
                'label'  => 'Parent service enabled for tenant',
                'pass'   => $serviceEnabled,
                'detail' => $serviceKey
                    ? "tenant_services row for '{$serviceKey}' = " . ($serviceEnabled ? 'enabled' : 'NOT enabled')
                    : 'Module has no parent service',
                'fix'    => !$serviceEnabled && $serviceKey
                    ? "Toggle the '" . ($serviceCfg['label'] ?? $serviceKey) . "' service ON via the tenant Services & Modules panel."
                    : null,
            ],
            [
                'label'  => 'Module enabled for tenant',
                'pass'   => $moduleEnabled,
                'detail' => 'tenant_modules row for ' . $moduleKey . ' = ' . ($moduleEnabled ? 'enabled' : 'NOT enabled'),
                'fix'    => !$moduleEnabled
                    ? "Toggle the '" . ($moduleCfg['label'] ?? $moduleKey) . "' module ON via the tenant Services & Modules panel."
                    : null,
            ],
        ];

        // Net verdict
        $allPass = collect($checks)->every(fn ($c) => $c['pass']);
        $expectedFullUrl = "/t/{$tenant->slug}" . $expectedUri;

        return view('admin.module-diagnostic', [
            'tenant'          => $tenant,
            'moduleKey'       => $moduleKey,
            'moduleCfg'       => $moduleCfg,
            'serviceCfg'      => $serviceCfg,
            'checks'          => $checks,
            'allPass'         => $allPass,
            'expectedFullUrl' => $expectedFullUrl,
            'active'          => 'tenants',
        ]);
    }
}
