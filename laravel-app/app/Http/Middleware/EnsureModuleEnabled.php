<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Gate a route by a module key from config/modules.php.
 *
 * Usage:
 *     Route::get('/revenue', ...)->middleware('module:revenue');
 *
 * Behaviour:
 *   - If the active tenant has the module enabled in tenant_modules, allow.
 *   - If the module is marked `core` in the registry, allow regardless
 *     (the dashboard and settings should never become inaccessible).
 *   - Otherwise return a 403 page guiding the user to contact their admin.
 */
class EnsureModuleEnabled
{
    public function __construct(private TenantContext $context)
    {
    }

    public function handle(Request $request, Closure $next, string $moduleKey): Response
    {
        if (!$this->context->has()) {
            abort(404, 'No tenant in context.');
        }

        // Core modules can never be disabled.
        $module = collect(config('modules.modules', []))
            ->firstWhere('key', $moduleKey);
        if ($module && !empty($module['core'])) {
            return $next($request);
        }

        if ($this->context->moduleEnabled($moduleKey)) {
            return $next($request);
        }

        throw new HttpException(403, sprintf(
            'The "%s" module is not activated for this workspace. Contact your administrator.',
            $module['label'] ?? $moduleKey
        ));
    }
}
