<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant-aware session gate.
 *
 * Phase 2 keeps the simple session-flag auth (full Laravel user auth lands
 * in Phase 3) but now also enforces that the logged-in session matches the
 * tenant in the current URL. A user logged into tenant A cannot just edit
 * the URL to /t/B/... and view a different workspace.
 */
class RequireAppLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        $session   = $request->session();
        $routeSlug = (string) $request->route('tenant');

        if (!$session->get('bi_logged_in')) {
            $target = $routeSlug !== '' ? "/t/{$routeSlug}/login" : '/login';
            return redirect($target);
        }

        // Reject tenant URL switching without re-login.
        if ($routeSlug !== '' && $session->get('bi_tenant_slug') !== $routeSlug) {
            $session->forget(['bi_logged_in', 'bi_tenant_slug']);
            return redirect("/t/{$routeSlug}/login");
        }

        return $next($request);
    }
}
