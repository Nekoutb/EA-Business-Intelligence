@extends('layouts.app')
@section('title', 'Post-Retirement Benefits')

@section('page-title', 'Post-Retirement Benefits')
@section('content')

@php
    // Resolve assumption values — saved row first, then platform defaults.
    $assumed = $assumptions ?: (object) [
        'discount_rate'             => $defaults['discount_rate'],
        'salary_increase_rate'      => $defaults['salary_increase_rate'],
        'inflation_rate'            => $defaults['inflation_rate'],
        'expected_return_on_assets' => $defaults['expected_return_on_assets'],
        'turnover_bands'            => $defaults['turnover_bands'],
        'mortality_table'           => $defaults['mortality_table'],
        'retirement_age'            => $defaults['retirement_age'],
        'funded'                    => $defaults['funded'],
        'fair_value_assets'         => 0,
        'valuation_date'            => null,
    ];
@endphp

@php $tenantSlug = request()->route('tenant'); @endphp

{{-- ───── Action bar ───── --}}
<div class="flex items-center justify-between mb-4">
    <div class="text-[11px] text-slate-500">
        Run a valuation to produce the {{ $totalHeadcount > 0 ? 'PBO + NPBC' : 'KPI' }} numbers and the Mercer-style report.
    </div>
    <div class="flex items-center gap-2">
        @if ($valuation)
            <a href="/t/{{ $tenantSlug }}/post-retirement-benefits/valuations"
               class="text-[11px] font-medium text-slate-700 border border-stone-300 rounded-lg px-3 py-2 hover:bg-stone-50">
                Valuation history
            </a>
        @endif
        @if ($totalHeadcount > 0)
            <form method="POST" action="/t/{{ $tenantSlug }}/post-retirement-benefits/valuations/run" class="inline">
                @csrf
                <button type="submit"
                        class="text-[11px] font-semibold text-white bg-gradient-to-r from-rose-600 to-orange-600 hover:from-rose-700 hover:to-orange-700 rounded-lg px-4 py-2 shadow inline-flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                    Run valuation
                </button>
            </form>
        @endif
    </div>
</div>

{{-- ───── KPI strip (4 cards) ───── --}}
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    @foreach ($kpis as $kpi)
        @php
            $statusColor = match($kpi['status']) {
                'green' => 'sage', 'amber' => 'warm', 'red' => 'coral', default => 'slate',
            };
            // Plain-language tooltips for actuarial jargon — matched loosely
            // on the label so it works regardless of exact wording, and
            // falls back to no hint if nothing matches.
            $lbl = strtolower($kpi['label'] ?? '');
            $kpiHint = match (true) {
                str_contains($lbl, 'pbo') || str_contains($lbl, 'obligation')
                    => 'Projected Benefit Obligation — the value today of the end-of-service indemnities the company is expected to owe its current staff.',
                str_contains($lbl, 'funded') || str_contains($lbl, 'funding')
                    => 'Funded status — assets set aside for the plan minus the obligation. Negative means the liability is unfunded.',
                str_contains($lbl, 'service cost')
                    => 'Service cost — the additional obligation employees earn from one more year of service.',
                str_contains($lbl, 'interest')
                    => 'Interest cost — the rise in the obligation as one year passes and the discount unwinds.',
                str_contains($lbl, 'duration')
                    => 'Duration — the average number of years until benefits fall due. Higher means more sensitive to the discount rate.',
                str_contains($lbl, 'employee') || str_contains($lbl, 'headcount')
                    => 'Number of active employees included in this valuation.',
                default => null,
            };
        @endphp
        <x-kpi :title="$kpi['label']"
               :value="$kpi['value_fmt']"
               :sub="$kpi['subtitle'] ?? ''"
               :hint="$kpiHint"
               :color="$statusColor" />
    @endforeach
</div>

{{-- ───── Status banner — no valuation yet ───── --}}
@if (!$valuation)
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-5 mb-6">
        <div class="flex items-start gap-3">
            <svg class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <div>
                <div class="text-sm font-semibold text-slate-900 mb-1">No valuation has been run yet</div>
                <p class="text-[13px] text-slate-700 leading-relaxed">
                    The Post-Retirement Benefits module is configured and ready, but no actuarial valuation has been
                    performed for this tenant. To get the SYSCOHADA-compliant Provision pour engagements de retraite
                    onto your balance sheet you need to:
                </p>
                <ol class="text-[12px] text-slate-700 list-decimal pl-5 mt-2 space-y-1">
                    <li>Import or add the employee roster (active employees covered by the CBA).</li>
                    <li>Confirm the assumptions (discount rate, salary increase, turnover, retirement age).</li>
                    <li>Click <strong>Run valuation</strong> — the Projected Unit Credit engine computes the PBO and net periodic benefit cost.</li>
                    <li>Finalize the result, then export the Mercer-style report (Word + Excel) for your auditor.</li>
                </ol>
                <p class="text-[11px] text-slate-500 mt-2 italic">Phases 2 and 3 (employee roster + calculation engine) ship in the next two PRs.</p>
            </div>
        </div>
    </div>
