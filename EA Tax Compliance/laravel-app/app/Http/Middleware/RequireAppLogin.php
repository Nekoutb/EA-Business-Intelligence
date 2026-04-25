<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Simple single-user session gate.
 *
 * We deliberately do NOT use Laravel's user Auth stack: there is no users
 * table and no registration flow — the app is a private BI console for a
 * single operator whose credentials live in config/.env.
 */
class RequireAppLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->session()->get('bi_logged_in')) {
            return redirect('/login');
        }
        return $next($request);
    }
}
