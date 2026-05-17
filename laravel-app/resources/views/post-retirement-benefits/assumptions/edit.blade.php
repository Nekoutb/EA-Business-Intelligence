@extends('layouts.app')
@section('title', 'Assumptions · Post-Retirement Benefits')

@section('page-title', 'Actuarial assumptions')
@section('content')

@php
    $tenantSlug = request()->route('tenant');
    $bands = $assumptions->turnover_bands ?: [
        ['from_age' => 0,  'to_age' => 25, 'rate' => 0.010],
        ['from_age' => 25, 'to_age' => 35, 'rate' => 0.020],
        ['from_age' => 35, 'to_age' => 45, 'rate' => 0.010],
        ['from_age' => 45, 'to_age' => 60, 'rate' => 0.000],
    ];
@endphp

<div class="mb-4">
    <a href="/t/{{ $tenantSlug }}/post-retirement-benefits"
       class="text-[12px] text-slate-600 hover:text-slate-900 inline-flex items-center gap-1.5">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        Back to Post-Retirement Benefits
    </a>
</div>

@if ($errors->any())
    <div class="mb-3 panel p-3 border-l-4 border-rose-400 bg-rose-50">
        <ul class="text-[11.5px] text-rose-800 list-disc pl-4">
            @foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach
        </ul>
    </div>
@endif

