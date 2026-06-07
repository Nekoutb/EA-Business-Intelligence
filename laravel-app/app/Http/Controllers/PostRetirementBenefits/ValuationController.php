<?php

namespace App\Http\Controllers\PostRetirementBenefits;

use App\Http\Controllers\Controller;
use App\Models\PostRetirementBenefits\Employee;
use App\Models\PostRetirementBenefits\Valuation;
use App\Services\PostRetirementBenefits\ExcelReportExporter;
use App\Services\PostRetirementBenefits\SensitivityAnalyzer;
use App\Services\PostRetirementBenefits\ValuationRunner;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Run + finalize + list + show valuations.
 *
 * Run is the workhorse: takes a valuation date (defaults to today)
 * and iterates every active employee through the PUC engine.
 * Output lands in `post_retirement_valuations` (headline totals)
 * + `post_retirement_valuation_lines` (per-employee detail).
 *
 * Finalize freezes a draft for the period — once finalized it's
 * the row read by the landing page KPI strip + the report exporter.
 */
class ValuationController extends Controller
{
    public function run(Request $request, ValuationRunner $runner)
    {
        $tenant = app(TenantContext::class)->tenant();

        // Check there's at least one active employee — running on
        // an empty roster yields a zero PBO, which is technically
        // correct but useless. Better to refuse with a clear hint.
        $count = Employee::where('tenant_id', $tenant->id)->where('active', true)->count();
        if ($count === 0) {
            return back()->with('flash', [
                'type' => 'error',
                'msg'  => 'No active employees in the plan. Add or import the roster before running a valuation.',
            ]);
        }

        // Default valuation date = today (year-end is the typical
        // production case, but during onboarding the operator
        // often wants a quick today-snapshot first).
        $valuationDate = $request->input('valuation_date')
            ? Carbon::parse($request->input('valuation_date'))->startOfDay()
            : now()->startOfDay();

        try {
            $v = $runner->run($tenant, $valuationDate);
        } catch (\Throwable $e) {
            return back()->with('flash', [
                'type' => 'error',
                'msg'  => ea_safe_error($e, 'prb_valuation_run', 'Valuation could not be completed. The error has been logged — check the Odoo connection in Settings and try again.'),
            ]);
        }

        return redirect()->to("/t/{$tenant->slug}/post-retirement-benefits/valuations/{$v->id}")
            ->with('flash', [
                'type' => 'success',
                'msg'  => "Valuation as at {$valuationDate->format('d M Y')} complete — PBO {$this->fmt($v->pbo_closing)} XAF across {$count} employee(s).",
            ]);
    }

    public function show(Request $request, SensitivityAnalyzer $sensitivity, $tenantSlug, int $id)
    {
        $tenant = app(TenantContext::class)->tenant();
        $v = Valuation::where('tenant_id', $tenant->id)
            ->with(['lines.employee', 'assumptionSet'])
            ->findOrFail($id);

        // Lines sorted by PBO desc so the biggest exposures land
        // at the top of the per-employee detail table.
        $lines = $v->lines->sortByDesc('pbo')->values();

        // Sensitivity analysis — only when the run is finalized OR
        // explicitly requested via ?sensitivity=1 (computing it on
        // every show would slow large populations down).
        $showSensitivity = $v->isFinalized() || $request->boolean('sensitivity');
        $discountSensitivity = null;
        $salarySensitivity   = null;
        if ($showSensitivity && $v->pbo_closing > 0) {
            $discountSensitivity = $sensitivity->discountRateSensitivity($tenant, $v);
            $salarySensitivity   = $sensitivity->salaryIncreaseSensitivity($tenant, $v);
        }

        return view('post-retirement-benefits.valuations.show', [
            'valuation'             => $v,
            'lines'                 => $lines,
            'assumptions'           => $v->assumptionSet,
            'discountSensitivity'   => $discountSensitivity,
            'salarySensitivity'     => $salarySensitivity,
            'showSensitivity'       => $showSensitivity,
            'active'                => 'post_retirement_benefits',
        ]);
    }

    public function finalize(Request $request, $tenantSlug, int $id)
    {
        $tenant = app(TenantContext::class)->tenant();
        $v = Valuation::where('tenant_id', $tenant->id)->findOrFail($id);

        if ($v->status === 'finalized') {
            return back()->with('flash', ['type' => 'error', 'msg' => 'Already finalized.']);
        }

        // Allow only one finalized valuation per date. If a
        // finalized one exists for the same date, demote it to
        // draft (audit trail kept in payload).
        Valuation::where('tenant_id', $tenant->id)
            ->where('valuation_date', $v->valuation_date)
            ->where('status', 'finalized')
            ->where('id', '!=', $v->id)
            ->update(['status' => 'draft']);

        $v->update([
            'status'       => 'finalized',
            'finalized_at' => now(),
            'finalized_by' => $request->session()->get('user_id'),
        ]);

        return back()->with('flash', [
            'type' => 'success',
            'msg'  => "Valuation as at {$v->valuation_date->format('d M Y')} finalized. This is now the official figure for the period.",
        ]);
    }

    public function index(Request $request)
    {
        $tenant = app(TenantContext::class)->tenant();
        $valuations = Valuation::where('tenant_id', $tenant->id)
            ->orderByDesc('valuation_date')
            ->orderByRaw("CASE WHEN status = 'finalized' THEN 0 ELSE 1 END")
            ->paginate(20);
        return view('post-retirement-benefits.valuations.index', [
            'valuations' => $valuations,
            'active'     => 'post_retirement_benefits',
        ]);
    }

    /**
     * Mercer-style print-friendly HTML report. The user prints to
     * PDF via the browser to produce the deliverable they sign
     * externally. Matches Mercer's Globeleq report structure:
     * Cover → Highlights → Basis → 6 appendices → Notices.
     */
    public function report(Request $request, SensitivityAnalyzer $sensitivity, $tenantSlug, int $id)
    {
        $tenant = app(TenantContext::class)->tenant();
        $v = Valuation::where('tenant_id', $tenant->id)
            ->with(['lines.employee', 'assumptionSet'])
            ->findOrFail($id);
        $lines = $v->lines->sortByDesc('pbo')->values();

        // Always include sensitivity in the printable report
        // (it's a required disclosure under IAS 19 paragraph 145).
        $discountSensitivity = $v->pbo_closing > 0
            ? $sensitivity->discountRateSensitivity($tenant, $v)
            : null;
        $salarySensitivity = $v->pbo_closing > 0
            ? $sensitivity->salaryIncreaseSensitivity($tenant, $v)
            : null;

        return view('post-retirement-benefits.valuations.report', [
            'valuation'           => $v,
            'lines'               => $lines,
            'assumptions'         => $v->assumptionSet,
            'discountSensitivity' => $discountSensitivity,
            'salarySensitivity'   => $salarySensitivity,
        ]);
    }

    /** Excel workbook download — Mercer-style multi-sheet structure. */
    public function exportExcel(Request $request, $tenantSlug, int $id)
    {
        $tenant = app(TenantContext::class)->tenant();
        $v = Valuation::where('tenant_id', $tenant->id)
            ->with(['lines.employee', 'assumptionSet', 'priorValuation'])
            ->findOrFail($id);
        return (new ExcelReportExporter($v))->streamDownload();
    }

    private function fmt(float $n): string
    {
        return number_format($n, 0);
    }
}
