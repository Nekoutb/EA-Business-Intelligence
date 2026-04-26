@extends('layouts.app')

@section('title', 'Inventory')

@section('header')
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-bold text-slate-900">Inventory</h2>
            @if ($data['period_label'])
                <p class="text-xs text-slate-500 mt-1">
                    {{ $data['period_label'] }}
                    @if ($data['error'])
                        — Last sync failed
                    @else
                        — Last synced just now
                    @endif
                </p>
            @endif
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
    {{-- Period Filter --}}
    <x-period-filter />

    {{-- Error Display --}}
    @if ($data['error'])
        <div class="mb-6 px-4 py-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-sm">
            <strong>Error:</strong> {{ $data['error'] }}
        </div>
    @endif

    {{-- ───────────────── Consolidated Reconciliation Box ───────────────── --}}
    @php
        $reconciliation = $data['reconciliation'] ?? [];
        $stockValue   = $reconciliation['stock_value']      ?? 0;
        $glBalance    = $reconciliation['gl_balance']       ?? 0;
        $variance     = $reconciliation['variance']         ?? 0;
        $accuracy     = $reconciliation['accuracy_percent'] ?? 0;
        $interpretation = $reconciliation['interpretation'] ?? '';
        $glAccounts   = $data['gl_inventory']['by_account'] ?? [];

        // Pill + status message colour, driven entirely by accuracy %.
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

    <section class="mb-8 bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        {{-- Header bar --}}
        <header class="flex items-center justify-between px-6 py-4 border-b border-slate-200 bg-gradient-to-r from-slate-50 to-white">
            <div>
                <h3 class="text-lg font-semibold text-slate-900">Inventory Reconciliation</h3>
                <p class="text-xs text-slate-500 mt-0.5">Stock ledger value vs. trial balance — accounts <code class="font-mono bg-slate-100 px-1 rounded">3%</code> (excl. <code class="font-mono bg-slate-100 px-1 rounded">39%</code> impairment)</p>
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

        {{-- 4-column metric grid --}}
        <div class="grid grid-cols-2 md:grid-cols-4 divide-x divide-y md:divide-y-0 divide-slate-200">
            {{-- Inventory Value --}}
            <div class="p-5">
                <p class="text-[11px] uppercase tracking-wide text-slate-500 font-semibold">Inventory Value</p>
                <p class="text-2xl font-bold text-slate-900 mt-1">{{ ea_money_short($stockValue, $data['currency']) }}</p>
                <p class="text-[11px] text-slate-400 mt-1">from <code class="font-mono">stock.quant</code></p>
            </div>
            {{-- GL Balance --}}
            <div class="p-5">
                <p class="text-[11px] uppercase tracking-wide text-slate-500 font-semibold">GL Balance</p>
                <p class="text-2xl font-bold text-slate-900 mt-1">{{ ea_money_short($glBalance, $data['currency']) }}</p>
                <p class="text-[11px] text-slate-400 mt-1">trial balance · accounts 3%</p>
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
                            {{-- Total row --}}
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

    {{-- YTD Inventory Value Trend Chart --}}
    <div class="mb-8 p-6 bg-white rounded-lg border border-slate-200 shadow-sm">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-slate-900">Inventory Value Trend</h3>
            <div class="flex gap-2">
                <button type="button" id="trendByMonth" class="px-3 py-1 text-xs font-medium rounded bg-indigo-100 text-indigo-700 hover:bg-indigo-200 transition">
                    By Month
                </button>
                <button type="button" id="trendByWeek" class="px-3 py-1 text-xs font-medium rounded bg-slate-100 text-slate-600 hover:bg-slate-200 transition">
                    By Week
                </button>
            </div>
        </div>

        <canvas id="inventoryChart" height="60"></canvas>
    </div>

    {{-- Inventory Breakdown --}}
    @php
        $byProduct = $data['current']['by_product'] ?? [];
    @endphp

    @if (!empty($byProduct))
        <div class="p-6 bg-white rounded-lg border border-slate-200 shadow-sm">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Inventory by Product</h3>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200">
                            <th class="text-left py-3 px-4 font-semibold text-slate-600">Product</th>
                            <th class="text-right py-3 px-4 font-semibold text-slate-600">Quantity</th>
                            <th class="text-right py-3 px-4 font-semibold text-slate-600">Unit Cost</th>
                            <th class="text-right py-3 px-4 font-semibold text-slate-600">Total Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($byProduct as $product => $data)
                            <tr class="border-b border-slate-100 hover:bg-slate-50">
                                <td class="py-3 px-4">
                                    <div class="font-medium text-slate-900">{{ $product }}</div>
                                    <div class="text-xs text-slate-500 mt-1">{{ $data['code'] ?? '' }}</div>
                                </td>
                                <td class="text-right py-3 px-4 font-mono text-slate-600">
                                    {{ number_format($data['qty'], 2) }}
                                </td>
                                <td class="text-right py-3 px-4 font-mono text-slate-600">
                                    {{ ea_money_short($data['cost'], '') }}
                                </td>
                                <td class="text-right py-3 px-4 font-mono text-slate-900 font-semibold">
                                    {{ ea_money_short($data['value'], '') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const trendData = @json($data['trend'] ?? []);
    const currency = @json($data['currency'] ?? 'USD');

    let currentMode = 'month'; // 'month' or 'week'
    let chart = null;

    function initChart(mode) {
        const data = mode === 'month' ? trendData.by_month : trendData.by_week;

        if (!data || data.length === 0) {
            document.getElementById('inventoryChart').parentElement.innerHTML =
                '<p class="text-center text-slate-500 py-6">No trend data available</p>';
            return;
        }

        const labels = data.map(d => mode === 'month' ? d.month : d.date);
        const values = data.map(d => d.value);

        const ctx = document.getElementById('inventoryChart').getContext('2d');

        if (chart) {
            chart.destroy();
        }

        chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Inventory Value (' + currency + ')',
                    data: values,
                    borderColor: 'rgb(79, 70, 229)',
                    backgroundColor: 'rgba(79, 70, 229, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
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
                    legend: {
                        display: true,
                        position: 'top',
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: { size: 13, weight: 'bold' },
                        bodyFont: { size: 12 },
                        callbacks: {
                            label: function(context) {
                                const value = context.parsed.y;
                                return 'Value: ' + new Intl.NumberFormat('en-US', {
                                    style: 'currency',
                                    currency: currency,
                                    minimumFractionDigits: 0,
                                    maximumFractionDigits: 0
                                }).format(value);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return new Intl.NumberFormat('en-US', {
                                    style: 'currency',
                                    currency: currency,
                                    minimumFractionDigits: 0,
                                    maximumFractionDigits: 0
                                }).format(value);
                            }
                        }
                    }
                }
            }
        });
    }

    // Initialize with month view
    initChart('month');

    // Tab switching
    document.getElementById('trendByMonth').addEventListener('click', function() {
        currentMode = 'month';
        document.getElementById('trendByMonth').classList.add('bg-indigo-100', 'text-indigo-700');
        document.getElementById('trendByMonth').classList.remove('bg-slate-100', 'text-slate-600');
        document.getElementById('trendByWeek').classList.remove('bg-indigo-100', 'text-indigo-700');
        document.getElementById('trendByWeek').classList.add('bg-slate-100', 'text-slate-600');
        initChart('month');
    });

    document.getElementById('trendByWeek').addEventListener('click', function() {
        currentMode = 'week';
        document.getElementById('trendByWeek').classList.add('bg-indigo-100', 'text-indigo-700');
        document.getElementById('trendByWeek').classList.remove('bg-slate-100', 'text-slate-600');
        document.getElementById('trendByMonth').classList.remove('bg-indigo-100', 'text-indigo-700');
        document.getElementById('trendByMonth').classList.add('bg-slate-100', 'text-slate-600');
        initChart('week');
    });
});
</script>
@endpush
