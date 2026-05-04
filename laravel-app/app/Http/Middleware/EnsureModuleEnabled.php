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

        // The parent service must also be enabled. Disabling a service
        // (e.g. Tax Compliance) cascades to every module beneath it.
        $serviceKey = $module['service'] ?? null;
        if ($serviceKey && !$this->context->serviceEnabled($serviceKey)) {
            $service = collect(config('modules.services', []))
                ->firstWhere('key', $serviceKey);
            throw new HttpException(403, sprintf(
                'The "%s" service is not activated for this workspace. Contact your administrator.',
                $service['label'] ?? $serviceKey
            ));
        }

        if (!$this->context->moduleEnabled($moduleKey)) {
            throw new HttpException(403, sprintf(
                'The "%s" module is not activated for this workspace. Contact your administrator.',
                $module['label'] ?? $moduleKey
            ));
        }

        // Per-user permission override. Tenant admin can opt individual
        // users out of specific services or modules even when the
        // tenant-level flag is on. A 403 here means "the workspace has
        // it; you specifically don't" — the message guides the user
        // back to their admin rather than IT.
        $user = $request->user();
        if ($user && method_exists($user, 'canAccessService')
            && $serviceKey && !$user->canAccessService($serviceKey)) {
            throw new HttpException(403, sprintf(
                'You do not have access to the "%s" service. Ask your tenant admin if you need it.',
                $service['label'] ?? $serviceKey
            ));
        }
        if ($user && method_exists($user, 'canAccessModule')
            && !$user->canAccessModule($moduleKey)) {
            throw new HttpException(403, sprintf(
                'You do not have access to the "%s" module. Ask your tenant admin if you need it.',
                $module['label'] ?? $moduleKey
            ));
        }

        return $next($request);
    }
}
