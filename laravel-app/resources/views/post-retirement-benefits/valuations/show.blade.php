@extends('layouts.app')
@section('title', 'Valuation · ' . $valuation->valuation_date->format('d M Y'))

@section('page-title', 'Valuation · ' . $valuation->valuation_date->format('d M Y'))
@section('content')

@php
    $tenantSlug = request()->route('tenant');
    $payByYear  = (array) ($valuation->payload['expected_payments_by_year'] ?? []);
    $byDecrement = (array) ($valuation->payload['pbo_by_decrement'] ?? []);
@endphp

<div class="flex items-center justify-between mb-4">
    <a href="/t/{{ $tenantSlug }}/post-retirement-benefits"
       class="text-[12px] text-slate-600 hover:text-slate-900 inline-flex items-center gap-1.5">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        Back to Post-Retirement Benefits
    </a>
    <div class="flex items-center gap-2">
        <span class="text-[10px] uppercase tracking-widest font-semibold {{ $valuation->isFinalized() ? 'text-emerald-700 bg-emerald-50 border-emerald-200' : 'text-amber-700 bg-amber-50 border-amber-200' }} px-2 py-1 rounded border">
            {{ $valuation->status }}
        </span>
        @if (!$valuation->isFinalized())
            <form method="POST" action="/t/{{ $tenantSlug }}/post-retirement-benefits/valuations/{{ $valuation->id }}/finalize"
                  onsubmit="return confirm('Finalize this valuation? Any prior finalized run for this date will be demoted to draft.')">
                @csrf
                <button type="submit"
                        class="text-[11px] font-semibold text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg px-4 py-2 shadow">
                    Finalize ↑
                </button>
            </form>
        @endif
    </div>
</div>

{{-- Headline numbers --}}
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
    <div class="panel p-4 border-l-4 border-stone-300">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">PBO closing</div>
        <div class="text-xl font-bold mono text-slate-900 mt-1">{{ number_format((float) $valuation->pbo_closing, 0) }}</div>
        <div class="text-[10px] text-slate-500 mt-0.5">XAF · projected benefit obligation</div>
    </div>
    <div class="panel p-4 border-l-4 {{ $valuation->funded_status >= 0 ? 'border-emerald-400' : 'border-rose-400' }}">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Funded status</div>
        <div class="text-xl font-bold mono {{ $valuation->funded_status >= 0 ? 'text-emerald-700' : 'text-rose-700' }} mt-1">
            {{ number_format((float) $valuation->funded_status, 0) }}
        </div>
        <div class="text-[10px] text-slate-500 mt-0.5">assets {{ number_format((float) $valuation->fair_value_assets, 0) }} − PBO</div>
    </div>
    <div class="panel p-4 border-l-4 border-warm-400">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Net periodic cost</div>
        <div class="text-xl font-bold mono text-slate-900 mt-1">{{ number_format((float) $valuation->net_periodic_benefit_cost, 0) }}</div>
        <div class="text-[10px] text-slate-500 mt-0.5">P&amp;L charge · account 6814</div>
    </div>
    <div class="panel p-4 border-l-4 border-stone-300">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Duration</div>
        <div class="text-xl font-bold mono text-slate-900 mt-1">{{ number_format((float) $valuation->duration_years, 1) }}y</div>
        <div class="text-[10px] text-slate-500 mt-0.5">expected payment timing</div>
    </div>
</div>

{{-- Roll-forward (Mercer Appendix A) --}}
<div class="panel p-5 mb-5">
    <h2 class="text-sm font-semibold text-slate-900 mb-3">Change in benefit obligation · roll-forward</h2>
    <table class="w-full text-[12.5px]">
        <tbody class="divide-y divide-stone-100">
            <tr>
                <td class="py-2 pr-3 text-slate-600">1. Benefit obligation at beginning of period</td>
                <td class="py-2 text-right mono text-slate-900">{{ number_format((float) $valuation->pbo_opening, 0) }}</td>
            </tr>
            <tr>
                <td class="py-2 pr-3 text-slate-600">2. Service cost</td>
                <td class="py-2 text-right mono text-slate-900">{{ number_format((float) $valuation->service_cost, 0) }}</td>
            </tr>
            <tr>
                <td class="py-2 pr-3 text-slate-600">3. Interest cost</td>
                <td class="py-2 text-right mono text-slate-900">{{ number_format((float) $valuation->interest_cost, 0) }}</td>
            </tr>
            <tr>
                <td class="py-2 pr-3 text-slate-600">4. Benefit payments</td>
                <td class="py-2 text-right mono text-slate-900">({{ number_format((float) $valuation->benefit_payments, 0) }})</td>
            </tr>
            <tr>
                <td class="py-2 pr-3 text-slate-600">5. Actuarial (gain) / loss</td>
                <td class="py-2 text-right mono {{ $valuation->actuarial_gain_loss >= 0 ? 'text-rose-700' : 'text-emerald-700' }}">
                    {{ number_format((float) $valuation->actuarial_gain_loss, 0) }}
                </td>
            </tr>
            <tr>
                <td class="py-2 pr-3 text-slate-600">6. Plan amendments</td>
                <td class="py-2 text-right mono text-slate-900">{{ number_format((float) $valuation->plan_amendments, 0) }}</td>
            </tr>
            <tr>
                <td class="py-2 pr-3 text-slate-600">7. Settlements</td>
                <td class="py-2 text-right mono text-slate-900">{{ number_format((float) $valuation->settlements, 0) }}</td>
            </tr>
            <tr class="border-t-2 border-stone-300">
                <td class="py-2 pr-3 font-bold text-slate-900">8. Benefit obligation at end of period</td>
                <td class="py-2 text-right mono font-bold text-slate-900">{{ number_format((float) $valuation->pbo_closing, 0) }}</td>
            </tr>
        </tbody>
    </table>
    <p class="text-[10.5px] text-slate-500 mt-3 leading-snug">
        For Phase 3 of the build, benefit payments are not yet split out from actuarial gain/loss
        (Phase 4 adds the payment entry + experience-vs-expected decomposition).
    </p>
