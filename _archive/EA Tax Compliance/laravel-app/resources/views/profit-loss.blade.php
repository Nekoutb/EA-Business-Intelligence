@extends('layouts.app')
@section('title', 'Profit & Loss')

@section('header')
    <h1 class="text-2xl font-bold text-slate-900">Profit &amp; Loss</h1>
    <p class="text-xs text-slate-500 mt-1">Period: <strong>{{ $periodLabel }}</strong> · {{ ea_last_sync_label() }}</p>
@endsection

@section('content')
    <x-period-filter :period="$period"/>

    @if ($error)
        <div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-xl p-4 text-sm">{{ $error }}</div>
    @elseif ($data)
        @php $cur = $data['current'] ?? []; $ccy = $data['currency'] ?? 'USD'; @endphp

        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 mb-6">
            <x-kpi title="Revenue"      :value="ea_money_short((float)($cur['revenue'] ?? 0), $ccy)" color="indigo"/>
            <x-kpi title="Gross Profit" :value="ea_money_short((float)($cur['gross_profit'] ?? 0), $ccy)"
                   :sub="'GM ' . number_format((float)($cur['gross_margin'] ?? 0), 1) . '%'" color="emerald"/>
            <x-kpi title="EBITDA"       :value="ea_money_short((float)($cur['ebitda'] ?? 0), $ccy)"
                   :sub="number_format((float)($cur['ebitda_margin'] ?? 0), 1) . '%'" color="indigo"/>
            <x-kpi title="EBIT"         :value="ea_money_short((float)($cur['ebit'] ?? 0), $ccy)"
                   :sub="number_format((float)($cur['ebit_margin'] ?? 0), 1) . '%'" color="amber"/>
            <x-kpi title="Net Profit"   :value="ea_money_short((float)($cur['net_profit'] ?? 0), $ccy)"
                   :sub="number_format((float)($cur['net_margin'] ?? 0), 1) . '%'"
                   :color="($cur['net_profit'] ?? 0) >= 0 ? 'emerald' : 'rose'"/>
            <x-kpi title="OpEx"         :value="ea_money_short((float)($cur['opex'] ?? 0), $ccy)" color="slate"/>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-slate-900 mb-3">P&amp;L waterfall</h2>
            <div class="h-72"><canvas id="plWaterfall"></canvas></div>
        </div>

        @push('scripts')
        <script>
        (function(){
            const c = @json($cur);
            const items = [
                ['Revenue',      +c.revenue      || 0, '#6366f1'],
                ['– COGS',       -(+c.cogs       || 0), '#f87171'],
                ['Gross Profit', +c.gross_profit || 0, '#10b981'],
                ['– OpEx',       -(+c.opex       || 0), '#fb923c'],
                ['EBITDA',       +c.ebitda       || 0, '#6366f1'],
                ['– D&A',        -(+c.depreciation || 0), '#f87171'],
                ['EBIT',         +c.ebit         || 0, '#f59e0b'],
                ['– Tax/Int',    -((+c.tax || 0)+(+c.interest || 0)), '#f87171'],
                ['Net Profit',   +c.net_profit   || 0, (+c.net_profit||0)>=0 ? '#10b981' : '#ef4444'],
            ];
            new Chart(document.getElementById('plWaterfall'), {
                type:'bar',
                data:{ labels:items.map(i=>i[0]),
                       datasets:[{ data:items.map(i=>i[1]), backgroundColor:items.map(i=>i[2]), borderRadius:4 }]},
                options:{responsive:true,maintainAspectRatio:false,
                    plugins:{legend:{display:false}, tooltip:{backgroundColor:'#0f172a'}},
                    scales:{x:{grid:{display:false},ticks:{font:{size:10}}},
                            y:{grid:{color:'#f1f5f9'},ticks:{font:{size:10},
                                callback:v=>{v=Math.abs(v);return v>=1e6?(v/1e6).toFixed(1)+'M':v>=1e3?(v/1e3).toFixed(0)+'K':v}}}}}
            });
        })();
        </script>
        @endpush
    @endif
@endsection
