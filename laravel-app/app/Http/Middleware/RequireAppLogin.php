<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant-aware session gate.
 *
 * Phase A wires Laravel's full Auth (login persists `auth()->user()`
 * in the session) but keeps the simple session flags (`bi_logged_in`
 * and `bi_tenant_slug`) so existing controllers and views that read
 * those keep working without a sweep. The two are kept in sync.
 *
 * A user logged into tenant A cannot just edit the URL to /t/B/...
 * and view a different workspace — we compare the tenant slug stored
 * at login time against the slug in the current URL.
 */
class RequireAppLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        $session   = $request->session();
        $routeSlug = (string) $request->route('tenant');

        $loggedIn = $session->get('bi_logged_in') || Auth::check();

        if (!$loggedIn) {
            $target = $routeSlug !== '' ? "/t/{$routeSlug}/login" : '/login';
            return redirect($target);
        }

        // Reject tenant URL switching without re-login.
        if ($routeSlug !== '' && $session->get('bi_tenant_slug') !== $routeSlug) {
            $session->forget(['bi_logged_in', 'bi_tenant_slug']);
            Auth::logout();
            return redirect("/t/{$routeSlug}/login");
        }

        // Suspended-user defence: if the user got disabled mid-session,
        // bounce them to login on the next request.
        $user = Auth::user();
        if ($user && method_exists($user, 'isActive') && !$user->isActive()) {
            $session->forget(['bi_logged_in', 'bi_tenant_slug']);
            Auth::logout();
            return redirect($routeSlug !== '' ? "/t/{$routeSlug}/login" : '/login');
        }

        return $next($request);
    }
}