</div>

{{-- PBO by decrement (Mercer split) --}}
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-5">
    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">PBO by decrement</h2>
        @php
            $totalDecrement = ($byDecrement['retirement'] ?? 0) + ($byDecrement['termination'] ?? 0) + ($byDecrement['death'] ?? 0);
        @endphp
        <table class="w-full text-[12.5px]">
            <tbody class="divide-y divide-stone-100">
                @foreach (['retirement' => 'Retirement', 'termination' => 'Turnover (involuntary)', 'death' => 'Death'] as $k => $label)
                    <tr>
                        <td class="py-2 pr-3 text-slate-700">{{ $label }}</td>
                        <td class="py-2 text-right mono text-slate-900">{{ number_format((float) ($byDecrement[$k] ?? 0), 0) }}</td>
                        <td class="py-2 pl-3 text-right text-[11px] text-slate-500 mono w-16">
                            {{ $totalDecrement > 0 ? number_format(($byDecrement[$k] ?? 0) / $totalDecrement * 100, 1) : '0.0' }}%
                        </td>
                    </tr>
                @endforeach
                <tr class="border-t-2 border-stone-300 font-semibold">
                    <td class="py-2 pr-3 text-slate-900">Total PBO</td>
                    <td class="py-2 text-right mono text-slate-900">{{ number_format($totalDecrement, 0) }}</td>
                    <td class="py-2 pl-3 text-right text-[11px] text-slate-500">100.0%</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="panel p-5 lg:col-span-2">
        <h2 class="text-sm font-semibold text-slate-900 mb-3">Expected benefit payments by year</h2>
        @php
            $maxPay = max(array_map('floatval', array_values($payByYear))) ?: 1;
            $payRows = collect($payByYear)->take(10);
        @endphp
        <table class="w-full text-[12.5px]">
            <tbody class="divide-y divide-stone-100">
                @foreach ($payRows as $year => $amount)
                    <tr>
                        <td class="py-1.5 pr-3 mono text-slate-600 w-16">{{ $year }}</td>
                        <td class="py-1.5 pl-3">
                            <div class="flex items-center gap-2">
                                <div class="h-1.5 bg-stone-100 rounded-full flex-1 overflow-hidden">
                                    <div class="h-full bg-gradient-to-r from-rose-500 to-orange-500"
                                         style="width: {{ ($amount / $maxPay) * 100 }}%"></div>
                                </div>
                                <span class="mono text-[11px] text-slate-900 whitespace-nowrap">{{ number_format((float) $amount, 0) }}</span>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if (count($payByYear) > 10)
            <p class="text-[10.5px] text-slate-500 mt-2 italic">Showing first 10 years; full schedule available in the report export (Phase 5).</p>
        @endif
    </div>
</div>

