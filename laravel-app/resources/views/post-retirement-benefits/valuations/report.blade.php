{{-- Print-friendly Mercer-style actuarial valuation report.
     Designed to be opened in the browser → Print → Save as PDF.
     Matches the 26-page Mercer Globeleq layout: Highlights → Basis
     of Valuation → 6 Appendices. The user signs the exported PDF
     externally (per their requirement).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Actuarial Valuation Report · {{ $valuation->valuation_date->format('d M Y') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; color: #1a202c; background: #f7fafc; }
        .mono { font-family: 'JetBrains Mono', ui-monospace, monospace; }
        .page {
            background: white;
            max-width: 800px;
            margin: 24px auto;
            padding: 56px 64px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            min-height: 95vh;
            page-break-after: always;
        }
        h1, h2, h3 { color: #0c4a6e; }
        h1 { font-weight: 700; font-size: 26px; letter-spacing: -0.02em; }
        h2 { font-weight: 600; font-size: 18px; margin-top: 24px; margin-bottom: 12px; }
        h3 { font-weight: 600; font-size: 14px; margin-top: 18px; margin-bottom: 8px; }
        table.disclosure { width: 100%; font-size: 12px; }
        table.disclosure th, table.disclosure td { padding: 5px 8px; }
        table.disclosure thead { background: #1e3a8a; color: white; }
        table.disclosure tbody tr:nth-child(odd) { background: #f8fafc; }
        .totals { font-weight: 700; border-top: 2px solid #1e293b; }
        .footer { font-size: 9px; color: #64748b; }
        @media print {
            body { background: white; }
            .page { box-shadow: none; margin: 0; padding: 40px 50px; max-width: 100%; }
            .no-print { display: none !important; }
            @page { size: A4; margin: 15mm; }
        }
    </style>
</head>
<body>

{{-- Action bar (no-print) --}}
<div class="no-print sticky top-0 bg-white border-b border-stone-200 shadow-sm z-10 py-2 px-6 flex items-center justify-between text-[12px]">
    <div class="text-slate-700">Print this page or use your browser's <strong>Print → Save as PDF</strong> to produce the deliverable.</div>
    <div class="flex items-center gap-2">
        <a href="/t/{{ request()->route('tenant') }}/post-retirement-benefits/valuations/{{ $valuation->id }}"
           class="text-slate-600 hover:text-slate-900">← Back</a>
        <a href="/t/{{ request()->route('tenant') }}/post-retirement-benefits/valuations/{{ $valuation->id }}/export/excel"
           class="font-semibold text-emerald-700 border border-emerald-300 rounded px-3 py-1.5 hover:bg-emerald-50">Download Excel</a>
        <button onclick="window.print()"
                class="font-semibold text-white bg-gradient-to-r from-rose-600 to-orange-600 rounded px-4 py-1.5">Print / Save as PDF</button>
    </div>
</div>

{{-- ───────────────── COVER PAGE ───────────────── --}}
<div class="page">
    <div class="flex items-baseline gap-3 text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-4">
        <span>Elite Advisors BMS</span>
        <span class="text-slate-400">·</span>
        <span>Internal Use</span>
    </div>
    <div class="mt-32">
        <h1 class="leading-tight">ACTUARIAL VALUATION REPORT</h1>
        <h1 class="leading-tight">AS AT {{ strtoupper($valuation->valuation_date->format('d F Y')) }}</h1>
    </div>

    <div class="mt-12 text-[14px] text-slate-700 leading-relaxed">
        <div class="font-semibold text-slate-900">{{ $valuation->tenant?->brand ?: $valuation->tenant?->name }}</div>
        <div class="text-[12px] text-slate-500 mono">Tenant slug · {{ $valuation->tenant?->slug }}</div>
    </div>

    <div class="mt-3 text-[11px] mono text-slate-500">
        Report issued on {{ now()->format('d F Y') }}
    </div>

    <div class="absolute bottom-12 left-16 right-16 text-[10px] text-slate-500 border-t border-stone-200 pt-3">
        Prepared by Elite Advisors Business Management Suite · OHADA SYSCOHADA REVISE 2017 · IAS 19 compliant<br>
        All figures expressed in XAF (Communauté Financière Africaine — CFA Franc BEAC) unless otherwise stated.
    </div>
</div>

{{-- ───────────────── 1. REPORT HIGHLIGHTS ───────────────── --}}
<div class="page">
    <div class="text-[10px] uppercase tracking-widest text-slate-500 mb-2">Section 1</div>
    <h1>Report Highlights</h1>

    <p class="text-[12.5px] text-slate-700 leading-relaxed mt-6">
        This report presents actuarial estimates of the post-retirement benefit obligation as at
        <strong>{{ $valuation->valuation_date->format('d F Y') }}</strong> for
        <strong>{{ $valuation->tenant?->brand ?: $valuation->tenant?->name }}</strong>, prepared under
        the <strong>Projected Unit Credit (PUC)</strong> method consistent with SYSCOHADA REVISE
        AUDC (2017) and IAS 19. All amounts are denominated in CFA Francs BEAC (XAF) unless
        otherwise indicated.
    </p>

    <h2>Summary of Results</h2>

    @php $prior = $valuation->priorValuation; @endphp
    <table class="disclosure mt-3">
        <thead>
            <tr>
                <th class="text-left"></th>
                <th class="text-right">Current period<br>{{ $valuation->valuation_date->format('d M Y') }}</th>
                <th class="text-right">Prior period<br>{{ $prior?->valuation_date?->format('d M Y') ?? '—' }}</th>
            </tr>
        </thead>
        <tbody>
            <tr><td>Net periodic benefit cost</td><td class="text-right mono">{{ number_format((float) $valuation->net_periodic_benefit_cost, 0) }}</td><td class="text-right mono">{{ $prior ? number_format((float) $prior->net_periodic_benefit_cost, 0) : '—' }}</td></tr>
            <tr><td>Benefit obligation</td><td class="text-right mono">{{ number_format((float) $valuation->pbo_closing, 0) }}</td><td class="text-right mono">{{ $prior ? number_format((float) $prior->pbo_closing, 0) : '—' }}</td></tr>
            <tr><td>Fair value of plan assets</td><td class="text-right mono">{{ number_format((float) $valuation->fair_value_assets, 0) }}</td><td class="text-right mono">{{ $prior ? number_format((float) $prior->fair_value_assets, 0) : '—' }}</td></tr>
            <tr class="totals"><td>Funded status</td><td class="text-right mono">({{ number_format(abs((float) $valuation->funded_status), 0) }})</td><td class="text-right mono">{{ $prior ? '(' . number_format(abs((float) $prior->funded_status), 0) . ')' : '—' }}</td></tr>
            <tr><td>Discount rate at year-end</td><td class="text-right mono">{{ number_format((float) $assumptions->discount_rate * 100, 2) }}%</td><td class="text-right mono">{{ $prior?->assumptionSet ? number_format((float) $prior->assumptionSet->discount_rate * 100, 2) . '%' : '—' }}</td></tr>
        </tbody>
    </table>

    <h2>Results by Employee Category</h2>
    @php
        $catMap = [
            'blue_collar' => ['Blue collars', $valuation->headcount_blue_collar],
            'supervisor'  => ['Supervisors',  $valuation->headcount_supervisor],
            'manager'     => ['Managers',     $valuation->headcount_manager],
        ];
        $pboByCategory = [];
        foreach ($catMap as $key => $_) {
            $pboByCategory[$key] = $lines->filter(fn ($l) => $l->employee && $l->employee->category === $key)->sum('pbo');
        }
        $totalCatPbo = array_sum($pboByCategory);
    @endphp
    <table class="disclosure mt-3">
        <thead>
            <tr>
                <th class="text-left">Category</th>
                <th class="text-right">Headcount</th>
                <th class="text-right">PBO (XAF)</th>
                <th class="text-right">% of total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($catMap as $key => [$label, $headcount])
                <tr>
                    <td>{{ $label }}</td>
                    <td class="text-right mono">{{ $headcount }}</td>
                    <td class="text-right mono">{{ number_format($pboByCategory[$key], 0) }}</td>
                    <td class="text-right mono">{{ $totalCatPbo > 0 ? number_format($pboByCategory[$key] / $totalCatPbo * 100, 1) : '0.0' }}%</td>
                </tr>
            @endforeach
            <tr class="totals">
                <td>Total benefit obligation</td>
                <td class="text-right mono">{{ $valuation->headcount_blue_collar + $valuation->headcount_supervisor + $valuation->headcount_manager }}</td>
                <td class="text-right mono">{{ number_format($totalCatPbo, 0) }}</td>
                <td class="text-right mono">100.0%</td>
            </tr>
        </tbody>
    </table>

    <h2>Changes in Plan Provisions</h2>
    <p class="text-[12px] text-slate-700">There were no changes in plan provisions, actuarial methods, nor accounting policies since the previous reporting period.</p>

    <h2>Changes in Actuarial Assumptions</h2>
    <p class="text-[12px] text-slate-700">
        The actuarial assumptions in effect on the valuation date are detailed in Appendix C.
        Discount rate set by reference to Cameroon government bond yields matching the plan's
        approximately {{ number_format((float) $valuation->duration_years, 1) }}-year liability duration.
    </p>
</div>

{{-- ───────────────── 2. BASIS OF VALUATION ───────────────── --}}
<div class="page">
    <div class="text-[10px] uppercase tracking-widest text-slate-500 mb-2">Section 2</div>
    <h1>Basis of Valuation</h1>

    <h2>Plan Data</h2>
    <p class="text-[12.5px] text-slate-700 leading-relaxed mt-3">
        Active employees covered by the applicable Collective Bargaining Agreement: <strong>{{ $valuation->headcount_blue_collar + $valuation->headcount_supervisor + $valuation->headcount_manager }}</strong> records.
        Data was extracted from the platform's employee register on the measurement date. We have reviewed
        the data for internal consistency and reasonableness. Expatriate and temporary employees not covered
        by the agreement are excluded from this valuation.
    </p>

    <h2>Actuarial Method</h2>
    <p class="text-[12.5px] text-slate-700 leading-relaxed mt-3">
        Benefit obligations are estimated using the <strong>Projected Unit Credit (PUC) method</strong>.
        Under this method each participant's benefits under the plan are attributed to years of service,
        taking into consideration future salary increases and the plan's benefit allocation formula. The
        estimated total indemnity to which each participant is expected to become entitled at separation
        is broken down into units, each associated with a year of past or future credited service.
    </p>
    <p class="text-[12.5px] text-slate-700 leading-relaxed mt-3">
        For each employee, the engine walks forward year by year from the measurement date to the
        retirement age, projecting the final monthly salary at the assumed escalation rate, computing
        the indemnity that would be paid for each possible exit (retirement, voluntary turnover, death),
        weighting by the probability of each event under the assumed demographic decrements, and
        discounting back to the measurement date at the assumed discount rate. The PBO is the present
        value of the indemnity attributable to past service alone (IAS 19 §72).
    </p>

    <h2>Valuation Procedures</h2>
    <table class="disclosure mt-3">
        <tbody>
            <tr><td class="font-semibold w-1/3">Funding status</td><td>{{ $assumptions->funded ? 'Funded — assets held against the obligation' : 'Unfunded — typical of Cameroon employers' }}</td></tr>
            <tr><td class="font-semibold">Discount rate setting</td><td>Set by reference to Cameroon government bond yields matching the {{ number_format((float) $valuation->duration_years, 1) }}-year liability duration</td></tr>
            <tr><td class="font-semibold">Projection of obligations</td><td>Valued as of {{ $valuation->valuation_date->format('d F Y') }}</td></tr>
            <tr><td class="font-semibold">Benefits included</td><td>All termination, retirement, and death indemnities under the applicable Collective Bargaining Agreement</td></tr>
            <tr><td class="font-semibold">Materiality</td><td>Notice-period payments and ancillary benefits (transport, water/electricity allowances) are not material to the long-term obligation and are not valued</td></tr>
        </tbody>
    </table>

    <h2>Accounting Policies</h2>
    <p class="text-[12.5px] text-slate-700 leading-relaxed mt-3">
        The net periodic benefit cost charged to profit or loss is budgeted using actuarial assumptions
        fixed at the start of each reporting period. It includes the service cost (current accrual),
        interest cost (unwinding of the discount on the opening PBO), and the expected return on plan
        assets (offset, for funded plans only). Posted to account
        <span class="mono">6814 — Dotations aux provisions pour engagements de retraite</span>,
        with the contra entry on account
        <span class="mono">197 — Provisions pour engagements de retraite et obligations similaires</span>.
    </p>
</div>

{{-- ───────────────── APPENDIX A — ROLL-FORWARD ───────────────── --}}
<div class="page">
    <div class="text-[10px] uppercase tracking-widest text-slate-500 mb-2">Appendix A</div>
    <h1>Disclosure Information — Change in Benefit Obligation</h1>

    <table class="disclosure mt-6">
        <tbody>
            <tr><td>1. Benefit obligation at beginning of period</td><td class="text-right mono">{{ number_format((float) $valuation->pbo_opening, 0) }}</td></tr>
            <tr><td>2. Service cost</td><td class="text-right mono">{{ number_format((float) $valuation->service_cost, 0) }}</td></tr>
            <tr><td>3. Interest cost</td><td class="text-right mono">{{ number_format((float) $valuation->interest_cost, 0) }}</td></tr>
            <tr><td>4. Plan amendments</td><td class="text-right mono">{{ number_format((float) $valuation->plan_amendments, 0) }}</td></tr>
            <tr><td>5. Benefits paid</td><td class="text-right mono">({{ number_format((float) $valuation->benefit_payments, 0) }})</td></tr>
            <tr><td>6. Actuarial (gain) / loss</td><td class="text-right mono">{{ number_format((float) $valuation->actuarial_gain_loss, 0) }}</td></tr>
            <tr><td>7. Settlements</td><td class="text-right mono">{{ number_format((float) $valuation->settlements, 0) }}</td></tr>
            <tr class="totals"><td>8. Benefit obligation at end of period</td><td class="text-right mono">{{ number_format((float) $valuation->pbo_closing, 0) }}</td></tr>
        </tbody>
    </table>

    <h2>Reconciliation of Funded Status</h2>
    <table class="disclosure mt-3">
        <tbody>
            <tr><td>1. Fair value of plan assets</td><td class="text-right mono">{{ number_format((float) $valuation->fair_value_assets, 0) }}</td></tr>
            <tr><td>2. Benefit obligation</td><td class="text-right mono">{{ number_format((float) $valuation->pbo_closing, 0) }}</td></tr>
            <tr class="totals"><td>3. Funded status (assets less benefit obligations)</td><td class="text-right mono">({{ number_format(abs((float) $valuation->funded_status), 0) }})</td></tr>
        </tbody>
    </table>
    <p class="text-[11px] text-slate-600 mt-3 italic">
        A negative funded status is recognized on the balance sheet as a long-term provision under
        account <span class="mono">197</span>. For unfunded plans (asset value = 0) the funded status
        equals the negative of the PBO closing.
    </p>
</div>

{{-- ───────────────── APPENDIX B — NPBC ───────────────── --}}
<div class="page">
    <div class="text-[10px] uppercase tracking-widest text-slate-500 mb-2">Appendix B</div>
    <h1>Components of Net Periodic Benefit Cost</h1>

    @php $era = (float) ($assumptions->expected_return_on_assets ?? 0); @endphp
    <table class="disclosure mt-6">
        <tbody>
            <tr><td>1. Service cost</td><td class="text-right mono">{{ number_format((float) $valuation->service_cost, 0) }}</td></tr>
            <tr><td>2. Interest cost</td><td class="text-right mono">{{ number_format((float) $valuation->interest_cost, 0) }}</td></tr>
            <tr><td>3. Expected return on plan assets</td><td class="text-right mono">({{ number_format($era * (float) $valuation->fair_value_assets, 0) }})</td></tr>
            <tr><td>4. Amortization of prior service cost</td><td class="text-right mono">—</td></tr>
            <tr><td>5. Amortization of net (gain) / loss</td><td class="text-right mono">—</td></tr>
            <tr><td>6. Curtailment (gain) / loss recognized</td><td class="text-right mono">—</td></tr>
            <tr><td>7. Settlement (gain) / loss recognized</td><td class="text-right mono">—</td></tr>
            <tr><td>8. Special termination benefit recognized</td><td class="text-right mono">—</td></tr>
            <tr class="totals"><td>9. Net periodic benefit cost</td><td class="text-right mono">{{ number_format((float) $valuation->net_periodic_benefit_cost, 0) }}</td></tr>
        </tbody>
    </table>

    <h2>SYSCOHADA Posting</h2>
    <table class="disclosure mt-3">
        <thead><tr><th>Account</th><th>Description</th><th class="text-right">Dr</th><th class="text-right">Cr</th></tr></thead>
        <tbody>
            <tr><td class="mono">6814</td><td>Dotations aux provisions pour engagements de retraite</td><td class="text-right mono">{{ number_format((float) $valuation->net_periodic_benefit_cost, 0) }}</td><td></td></tr>
            <tr><td class="mono">197</td><td>Provisions pour engagements de retraite et obligations similaires</td><td></td><td class="text-right mono">{{ number_format((float) $valuation->net_periodic_benefit_cost, 0) }}</td></tr>
        </tbody>
    </table>
</div>

{{-- ───────────────── APPENDIX C — ASSUMPTIONS ───────────────── --}}
<div class="page">
    <div class="text-[10px] uppercase tracking-widest text-slate-500 mb-2">Appendix C</div>
    <h1>Actuarial Assumptions</h1>

    <table class="disclosure mt-6">
        <thead><tr><th class="text-left">Assumption</th><th class="text-left">Valuation as of {{ $valuation->valuation_date->format('d M Y') }}</th><th class="text-left">Comments</th></tr></thead>
        <tbody>
            <tr><td>Discount rate</td><td class="mono">{{ number_format((float) $assumptions->discount_rate * 100, 2) }}%</td><td>Set by reference to Cameroon government bond yields matching the {{ number_format((float) $valuation->duration_years, 1) }}-year duration of the plan's liabilities, rounded to the nearest 0.25%.</td></tr>
            <tr><td>Inflation</td><td class="mono">{{ number_format((float) $assumptions->inflation_rate * 100, 2) }}%</td><td>Long-term expectation of CEMAC inflation, anchored to the EUR peg.</td></tr>
            <tr><td>Salary increases</td><td class="mono">{{ number_format((float) $assumptions->salary_increase_rate * 100, 2) }}%</td><td>Long-term annual escalation including expected promotion-driven progression.</td></tr>
            <tr>
                <td>Turnover</td>
                <td class="mono text-[11px]">
                    @foreach (($assumptions->turnover_bands ?: []) as $b)
                        Age {{ $b['from_age'] }}-{{ $b['to_age'] }}: {{ number_format(($b['rate'] ?? 0) * 100, 1) }}%<br>
                    @endforeach
                </td>
                <td>Annual probability of voluntary leave by age band. Calibrated to recent experience studies.</td>
            </tr>
            <tr><td>Mortality</td><td class="mono">{{ strtoupper(str_replace('_', ' ', $assumptions->mortality_table)) }}</td><td>Regional African insurance mortality table — CIMA standard for life and pension actuarial work.</td></tr>
            <tr><td>Retirement age</td><td class="mono">{{ $assumptions->retirement_age }}</td><td>Cameroon legal retirement age per the Code du Travail.</td></tr>
            <tr><td>Funded status</td><td class="mono">{{ $assumptions->funded ? 'Funded' : 'Unfunded' }}</td><td>{{ $assumptions->funded ? 'Plan assets held against the obligation.' : 'No assets held — full obligation recognized as a liability.' }}</td></tr>
            @if ($assumptions->funded)
                <tr><td>Expected return on assets</td><td class="mono">{{ number_format((float) $assumptions->expected_return_on_assets * 100, 2) }}%</td><td>Long-term assumption used to compute the offset to net periodic benefit cost.</td></tr>
            @endif
        </tbody>
    </table>

    @if (!empty($assumptions->notes))
        <h2>Notes on Assumption Setting</h2>
        <p class="text-[12px] text-slate-700 mt-3 whitespace-pre-line">{{ $assumptions->notes }}</p>
    @endif
</div>

{{-- ───────────────── APPENDIX D — METHODS ───────────────── --}}
<div class="page">
    <div class="text-[10px] uppercase tracking-widest text-slate-500 mb-2">Appendix D</div>
    <h1>Actuarial Methods</h1>

    <p class="text-[12.5px] text-slate-700 leading-relaxed mt-6">
        Benefit obligations are estimated using the <strong>Projected Unit Credit method</strong>.
        Under this method each participant's benefits under the plan are attributed to years of
        service, taking into consideration future salary increases and the plan's benefit allocation
        formula. Thus, the estimated total indemnity to which each participant is expected to become
        entitled at separation is broken down into units, each associated with a year of past or
        future credited service.
    </p>

    <h2>Calculation Sequence</h2>
    <ol class="text-[12px] text-slate-700 list-decimal pl-5 space-y-2 mt-3">
        <li>An individual's estimated attributed benefit for valuation purposes related to a particular separation date (retirement, leaving service, death) is the benefit described under the plan based on credited service as at the measurement date, but determined using the projected salary that would be used in the calculation of the benefit on the expected separation date.</li>
        <li>The benefit attributed to an individual's service during a plan year is the excess of the attributed benefit for valuation purposes at the end of the plan year over the attributed benefit for valuation purposes at the beginning of the plan year. Both attributed benefits are estimated from the same projections to the various anticipated separation dates.</li>
        <li>An individual's estimated benefit obligation is the present value of the attributed benefit for valuation purposes at the beginning of the plan year, and the service cost is the present value of the benefit attributed to the year of service in the plan year. If multiple decrements are used, the benefit obligation and the service cost for an individual are the sum of the component benefit obligations and service costs associated with the various anticipated separation dates. Such benefit obligations and service costs reflect the estimated attributed benefits and the probability of the individual separating on those dates.</li>
    </ol>

    <p class="text-[12.5px] text-slate-700 leading-relaxed mt-4">
        In all cases, the benefit obligation is the total present value of the individuals' attributed
        benefits for valuation purposes at the measurement date, and the service cost is the total
        present value of the individuals' benefits attributable to service during the year. If multiple
        decrements are used, the present values take into account the probability of the individual
        leaving employment at the various anticipated separation dates.
    </p>

    <h2>Plan Asset Valuation</h2>
    <p class="text-[12px] text-slate-700 mt-3">
        {{ $assumptions->funded ? "Plan assets are valued at fair value as at the measurement date." : "The plan is unfunded; no plan asset valuation is required." }}
    </p>
</div>

{{-- ───────────────── APPENDIX E — PLAN PROVISIONS ───────────────── --}}
<div class="page">
    <div class="text-[10px] uppercase tracking-widest text-slate-500 mb-2">Appendix E</div>
    <h1>Plan Provisions</h1>

    @php
        $firstEmp = $lines->first()?->employee;
        $cba = $firstEmp ? \App\Services\PostRetirementBenefits\CbaRegistry::forEmployee($firstEmp, $valuation->tenant) : null;
    @endphp

    @if ($cba)
        <p class="text-[12.5px] text-slate-700 mt-4">
            Indemnities payable under <strong>{{ $cba['name'] }}</strong>
            ({{ $cba['source_doc'] ?? 'sector CBA' }}). Eligibility threshold:
            {{ $cba['min_service_months'] }} months of service in the entity (except for death from
            occupational accident, where no service threshold applies).
        </p>
        <p class="text-[12px] text-slate-700 mt-3">
            <strong>Formula:</strong> Indemnity = average monthly salary of the last 12 months
            × Σ (band_rate × years_in_band).
        </p>

        @foreach ([
            'termination_brackets' => ['Termination by employer', 'When the employee is not entitled to a CNPS pension at separation.'],
            'retirement_brackets'  => ['Retirement (Indemnité de fin de carrière)', 'At normal retirement age (60) with at least one year of service.'],
            'death_brackets'       => ['Death (Indemnité de décès)', 'Paid to surviving heirs; doubled if death is consecutive to a work accident or occupational illness.'],
        ] as $key => [$label, $eligibility])
            <h3>{{ $label }}</h3>
            <p class="text-[11px] text-slate-600 italic">{{ $eligibility }}</p>
            <table class="disclosure mt-2">
                <thead><tr><th class="text-left">From year</th><th class="text-left">To year</th><th class="text-right">Rate (% of monthly salary × years in band)</th></tr></thead>
                <tbody>
                    @foreach ($cba[$key] ?? [] as $b)
                        <tr>
                            <td class="mono">{{ (int) $b['from'] }}</td>
                            <td class="mono">{{ $b['to'] === null ? 'and above' : (int) $b['to'] }}</td>
                            <td class="text-right mono">{{ number_format((float) $b['rate'] * 100, 0) }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach

        @if (!empty($cba['notes']))
            <h3>Notes</h3>
            <ul class="text-[11px] text-slate-700 list-disc pl-5 space-y-1">
                @foreach ($cba['notes'] as $n)
                    <li>{{ $n }}</li>
                @endforeach
            </ul>
        @endif
    @endif
</div>

{{-- ───────────────── APPENDIX F — PARTICIPANTS DATA ───────────────── --}}
<div class="page">
    <div class="text-[10px] uppercase tracking-widest text-slate-500 mb-2">Appendix F</div>
    <h1>Participants Data</h1>

    <p class="text-[12.5px] text-slate-700 mt-4">
        Active employees covered by the applicable Collective Bargaining Agreement as at
        {{ $valuation->valuation_date->format('d M Y') }} (salaries in XAF):
    </p>

    <table class="disclosure mt-4">
        <thead>
            <tr>
                <th class="text-left">Category</th>
                <th class="text-right">Headcount</th>
                <th class="text-right">Avg age</th>
                <th class="text-right">Avg service years</th>
                <th class="text-right">Avg monthly salary</th>
                <th class="text-right">Total monthly payroll</th>
            </tr>
        </thead>
        <tbody>
            @foreach (['blue_collar' => 'Blue collars', 'supervisor' => 'Supervisors', 'manager' => 'Managers'] as $cat => $label)
                @php
                    $catLines = $lines->filter(fn ($l) => $l->employee && $l->employee->category === $cat);
                    $count = $catLines->count();
                @endphp
                <tr>
                    <td>{{ $label }}</td>
                    <td class="text-right mono">{{ $count }}</td>
                    <td class="text-right mono">{{ $count > 0 ? number_format($catLines->avg('age_at_valuation'), 1) : '—' }}</td>
                    <td class="text-right mono">{{ $count > 0 ? number_format($catLines->avg('service_years_at_valuation'), 1) : '—' }}</td>
                    <td class="text-right mono">{{ $count > 0 ? number_format($catLines->avg('monthly_salary_at_valuation'), 0) : '—' }}</td>
                    <td class="text-right mono">{{ number_format($catLines->sum('monthly_salary_at_valuation'), 0) }}</td>
                </tr>
            @endforeach
            <tr class="totals">
                <td>Total</td>
                <td class="text-right mono">{{ $lines->count() }}</td>
                <td class="text-right mono">{{ $lines->count() > 0 ? number_format($lines->avg('age_at_valuation'), 1) : '—' }}</td>
                <td class="text-right mono">{{ $lines->count() > 0 ? number_format($lines->avg('service_years_at_valuation'), 1) : '—' }}</td>
                <td class="text-right mono">{{ $lines->count() > 0 ? number_format($lines->avg('monthly_salary_at_valuation'), 0) : '—' }}</td>
                <td class="text-right mono">{{ number_format($lines->sum('monthly_salary_at_valuation'), 0) }}</td>
            </tr>
        </tbody>
    </table>
</div>

{{-- ───────────────── APPENDIX M — CASH FLOWS ───────────────── --}}
<div class="page">
    <div class="text-[10px] uppercase tracking-widest text-slate-500 mb-2">Appendix M</div>
    <h1>Projected Cash Flows</h1>

    <p class="text-[12.5px] text-slate-700 mt-4">
        Expected benefit payments for the next 10 years from the measurement date. These are the
        probability-weighted projected indemnity outflows under the assumed demographic decrements.
    </p>

    @php
        $payments = (array) ($valuation->payload['expected_payments_by_year'] ?? []);
        ksort($payments);
        $next10 = array_slice($payments, 0, 10, true);
        $total10 = array_sum($next10);
    @endphp
    <table class="disclosure mt-4">
        <thead><tr><th class="text-left">Year</th><th class="text-right">Expected payment (XAF)</th></tr></thead>
        <tbody>
            @foreach ($next10 as $year => $amt)
                <tr><td class="mono">{{ $year }}</td><td class="text-right mono">{{ number_format((float) $amt, 0) }}</td></tr>
            @endforeach
            <tr class="totals"><td>Total · 10 year cumulative</td><td class="text-right mono">{{ number_format($total10, 0) }}</td></tr>
        </tbody>
    </table>

    @if ($discountSensitivity && $salarySensitivity)
        <h2>Sensitivity Analysis</h2>
        <p class="text-[12px] text-slate-700 mt-3">PBO under reasonably-possible parameter shifts:</p>

        <h3>Discount Rate</h3>
        <table class="disclosure">
            <thead><tr><th class="text-left">Shift</th><th class="text-right">Rate</th><th class="text-right">PBO</th><th class="text-right">Change</th></tr></thead>
            <tbody>
                @foreach ($discountSensitivity as $r)
                    <tr><td class="mono">{{ $r['label'] }}</td><td class="text-right mono">{{ number_format($r['discount_rate'] * 100, 2) }}%</td><td class="text-right mono">{{ number_format($r['pbo'], 0) }}</td><td class="text-right mono">{{ $r['change_pct'] >= 0 ? '+' : '' }}{{ number_format($r['change_pct'], 1) }}%</td></tr>
                @endforeach
                <tr class="totals"><td>Baseline</td><td class="text-right mono">{{ number_format((float) $assumptions->discount_rate * 100, 2) }}%</td><td class="text-right mono">{{ number_format((float) $valuation->pbo_closing, 0) }}</td><td class="text-right mono">—</td></tr>
            </tbody>
        </table>

        <h3>Salary Increase</h3>
        <table class="disclosure">
            <thead><tr><th class="text-left">Shift</th><th class="text-right">Rate</th><th class="text-right">PBO</th><th class="text-right">Change</th></tr></thead>
            <tbody>
                @foreach ($salarySensitivity as $r)
                    <tr><td class="mono">{{ $r['label'] }}</td><td class="text-right mono">{{ number_format($r['salary_increase'] * 100, 2) }}%</td><td class="text-right mono">{{ number_format($r['pbo'], 0) }}</td><td class="text-right mono">{{ $r['change_pct'] >= 0 ? '+' : '' }}{{ number_format($r['change_pct'], 1) }}%</td></tr>
                @endforeach
                <tr class="totals"><td>Baseline</td><td class="text-right mono">{{ number_format((float) $assumptions->salary_increase_rate * 100, 2) }}%</td><td class="text-right mono">{{ number_format((float) $valuation->pbo_closing, 0) }}</td><td class="text-right mono">—</td></tr>
            </tbody>
        </table>
    @endif
</div>

{{-- ───────────────── PROFESSIONAL NOTICES ───────────────── --}}
<div class="page">
    <div class="text-[10px] uppercase tracking-widest text-slate-500 mb-2">Notice</div>
    <h1>Professional Qualifications</h1>

    <p class="text-[12.5px] text-slate-700 leading-relaxed mt-6">
        This report has been prepared in accordance with generally accepted actuarial principles and
        procedures. The actuarial assumptions were selected by {{ $valuation->tenant?->brand ?: 'the entity' }}.
        Based on the participant data provided to the platform, we believe that the actuarial economic
        assumptions are reasonable for the purposes described in this report.
    </p>

    <p class="text-[12.5px] text-slate-700 leading-relaxed mt-4">
        Decisions about benefit changes, granting new benefits, investment policy, funding policy,
        benefit security, and / or benefit-related issues should not be made solely on the basis of
        this valuation, but only after careful consideration of alternative economic, financial,
        demographic, and societal factors.
    </p>

    <p class="text-[12.5px] text-slate-700 leading-relaxed mt-4">
        All parts of this report, including any documents incorporated by reference, are integral to
        understanding and explaining its contents; no part may be taken out of context, used, or
        relied upon without reference to the report as a whole.
    </p>

    <div class="mt-16">
        <div class="border-b border-slate-700 w-80"></div>
        <div class="text-[11px] mt-1 text-slate-600">Prepared by (name + qualification)</div>
    </div>

    <div class="mt-12">
        <div class="border-b border-slate-700 w-40"></div>
        <div class="text-[11px] mt-1 text-slate-600">Date</div>
    </div>

    <div class="mt-12 footer">
        Elite Advisors Business Management Suite · Cameroon
    </div>
</div>

</body>
</html>
