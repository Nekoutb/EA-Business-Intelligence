<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Gate routes that should only be reachable by tenant admins (or the
 * platform super admin). Layered on top of `bi.auth` and
 * `tenant.resolve`, so by the time we run the user is already
 * authenticated AND a tenant is in scope.
 *
 * Usage:
 *     Route::middleware('tenant.admin')->group(...);
 */
class RequireTenantAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user) {
            // Defence-in-depth: bi.auth should have caught this, but
            // never hand a 200 to an anonymous request on an admin
            // surface.
            $slug = (string) $request->route('tenant');
            return redirect($slug !== '' ? "/t/{$slug}/login" : '/login');
        }

        $isAdmin = method_exists($user, 'isTenantAdmin') && $user->isTenantAdmin();
        $isSuper = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        if (!$isAdmin && !$isSuper) {
            throw new HttpException(403,
                'Only tenant admins can manage users. Ask your administrator.'
            );
        }

        return $next($request);
    }
}
