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

    {{-- ───────────── Two compact boxes side-by-side ───────────── --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">

        @php
            $beginningInv = $invDays['beginning_inventory'] ?? 0;
            $endingInv    = $invDays['ending_inventory']    ?? 0;
        @endphp

        {{-- Box 1: Reconciliation — collapsed by default, headline %  visible --}}
        <details class="group/recon bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <summary class="px-5 py-4 cursor-pointer list-none flex items-center justify-between gap-4 hover:bg-slate-50 transition [&::-webkit-details-marker]:hidden">
                <div class="flex-1 min-w-0">
                    <h3 class="text-base font-semibold text-slate-900">Inventory Reconciliation</h3>
                    @if ($asOfLabel)
                        <p class="text-xs text-slate-500 mt-0.5">
                            <span class="font-medium text-slate-700">YTD as of {{ $asOfLabel }}</span>
                        </p>
                    @endif
                </div>
                <div class="flex items-center gap-3 flex-shrink-0">
                    <span class="text-2xl font-bold {{ $tone['msg'] }}">{{ number_format($accuracy, 1) }}%</span>
                    <svg class="w-4 h-4 text-slate-400 transition-transform group-open/recon:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </summary>

            <div class="border-t border-slate-200">
                <p class="text-xs text-slate-500 px-5 pt-3">
                    Stock ledger vs trial balance · accounts <code class="font-mono bg-slate-100 px-1 rounded">3%</code> (excl. <code class="font-mono bg-slate-100 px-1 rounded">39%</code>)
                </p>

                <dl class="px-5 py-4 space-y-3">
                    <div class="flex items-baseline justify-between border-b border-slate-100 pb-2">
                        <dt class="text-sm text-slate-600">Stock ledger (inventory)</dt>
                        <dd class="text-base font-semibold text-slate-900">{{ ea_money_short($stockValue, $data['currency']) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between border-b border-slate-100 pb-2">
                        <dt class="text-sm text-slate-600">General ledger</dt>
                        <dd class="text-base font-semibold text-slate-900">{{ ea_money_short($glBalance, $data['currency']) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between border-b border-slate-100 pb-2">
                        <dt class="text-sm text-slate-600">Variance <span class="text-[11px] text-slate-400">(GL − Stock)</span></dt>
                        <dd class="text-base font-semibold {{ $varianceColor }}">{{ ea_money_short($variance, $data['currency']) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between">
                        <dt class="text-sm text-slate-600">Reconciliation</dt>
                        <dd class="text-lg font-bold {{ $tone['msg'] }}">{{ number_format($accuracy, 1) }}%</dd>
                    </div>
                </dl>

                {{-- Nested: Trial balance breakdown --}}
                <details class="border-t border-slate-200 group/breakdown">
                    <summary class="px-5 py-2.5 text-xs font-medium text-slate-600 cursor-pointer hover:bg-slate-50 flex items-center gap-2 select-none [&::-webkit-details-marker]:hidden">
                        <svg class="w-3.5 h-3.5 transition-transform group-open/breakdown:rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        Trial balance breakdown
                        <span class="text-[11px] text-slate-400 font-normal">({{ count($glAccounts) }} {{ count($glAccounts) === 1 ? 'account' : 'accounts' }})</span>
                    </summary>
                    <div class="overflow-x-auto border-t border-slate-200">
                        <table class="w-full text-xs">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="text-left py-2 px-5 font-semibold text-slate-600 uppercase tracking-wide">Code</th>
                                    <th class="text-left py-2 px-3 font-semibold text-slate-600 uppercase tracking-wide">Account</th>
                                    <th class="text-right py-2 px-5 font-semibold text-slate-600 uppercase tracking-wide">Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                @if (empty($glAccounts))
                                    <tr><td colspan="3" class="py-4 px-5 text-center text-slate-500">No inventory accounts found</td></tr>
                                @else
                                    @foreach ($glAccounts as $account)
                                        <tr class="border-t border-slate-100 hover:bg-slate-50">
                                            <td class="py-2 px-5 text-slate-700">{{ $account['code'] ?? '—' }}</td>
                                            <td class="py-2 px-3 text-slate-900">{{ $account['name'] ?? 'Unknown' }}</td>
                                            <td class="text-right py-2 px-5 text-slate-700">{{ ea_money_short($account['balance'] ?? 0, $data['currency']) }}</td>
                                        </tr>
                                    @endforeach
                                    <tr class="border-t-2 border-slate-300 bg-slate-50 font-semibold">
                                        <td class="py-2 px-5"></td>
                                        <td class="py-2 px-3 text-slate-900">Total</td>
                                        <td class="text-right py-2 px-5 text-slate-900">{{ ea_money_short($glBalance, $data['currency']) }}</td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </details>
            </div>
        </details>

        {{-- Box 2: Inventory Days — collapsed by default, headline days visible --}}
        <details class="group/days bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
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
