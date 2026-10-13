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
        $logistics = null;

        // Logistics-sector tenants: unit economics + débours reconciliation
        // from the dossier warehouse. The classic per-scan analysis below
        // needs live Odoo, which these tenants don't have — skip it rather
        // than render its error banner.
        $periodLabel = null;
        if (\App\Services\LogisticsOpsService::available($tenantId)) {
            // Driven by the sidebar master filter (session-synced), like the
            // other logistics pages — YTD, month-to-date or a specific month.
            $period = current_period();
            [$from, $to, $periodLabel] = ea_resolve_period($today, $period);
            $logistics = ea_cached('logi_profit_v2_' . md5(json_encode($period)),
                fn () => (new \App\Services\LogisticsOpsService((int) $tenantId))->profitability($from, $to));
        } else {
            try {
                $client = OdooFactory::fromConfig();
                // File-based tenants (KPI_FILE_REF_FIELD): unit economics per
                // file instead of per scan.
                $ref = (string) config_env('KPI_FILE_REF_FIELD', '');
                $ref = in_array($ref, \App\Services\FileUnitEconomics::FIELDS, true) ? $ref : null;
                $data = ea_cached("profitability_v4_{$year}_" . ($ref ?? 'scan') . '_' . \App\Services\ProductivityOverrides::fingerprint(), function () use ($client, $year, $assumptions, $ref) {
                    return (new ProfitabilityService($client, $ref))->analyze($year, $assumptions);
                });
            } catch (\Throwable $e) {
                $error = ea_safe_error($e, 'profitability.index', 'Could not load profitability data.');
            }
        }

        // Render through the existing (already-compiled) profit-loss view via
        // profMode — this host can't compile a brand-new template at runtime
        // (storage/framework/views isn't writable by the web user), so a
        // dedicated profitability.blade.php 500s. Same markup, hosted there.
        return view('profit-loss', [
            'active'    => 'profitability',
            'year'      => $year,
            'data'      => $data,
            'error'     => $error,
            // profMode stays on (the view tolerates null data); logistics
            // tenants additionally get their section rendered on top.
            'profMode'  => true,
            'logistics' => $logistics,
            'periodLabel' => $periodLabel,
        ]);
    }
}
