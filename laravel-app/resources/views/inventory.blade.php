@extends('layouts.app')

@section('title', 'Inventory')

@section('page-title', 'Inventory')
@section('content')
    @if ($error ?? null)
        <div class="mb-6 px-4 py-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-sm">
            <div class="font-medium mb-1">Could not load data from Odoo</div>
            <div>{{ $error }}</div>
            <div class="mt-2 text-[12px]">Go to <a href="{{ tenant_url('/settings') }}" class="underline">Settings</a> to verify your Odoo connection.</div>
        </div>
    @elseif (!$data)
        <div class="mb-6 px-4 py-3 rounded-lg bg-slate-50 border border-slate-200 text-slate-600 text-sm">
            No data available.
        </div>
    @else
    {{-- Error Display --}}
    @if ($data['error'] ?? null)
        <div class="mb-6 px-4 py-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-sm">
            <strong>Partial error:</strong> {{ $data['error'] }}
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

    @endif {{-- end: $error / $data null guard --}}

    {{-- ════════════ Stock Operations (from Odoo Inventory) ════════════
         Independent of the GL boxes above: renders from StockOperationsService
         and stays useful even when the trial-balance section fails. --}}
    @if ($stockError ?? null)
        <div class="mb-6 px-4 py-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-sm">
            <strong>Stock operations unavailable:</strong> {{ $stockError }}
        </div>
    @elseif (($stock['installed'] ?? null) === false)
        <section class="mb-6 bg-white rounded-xl border border-slate-200 shadow-sm p-6">
            <div class="flex items-start gap-3">
                <svg class="w-8 h-8 text-slate-300 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.27 6.96 12 12.01l8.73-5.05M12 22.08V12"/></svg>
                <div>
                    <h3 class="text-base font-semibold text-slate-900">Stock-level analytics not available yet</h3>
                    <p class="text-sm text-slate-600 mt-1 max-w-2xl">
                        The Odoo <strong>Inventory</strong> module isn't in use on this database, so the figures above
                        are book values from the general ledger. Once warehouse operations (receipts, deliveries,
                        counts) run in Odoo, this section automatically shows per-product stock on hand, slow-moving
                        stock, reorder alerts, shrinkage and cycle-count discipline.
                    </p>
                </div>
            </div>
        </section>
    @elseif ($stock ?? null)
        @php
            $sCcy    = $stock['currency'] ?? 'XAF';
            $totals  = $stock['totals'] ?? [];
            $ageing  = $stock['ageing'] ?? null;
            $reorder = $stock['reorder'] ?? null;
        @endphp

        <div class="flex items-baseline justify-between mt-2 mb-3">
            <h2 class="text-lg font-semibold text-slate-900">Stock Operations</h2>
            <span class="text-[11px] text-slate-400">Odoo Inventory · valued via {{ $stock['valuation_source'] }} · as of {{ $stock['as_of'] }}</span>
        </div>

        @if (!empty($stock['errors']))
            <div class="mb-4 px-4 py-2.5 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-[12px]">
                <strong>{{ count($stock['errors']) }} section(s) unavailable:</strong>
                {{ implode(' · ', $stock['errors']) }}
            </div>
        @endif

        {{-- KPI strip --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <x-kpi title="Stock Value" :value="ea_money_short((float)($totals['on_hand_value'] ?? 0), $sCcy)" color="indigo"
                   hint="Value of stock currently on hand, at cost ({{ $stock['valuation_source'] }})."/>
            <x-kpi title="SKUs in Stock" :value="number_format($totals['sku_count'] ?? 0)" color="sage"
                   :sub="($totals['negative_stock_count'] ?? 0) > 0 ? ($totals['negative_stock_count'] . ' negative — see hygiene') : null"
                   hint="Distinct products with positive on-hand quantity in internal locations."/>
            <x-kpi title="Slow-Mover Value"
                   :value="($totals['slow_mover_value'] ?? null) !== null ? ea_money_short((float)$totals['slow_mover_value'], $sCcy) : '—'"
                   :sub="($totals['slow_mover_value'] ?? null) === null ? 'movement history unavailable' : null"
                   color="coral"
                   hint="Value of stock with no warehouse movement for over 90 days — candidate for the OHADA 39X impairment provision and for promotions or clearance."/>
            <x-kpi title="Reorder Coverage" :value="$reorder && $reorder['coverage_pct'] !== null ? number_format($reorder['coverage_pct'], 0) . '%' : '—'" color="warm"
                   :sub="$reorder ? ($reorder['rules_count'] . ' rule(s) · ' . count($reorder['below_min'] ?? []) . ' below min') : null"
                   hint="Share of storable products protected by a min/max reordering rule in Odoo. Low coverage means stockouts are caught by people, not the system."/>
        </div>

        {{-- Per-SKU stock table --}}
        @php $skuRows = array_slice($stock['skus'] ?? [], 0, 20); @endphp
        <section class="mb-6 bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <header class="px-6 py-4 border-b border-slate-200 bg-gradient-to-r from-slate-50 to-white flex items-baseline justify-between">
                <div>
                    <h3 class="text-base font-semibold text-slate-900">Stock by Product</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Top {{ count($skuRows) }} of {{ count($stock['skus'] ?? []) }} by value · on-hand / reserved / incoming from Odoo Inventory</p>
                </div>
            </header>
            <div class="ea-tablewrap">
                <table class="w-full text-[12.5px]">
                    <thead class="bg-slate-50 text-[10px] uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="text-left  py-2 px-3 font-semibold">Product</th>
                            <th class="text-right py-2 px-3 font-semibold">On hand</th>
                            <th class="text-right py-2 px-3 font-semibold" title="Quantity reserved against confirmed orders">Reserved</th>
                            <th class="text-right py-2 px-3 font-semibold" title="Quantity on confirmed incoming receipts not yet received">Incoming</th>
                            <th class="text-right py-2 px-3 font-semibold">Unit cost</th>
                            <th class="text-right py-2 px-3 font-semibold">Value</th>
                            <th class="text-left  py-2 px-3 font-semibold" title="Date of the most recent validated stock movement">Last move</th>
                            <th class="text-right py-2 px-3 font-semibold" title="On-hand ÷ daily sell-through (invoiced, trailing 90 days)">Days cover</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($skuRows as $s)
                            <tr class="table-row-hover {{ $s['on_hand'] < 0 ? 'bg-rose-50/40' : '' }}">
                                <td class="py-2 px-3">
                                    <div class="font-medium text-slate-800">{{ $s['name'] }}</div>
                                    <div class="text-[10.5px] text-slate-400">{{ $s['code'] ? $s['code'] . ' · ' : '' }}{{ $s['category'] }}</div>
                                </td>
                                <td class="py-2 px-3 text-right tabular-nums {{ $s['on_hand'] < 0 ? 'text-rose-700 font-semibold' : '' }}">{{ number_format($s['on_hand'], 0) }}</td>
                                <td class="py-2 px-3 text-right tabular-nums text-slate-500">{{ number_format($s['reserved'], 0) }}</td>
                                <td class="py-2 px-3 text-right tabular-nums text-slate-500">{{ number_format($s['incoming'], 0) }}</td>
                                <td class="py-2 px-3 text-right tabular-nums">{{ ea_money_short($s['cost'], $sCcy) }}</td>
                                <td class="py-2 px-3 text-right tabular-nums font-semibold">{{ ea_money_short($s['value'], $sCcy) }}</td>
                                <td class="py-2 px-3 text-slate-500">
                                    {{ $s['last_move'] ?? 'never' }}
                                    @if (($s['age_days'] ?? 0) > 90)
                                        <span class="text-[9px] font-bold uppercase text-amber-700 bg-amber-50 border border-amber-200 rounded px-1 ml-1">{{ $s['age_days'] }}d</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3 text-right tabular-nums">{{ $s['days_cover'] !== null ? number_format($s['days_cover']) : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="py-6 text-center text-slate-400 text-sm">No stock on hand — quants are empty in internal locations.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
            {{-- Ageing + indicative provision --}}
            @if ($ageing)
            <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
                <h3 class="text-base font-semibold text-slate-900">Stock Ageing
                    <span class="ml-1 w-3.5 h-3.5 inline-flex items-center justify-center rounded-full bg-slate-100 text-slate-500 text-[9px] font-bold cursor-help align-middle"
                          title="Aged by last warehouse movement. Old stock loses value — OHADA requires an impairment provision (39X accounts) for slow or obsolete stock.">?</span>
                </h3>
                <table class="w-full text-[12px] mt-3">
                    <thead class="text-[10px] uppercase tracking-wider text-slate-400">
                        <tr><th class="text-left py-1">Bucket</th><th class="text-right py-1">SKUs</th><th class="text-right py-1">Value</th><th class="text-right py-1" title="Indicative tier — set the actual rate in your accounting policy">Indicative 39X</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($ageing['buckets'] as $b)
                            <tr>
                                <td class="py-1.5 text-slate-700">{{ $b['label'] }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ $b['sku_count'] }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ ea_money_short($b['value'], $sCcy) }}</td>
                                <td class="py-1.5 text-right tabular-nums {{ $b['provision'] > 0 ? 'text-amber-700 font-semibold' : 'text-slate-400' }}">
                                    {{ $b['tier'] > 0 ? ea_money_short($b['provision'], $sCcy) . ' (' . (int)($b['tier']*100) . '%)' : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="text-[11px] text-slate-500 mt-3 border-t border-slate-100 pt-2">
                    Indicative impairment provision: <strong class="text-amber-700">{{ ea_money_short($ageing['indicative_provision'], $sCcy) }}</strong>
                    — a starting point for the 39X provision discussion, not an accounting policy.
                </p>
            </section>
            @endif

            {{-- Reorder alerts --}}
            @if ($reorder && !empty($reorder['below_min']))
            <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
                <h3 class="text-base font-semibold text-slate-900">Below Reorder Minimum</h3>
                <p class="text-xs text-slate-500 mt-0.5">{{ count($reorder['below_min']) }} product(s) under their min/max rule — order now or stock out.</p>
                <table class="w-full text-[12px] mt-3">
                    <thead class="text-[10px] uppercase tracking-wider text-slate-400">
                        <tr><th class="text-left py-1">Product</th><th class="text-right py-1">On hand</th><th class="text-right py-1">Min</th><th class="text-right py-1">Max</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($reorder['below_min'] as $r)
                            <tr>
                                <td class="py-1.5 text-slate-700">{{ $r['product'] }}</td>
                                <td class="py-1.5 text-right tabular-nums text-rose-700 font-semibold">{{ number_format($r['on_hand'], 0) }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ number_format($r['min'], 0) }}</td>
                                <td class="py-1.5 text-right tabular-nums text-slate-400">{{ number_format($r['max'], 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
            @endif
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
            {{-- Cycle counts --}}
            <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
                <h3 class="text-sm font-semibold text-slate-900">Cycle Counting
                    <span class="ml-1 w-3.5 h-3.5 inline-flex items-center justify-center rounded-full bg-slate-100 text-slate-500 text-[9px] font-bold cursor-help align-middle"
                          title="Physical inventory counts booked in Odoo over the last 12 months. Regular counts are the control that keeps book stock equal to physical stock.">?</span>
                </h3>
                @if (($stock['cycle_counts']['available'] ?? false))
                    <dl class="mt-3 space-y-2 text-[12px]">
                        <div class="flex justify-between"><dt class="text-slate-500">Adjustments (12m)</dt><dd class="font-semibold tabular-nums">{{ number_format($stock['cycle_counts']['adjustments_12m'] ?? 0) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Last count</dt><dd class="font-semibold">{{ $stock['cycle_counts']['last_count_date'] ?? 'never' }}</dd></div>
                        @if (($stock['cycle_counts']['skus_adjusted'] ?? null) !== null)
                            <div class="flex justify-between"><dt class="text-slate-500">SKUs touched</dt><dd class="font-semibold tabular-nums">{{ number_format($stock['cycle_counts']['skus_adjusted']) }}</dd></div>
                        @endif
                    </dl>
                    @if (($stock['cycle_counts']['adjustments_12m'] ?? 0) === 0)
                        <p class="text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-1.5 mt-3">No counts booked in 12 months — book stock is unverified. Consider a cycle-count program (count A-items monthly, B quarterly, C annually).</p>
                    @endif
                @else
                    <p class="text-[12px] text-slate-400 mt-3">Not available on this Odoo version.</p>
                @endif
            </section>

            {{-- Shrinkage --}}
            <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
                <h3 class="text-sm font-semibold text-slate-900">Shrinkage / Scrap
                    <span class="ml-1 w-3.5 h-3.5 inline-flex items-center justify-center rounded-full bg-slate-100 text-slate-500 text-[9px] font-bold cursor-help align-middle"
                          title="Authorized write-offs (stock.scrap) over the last 12 months, valued at cost. Unexplained variance shows up in cycle-count adjustments instead — both belong in a shrinkage policy.">?</span>
                </h3>
                @if (($stock['shrinkage']['available'] ?? false))
                    <dl class="mt-3 space-y-2 text-[12px]">
                        @if (($stock['shrinkage']['count_12m'] ?? null) === null && isset($stock['shrinkage']['count_all_time']))
                            {{-- Legacy Odoo without a usable scrap date field — be honest about the period. --}}
                            <div class="flex justify-between"><dt class="text-slate-500">Scraps (all-time)</dt><dd class="font-semibold tabular-nums">{{ number_format($stock['shrinkage']['count_all_time']) }}</dd></div>
                        @else
                            <div class="flex justify-between"><dt class="text-slate-500">Scraps (12m)</dt><dd class="font-semibold tabular-nums">{{ number_format($stock['shrinkage']['count_12m'] ?? 0) }}</dd></div>
                        @endif
                        @if (($stock['shrinkage']['value_12m'] ?? null) !== null)
                            <div class="flex justify-between"><dt class="text-slate-500">Value written off</dt><dd class="font-semibold tabular-nums">{{ ea_money_short((float)$stock['shrinkage']['value_12m'], $sCcy) }}</dd></div>
                        @endif
                    </dl>
                    @foreach (($stock['shrinkage']['recent'] ?? []) as $sc)
                        <div class="flex justify-between text-[11px] text-slate-500 mt-1.5"><span class="truncate">{{ $sc['product'] }} × {{ $sc['qty'] }}</span><span class="tabular-nums shrink-0 ml-2">{{ $sc['date'] }}</span></div>
                    @endforeach
                @else
                    <p class="text-[12px] text-slate-400 mt-3">Not available.</p>
                @endif
            </section>

            {{-- Hygiene + costing --}}
            <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
                <h3 class="text-sm font-semibold text-slate-900">Data Hygiene & Costing</h3>
                <dl class="mt-3 space-y-2 text-[12px]">
                    @if (($stock['hygiene']['zero_cost_storables'] ?? null) !== null)
                        <div class="flex justify-between">
                            <dt class="text-slate-500" title="Products with no standard cost distort every margin and valuation figure in the suite.">Zero-cost storables</dt>
                            <dd class="font-semibold tabular-nums {{ ($stock['hygiene']['zero_cost_storables'] ?? 0) > 0 ? 'text-amber-700' : '' }}">{{ number_format($stock['hygiene']['zero_cost_storables']) }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between"><dt class="text-slate-500">Archived with stock</dt><dd class="font-semibold tabular-nums {{ ($stock['hygiene']['archived_with_stock'] ?? 0) > 0 ? 'text-amber-700' : '' }}">{{ number_format($stock['hygiene']['archived_with_stock'] ?? 0) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Negative stock SKUs</dt><dd class="font-semibold tabular-nums {{ ($totals['negative_stock_count'] ?? 0) > 0 ? 'text-rose-700' : '' }}">{{ number_format($totals['negative_stock_count'] ?? 0) }}</dd></div>
                    @if ($stock['costing']['available'] ?? false)
                        <div class="flex justify-between border-t border-slate-100 pt-2">
                            <dt class="text-slate-500" title="Costing method per product category (standard price, average cost, FIFO) — the basis of the written costing policy.">Costing methods</dt>
                            <dd class="font-semibold text-right">
                                @foreach ($stock['costing']['methods'] as $m => $n){{ $m }} ({{ $n }})@if (!$loop->last) · @endif @endforeach
                            </dd>
                        </div>
                    @endif
                    @if (($stock['expiry']['tracked'] ?? false))
                        <div class="flex justify-between"><dt class="text-slate-500">Lots tracked</dt><dd class="font-semibold tabular-nums">{{ number_format($stock['expiry']['lot_count'] ?? 0) }}</dd></div>
                        @if (($stock['expiry']['expiry_dates'] ?? false) && ($stock['expiry']['expired_count'] ?? 0) > 0)
                            <div class="flex justify-between"><dt class="text-slate-500">Expired lots</dt><dd class="font-semibold tabular-nums text-rose-700">{{ number_format($stock['expiry']['expired_count']) }}</dd></div>
                        @endif
                    @endif
                </dl>
            </section>
        </div>

        {{-- Expiring lots (only when expiry tracking is active and something expires soon) --}}
        @if (($stock['expiry']['expiry_dates'] ?? false) && !empty($stock['expiry']['expiring_90d']))
        <section class="mb-6 bg-white rounded-xl border border-amber-200 shadow-sm p-5">
            <h3 class="text-sm font-semibold text-slate-900">Lots Expiring Within 90 Days</h3>
            <table class="w-full text-[12px] mt-3">
                <thead class="text-[10px] uppercase tracking-wider text-slate-400">
                    <tr><th class="text-left py-1">Lot</th><th class="text-left py-1">Product</th><th class="text-right py-1">Expiry</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($stock['expiry']['expiring_90d'] as $lot)
                        <tr><td class="py-1.5 mono">{{ $lot['lot'] }}</td><td class="py-1.5">{{ $lot['product'] }}</td><td class="py-1.5 text-right tabular-nums text-amber-700 font-semibold">{{ $lot['date'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
            <p class="text-[11px] text-slate-500 mt-2">Sell or rotate these first (FEFO — first-expired, first-out).</p>
        </section>
        @endif
    @endif
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
