<?php

namespace App\Http\Controllers\PostRetirementBenefits;

use App\Http\Controllers\Controller;
use App\Models\PostRetirementBenefits\AssumptionSet;
use App\Models\PostRetirementBenefits\Employee;
use App\Models\PostRetirementBenefits\Valuation;
use App\Services\PostRetirementBenefits\CbaRegistry;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Landing page for the Post-Retirement Benefits module.
 *
 * Phase 1: shows the KPI strip (PBO, funded status, NPBC, headcount),
 * the latest valuation summary, the assumption snapshot, the
 * applicable CBA, and a list of participants. No calculation engine
 * yet — those KPIs are pulled from the most recent finalized
 * Valuation row, or show as "—" with a call-to-action when none
 * exists.
 *
 * Future phases add: assumption editor, employee CRUD, run-valuation
 * action, Word/Excel report exporters.
 */
class PostRetirementBenefitsController extends Controller
{
    public function index(Request $request)
    {
        $tenant = app(TenantContext::class)->has()
            ? app(TenantContext::class)->tenant()
            : null;

        // Latest finalized valuation for this tenant (or latest draft
        // if no finalized exists yet) — used for the KPI strip.
        $valuation = null;
        $priorValuation = null;
        if ($tenant) {
            $valuation = Valuation::where('tenant_id', $tenant->id)
                ->orderByDesc('valuation_date')
                ->orderByRaw("CASE WHEN status = 'finalized' THEN 0 ELSE 1 END")
                ->first();
            if ($valuation && $valuation->prior_valuation_id) {
                $priorValuation = Valuation::find($valuation->prior_valuation_id);
            }
        }

        // Active employee headcount + monthly payroll, broken down
        // by category — used for the "Participants" snapshot block.
        $headcount = ['blue_collar' => 0, 'supervisor' => 0, 'manager' => 0];
        $payroll   = ['blue_collar' => 0, 'supervisor' => 0, 'manager' => 0];
        if ($tenant) {
            Employee::where('tenant_id', $tenant->id)
                ->where('active', true)
                ->get(['category', 'monthly_salary'])
                ->each(function ($e) use (&$headcount, &$payroll) {
                    $headcount[$e->category] = ($headcount[$e->category] ?? 0) + 1;
                    $payroll[$e->category]   = ($payroll[$e->category]   ?? 0) + (float) $e->monthly_salary;
                });
        }

        // Most recent assumption set, or platform defaults if none
        // saved yet — drives the "Assumptions" panel.
        $assumptions = null;
        if ($tenant) {
            $assumptions = AssumptionSet::where('tenant_id', $tenant->id)
                ->orderByDesc('valuation_date')
                ->first();
        }
        $defaults = config('post_retirement_assumptions');

        // List of CBAs available to the tenant (config + DB overrides),
        // and the one in use by the largest share of active employees.
        $cbas = CbaRegistry::listForTenant($tenant);
        $activeCbaKey = null;
        if ($tenant) {
            $activeCbaKey = Employee::where('tenant_id', $tenant->id)
                ->where('active', true)
                ->selectRaw('cba_key, count(*) as n')
                ->groupBy('cba_key')
                ->orderByDesc('n')
                ->value('cba_key');
        }
        $activeCba = $activeCbaKey
            ? collect($cbas)->firstWhere('key', $activeCbaKey)
            : (collect($cbas)->firstWhere('key', 'commerce') ?: ($cbas[0] ?? null));

        // KPI cards — matches the standard module-page strip used
        // across the BMS. Status colours follow the rest of the suite.
        $kpis = $this->buildKpis($valuation, $priorValuation, $headcount);

        return view('post-retirement-benefits.index', [
            'tenant'         => $tenant,
            'valuation'      => $valuation,
            'priorValuation' => $priorValuation,
            'assumptions'    => $assumptions,
            'defaults'       => $defaults,
            'cbas'           => $cbas,
            'activeCba'      => $activeCba,
            'headcount'      => $headcount,
            'payroll'        => $payroll,
            'totalHeadcount' => array_sum($headcount),
            'totalPayroll'   => array_sum($payroll),
            'kpis'           => $kpis,
            'active'         => 'post_retirement_benefits',
        ]);
    }

    /**
     * Build the 4-card traffic-light KPI strip.
     *
     * - PBO total → "na" if no valuation, green if down YoY,
     *   amber if up <10% YoY, red if up >10%
     * - Funded status → green > 0, red < 0, amber ≈ 0
     * - NPBC → simply informational (always blue/na)
     * - Active employees → just a count
     */
    private function buildKpis(?Valuation $v, ?Valuation $prior, array $headcount): array
    {
        if (!$v) {
            return [
                ['label' => 'PBO total',          'value_fmt' => '—',                                         'status' => 'na', 'subtitle' => 'no valuation yet',                                  'threshold' => 'Run your first valuation'],
                ['label' => 'Funded status',      'value_fmt' => '—',                                         'status' => 'na', 'subtitle' => 'assets − obligation',                               'threshold' => 'Green > 0, red < 0'],
                ['label' => 'Net periodic cost',  'value_fmt' => '—',                                         'status' => 'na', 'subtitle' => 'annual P&L charge',                                 'threshold' => null],
                ['label' => 'Active participants','value_fmt' => (string) array_sum($headcount),              'status' => array_sum($headcount) > 0 ? 'green' : 'amber', 'subtitle' => 'currently in plan', 'threshold' => null],
            ];
        }

        $pbo = (float) $v->pbo_closing;
        $priorPbo = $prior ? (float) $prior->pbo_closing : null;
        $pboStatus = 'na';
        $pboSubtitle = '';
        if ($priorPbo !== null && $priorPbo > 0) {
            $pct = (($pbo - $priorPbo) / $priorPbo) * 100;
            $pboStatus = $pct < 0 ? 'green' : ($pct < 10 ? 'amber' : 'red');
            $pboSubtitle = ($pct >= 0 ? '+' : '') . number_format($pct, 1) . '% vs prior';
        } else {
            $pboSubtitle = 'first valuation';
            $pboStatus = 'amber';
        }

        $fundedStatus = (float) $v->funded_status;
        $fsStatus = $fundedStatus > 0 ? 'green' : ($fundedStatus > -1 ? 'amber' : 'red');

        return [
            ['label' => 'PBO total',          'value_fmt' => number_format($pbo, 0) . ' XAF',                  'status' => $pboStatus,  'subtitle' => $pboSubtitle,                                       'threshold' => 'Green = decreasing YoY · red = +10%+'],
            ['label' => 'Funded status',      'value_fmt' => number_format($fundedStatus, 0) . ' XAF',         'status' => $fsStatus,   'subtitle' => 'assets − PBO (account 197)',                       'threshold' => 'Green > 0, red < 0'],
            ['label' => 'Net periodic cost',  'value_fmt' => number_format((float) $v->net_periodic_benefit_cost, 0) . ' XAF', 'status' => 'amber', 'subtitle' => 'annual P&L charge (account 6814)',                 'threshold' => null],
            ['label' => 'Active participants','value_fmt' => (string) array_sum($headcount),                   'status' => 'green',     'subtitle' => 'currently in plan',                                'threshold' => null],
        ];
    }
}
