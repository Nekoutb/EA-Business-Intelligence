@extends('layouts.app')
@section('title', 'Payables')

@section('page-title', 'Accounts Payable')
@section('content')
    @if ($error)
        <div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-xl p-4 text-sm">{{ $error }}</div>
    @elseif ($data && !empty($data['kpis']))
        @php
            $ccy = $data['currency'] ?? 'XAF';
            $k   = $data['kpis'];
        @endphp

        {{-- ── KPIs ──────────────────────────────────────────────── --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <x-kpi title="Total AP" :value="ea_money_short((float)($k['total_ap'] ?? 0), $ccy)"
                   :sub="number_format((int)($data['open_bill_count'] ?? 0)) . ' open bills'" color="indigo"
                   hint="Accounts Payable — the total you currently owe your suppliers."/>
            <x-kpi title="Overdue AP" :value="ea_money_short((float)($k['overdue_ap'] ?? 0), $ccy)"
                   :sub="number_format((float)($k['overdue_rate_pct'] ?? 0), 1) . '% of AP'" color="rose"
                   hint="Supplier bills already past their due date."/>
            <x-kpi title="DPO" :value="number_format((float)($k['dpo_days'] ?? 0), 0) . ' d'"
                   :sub="'avg age ' . number_format((float)($k['avg_age_days'] ?? 0), 0) . 'd'" color="amber"
                   hint="Days Payable Outstanding — average days taken to pay suppliers. Higher = you hold cash longer."/>
            <x-kpi title="Top-5 concentration" :value="number_format((float)($k['top5_concentration_pct'] ?? 0), 1) . '%'" color="slate"
                   hint="Share of total payables owed to your five largest suppliers — concentration risk."/>
        </div>

        {{-- ── Aging buckets ─────────────────────────────────────── --}}
        @if (!empty($data['aging']))
            <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm mb-6">
                <h2 class="text-sm font-semibold text-slate-900 mb-3">Aging</h2>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                    @foreach ($data['aging'] as $key => $bucket)
                        @php $isOverdue = $key !== 'current'; @endphp
                        <div class="rounded-lg border {{ $isOverdue ? 'border-rose-100 bg-rose-50/40' : 'border-slate-100 bg-slate-50/60' }} p-3">
                            <div class="text-[11px] text-slate-500">{{ $bucket['label'] ?? $key }}</div>
                            <div class="text-base font-bold tabular-nums {{ $isOverdue ? 'text-rose-700' : 'text-slate-800' }}">{{ ea_money_short((float)($bucket['amount'] ?? 0), $ccy) }}</div>
                            <div class="text-[10px] text-slate-400">{{ number_format((int)($bucket['count'] ?? 0)) }} bills</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ── Monthly DPO trend ─────────────────────────────────── --}}
        @if (!empty($data['dpo_trend']))
            <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm mb-6">
                <h2 class="text-sm font-semibold text-slate-900 mb-1">DPO evolution · running (cumulative YTD)</h2>
                <p class="text-[11px] text-slate-500 mb-3">Each month is the year-to-date DPO computed cumulatively (AP ÷ cumulative expenses × 365); the latest month reconciles to the headline DPO above.</p>
                <div class="h-64"><canvas id="apDpoTrend"></canvas></div>
            </div>
        @endif

        {{-- ── Top 30 vendors ────────────────────────────────────── --}}
        @if (!empty($data['top_creditors']))
            <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-slate-900 mb-3">Top 30 vendors · unpaid invoices</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-[13px]">
                        <thead>
                            <tr class="text-left text-[11px] text-slate-500 uppercase tracking-wide border-b border-slate-100">
                                <th class="pb-2 pr-2 w-8">#</th>
                                <th class="pb-2 pr-2">Vendor</th>
                                <th class="pb-2 px-2 text-right">Outstanding</th>
                                <th class="pb-2 px-2 text-right">Overdue</th>
                                <th class="pb-2 px-2 text-right">Invoices</th>
                                <th class="pb-2 pl-2 text-right">Max days late</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @foreach ($data['top_creditors'] as $i => $v)
                                @php $od = (float) ($v['overdue'] ?? 0); @endphp
                                <tr class="hover:bg-slate-50">
                                    <td class="py-1.5 pr-2 text-slate-400 tabular-nums">{{ $i + 1 }}</td>
                                    <td class="py-1.5 pr-2 text-slate-700">{{ $v['name'] ?? '—' }}</td>
                                    <td class="py-1.5 px-2 text-right tabular-nums font-semibold text-slate-800">{{ ea_money_short((float)($v['residual'] ?? 0), $ccy) }}</td>
                                    <td class="py-1.5 px-2 text-right tabular-nums {{ $od > 0 ? 'text-rose-600' : 'text-slate-400' }}">{{ $od > 0 ? ea_money_short($od, $ccy) : '—' }}</td>
                                    <td class="py-1.5 px-2 text-right tabular-nums text-slate-500">{{ number_format((int)($v['invoices'] ?? 0)) }}</td>
                                    <td class="py-1.5 pl-2 text-right tabular-nums text-slate-500">{{ (int)($v['max_days_overdue'] ?? 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @push('scripts')
        <script>
        (function () {
            const el = document.getElementById('apDpoTrend');
            if (!el || typeof Chart === 'undefined') return;
            const tr = @json($data['dpo_trend'] ?? []);
            if (!tr.length) return;
            new Chart(el, {
                type: 'line',
                data: { labels: tr.map(p => p.label), datasets: [{
                    label: 'DPO (days)', data: tr.map(p => p.dpo),
                    borderColor: '#d97706', backgroundColor: 'rgba(217,119,6,0.08)',
                    tension: 0.3, borderWidth: 2, pointRadius: 3, fill: true,
                }]},
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0f172a',
                        callbacks: { label: c => 'DPO: ' + Math.round(c.parsed.y) + ' days' } } },
                    scales: { x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                              y: { grid: { color: '#f1f5f9' }, ticks: { font: { size: 10 }, callback: v => v + 'd' } } },
                },
            });
        })();
        </script>
        @endpush
    @else
        <div class="bg-warm-50 border border-warm-200 rounded-xl p-5">
            <div class="text-sm font-semibold text-[#7a5a1a]">Awaiting data</div>
            <p class="text-xs text-[#7c7466] mt-1">No posted vendor bills with an open balance were found.</p>
        </div>
    @endif
@endsection
