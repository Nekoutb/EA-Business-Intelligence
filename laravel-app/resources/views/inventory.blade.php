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

    {{-- Reconciliation Summary --}}
    @php
        $reconciliation = $data['reconciliation'] ?? [];
    @endphp

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
        {{-- Stock Value --}}
        <x-kpi
            title="Stock Value"
            :value="ea_money_short($reconciliation['stock_value'] ?? 0, $data['currency'])"
            :delta="null"
            color="brand"
        />

        {{-- GL Inventory Balance --}}
        <x-kpi
            title="GL Inventory Balance"
            :value="ea_money_short($reconciliation['gl_balance'] ?? 0, $data['currency'])"
            :delta="null"
            color="sage"
        />

        {{-- Variance --}}
        @php
            $variance = $reconciliation['variance'] ?? 0;
            $variancePercent = $reconciliation['variance_percent'] ?? 0;
            $varianceColor = abs($variance) < 0.01 ? 'sage' : (abs($variancePercent) > 5 ? 'coral' : 'warm');
        @endphp
        <x-kpi
            title="Variance"
            :value="ea_money_short($variance, $data['currency'])"
            :sub="$variancePercent . '%'"
            :delta="$variancePercent"
            :color="$varianceColor"
        />
    </div>

    {{-- Reconciliation Status --}}
    <div class="mb-8 p-6 bg-white rounded-lg border border-slate-200 shadow-sm">
        <h3 class="text-lg font-semibold text-slate-900 mb-4">Reconciliation Status</h3>

        @php
            $interpretation = $reconciliation['interpretation'] ?? '';
            $isReconciled = $reconciliation['reconciled'] ?? false;
        @endphp

        <div class="flex items-start gap-3 mb-6">
            @if ($isReconciled)
                <div class="w-12 h-12 rounded-full bg-emerald-100 flex items-center justify-center">
                    <svg class="w-6 h-6 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                </div>
                <div>
                    <p class="font-medium text-emerald-900">Inventory Reconciled</p>
                    <p class="text-xs text-emerald-700 mt-1">{{ $interpretation }}</p>
                </div>
            @else
                <div class="w-12 h-12 rounded-full bg-amber-100 flex items-center justify-center">
                    <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4v2m0-6a4 4 0 110-8 4 4 0 010 8z"></path>
                    </svg>
                </div>
                <div>
                    <p class="font-medium text-amber-900">Variance Detected</p>
                    <p class="text-xs text-amber-700 mt-1">{{ $interpretation }}</p>
                </div>
            @endif
        </div>

        {{-- Detailed Reconciliation Table --}}
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200">
                        <th class="text-left py-3 px-4 font-semibold text-slate-600">Account</th>
                        <th class="text-right py-3 px-4 font-semibold text-slate-600">GL Balance</th>
                        <th class="text-right py-3 px-4 font-semibold text-slate-600">Stock Value</th>
                        <th class="text-right py-3 px-4 font-semibold text-slate-600">Variance</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $glAccounts = $data['gl_inventory']['by_account'] ?? [];
                    @endphp

                    @if (empty($glAccounts))
                        <tr>
                            <td colspan="4" class="py-6 px-4 text-center text-slate-500 text-xs">
                                No inventory GL accounts found (check OHADA 3XX accounts)
                            </td>
                        </tr>
                    @else
                        @foreach ($glAccounts as $account)
                            @php
                                $glBalance = $account['balance'] ?? 0;
                                $variance = $glBalance - ($reconciliation['stock_value'] ?? 0);
                            @endphp
                            <tr class="border-b border-slate-100 hover:bg-slate-50">
                                <td class="py-3 px-4">
                                    <div class="font-medium text-slate-900">{{ $account['name'] ?? 'Unknown' }}</div>
                                    <div class="text-xs text-slate-500 mt-1">{{ $account['code'] ?? '' }}</div>
                                </td>
                                <td class="text-right py-3 px-4 font-mono text-slate-600">
                                    {{ ea_money_short($glBalance, $data['currency']) }}
                                </td>
                                <td class="text-right py-3 px-4 font-mono text-slate-600">
                                    {{ ea_money_short($reconciliation['stock_value'] ?? 0, $data['currency']) }}
                                </td>
                                <td class="text-right py-3 px-4">
                                    @php
                                        $varColor = abs($variance) < 0.01 ? 'text-emerald-600' : 'text-amber-600';
                                    @endphp
                                    <span class="font-mono {{ $varColor }}">
                                        {{ ea_money_short($variance, $data['currency']) }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    @endif
                </tbody>
            </table>
        </div>
    </div>

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