<form method="POST" action="/t/{{ $tenantSlug }}/post-retirement-benefits/assumptions" class="space-y-4 max-w-4xl">
    @csrf

    {{-- ── Economic assumptions ── --}}
    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-4">Economic assumptions</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Valuation date *</label>
                <input type="date" name="valuation_date" required
                       value="{{ old('valuation_date', $assumptions->valuation_date ? $assumptions->valuation_date->format('Y-m-d') : now()->endOfYear()->format('Y-m-d')) }}"
                       class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm mono">
                <p class="text-[10px] text-slate-500 mt-1">Year-end is typical.</p>
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Discount rate (%) *</label>
                <input type="number" step="0.01" name="discount_rate_pct" required min="0" max="30"
                       value="{{ old('discount_rate_pct', number_format((float) $assumptions->discount_rate * 100, 2, '.', '')) }}"
                       class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm mono">
                <p class="text-[10px] text-slate-500 mt-1">BEAC bond yield, duration-matched.</p>
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Salary increase (%) *</label>
                <input type="number" step="0.01" name="salary_increase_rate_pct" required min="0" max="30"
                       value="{{ old('salary_increase_rate_pct', number_format((float) $assumptions->salary_increase_rate * 100, 2, '.', '')) }}"
                       class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm mono">
                <p class="text-[10px] text-slate-500 mt-1">Annual escalation.</p>
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Inflation (%) *</label>
                <input type="number" step="0.01" name="inflation_rate_pct" required min="0" max="30"
                       value="{{ old('inflation_rate_pct', number_format((float) $assumptions->inflation_rate * 100, 2, '.', '')) }}"
                       class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm mono">
                <p class="text-[10px] text-slate-500 mt-1">CEMAC long-term target.</p>
            </div>
        </div>
    </div>

    {{-- ── Demographics & mortality ── --}}
    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-4">Demographics</h2>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Mortality table *</label>
                <select name="mortality_table" required class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm bg-white">
                    @php $mt = old('mortality_table', $assumptions->mortality_table ?? 'cima_f'); @endphp
                    <option value="cima_f" @selected($mt === 'cima_f')>CIMA F · female / mixed (default)</option>
                    <option value="cima_h" @selected($mt === 'cima_h')>CIMA H · male</option>
                </select>
                <p class="text-[10px] text-slate-500 mt-1">CIMA = Conférence Interafricaine des Marchés d'Assurances.</p>
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Retirement age</label>
                <input type="text" disabled value="60 (Cameroon legal)" class="w-full border border-stone-200 rounded-lg px-3 py-2 text-sm bg-stone-50 text-slate-600 mono">
                <p class="text-[10px] text-slate-500 mt-1">Fixed by Code du Travail.</p>
            </div>
        </div>
    </div>

    {{-- ── Funding ── --}}
    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-4">Plan funding</h2>
        <label class="flex items-start gap-2 text-[12px] cursor-pointer mb-3">
            <input type="checkbox" name="funded" value="1"
                   @checked(old('funded', $assumptions->funded ?? false))
                   class="mt-0.5 rounded border-stone-300 text-emerald-600"
                   onchange="document.getElementById('funded-fields').style.display = this.checked ? 'grid' : 'none'">
            <span>
                <strong>This plan is funded</strong>
                <span class="block text-[10px] text-slate-500">Assets are held in a trust / dedicated investment vehicle to back the indemnity obligation. Most Cameroon plans are unfunded.</span>
            </span>
        </label>

        <div id="funded-fields" class="grid grid-cols-2 md:grid-cols-3 gap-4 mt-3" style="display: {{ old('funded', $assumptions->funded ?? false) ? 'grid' : 'none' }}">
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Fair value of plan assets (XAF)</label>
                <input type="number" step="1" name="fair_value_assets" min="0"
                       value="{{ old('fair_value_assets', (int) ($assumptions->fair_value_assets ?? 0)) }}"
                       class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm mono">
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Expected return on assets (%)</label>
                <input type="number" step="0.01" name="expected_return_on_assets_pct" min="0" max="30"
                       value="{{ old('expected_return_on_assets_pct', number_format((float) $assumptions->expected_return_on_assets * 100, 2, '.', '')) }}"
                       class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm mono">
                <p class="text-[10px] text-slate-500 mt-1">Long-term assumption used to compute the NPBC offset.</p>
            </div>
        </div>
    </div>

    {{-- ── Turnover bands ── --}}
    <div class="panel p-5">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-sm font-semibold text-slate-900">Turnover by age band</h2>
            <span class="text-[10px] text-slate-500">Annual probability of voluntary leave.</span>
        </div>
        <div id="turnover-bands" class="space-y-2">
            @foreach ($bands as $i => $band)
                <div class="grid grid-cols-12 gap-2 items-center turnover-row">
                    <div class="col-span-4">
                        <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">From age</label>
                        <input type="number" min="0" max="99" name="turnover_bands[{{ $i }}][from_age]" value="{{ $band['from_age'] ?? 0 }}"
                               class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm mono">
                    </div>
                    <div class="col-span-4">
                        <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">To age</label>
                        <input type="number" min="1" max="100" name="turnover_bands[{{ $i }}][to_age]" value="{{ $band['to_age'] ?? 99 }}"
                               class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm mono">
                    </div>
                    <div class="col-span-3">
                        <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Rate (%)</label>
                        <input type="number" step="0.01" min="0" max="100" name="turnover_bands[{{ $i }}][rate_pct]"
                               value="{{ number_format(($band['rate'] ?? 0) * 100, 2, '.', '') }}"
                               class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm mono">
                    </div>
                    <div class="col-span-1 pt-5">
                        <button type="button" onclick="this.closest('.turnover-row').remove()" class="text-rose-600 hover:text-rose-800 text-[11px] font-medium">Remove</button>
                    </div>
                </div>
            @endforeach
        </div>
        <button type="button" id="add-band" class="mt-3 text-[11px] text-indigo-600 hover:text-indigo-800 font-medium">+ Add band</button>
    </div>

    <div class="panel p-5">
        <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Notes</label>
        <textarea name="notes" rows="2" maxlength="2000"
                  class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm">{{ old('notes', $assumptions->notes ?? '') }}</textarea>
        <p class="text-[10px] text-slate-500 mt-1">Auditor-facing notes about the assumption choices (e.g. source of discount rate).</p>
    </div>

    <div class="flex justify-between items-center">
        <a href="/t/{{ $tenantSlug }}/post-retirement-benefits" class="text-[12px] text-slate-500 hover:text-slate-900">← Cancel</a>
        <button type="submit"
                class="text-[12px] font-semibold text-white bg-gradient-to-r from-rose-600 to-orange-600 hover:from-rose-700 hover:to-orange-700 rounded-lg px-5 py-2 shadow">
            Save assumptions
        </button>
    </div>
</form>

<script>
(function () {
    var i = document.querySelectorAll('.turnover-row').length;
    document.getElementById('add-band').addEventListener('click', function () {
        var c = document.getElementById('turnover-bands');
        var row = document.createElement('div');
        row.className = 'grid grid-cols-12 gap-2 items-center turnover-row';
        row.innerHTML = `
            <div class="col-span-4"><label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">From age</label><input type="number" min="0" max="99" name="turnover_bands[${i}][from_age]" value="0" class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm mono"></div>
            <div class="col-span-4"><label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">To age</label><input type="number" min="1" max="100" name="turnover_bands[${i}][to_age]" value="99" class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm mono"></div>
            <div class="col-span-3"><label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1">Rate (%)</label><input type="number" step="0.01" min="0" max="100" name="turnover_bands[${i}][rate_pct]" value="0.00" class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm mono"></div>
            <div class="col-span-1 pt-5"><button type="button" onclick="this.closest('.turnover-row').remove()" class="text-rose-600 hover:text-rose-800 text-[11px] font-medium">Remove</button></div>`;
        c.appendChild(row);
        i++;
    });
})();
</script>

@endsection
