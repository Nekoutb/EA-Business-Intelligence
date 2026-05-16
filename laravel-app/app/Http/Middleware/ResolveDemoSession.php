<?php

namespace App\Http\Middleware;

use App\Models\Demo\DemoSession;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the demo tenant for every /demo/sandbox/* request.
 *
 * Looks up the DemoSession row by the visitor's Laravel session id and
 * binds the associated demo tenant to TenantContext. Redirects to the
 * /demo/enter form if no active session is found (or it's expired).
 *
 * Touches last_seen_at on every request so the cleanup reaper knows
 * which sandboxes are still being actively used.
 */
class ResolveDemoSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $sid = $request->session()->getId();
        $demoSession = DemoSession::where('session_id', $sid)->first();

        if (!$demoSession) {
            return redirect('/demo/enter')->with('flash', [
                'type' => 'error',
                'msg'  => 'Demo session not found — please re-enter your details.',
            ]);
        }

        if ($demoSession->isExpired()) {
            // Don't auto-delete here — the daily cleanup command handles
            // that. Just refuse the request and prompt to re-enter.
            return redirect('/demo/enter')->with('flash', [
                'type' => 'error',
                'msg'  => 'Your demo session has expired. Re-enter to start a fresh sandbox.',
            ]);
        }

        $tenant = $demoSession->tenant;
        if (!$tenant || !$tenant->is_demo) {
            return redirect('/demo/enter')->with('flash', [
                'type' => 'error',
                'msg'  => 'Demo tenant is missing — please re-enter.',
            ]);
        }

        // Bind the demo tenant to TenantContext so downstream
        // controllers + services see the right scope.
        app(TenantContext::class)->set($tenant);

        // Bookkeeping: refresh activity. Cheap update on a single row.
        $demoSession->update(['last_seen_at' => now()]);

        // Make the session + tenant available to controllers via
        // request attributes (avoids re-querying).
        $request->attributes->set('demo_session', $demoSession);
        $request->attributes->set('demo_tenant', $tenant);

        return $next($request);
    }
}
