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
        $reconciliation = $data['reconciliation'] ?? [];
        $stockValue     = $reconciliation['stock_value']      ?? 0;
        $glBalance      = $reconciliation['gl_balance']       ?? 0;
        $variance       = $reconciliation['variance']         ?? 0;
        $accuracy       = $reconciliation['accuracy_percent'] ?? 0;
        $interpretation = $reconciliation['interpretation']   ?? '';
        $glAccounts     = $data['gl_inventory']['by_account'] ?? [];

        $invDays        = $data['inventory_days'] ?? [];
        $avgInventory   = $invDays['avg_inventory']    ?? 0;
        $cogsYtd        = $invDays['cogs_ytd']         ?? 0;
        $daysOutstanding= $invDays['days_outstanding'] ?? 0;

        $asOfDate  = $data['as_of'] ?? null;
        $asOfLabel = $asOfDate ? \DateTime::createFromFormat('Y-m-d', $asOfDate)->format('j M Y') : '';

        // Pill + status colour, driven by accuracy %.
        if ($accuracy >= 99.99) {
            $tone = ['pill' => 'bg-emerald-100 text-emerald-700 ring-emerald-200',
                     'msg'  => 'text-emerald-700',
                     'icon' => 'text-emerald-600',
                     'label'=> 'FULLY RECONCILED'];
        } elseif ($accuracy >= 99) {
            $tone = ['pill' => 'bg-yellow-100 text-yellow-800 ring-yellow-200',
                     'msg'  => 'text-yellow-800',
                     'icon' => 'text-yellow-600',
                     'label'=> 'MINOR ROUNDING'];
        } elseif ($accuracy >= 95) {
            $tone = ['pill' => 'bg-amber-100 text-amber-800 ring-amber-200',
                     'msg'  => 'text-amber-800',
                     'icon' => 'text-amber-600',
                     'label'=> 'MINOR VARIANCE'];
        } else {
            $tone = ['pill' => 'bg-rose-100 text-rose-800 ring-rose-200',
                     'msg'  => 'text-rose-800',
                     'icon' => 'text-rose-600',
                     'label'=> 'SIGNIFICANT VARIANCE'];
        }

        $varianceColor = abs($variance) < 0.01 ? 'text-slate-700' : 'text-amber-700';
    @endphp

    {{-- ───────────────── Box 1: Reconciliation ───────────────── --}}
    <section class="mb-6 bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <header class="flex items-center justify-between px-6 py-4 border-b border-slate-200 bg-gradient-to-r from-slate-50 to-white">
            <div>
                <h3 class="text-lg font-semibold text-slate-900">Inventory Reconciliation</h3>
                <p class="text-xs text-slate-500 mt-0.5">
                    Stock ledger value vs. trial balance — accounts <code class="font-mono bg-slate-100 px-1 rounded">3%</code> (excl. <code class="font-mono bg-slate-100 px-1 rounded">39%</code> impairment)
                    @if ($asOfLabel)
                        <span class="ml-1">·</span>
                        <span class="font-medium text-slate-700">YTD as of {{ $asOfLabel }}</span>
                    @endif
                </p>
            </div>
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold ring-1 {{ $tone['pill'] }}">
                @if ($accuracy >= 99.99)
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                @else
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg>
                @endif
                {{ number_format($accuracy, 1) }}% — {{ $tone['label'] }}
            </span>
        </header>

        {{-- 3-column metric grid --}}
        <div class="grid grid-cols-1 md:grid-cols-3 divide-y md:divide-y-0 md:divide-x divide-slate-200">
            {{-- Inventory Value (with stock vs GL sub-comparison) --}}
            <div class="p-5">
                <p class="text-[11px] uppercase tracking-wide text-slate-500 font-semibold">Inventory Value</p>
                <p class="text-2xl font-bold text-slate-900 mt-1">{{ ea_money_short($stockValue, $data['currency']) }}</p>
                <div class="mt-2 space-y-0.5 text-[11px]">
                    <p class="text-slate-500">Stock ledger: <span class="font-mono text-slate-700">{{ ea_money_short($stockValue, $data['currency']) }}</span></p>
                    <p class="text-slate-500">GL balance:&nbsp;&nbsp;&nbsp;<span class="font-mono text-slate-700">{{ ea_money_short($glBalance, $data['currency']) }}</span></p>
                </div>
            </div>
            {{-- Variance --}}
            <div class="p-5">
                <p class="text-[11px] uppercase tracking-wide text-slate-500 font-semibold">Variance</p>
                <p class="text-2xl font-bold {{ $varianceColor }} mt-1">{{ ea_money_short($variance, $data['currency']) }}</p>
                <p class="text-[11px] text-slate-400 mt-1">GL − Stock</p>
            </div>
            {{-- Accuracy --}}
            <div class="p-5">
                <p class="text-[11px] uppercase tracking-wide text-slate-500 font-semibold">Accuracy</p>
                <p class="text-2xl font-bold {{ $tone['msg'] }} mt-1">{{ number_format($accuracy, 1) }}%</p>
                <p class="text-[11px] text-slate-400 mt-1">100% = fully matched</p>
            </div>
        </div>

        {{-- Status message --}}
        <div class="px-6 py-3 border-t border-slate-200 bg-slate-50/50">
            <p class="text-sm {{ $tone['msg'] }} flex items-start gap-2">
                @if ($accuracy >= 99.99)
                    <svg class="w-4 h-4 mt-0.5 flex-shrink-0 {{ $tone['icon'] }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                @else
                    <svg class="w-4 h-4 mt-0.5 flex-shrink-0 {{ $tone['icon'] }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg>
                @endif
                <span>{{ $interpretation }}</span>
            </p>
        </div>

        {{-- Collapsible trial balance breakdown --}}
        <details class="border-t border-slate-200 group">
            <summary class="px-6 py-3 text-sm font-medium text-slate-700 cursor-pointer hover:bg-slate-50 flex items-center gap-2 select-none">
                <svg class="w-4 h-4 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                Trial balance breakdown
                <span class="text-xs text-slate-400 font-normal">({{ count($glAccounts) }} {{ count($glAccounts) === 1 ? 'account' : 'accounts' }})</span>
            </summary>
            <div class="overflow-x-auto border-t border-slate-200">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="text-left py-2.5 px-6 font-semibold text-slate-600 text-xs uppercase tracking-wide">Code</th>
                            <th class="text-left py-2.5 px-4 font-semibold text-slate-600 text-xs uppercase tracking-wide">Account name</th>
                            <th class="text-right py-2.5 px-6 font-semibold text-slate-600 text-xs uppercase tracking-wide">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        @if (empty($glAccounts))
                            <tr>
                                <td colspan="3" class="py-6 px-6 text-center text-slate-500 text-xs">
                                    No inventory accounts found (3% range, excluding 39%)
                                </td>
                            </tr>
                        @else
                            @foreach ($glAccounts as $account)
                                <tr class="border-t border-slate-100 hover:bg-slate-50">
                                    <td class="py-2.5 px-6 font-mono text-xs text-slate-700">{{ $account['code'] ?? '—' }}</td>
                                    <td class="py-2.5 px-4 text-slate-900">{{ $account['name'] ?? 'Unknown' }}</td>
                                    <td class="text-right py-2.5 px-6 font-mono text-slate-700">{{ ea_money_short($account['balance'] ?? 0, $data['currency']) }}</td>
                                </tr>
                            @endforeach
                            <tr class="border-t-2 border-slate-300 bg-slate-50 font-semibold">
                                <td class="py-2.5 px-6"></td>
                                <td class="py-2.5 px-4 text-slate-900">Total inventory accounts</td>
                                <td class="text-right py-2.5 px-6 font-mono text-slate-900">{{ ea_money_short($glBalance, $data['currency']) }}</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </details>
    </section>

    {{-- ───────────────── Box 2: Inventory Days (YTD) ───────────────── --}}
    <section class="mb-6 bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <header class="flex items-center justify-between px-6 py-4 border-b border-slate-200 bg-gradient-to-r from-slate-50 to-white">
            <div>
                <h3 class="text-lg font-semibold text-slate-900">Inventory Days</h3>
                <p class="text-xs text-slate-500 mt-0.5">
                    Days of stock-on-hand at YTD consumption rate
                    @if ($asOfLabel)
                        <span class="ml-1">·</span>
                        <span class="font-medium text-slate-700">YTD as of {{ $asOfLabel }}</span>
                    @endif
                </p>
            </div>
            <span class="text-[11px] text-slate-400 font-mono">
                avg inv × {{ $invDays['period_days'] ?? 0 }} days ÷ COGS
            </span>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-3 divide-y md:divide-y-0 md:divide-x divide-slate-200">
            {{-- Average Inventory --}}
            <div class="p-5">
                <p class="text-[11px] uppercase tracking-wide text-slate-500 font-semibold">Average Inventory</p>
                <p class="text-2xl font-bold text-slate-900 mt-1">{{ ea_money_short($avgInventory, $data['currency']) }}</p>
                <p class="text-[11px] text-slate-400 mt-1">mean of month-end values</p>
            </div>
            {{-- COGS YTD --}}
            <div class="p-5">
                <p class="text-[11px] uppercase tracking-wide text-slate-500 font-semibold">COGS YTD</p>
                <p class="text-2xl font-bold text-slate-900 mt-1">{{ ea_money_short($cogsYtd, $data['currency']) }}</p>
                <p class="text-[11px] text-slate-400 mt-1">stock outflows at cost</p>
            </div>
            {{-- Days Outstanding --}}
            <div class="p-5">
                <p class="text-[11px] uppercase tracking-wide text-slate-500 font-semibold">Days Outstanding</p>
                <p class="text-2xl font-bold text-indigo-700 mt-1">{{ number_format($daysOutstanding, 1) }} days</p>
                <p class="text-[11px] text-slate-400 mt-1">at current consumption rate</p>
            </div>
        </div>
    </section>

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
            '<p class="text-center text-slate-500 py-6 text-sm">No trend data available — stock.valuation.layer is empty for the current year.</p>';
        return;
    }

    const labels = points.map(d => d.month);
    const values = points.map(d => d.value);

    const fmt = v => new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: currency,
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(v);

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
