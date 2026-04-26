@extends('layouts.app')

@section('title', 'Inventory')

@section('header')
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-bold text-slate-900">Inventory</h2>
            <p class="text-xs text-slate-500 mt-1">
                {{ $data['period_label'] ?? 'YTD' }}
                @if ($data['error'] ?? null)
                    — Last sync failed
                @else
                    — Last synced just now
                @endif
            </p>
        </div>
        <form method="POST" action="{{ url('/sync') }}" class="inline">
            @csrf
            <button type="submit" class="px-4 py-2 text-sm font-medium bg-slate-200 text-slate-700 rounded-lg hover:bg-slate-300 transition">
                Sync now
            </button>
        </form>
    </div>
@endsection

@section('content')
    {{-- Error Display --}}
    @if ($data['error'] ?? null)
        <div class="mb-6 px-4 py-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-sm">
            <strong>Error:</strong> {{ $data['error'] }}
        </div>
    @endif

    {{-- Pre-compute view state --}}
    @php
        $invDays         = $data['inventory_days'] ?? [];
        $avgInventory    = $invDays['avg_inventory']    ?? 0;
        $cogsYtd         = $invDays['cogs_ytd']         ?? 0;
        $daysOutstanding = $invDays['days_outstanding'] ?? 0;
        $beginningInv    = $invDays['beginning_inventory'] ?? 0;
        $endingInv       = $invDays['ending_inventory']    ?? 0;

        $asOfDate  = $data['as_of'] ?? null;
        $asOfLabel = $asOfDate ? \DateTime::createFromFormat('Y-m-d', $asOfDate)->format('j M Y') : '';
    @endphp

    {{-- ───────────── Inventory Days (collapsed by default) ───────────── --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <details class="group/days bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden md:col-start-1">
            <summary class="px-5 py-4 cursor-pointer list-none flex items-center justify-between gap-4 hover:bg-slate-50 transition [&::-webkit-details-marker]:hidden">
                <div class="flex-1 min-w-0">
                    <h3 class="text-base font-semibold text-slate-900">Inventory Days</h3>
                    @if ($asOfLabel)
                        <p class="text-xs text-slate-500 mt-0.5">
                            <span class="font-medium text-slate-700">YTD as of {{ $asOfLabel }}</span>
                        </p>
                    @endif
                </div>
                <div class="flex items-center gap-3 flex-shrink-0">
                    <span class="text-2xl font-bold text-indigo-700">{{ number_format($daysOutstanding, 1) }} days</span>
                    <svg class="w-4 h-4 text-slate-400 transition-transform group-open/days:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </summary>

            <div class="border-t border-slate-200">
                <p class="text-xs text-slate-500 px-5 pt-3">
                    Days of stock-on-hand · COGS from GL <code class="font-mono bg-slate-100 px-1 rounded">60%</code> accounts
                </p>

                <dl class="px-5 py-4 space-y-3">
                    <div class="flex items-baseline justify-between border-b border-slate-100 pb-2">
                        <dt class="text-sm text-slate-600">
                            Average inventory
                            <span class="block text-[11px] text-slate-400 font-normal mt-0.5">(beginning {{ ea_money_short($beginningInv, $data['currency']) }} + ending {{ ea_money_short($endingInv, $data['currency']) }}) ÷ 2</span>
                        </dt>
                        <dd class="text-base font-semibold text-slate-900">{{ ea_money_short($avgInventory, $data['currency']) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between border-b border-slate-100 pb-2">
                        <dt class="text-sm text-slate-600">COGS YTD <span class="text-[11px] text-slate-400">(60X accounts)</span></dt>
                        <dd class="text-base font-semibold text-slate-900">{{ ea_money_short($cogsYtd, $data['currency']) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between border-b border-slate-100 pb-2">
                        <dt class="text-sm text-slate-600">Period days elapsed</dt>
                        <dd class="text-base font-semibold text-slate-900">{{ $invDays['period_days'] ?? 0 }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between">
                        <dt class="text-sm text-slate-600">Days outstanding</dt>
                        <dd class="text-lg font-bold text-indigo-700">{{ number_format($daysOutstanding, 1) }} days</dd>
                    </div>
                </dl>

                <p class="px-5 pb-3 text-[11px] text-slate-400">
                    = (avg inventory × period days) ÷ COGS
                </p>
            </div>
        </details>
    </div>

    {{-- ───────────────── Box 3: Inventory Value Trend ───────────────── --}}
    <section class="mb-6 bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <header class="px-6 py-4 border-b border-slate-200 bg-gradient-to-r from-slate-50 to-white">
            <h3 class="text-lg font-semibold text-slate-900">Inventory Value Trend</h3>
            <p class="text-xs text-slate-500 mt-0.5">Month-end inventory value · January {{ now()->year }} to date</p>
        </header>
        <div class="p-6">
            <canvas id="inventoryChart" height="60"></canvas>
        </div>
    </section>

@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const trendData = @json($data['trend'] ?? ['by_month' => []]);
    const currency  = @json($data['currency'] ?? 'USD');

    const points = trendData.by_month || [];
    const canvas = document.getElementById('inventoryChart');

    if (!points.length) {
        canvas.parentElement.innerHTML =
            '<p class="text-center text-slate-500 py-6 text-sm">No trend data available — no posted entries on inventory accounts for the current year.</p>';
        return;
    }

    const labels = points.map(d => d.month);
    const values = points.map(d => d.value);

    // Mirror PHP's ea_money_short() so axis labels and tooltips use the same
    // notation as the rest of the report (1.23M XAF / 4.5K XAF / 720 XAF).
    // Logic kept in lockstep with helpers.php::ea_money_short.
    const fmt = v => {
        const n = Number(v) || 0;
        const abs = Math.abs(n);
        const sign = n < 0 ? '-' : '';
        const withCommas = (x, dp) => x.toLocaleString('en-US', {
            minimumFractionDigits: dp, maximumFractionDigits: dp
        });
        if (abs >= 1e9) return sign + withCommas(abs / 1e9, 2) + 'B ' + currency;
        if (abs >= 1e6) return sign + withCommas(abs / 1e6, 2) + 'M ' + currency;
        if (abs >= 1e3) return sign + withCommas(abs / 1e3, 1) + 'K ' + currency;
        return sign + withCommas(abs, 0) + ' ' + currency;
    };

    new Chart(canvas.getContext('2d'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Inventory value (' + currency + ')',
                data: values,
                borderColor: 'rgb(79, 70, 229)',
                backgroundColor: 'rgba(79, 70, 229, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.3,
                pointBackgroundColor: 'rgb(79, 70, 229)',
                pointBorderColor: 'white',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { display: true, position: 'top' },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.85)',
                    padding: 12,
                    titleFont: { size: 13, weight: 'bold' },
                    bodyFont: { size: 12 },
                    callbacks: {
                        label: ctx => 'Value: ' + fmt(ctx.parsed.y)
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { callback: fmt }
                }
            }
        }
    });
});
</script>
@endpush
