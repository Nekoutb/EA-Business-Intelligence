<?php

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Services\Demo\DemoFactsService;
use App\Services\Demo\DemoKpiStripBuilder;
use App\Services\Demo\DemoTenantSeeder;
use Illuminate\Http\Request;

/**
 * Demo sandbox surfaces — dashboard + 3 BMS modules (Revenue,
 * Receivables, P&L) running against demo_* tables for the current
 * demo tenant. ResolveDemoSession has already bound the tenant
 * to TenantContext by the time we get here.
 */
class DemoSandboxController extends Controller
{
    public function dashboard(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        return view('demo.sandbox.dashboard', [
            'kpi'    => $facts->dashboard($tenant),
            'tenant' => $tenant,
            'active' => 'dashboard',
        ]);
    }

    public function revenue(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $days   = max(7, min(365, (int) $request->query('days', 30)));
        $data   = $facts->revenueDetail($tenant, $days);
        return view('demo.sandbox.revenue', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forRevenue($data),
            'days'   => $days,
            'tenant' => $tenant,
            'active' => 'revenue',
        ]);
    }

    public function receivables(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $data   = $facts->receivables($tenant);
        return view('demo.sandbox.receivables', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forReceivables($data),
            'tenant' => $tenant,
            'active' => 'receivables',
        ]);
    }

    public function profitLoss(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $days   = max(30, min(365, (int) $request->query('days', 90)));
        $data   = $facts->profitLoss($tenant, $days);
        return view('demo.sandbox.profit-loss', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forProfitLoss($data),
            'days'   => $days,
            'tenant' => $tenant,
            'active' => 'profit_loss',
        ]);
    }

    public function trialBalance(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $data   = $facts->trialBalance($tenant);
        return view('demo.sandbox.trial-balance', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forTrialBalance($data),
            'tenant' => $tenant,
            'active' => 'trial_balance',
        ]);
    }

    public function glQuality(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $data   = $facts->glQuality($tenant);
        return view('demo.sandbox.gl-quality', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forGlQuality($data),
            'tenant' => $tenant,
            'active' => 'gl_quality',
        ]);
    }

    public function workingCapital(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $data   = $facts->workingCapital($tenant);
        return view('demo.sandbox.working-capital', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forWorkingCapital($data),
            'tenant' => $tenant,
            'active' => 'working_capital',
        ]);
    }

    public function billing(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $days   = max(7, min(365, (int) $request->query('days', 90)));
        $data   = $facts->billing($tenant, $days);
        return view('demo.sandbox.billing', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forBilling($data),
            'days'   => $days,
            'tenant' => $tenant,
            'active' => 'billing',
        ]);
    }

    public function customerInsights(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $data   = $facts->customerInsights($tenant);
        return view('demo.sandbox.customer-insights', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forCustomerInsights($data),
            'tenant' => $tenant,
            'active' => 'customer_insights',
        ]);
    }

    public function taxReturn(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $month  = $request->query('month');
        $monthStart = $month && preg_match('/^\d{4}-\d{2}$/', $month)
            ? \Carbon\Carbon::createFromFormat('Y-m', $month)->startOfMonth()
            : null;
        $data = $facts->taxReturn($tenant, $monthStart);
        return view('demo.sandbox.tax-return', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forTaxReturn($data),
            'tenant' => $tenant,
            'active' => 'tax_return',
        ]);
    }

    public function treasury(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $days   = max(7, min(365, (int) $request->query('days', 90)));
        $data   = $facts->treasury($tenant, $days);
        return view('demo.sandbox.treasury', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forTreasury($data),
            'days'   => $days,
            'tenant' => $tenant,
            'active' => 'treasury',
        ]);
    }

    public function payables(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $data   = $facts->payables($tenant);
        return view('demo.sandbox.payables', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forPayables($data),
            'tenant' => $tenant,
            'active' => 'payables',
        ]);
    }

    public function procurement(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $days   = max(7, min(365, (int) $request->query('days', 90)));
        $data   = $facts->procurement($tenant, $days);
        return view('demo.sandbox.procurement', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forProcurement($data),
            'days'   => $days,
            'tenant' => $tenant,
            'active' => 'procurement',
        ]);
    }

    public function fixedAssets(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $data   = $facts->fixedAssets($tenant);
        return view('demo.sandbox.fixed-assets', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forFixedAssets($data),
            'tenant' => $tenant,
            'active' => 'fixed_assets',
        ]);
    }

    public function inventory(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $data   = $facts->inventory($tenant);
        return view('demo.sandbox.inventory', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forInventory($data),
            'tenant' => $tenant,
            'active' => 'inventory',
        ]);
    }

    /** Reset the seed data on demand. */
    public function reset(Request $request, DemoTenantSeeder $seeder)
    {
        $tenant = $request->attributes->get('demo_tenant');
        try {
            $seeder->reseed($tenant);
            \App\Models\Demo\DemoCommandAudit::create([
                'tenant_id'         => $tenant->id,
                'source'            => \App\Models\Demo\DemoCommandAudit::SOURCE_SEED_RESET,
                'parsed_operations' => ['action' => 'reseed'],
                'status'            => \App\Models\Demo\DemoCommandAudit::STATUS_SUCCESS,
                'applied_at'        => now(),
            ]);
            return redirect('/demo/sandbox')->with('flash', [
                'type' => 'success',
                'msg'  => 'Demo data reset to seed. All your edits have been replaced with the original dataset.',
            ]);
        } catch (\Throwable $e) {
            return redirect('/demo/sandbox')->with('flash', [
                'type' => 'error',
                'msg'  => 'Reset failed: ' . $e->getMessage(),
            ]);
        }
    }

    // ── Financial Reporting (2) ────────────────────────────────────

    public function balanceSheet(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $data   = $facts->balanceSheet($tenant);
        return view('demo.sandbox.balance-sheet', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forBalanceSheet($data),
            'tenant' => $tenant,
            'active' => 'balance_sheet',
        ]);
    }

    public function ohadaStatements(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $data   = $facts->ohadaStatements($tenant);
        return view('demo.sandbox.ohada-statements', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forOhadaStatements($data),
            'tenant' => $tenant,
            'active' => 'ohada_statements',
        ]);
    }

    // ── Tax (5) ────────────────────────────────────────────────────

    public function taxDashboard(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $data   = $facts->taxDashboard($tenant);
        return view('demo.sandbox.tax-dashboard', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forTaxDashboard($data),
            'tenant' => $tenant,
            'active' => 'tax_dashboard',
        ]);
    }

    public function incomeTaxCompliance(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $data   = $facts->incomeTaxCompliance($tenant);
        return view('demo.sandbox.income-tax', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forIncomeTax($data),
            'tenant' => $tenant,
            'active' => 'income_tax',
        ]);
    }

    public function vatCompliance(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $data   = $facts->vatCompliance($tenant);
        return view('demo.sandbox.vat-compliance', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forVatCompliance($data),
            'tenant' => $tenant,
            'active' => 'vat_compliance',
        ]);
    }

    public function invoiceCompliance(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $data   = $facts->invoiceCompliance($tenant);
        return view('demo.sandbox.invoice-compliance', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forInvoiceCompliance($data),
            'tenant' => $tenant,
            'active' => 'invoice_compliance',
        ]);
    }

    public function returnReconciliation(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $month  = $request->query('month');
        $monthStart = $month && preg_match('/^\d{4}-\d{2}$/', $month)
            ? \Carbon\Carbon::createFromFormat('Y-m', $month)->startOfMonth()
            : null;
        $data = $facts->returnReconciliation($tenant, $monthStart);
        return view('demo.sandbox.return-reconciliation', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forReturnReconciliation($data),
            'tenant' => $tenant,
            'active' => 'return_reconciliation',
        ]);
    }

    // ── Planning (5) ───────────────────────────────────────────────

    public function macroEconomics(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $data   = $facts->macroEconomics();
        return view('demo.sandbox.macro-economics', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forMacroEconomics($data),
            'tenant' => $tenant,
            'active' => 'macro_economics',
        ]);
    }

    public function plForecast(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $data   = $facts->plForecast($tenant);
        return view('demo.sandbox.pl-forecast', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forPlForecast($data),
            'tenant' => $tenant,
            'active' => 'pl_forecast',
        ]);
    }

    public function cashflowForecast(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $data   = $facts->cashflowForecast($tenant);
        return view('demo.sandbox.cashflow-forecast', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forCashflowForecast($data),
            'tenant' => $tenant,
            'active' => 'cashflow_forecast',
        ]);
    }

    public function investmentAnalysis(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $data   = $facts->investmentAnalysis();
        return view('demo.sandbox.investment-analysis', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forInvestmentAnalysis($data),
            'tenant' => $tenant,
            'active' => 'investment_analysis',
        ]);
    }

    public function budgetUpload(Request $request, DemoFactsService $facts)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $data   = $facts->budgetGrid($tenant);
        return view('demo.sandbox.budget-upload', [
            'data'   => $data,
            'kpis'   => DemoKpiStripBuilder::forBudgetUpload($data),
            'tenant' => $tenant,
            'active' => 'budget_upload',
        ]);
    }

    // ── Coming-soon placeholders (9 modules, 1 generic handler) ────

    public function comingSoon(Request $request, string $slug)
    {
        $registry = [
            'cash_flow' => [
                'service'      => 'Financial Reporting',
                'label'        => 'Cash Flow Statement',
                'subtitle'     => 'OHADA-format cash-flow statement (operating, investing, financing).',
                'description'  => 'Reconciles net income to actual cash movement and segments cash by source — the third primary financial statement under OHADA.',
                'capabilities' => [
                    'Reconciliation from net profit to operating cash flow',
                    'Investing activities: capex acquisitions + disposals from the FA register',
                    'Financing activities: loan draws/repayments + dividends + capital changes',
                    'Period comparison: current vs prior year',
                    'PDF export ready for board pack',
                ],
            ],
            'post_retirement_benefits' => [
                'service'      => 'Financial Reporting',
                'label'        => 'Post-Retirement Benefits Calculator',
                'subtitle'     => 'IAS 19 / OHADA actuarial valuation of employee benefits provisions.',
                'description'  => 'Computes the present value of end-of-service indemnities, long-service awards, and post-retirement medical so the employee-benefits provision on the balance sheet is right.',
                'capabilities' => [
                    'Employee roster with hire date + grade + projected leave date',
                    'Salary growth + discount-rate assumptions',
                    'Projected Benefit Obligation (PBO) per employee + aggregate',
                    'Annual service cost + interest cost roll-forward',
                    'Sensitivity table: ±100bp discount-rate / salary-growth',
                ],
            ],
            'bank_reconciliation' => [
                'service'      => 'Financial Reporting',
                'label'        => 'Bank Reconciliation Tool',
                'subtitle'     => 'Match cleared bank statement lines against GL journal entries.',
                'description'  => 'Imports a bank-statement CSV (or via API where supported), auto-matches against the matching 521/571 journal entries, surfaces unreconciled stragglers, produces the period-end reconciliation statement.',
                'capabilities' => [
                    'CSV / MT940 statement import per bank account',
                    'Auto-match by amount + value-date with tolerance',
                    'Unreconciled lines queue for manual handling',
                    'Period-end reconciliation statement (book ↔ bank)',
                    'Audit log of every match decision',
                ],
            ],
            'budgeting' => [
                'service'      => 'Financial Planning',
                'label'        => 'Budgeting',
                'subtitle'     => 'Full annual budget build with per-account / per-cost-center allocation.',
                'description'  => 'Goes beyond the simple budget-upload screen to a full budget-build workflow with line-item version control, manager sign-off, and locked-baseline reporting.',
                'capabilities' => [
                    'Multi-version budget tracking (V1 → V2 → Locked)',
                    'Per-cost-center + per-account line items',
                    'Top-down vs. bottom-up reconciliation',
                    'Manager approval workflow',
                    'YTD actual vs locked budget on every BMS page',
                ],
            ],
            'forecasting' => [
                'service'      => 'Financial Planning',
                'label'        => 'Forecasting',
                'subtitle'     => 'Rolling 12-month forecast that updates as actuals land.',
                'description'  => 'Combines the locked budget baseline with statistical forecasting (trailing-window regression + seasonality) to produce a rolling 12-month outlook that updates monthly.',
                'capabilities' => [
                    'Trailing-window seasonality detection per revenue line',
                    'Manager overlay (manual adjustments per line, audit-logged)',
                    'Confidence bands (P10 / P50 / P90)',
                    'Forecast-vs-actual variance accumulator',
                ],
            ],
            'scenario_modeling' => [
                'service'      => 'Financial Planning',
                'label'        => 'Scenario Modeling',
                'subtitle'     => 'What-if analysis on the locked budget and forecast.',
                'description'  => 'Lets the CFO model the impact of price changes, cost shocks, FX moves, and growth slowdowns on the full P&L + cash position before committing.',
                'capabilities' => [
                    'Per-driver sensitivity (price ±, volume ±, COGS ±, FX ±)',
                    'Scenario comparison side-by-side',
                    'Cash-runway impact per scenario',
                    'Saved scenarios with audit trail',
                ],
            ],
            'payroll_processing' => [
                'service'      => 'Human Resources',
                'label'        => 'Payroll Processing',
                'subtitle'     => 'Gross-to-net payroll engine with full Cameroon statutory contributions.',
                'description'  => 'Runs the monthly payroll cycle end-to-end: gross salaries, statutory deductions, employer contributions, net pay, and posts the matching journal entries.',
                'capabilities' => [
                    'Employee roster with grade + salary + dependants',
                    'CNPS · IRPP · CAC · FNE · RAV · TDL · CFC computations',
                    'Bulletin de paie generation (PDF)',
                    'CNPS DPAE + IRPP DSF outputs',
                    'Auto-posting to 641/661/447/421 accounts',
                ],
            ],
            'product_revenue_tsd' => [
                'service'      => 'Business Intelligence',
                'label'        => 'Product Revenue (TSD)',
                'subtitle'     => 'Per-product revenue trajectory vs unit-price × volume budget.',
                'description'  => 'Compares scanned actuals to a per-product unit-price × monthly-volume budget. Specifically calibrated for logistics businesses moving containers / vehicles / wood / general cargo.',
                'capabilities' => [
                    'Per-product revenue line vs budget line',
                    'Decomposition into price effect vs volume effect',
                    'YTD / MTD switch with budget proration',
                    'Tracked-vs-all-budgeted distinction (incomplete catalogues)',
                    'PDF export with product-trend chart per line',
                ],
            ],
            'operations' => [
                'service'      => 'Business Intelligence',
                'label'        => 'Operations',
                'subtitle'     => 'Container & logistics analytics — scans, manifestes, EDI throughput.',
                'description'  => 'For businesses whose P&L revolves around moving physical units through a logistics pipeline (containers, shipments, deliveries) rather than holding stock.',
                'capabilities' => [
                    'Throughput by terminal / route / day',
                    'Cycle-time decomposition (waiting + processing + clearance)',
                    'Manifest reconciliation against EDI',
                    'Container utilisation + dwell-time analysis',
                ],
            ],
        ];

        $cfg = $registry[$slug] ?? [
            'service' => 'BMS', 'label' => 'Coming soon', 'subtitle' => null,
            'description' => null, 'capabilities' => [],
        ];

        return view('demo.sandbox.coming-soon', [
            'moduleService'      => $cfg['service'],
            'moduleLabel'        => $cfg['label'],
            'moduleSubtitle'     => $cfg['subtitle'],
            'moduleDescription'  => $cfg['description'],
            'moduleCapabilities' => $cfg['capabilities'],
            'active'             => $slug,
        ]);
    }
}