{{-- Headcount split --}}
<div class="panel p-5 mb-5">
    <h2 class="text-sm font-semibold text-slate-900 mb-3">Headcount &amp; PBO by category</h2>
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
    <table class="w-full text-[12.5px]">
        <thead class="text-[10px] uppercase tracking-widest text-slate-500 border-b border-stone-200">
            <tr>
                <th class="py-2 pr-3 text-left font-semibold">Category</th>
                <th class="py-2 px-3 text-right font-semibold">Headcount</th>
                <th class="py-2 px-3 text-right font-semibold">PBO (XAF)</th>
                <th class="py-2 pl-3 text-right font-semibold">% of total</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
            @foreach ($catMap as $key => [$label, $headcount])
                <tr>
                    <td class="py-2 pr-3 text-slate-700">{{ $label }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-900">{{ $headcount }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-900">{{ number_format($pboByCategory[$key], 0) }}</td>
                    <td class="py-2 pl-3 text-right mono text-slate-500">{{ $totalCatPbo > 0 ? number_format($pboByCategory[$key] / $totalCatPbo * 100, 1) : '0.0' }}%</td>
                </tr>
            @endforeach
            <tr class="border-t-2 border-stone-300 font-semibold">
                <td class="py-2 pr-3 text-slate-900">Total</td>
                <td class="py-2 px-3 text-right mono text-slate-900">{{ $valuation->headcount_blue_collar + $valuation->headcount_supervisor + $valuation->headcount_manager }}</td>
                <td class="py-2 px-3 text-right mono text-slate-900">{{ number_format($totalCatPbo, 0) }}</td>
                <td class="py-2 pl-3 text-right mono text-slate-500">100.0%</td>
            </tr>
        </tbody>
    </table>
</div>

{{-- Per-employee detail (top contributors) --}}
<div class="panel p-5 mb-5">
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-sm font-semibold text-slate-900">Top 20 employees by PBO</h2>
        <span class="text-[11px] text-slate-500">{{ $lines->count() }} total employees</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-[11.5px]">
            <thead class="text-[10px] uppercase tracking-widest text-slate-500 border-b border-stone-200">
                <tr>
                    <th class="py-2 pr-3 text-left font-semibold">Employee</th>
                    <th class="py-2 px-3 text-right font-semibold">Age</th>
                    <th class="py-2 px-3 text-right font-semibold">Service</th>
                    <th class="py-2 px-3 text-right font-semibold">Salary</th>
                    <th class="py-2 px-3 text-right font-semibold">PBO</th>
                    <th class="py-2 px-3 text-right font-semibold">Service cost</th>
                    <th class="py-2 px-3 text-right font-semibold">Interest cost</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @foreach ($lines->take(20) as $line)
                    <tr>
                        <td class="py-2 pr-3">
                            <div class="text-slate-900 font-medium">{{ $line->employee->full_name ?? '—' }}</div>
                            <div class="text-[10px] text-slate-500 mono">{{ $line->employee->employee_code ?? '—' }} · {{ str_replace('_', ' ', $line->employee->category ?? '') }}</div>
                        </td>
                        <td class="py-2 px-3 text-right mono text-slate-600">{{ number_format((float) $line->age_at_valuation, 1) }}</td>
                        <td class="py-2 px-3 text-right mono text-slate-600">{{ number_format((float) $line->service_years_at_valuation, 1) }}y</td>
                        <td class="py-2 px-3 text-right mono text-slate-600">{{ number_format((float) $line->monthly_salary_at_valuation, 0) }}</td>
                        <td class="py-2 px-3 text-right mono font-semibold text-slate-900">{{ number_format((float) $line->pbo, 0) }}</td>
                        <td class="py-2 px-3 text-right mono text-slate-600">{{ number_format((float) $line->service_cost, 0) }}</td>
                        <td class="py-2 px-3 text-right mono text-slate-600">{{ number_format((float) $line->interest_cost, 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- Assumptions snapshot --}}
<div class="panel p-5">
    <h2 class="text-sm font-semibold text-slate-900 mb-3">Assumptions used</h2>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-[12px]">
        <div><span class="text-slate-500">Discount rate</span> <span class="block mono font-semibold text-slate-900">{{ number_format((float) $assumptions->discount_rate * 100, 2) }}%</span></div>
        <div><span class="text-slate-500">Salary increase</span> <span class="block mono font-semibold text-slate-900">{{ number_format((float) $assumptions->salary_increase_rate * 100, 2) }}%</span></div>
        <div><span class="text-slate-500">Inflation</span> <span class="block mono font-semibold text-slate-900">{{ number_format((float) $assumptions->inflation_rate * 100, 2) }}%</span></div>
        <div><span class="text-slate-500">Expected return on assets</span> <span class="block mono font-semibold text-slate-900">{{ number_format((float) $assumptions->expected_return_on_assets * 100, 2) }}%</span></div>
        <div><span class="text-slate-500">Mortality table</span> <span class="block mono font-semibold text-slate-900">{{ strtoupper(str_replace('_', ' ', $assumptions->mortality_table)) }}</span></div>
        <div><span class="text-slate-500">Retirement age</span> <span class="block mono font-semibold text-slate-900">{{ $assumptions->retirement_age }}</span></div>
        <div><span class="text-slate-500">Funded?</span> <span class="block mono font-semibold {{ $assumptions->funded ? 'text-emerald-700' : 'text-slate-700' }}">{{ $assumptions->funded ? 'Yes' : 'No' }}</span></div>
        <div><span class="text-slate-500">Plan assets</span> <span class="block mono font-semibold text-slate-900">{{ number_format((float) $assumptions->fair_value_assets, 0) }}</span></div>
    </div>
</div>

@endsection
