<?php

namespace App\Http\Controllers;

use App\Services\ScanningOpsService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Operations_TSA — container-scanning operations reconstructed from the
 * warehouse (products + invoice lines + Type_voyage), for instances that
 * do not carry the aw_cargo.* models the original Operations page needs.
 */
class ScanningOpsController extends Controller
{
    public function index(Request $request)
    {
        $today  = new \DateTimeImmutable();
        // Sidebar master filter only — no page-level filter.
        $period = current_period();
        [$from, $to, $label] = ea_resolve_period($today, $period);
        $tenantId = (int) (optional(app(TenantContext::class)->has() ? app(TenantContext::class)->tenant() : null)->id ?? 0);

        $data = ea_cached('scanops_v1_' . md5(json_encode($period)),
            fn () => (new ScanningOpsService($tenantId))->analyze($from, $to));

        return view('scanning-ops', [
            'data' => $data, 'active' => 'operations_tsa',
            'period' => $period, 'periodLabel' => $label,
        ]);
    }
}
