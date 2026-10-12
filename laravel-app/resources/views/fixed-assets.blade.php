@extends('layouts.app')
@section('title', 'Fixed Assets')

@section('page-title', 'Fixed Assets')
@section('content')
@if ($error)
    <div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-xl p-4 text-sm">{{ $error }}</div>
@elseif ($data)
    @php
        $ccy = $data['currency'] ?? 'XAF';
        $k   = $data['kpis'] ?? [];
        $m   = fn ($v) => $v === null ? '—' : ea_money_short((float) $v, $ccy);
        $x   = fn ($v) => $v === null ? '—' : number_format((float) $v, 0, '.', ' ');
        $pyD = (float) ($k['depreciation_py_same'] ?? 0);
        $dVs = $pyD != 0.0 ? round(100 * (((float) ($k['depreciation_ytd'] ?? 0)) - $pyD) / abs($pyD), 1) : null;
        $th  = 'px-3 py-2 text-[10px] font-semibold uppercase tracking-wide text-[#8a8578]';
        $td  = 'px-3 py-1.5 text-[12px] text-[#2d3748]';
        $num = 'text-right tabular-nums';
    @endphp

    @if (!empty($data['message']))
        <div class="bg-warm-50 border border-warm-200 text-[#7a5a1a] rounded-lg p-3 text-[12px] mb-4">{{ $data['message'] }}</div>
    @endif

    <div class="text-[11px] text-[#7c7466] mb-3">Asset register as of {{ $data['as_of'] }} · Odoo Assets module · {{ number_format((int) ($k['active_asset_count'] ?? 0)) }} assets in service{{ ($k['closed_count'] ?? 0) ? ' · ' . number_format((int) $k['closed_count']) . ' disposed' : '' }}</div>

    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 mb-4">
        <x-kpi title="Gross value" :value="$m($k['gross_value'] ?? 0)" :sub="number_format((int) ($k['active_asset_count'] ?? 0)) . ' assets'" color="indigo"
               hint="Original cost of every asset still in service." />
        <x-kpi title="Accumulated depreciation" :value="$m($k['accumulated_depr'] ?? 0)" :sub="number_format((float) ($k['depreciation_ratio_pct'] ?? 0), 1) . '% of gross consumed'" color="amber"
               hint="Gross value minus net book value. A high share means an ageing asset base that will need replacing." />
        <x-kpi title="Net book value" :value="$m($k['net_book_value'] ?? 0)" color="emerald"
               hint="What the assets still carry on the balance sheet." />
        <x-kpi title="Depreciation YTD" :value="$m($k['depreciation_ytd'] ?? 0)" :vsPy="$dVs" prefer="down" color="slate"
               hint="Charge booked to class 68 this year, compared with the same window last year." />
        <x-kpi title="Average age" :value="number_format((float) ($k['avg_asset_age_years'] ?? 0), 1) . ' yrs'" sub="weighted by cost" color="slate" />
        <x-kpi title="Replace / renew" :value="number_format((int) ($k['fully_depreciated_count'] ?? 0) + (int) ($k['eol_12m_count'] ?? 0))"
               :sub="number_format((int) ($k['fully_depreciated_count'] ?? 0)) . ' fully depreciated · ' . number_format((int) ($k['eol_12m_count'] ?? 0)) . ' end ≤ 12 mo'" color="coral"
               hint="Assets with no book value left, or whose depreciation ends within 12 months — candidates for replacement budgeting." />
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-4">
        <div class="bg-white rounded-xl border border-[#e8e4de] p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-[#1e3d44] mb-1">Depreciation charge · next 12 months</h2>
            <p class="text-[11px] text-[#7c7466] mb-3">Scheduled depreciation entries already generated in Odoo — the P&amp;L charge to budget. Total {{ $m(array_sum(array_column($data['forecast'] ?? [], 'amount'))) }}.</p>
            <div class="h-60"><canvas id="faForecast"></canvas></div>
        </div>
        <div class="bg-white rounded-xl border border-[#e8e4de] p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-[#1e3d44] mb-1">Investment by acquisition year</h2>
            <p class="text-[11px] text-[#7c7466] mb-3">Gross value of the assets still in service, by the year they were acquired — the capex rhythm behind the base.</p>
            <div class="h-60"><canvas id="faCapex"></canvas></div>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-[#e8e4de] shadow-sm mb-4 overflow-x-auto">
        <h2 class="text-sm font-semibold text-[#1e3d44] px-5 pt-4 pb-2">Register by asset class</h2>
        <table class="w-full">
            <thead class="bg-[#faf9f6]"><tr>
                <th class="{{ $th }} text-left">Asset account</th><th class="{{ $th }} {{ $num }}">Assets</th><th class="{{ $th }} {{ $num }}">Gross</th>
                <th class="{{ $th }} {{ $num }}">Accumulated</th><th class="{{ $th }} {{ $num }}">Net book value</th><th class="{{ $th }} {{ $num }}">% depreciated</th>
                <th class="{{ $th }} {{ $num }}">Avg remaining life</th><th class="{{ $th }} {{ $num }}">Fully depreciated</th><th class="{{ $th }} {{ $num }}">Share of NBV</th>
            </tr></thead>
            <tbody>
            @forelse ($data['by_category'] ?? [] as $c)
                <tr class="border-t border-[#f1ede5]">
                    <td class="{{ $td }}">{{ $c['category'] }}</td><td class="{{ $td }} {{ $num }}">{{ $c['count'] }}</td>
                    <td class="{{ $td }} {{ $num }}">{{ $x($c['gross']) }}</td><td class="{{ $td }} {{ $num }}">{{ $x($c['accum']) }}</td>
                    <td class="{{ $td }} {{ $num }} font-semibold">{{ $x($c['nbv']) }}</td>
                    <td class="{{ $td }} {{ $num }} {{ $c['depr_pct'] >= 80 ? 'text-coral-600 font-semibold' : '' }}">{{ number_format($c['depr_pct'], 1) }}%</td>
                    <td class="{{ $td }} {{ $num }}">{{ $c['avg_remaining_months'] === null ? '—' : number_format($c['avg_remaining_months'], 0) . ' mo' }}</td>
                    <td class="{{ $td }} {{ $num }}">{{ $c['fully'] ?: '—' }}</td><td class="{{ $td }} {{ $num }}">{{ number_format($c['share_nbv'], 1) }}%</td>
                </tr>
            @empty
                <tr><td colspan="9" class="{{ $td }} text-[#7c7466]">No assets in the register.</td></tr>
            @endforelse
            @if (!empty($data['by_category']))
                <tr class="border-t-2 border-[#e8e4de] font-semibold">
                    <td class="{{ $td }}">Total</td><td class="{{ $td }} {{ $num }}">{{ (int) ($k['active_asset_count'] ?? 0) }}</td>
                    <td class="{{ $td }} {{ $num }}">{{ $x($k['gross_value'] ?? 0) }}</td><td class="{{ $td }} {{ $num }}">{{ $x($k['accumulated_depr'] ?? 0) }}</td>
                    <td class="{{ $td }} {{ $num }}">{{ $x($k['net_book_value'] ?? 0) }}</td><td class="{{ $td }} {{ $num }}">{{ number_format((float) ($k['depreciation_ratio_pct'] ?? 0), 1) }}%</td>
                    <td class="{{ $td }}"></td><td class="{{ $td }} {{ $num }}">{{ (int) ($k['fully_depreciated_count'] ?? 0) }}</td><td class="{{ $td }} {{ $num }}">100%</td>
                </tr>
            @endif
            </tbody>
        </table>
    </div>

    <div class="bg-white rounded-xl border border-[#e8e4de] shadow-sm mb-4 overflow-x-auto">
        <h2 class="text-sm font-semibold text-[#1e3d44] px-5 pt-4">Replacement watchlist</h2>
        <p class="text-[11px] text-[#7c7466] px-5 pb-2">Assets fully depreciated but still in service, or whose depreciation ends within 12 months. Decide: keep running, replace (budget the capex), or dispose and remove from the register.</p>
        <table class="w-full">
            <thead class="bg-[#faf9f6]"><tr>
                <th class="{{ $th }} text-left">Asset</th><th class="{{ $th }} text-left">Account</th><th class="{{ $th }} text-left">Acquired</th>
                <th class="{{ $th }} {{ $num }}">Gross</th><th class="{{ $th }} {{ $num }}">Net book value</th><th class="{{ $th }} {{ $num }}">Remaining</th><th class="{{ $th }} text-left">Status</th>
            </tr></thead>
            <tbody>
            @forelse (array_slice($data['watchlist'] ?? [], 0, 40) as $w)
                <tr class="border-t border-[#f1ede5]">
                    <td class="{{ $td }}">{{ $w['name'] }}</td><td class="{{ $td }} text-[#7c7466]">{{ $w['category'] }}</td><td class="{{ $td }}">{{ $w['acquired'] ?: '—' }}</td>
                    <td class="{{ $td }} {{ $num }}">{{ $x($w['gross']) }}</td><td class="{{ $td }} {{ $num }}">{{ $x($w['nbv']) }}</td>
                    <td class="{{ $td }} {{ $num }}">{{ $w['remaining_months'] === null ? '—' : $w['remaining_months'] . ' mo' }}</td>
                    <td class="{{ $td }} {{ $w['status'] === 'Fully depreciated' ? 'text-coral-600' : 'text-warm-600' }}">{{ $w['status'] }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="{{ $td }} text-[#7c7466]">No asset reaches the end of its life in the next 12 months.</td></tr>
            @endforelse
            </tbody>
        </table>
        @if (count($data['watchlist'] ?? []) > 40)
            <p class="text-[11px] text-[#7c7466] px-5 py-2">Showing 40 of {{ count($data['watchlist']) }}.</p>
        @endif
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-4">
        <div class="bg-white rounded-xl border border-[#e8e4de] shadow-sm overflow-x-auto">
            <h2 class="text-sm font-semibold text-[#1e3d44] px-5 pt-4">Register vs general ledger</h2>
            <p class="text-[11px] text-[#7c7466] px-5 pb-2">The register should equal the ledger balance of each asset and depreciation account. A difference means entries posted outside the Assets module, or assets missing from the register.</p>
            <table class="w-full">
                <thead class="bg-[#faf9f6]"><tr>
                    <th class="{{ $th }} text-left">Account</th><th class="{{ $th }} {{ $num }}">Register</th><th class="{{ $th }} {{ $num }}">Ledger</th><th class="{{ $th }} {{ $num }}">Difference</th>
                </tr></thead>
                <tbody>
                @forelse ($data['recon'] ?? [] as $r)
                    <tr class="border-t border-[#f1ede5]">
                        <td class="{{ $td }}">{{ $r['account'] }}<div class="text-[10px] text-[#a09d95]">{{ $r['type'] }}</div></td>
                        <td class="{{ $td }} {{ $num }}">{{ $x($r['register']) }}</td><td class="{{ $td }} {{ $num }}">{{ $x($r['ledger']) }}</td>
                        <td class="{{ $td }} {{ $num }} {{ abs($r['difference']) >= 1 ? 'text-coral-600 font-semibold' : 'text-sage-600' }}">{{ abs($r['difference']) >= 1 ? $x($r['difference']) : 'OK' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="{{ $td }} text-[#7c7466]">No accounts to reconcile.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="bg-white rounded-xl border border-[#e8e4de] shadow-sm overflow-x-auto">
            <h2 class="text-sm font-semibold text-[#1e3d44] px-5 pt-4 pb-2">Largest assets by net book value</h2>
            <table class="w-full">
                <thead class="bg-[#faf9f6]"><tr>
                    <th class="{{ $th }} text-left">Asset</th><th class="{{ $th }} {{ $num }}">Gross</th><th class="{{ $th }} {{ $num }}">NBV</th><th class="{{ $th }} {{ $num }}">% depr.</th><th class="{{ $th }} {{ $num }}">Remaining</th>
                </tr></thead>
                <tbody>
                @foreach ($data['top_assets'] ?? [] as $a)
                    <tr class="border-t border-[#f1ede5]">
                        <td class="{{ $td }}">{{ $a['name'] }}<div class="text-[10px] text-[#a09d95]">{{ $a['category'] }} · {{ $a['acquired'] ?: '—' }}</div></td>
                        <td class="{{ $td }} {{ $num }}">{{ $x($a['gross']) }}</td><td class="{{ $td }} {{ $num }} font-semibold">{{ $x($a['nbv']) }}</td>
                        <td class="{{ $td }} {{ $num }}">{{ number_format($a['depr_pct'], 0) }}%</td>
                        <td class="{{ $td }} {{ $num }}">{{ $a['remaining_months'] === null ? '—' : $a['remaining_months'] . ' mo' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @php $q = $data['quality'] ?? []; @endphp
    @if (array_sum($q) > 0 || !empty($data['notes']))
        <div class="bg-[#f5f2ec] border border-[#e8e4de] text-[#7c7466] rounded-lg p-3 text-[11px]">
            <span class="font-semibold">Register hygiene:</span>
            {{ ($q['missing_description'] ?? 0) }} asset(s) with a missing/placeholder description ·
            {{ ($q['no_acquisition_date'] ?? 0) }} without an acquisition date ·
            {{ ($q['no_asset_account'] ?? 0) }} without an asset account.
            @if (!empty($data['notes'])) {{ implode(' ', $data['notes']) }} @endif
        </div>
    @endif

    @push('scripts')
    <script>
    (function () {
        const fc = @json(array_values($data['forecast'] ?? []));
        const cx = @json($data['capex'] ?? []);
        const fmt = v => { v = Math.abs(+v || 0); return v >= 1e9 ? (v/1e9).toFixed(2)+'B' : v >= 1e6 ? (v/1e6).toFixed(1)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'K' : Math.round(v); };
        const opts = { responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false},
            tooltip:{ backgroundColor:'#1e3d44', callbacks:{ label: c => fmt(c.parsed.y) } } },
            scales:{ x:{ grid:{display:false}, ticks:{font:{size:10}} }, y:{ grid:{color:'#f1ede5'}, ticks:{font:{size:10}, callback:fmt} } } };
        if (window.Chart && document.getElementById('faForecast')) new Chart(document.getElementById('faForecast'), { type:'bar',
            data:{ labels: fc.map(r => r.label), datasets:[{ data: fc.map(r => r.amount), backgroundColor:'#d98c3f', borderRadius:4 }] }, options: opts });
        if (window.Chart && document.getElementById('faCapex')) new Chart(document.getElementById('faCapex'), { type:'bar',
            data:{ labels: Object.keys(cx), datasets:[{ data: Object.values(cx).map(r => r.gross), backgroundColor:'#2f6f76', borderRadius:4 }] }, options: opts });
    })();
    </script>
    @endpush
@endif
@endsection
