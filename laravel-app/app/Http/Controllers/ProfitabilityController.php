<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\BudgetService;
use App\Services\ProfitabilityService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Profitability — unit economics (per scan) + profit growth.
 * Cached per fiscal year. Year selectable via ?year=2026.
 */
class ProfitabilityController extends Controller
{
    public function index(Request $request)
    {
        $today    = new \DateTimeImmutable();
        $thisYear = (int) $today->format('Y');
        $year     = (int) $request->query('year', $thisYear);
        $year     = max(2018, min($thisYear + 2, $year));

        // Thread the saved forecast assumptions into the monthly trend so it
        // matches what the P&L Forecast page shows.
        $tenantId = optional(app(TenantContext::class)->has() ? app(TenantContext::class)->tenant() : null)->id;
        $assumptions = $tenantId
            ? app(BudgetService::class)->loadAssumptions($tenantId, $year)
            : null;

        $data  = null;
        $error = null;

        try {
            $client = OdooFactory::fromConfig();
            $data = ea_cached("profitability_v2_{$year}", function () use ($client, $year, $assumptions) {
                return (new ProfitabilityService($client))->analyze($year, $assumptions);
            });
        } catch (\Throwable $e) {
            $error = ea_safe_error($e, 'profitability.index', 'Could not load profitability data.');
        }

        // Render through the existing (already-compiled) profit-loss view via
        // profMode — this host can't compile a brand-new template at runtime
        // (storage/framework/views isn't writable by the web user), so a
        // dedicated profitability.blade.php 500s. Same markup, hosted there.
        return view('profit-loss', [
            'active'   => 'profitability',
            'year'     => $year,
            'data'     => $data,
            'error'    => $error,
            'profMode' => true,
        ]);
    }
}