@endif

{{-- ───── Two-column section: Assumptions & Plan provisions ───── --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">

    {{-- ─── Actuarial assumptions ─── --}}
    <div class="panel p-5">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-semibold text-slate-900">Actuarial assumptions</h2>
            <div class="flex items-center gap-2">
                <span class="text-[10px] uppercase tracking-widest font-semibold {{ $assumptions ? 'text-emerald-700 bg-emerald-50' : 'text-slate-500 bg-slate-100' }} px-2 py-0.5 rounded">
                    {{ $assumptions ? 'tenant override' : 'platform defaults' }}
                </span>
                <a href="/t/{{ $tenantSlug }}/post-retirement-benefits/assumptions"
                   class="text-[11px] font-semibold text-indigo-600 hover:text-indigo-800">Edit →</a>
            </div>
        </div>
        <table class="w-full text-[12.5px]">
            <tbody class="divide-y divide-stone-100">
                <tr>
                    <td class="py-2 pr-3 text-slate-600">Discount rate</td>
                    <td class="py-2 text-right mono font-semibold text-slate-900">{{ number_format($assumed->discount_rate * 100, 2) }}%</td>
                </tr>
                <tr>
                    <td class="py-2 pr-3 text-slate-600">Salary increase rate</td>
                    <td class="py-2 text-right mono text-slate-900">{{ number_format($assumed->salary_increase_rate * 100, 2) }}%</td>
                </tr>
                <tr>
                    <td class="py-2 pr-3 text-slate-600">Inflation</td>
                    <td class="py-2 text-right mono text-slate-900">{{ number_format($assumed->inflation_rate * 100, 2) }}%</td>
                </tr>
                <tr>
                    <td class="py-2 pr-3 text-slate-600">Mortality table</td>
                    <td class="py-2 text-right mono text-slate-900">{{ strtoupper(str_replace('_', ' ', $assumed->mortality_table)) }}</td>
                </tr>
                <tr>
                    <td class="py-2 pr-3 text-slate-600">Retirement age</td>
                    <td class="py-2 text-right mono text-slate-900">{{ $assumed->retirement_age }}</td>
                </tr>
                <tr>
                    <td class="py-2 pr-3 text-slate-600">Funded?</td>
                    <td class="py-2 text-right mono {{ $assumed->funded ? 'text-emerald-700' : 'text-slate-500' }}">
                        {{ $assumed->funded ? 'Yes' : 'No (unfunded)' }}
                    </td>
                </tr>
                @if ($assumed->funded)
                    <tr>
                        <td class="py-2 pr-3 text-slate-600">Expected return on assets</td>
                        <td class="py-2 text-right mono text-slate-900">{{ number_format($assumed->expected_return_on_assets * 100, 2) }}%</td>
                    </tr>
                    <tr>
                        <td class="py-2 pr-3 text-slate-600">Fair value of plan assets</td>
                        <td class="py-2 text-right mono text-slate-900">{{ number_format((float) $assumed->fair_value_assets, 0) }} XAF</td>
                    </tr>
                @endif
            </tbody>
        </table>
        <p class="text-[10.5px] text-slate-500 mt-3 leading-snug">
            Discount rate is set by reference to Cameroon government bond yields matching the plan duration
            (typically ~13 years). Mortality uses CIMA F (regional African insurance table).
            Retirement age is fixed at 60 (Cameroon legal age).
        </p>
        <div class="mt-3">
            <span class="text-[10.5px] text-slate-500">Turnover by age:</span>
            <span class="text-[11px] mono text-slate-700 ml-1">
                @foreach ((array) $assumed->turnover_bands as $band)
                    {{ (int)($band['from_age'] ?? 0) }}-{{ (int)($band['to_age'] ?? 0) }}:{{ number_format(($band['rate'] ?? 0) * 100, 1) }}%
                    @if (!$loop->last) · @endif
                @endforeach
            </span>
        </div>
    </div>

    {{-- ─── Plan provisions (CBA in use) ─── --}}
    <div class="panel p-5">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-semibold text-slate-900">Plan provisions</h2>
            @if ($activeCba)
                <span class="text-[10px] uppercase tracking-widest font-semibold text-rose-700 bg-rose-50 px-2 py-0.5 rounded">
                    {{ $activeCba['source'] === 'db' ? 'tenant-customized' : 'platform CBA' }}
                </span>
            @endif
        </div>

        @if ($activeCba)
            <div class="mb-3">
                <div class="text-[14px] font-semibold text-slate-900">{{ $activeCba['name'] }}</div>
                <div class="text-[11px] text-slate-500 mono mt-0.5">{{ $activeCba['source_doc'] ?? $activeCba['key'] }}</div>
            </div>

            {{-- Bracket schedules side by side. The three scenarios share the same column structure: years range + rate. --}}
            <div class="grid grid-cols-3 gap-3 text-[11.5px]">
                @foreach (['termination_brackets' => 'Termination', 'retirement_brackets' => 'Retirement', 'death_brackets' => 'Death'] as $key => $label)
                    <div>
                        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">{{ $label }}</div>
                        <table class="w-full">
                            <tbody>
                                @foreach ((array) ($activeCba[$key] ?? []) as $b)
                                    <tr class="border-b border-stone-100 last:border-0">
                                        <td class="py-1 mono text-slate-600">
                                            {{ (int) $b['from'] }}-{{ $b['to'] === null ? '+' : (int) $b['to'] }}y
                                        </td>
                                        <td class="py-1 text-right mono font-semibold text-slate-900">{{ number_format((float) $b['rate'] * 100, 0) }}%</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
            </div>

            <p class="text-[10.5px] text-slate-500 mt-3 leading-snug">
                Indemnity = average monthly salary of the last 12 months × Σ (band_rate × years_in_band). Eligibility threshold {{ $activeCba['min_service_months'] }} months of service.
            </p>

            @if (!empty($activeCba['notes']))
                <ul class="mt-3 text-[10.5px] text-slate-500 list-disc pl-4 space-y-0.5">
                    @foreach ($activeCba['notes'] as $note)
                        <li>{{ $note }}</li>
                    @endforeach
                </ul>
            @endif
        @else
            <div class="text-sm text-slate-500 italic">No CBA configured yet.</div>
        @endif

        @if (count($cbas) > 1)
            <div class="mt-4 pt-3 border-t border-stone-100">
                <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1.5">{{ count($cbas) }} CBA{{ count($cbas) !== 1 ? 's' : '' }} available</div>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($cbas as $cba)
                        <span class="text-[10.5px] mono px-2 py-0.5 rounded border {{ $activeCba && $activeCba['key'] === $cba['key'] ? 'border-rose-300 bg-rose-50 text-rose-700' : 'border-stone-200 bg-stone-50 text-slate-600' }}">
                            {{ $cba['key'] }}
                        </span>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>

{{-- ───── Participants snapshot ───── --}}
@php $tenantSlug = request()->route('tenant'); @endphp
<div class="panel p-5 mb-6">
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-sm font-semibold text-slate-900">Participants</h2>
        <div class="flex items-center gap-3">
            <span class="text-[11px] text-slate-500">{{ $totalHeadcount }} active · monthly payroll {{ number_format($totalPayroll, 0) }} XAF</span>
            <a href="/t/{{ $tenantSlug }}/post-retirement-benefits/employees"
               class="text-[11px] font-semibold text-rose-700 hover:text-rose-900 border border-rose-300 hover:border-rose-500 rounded-lg px-3 py-1.5 inline-flex items-center gap-1.5">
                Manage employees →
            </a>
        </div>
    </div>
    <table class="w-full text-[12.5px]">
        <thead class="text-[10px] uppercase tracking-widest text-slate-500 border-b border-stone-200">
            <tr>
                <th class="py-2 pr-3 text-left font-semibold">Category</th>
                <th class="py-2 px-3 text-right font-semibold">Headcount</th>
                <th class="py-2 px-3 text-right font-semibold">Monthly payroll (XAF)</th>
                <th class="py-2 pl-3 text-right font-semibold">% of total</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
            @foreach (['blue_collar' => 'Blue collars', 'supervisor' => 'Supervisors', 'manager' => 'Managers'] as $key => $label)
                <tr>
                    <td class="py-2 pr-3 text-slate-700 font-medium">{{ $label }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-900">{{ $headcount[$key] }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-900">{{ number_format($payroll[$key], 0) }}</td>
                    <td class="py-2 pl-3 text-right mono text-slate-500">
                        {{ $totalPayroll > 0 ? number_format($payroll[$key] / $totalPayroll * 100, 1) : '0.0' }}%
                    </td>
                </tr>
            @endforeach
            <tr class="border-t-2 border-stone-300 font-semibold">
                <td class="py-2 pr-3 text-slate-900">Total</td>
                <td class="py-2 px-3 text-right mono text-slate-900">{{ $totalHeadcount }}</td>
                <td class="py-2 px-3 text-right mono text-slate-900">{{ number_format($totalPayroll, 0) }}</td>
                <td class="py-2 pl-3 text-right mono text-slate-500">100.0%</td>
            </tr>
        </tbody>
    </table>

    @if ($totalHeadcount === 0)
        <div class="mt-3 text-[11px] text-slate-500 italic">No employees recorded yet. The next PR ships the employee editor and CSV import.</div>
    @endif
</div>

{{-- ───── Latest valuation summary (only if present) ───── --}}
@if ($valuation)
    <div class="panel p-5 mb-6">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-semibold text-slate-900">Latest valuation · {{ $valuation->valuation_date->format('d M Y') }}</h2>
            <span class="text-[10px] uppercase tracking-widest font-semibold {{ $valuation->isFinalized() ? 'text-emerald-700 bg-emerald-50' : 'text-amber-700 bg-amber-50' }} px-2 py-0.5 rounded">
                {{ $valuation->isFinalized() ? 'finalized' : 'draft' }}
            </span>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-[12.5px]">
            <div class="bg-stone-50 rounded-lg p-3">
                <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">PBO closing</div>
                <div class="text-base mono font-semibold text-slate-900 mt-0.5">{{ number_format((float) $valuation->pbo_closing, 0) }}</div>
                <div class="text-[10px] text-slate-500 mono">XAF</div>
            </div>
            <div class="bg-stone-50 rounded-lg p-3">
                <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Funded status</div>
                <div class="text-base mono font-semibold {{ $valuation->funded_status >= 0 ? 'text-emerald-700' : 'text-rose-700' }} mt-0.5">
                    {{ number_format((float) $valuation->funded_status, 0) }}
                </div>
                <div class="text-[10px] text-slate-500 mono">{{ $valuation->fair_value_assets > 0 ? 'funded' : 'unfunded' }}</div>
            </div>
            <div class="bg-stone-50 rounded-lg p-3">
                <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Service cost</div>
                <div class="text-base mono font-semibold text-slate-900 mt-0.5">{{ number_format((float) $valuation->service_cost, 0) }}</div>
                <div class="text-[10px] text-slate-500 mono">accrual</div>
            </div>
            <div class="bg-stone-50 rounded-lg p-3">
                <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Interest cost</div>
                <div class="text-base mono font-semibold text-slate-900 mt-0.5">{{ number_format((float) $valuation->interest_cost, 0) }}</div>
                <div class="text-[10px] text-slate-500 mono">unwinding</div>
            </div>
        </div>

        <p class="text-[10.5px] text-slate-500 mt-3 leading-snug">
            SYSCOHADA posting: <span class="mono">Dr 6814 (Dotations aux provisions pour engagements de retraite)</span> /
            <span class="mono">Cr 197 (Provisions pour engagements de retraite)</span> for the net periodic benefit cost
            of {{ number_format((float) $valuation->net_periodic_benefit_cost, 0) }} XAF.
        </p>
    </div>
@endif

{{-- ───── Methodology footer ───── --}}
<div class="bg-slate-900 text-slate-300 rounded-xl p-5 text-[12.5px] leading-relaxed">
    <div class="text-[11px] uppercase tracking-widest text-amber-400 font-semibold mb-2">Methodology</div>
    <p>
        Valuations use the <strong class="text-white">Projected Unit Credit (PUC)</strong> method, identical to IAS 19 and
        accepted under SYSCOHADA AUDC (2017). For each active employee we project the final monthly salary forward
        to each possible separation date (retirement, voluntary leave, death, involuntary termination), compute the
        indemnity that would be owed under the applicable CBA, discount it back to the measurement date using the
        BEAC-bond-matched discount rate, and weight by the probability of each separation event (mortality from
        CIMA tables × turnover by age band).
    </p>
    <p class="mt-2">
        The resulting <strong class="text-white">Projected Benefit Obligation (PBO)</strong> is recognized as a long-term
        liability on account <span class="mono text-amber-200">197</span>. The annual change in PBO (service cost
        + interest cost − benefit payments ± actuarial gain/loss) is the
        <strong class="text-white">net periodic benefit cost</strong> charged to P&amp;L through account
        <span class="mono text-amber-200">6814</span>.
    </p>
    <p class="mt-2 text-slate-400 text-[11px]">
        Reference sources: Cameroon Code du Travail (Loi 92-007), sector Collective Bargaining Agreements,
        SYSCOHADA REVISE (2017) — Acte Uniforme du Droit Comptable. The Mercer-style actuarial report exporter
        ships in Phase 5.
    </p>
</div>

@endsection
