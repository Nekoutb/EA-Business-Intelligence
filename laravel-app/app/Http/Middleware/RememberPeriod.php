<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Master period filter — single source of truth for "what window is the user
 * looking at?" across every BMS page.
 *
 * The sidebar's master filter (rendered in layouts/app.blade.php) submits
 * GET params (period / year / month) to whatever URL the user is on. This
 * middleware intercepts those params, persists the selection in the user's
 * session, then back-fills the request so downstream controllers see the
 * filter values exactly as if they'd been on the URL.
 *
 * Net effect:
 *   - User toggles MTD on the Revenue page → session stores 'mtd'.
 *   - User clicks "P&L" in the sidebar → no period in URL → middleware
 *     reads 'mtd' from session and merges it into the request →
 *     PLController sees ?period=mtd just like before.
 *
 * Controllers don't need to change. The contract `$request->query('period')`
 * still returns the active value.
 */
class RememberPeriod
{
    public function handle(Request $request, Closure $next)
    {
        // Only apply to GET requests — POSTs (Refresh, settings save, etc.)
        // shouldn't accidentally rewrite the filter just because they happen
        // to ride on the same URL.
        if ($request->isMethod('GET')) {
            // If the URL carries any of the three params, treat it as a
            // deliberate filter change and persist each provided one
            // independently (so passing only ?year=2025 doesn't reset
            // the period type to its default).
            if ($request->has('period')) {
                $type = strtolower((string) $request->query('period'));
                if (in_array($type, ['ytd', 'mtd', 'month', 'yoy'], true)) {
                    session(['bms.period.type' => $type]);
                }
            }
            if ($request->has('year')) {
                $y = (int) $request->query('year');
                if ($y >= 2000 && $y <= 2100) {
                    session(['bms.period.year' => $y]);
                }
            }
            if ($request->has('month')) {
                $m = (int) $request->query('month');
                if ($m >= 1 && $m <= 12) {
                    session(['bms.period.month' => $m]);
                }
            }

            // Back-fill: ensure controllers always see populated period
            // params, regardless of which page the user navigated from.
            $today = new \DateTimeImmutable();
            $request->merge([
                'period' => $request->query('period', session('bms.period.type',  'ytd')),
                'year'   => $request->query('year',   session('bms.period.year',  (int) $today->format('Y'))),
                'month'  => $request->query('month',  session('bms.period.month', (int) $today->format('m'))),
            ]);
        }

        return $next($request);
    }
}
