<?php

namespace App\Http\Controllers\Tax;

use App\Http\Controllers\Controller;
use App\Odoo\OdooFactory;
use App\Services\Tax\MonthlyTaxReturnService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

class MonthlyTaxReturnController extends Controller
{
    public function index(Request $request)
    {
        return $this->render($request, 'tax.monthly-tax-return');
    }

    /**
     * Print-friendly replica of the I/TVA-IR. Same data, dedicated layout.
     * Customer can use browser Print → Save as PDF for filing.
     */
    public function printable(Request $request)
    {
        return $this->render($request, 'tax.monthly-tax-return-print');
    }

    private function render(Request $request, string $view)
    {
        $analysis = null;
        $error    = null;
        $month    = (string) $request->query('month', '');
        if ($month !== '' && !preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = '';
        }

        $tenant = app(TenantContext::class)->has() ? app(TenantContext::class)->tenant() : null;
        // Cache key version suffix — bumped on every structural change
        // to the analyze() output shape so stale session-cache entries
        // don't suppress new view branches. Latest bumps:
        //   v1 → v2 (2026-05-14, PR #141): analyze() now returns a new
        //     top-level 'reconciliation' key consumed by the vertical
        //     reconciliation card; old v1 entries lack the key and the
        //     view's @if guard quietly hid the card for 5 min after deploy.
        //   v2 → v3 (2026-05-14, PR #143): §12 L67 row shape changed —
        //     now passes Principal + CAC + Total explicitly (Principal
        //     = Total / 1.10, CAC = Principal × 10%). L73 aggregates
        //     sub-line CACs instead of computing 10% on the principal
        //     sum, so the "5,000,000 IRPP" case correctly displays as
        //     4,545,454 + 454,545 = 5,000,000 (not 5,000,000 + 500,000).
        $cacheKey = 'monthly_tax_return_v3_' . ($month ?: 'current');

        try {
            $analysis = ea_cached($cacheKey, function () use ($month, $tenant) {
                return (new MonthlyTaxReturnService(OdooFactory::fromConfig()))->analyze($month ?: null, $tenant);
            });
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view($view, [
            'analysis' => $analysis,
            'error'    => $error,
            'month'    => $month,
            'active'   => 'monthly_tax_return',
        ]);
    }
}
