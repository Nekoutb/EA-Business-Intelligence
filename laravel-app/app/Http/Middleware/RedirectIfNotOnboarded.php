<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RedirectIfNotOnboarded
{
    public function handle(Request $request, Closure $next)
    {
        $tenant = $request->attributes->get('tenant');

        // Skip onboarding for onboarding routes themselves
        if (str_starts_with($request->path(), '/t/' . ($tenant->slug ?? '') . '/onboarding')) {
            return $next($request);
        }

        // If not yet onboarded, redirect to onboarding wizard
        if ($tenant && $tenant->onboarded_at === null) {
            return redirect('/t/' . $tenant->slug . '/onboarding?step=1');
        }

        return $next($request);
    }
}
