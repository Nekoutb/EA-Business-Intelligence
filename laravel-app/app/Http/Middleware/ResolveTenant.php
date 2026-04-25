<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Resolves the current tenant from the {tenant} route segment, loads it, and
 * binds it into the TenantContext singleton + the route binding cache.
 *
 *   - Missing slug         -> 404
 *   - Slug not found       -> 404
 *   - Tenant suspended     -> 403 with friendly message
 *   - Otherwise            -> request continues with $request->route('tenantModel') set
 *
 * Tenant brand/logo are also pushed into config('bi.brand') / config('bi.logo')
 * so existing layouts that read those keys "just work" without per-view changes.
 */
class ResolveTenant
{
    public function __construct(private TenantContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $slug = (string) $request->route('tenant');
        if ($slug === '') {
            abort(404, 'Tenant not specified.');
        }

        $tenant = Tenant::where('slug', $slug)->first();
        if (!$tenant) {
            abort(404, 'Unknown workspace.');
        }
        if ($tenant->status === Tenant::STATUS_SUSPENDED) {
            throw new HttpException(403, 'This workspace is suspended. Contact your administrator.');
        }

        $this->context->set($tenant);

        // Make the model available to controllers via the {tenant} param too.
        $request->route()->setParameter('tenantModel', $tenant);

        // Branding: per-tenant override of layout-consumed config keys.
        if ($tenant->brand) {
            config(['bi.brand' => $tenant->brand]);
        }
        if ($tenant->logo_path) {
            config(['bi.logo' => $tenant->logo_path]);
        }

        return $next($request);
    }
}
