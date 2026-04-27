@extends('layouts.app')
@section('title', 'Treasury')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="text-[10px] uppercase tracking-[0.35em] text-[#8a8578] font-medium">Liquidity Cockpit · {{ $receivables['as_of'] ?? '—' }}</span>
            </div>
            <h1 class="text-2xl font-bold text-[#1e3d44]">Treasury & Working Capital</h1>
        </div>
        <div class="section-card px-4 py-2.5 text-[11px] text-[#8a8578]">
            <span class="font-semibold text-[#5a6a70]">Data sources:</span>
            AR ({{ $receivables['as_of'] ?? '—' }}) · AP ({{ $payables['as_of'] ?? '—' }})
        </div>
    </div>
@endsection

@section('content')
    @if ($error)
        <div class="bg-coral-50 border border-coral-200 text-coral-800 rounded-xl p-4 text-sm">{{ $error }}</div>
        @return
    @endif

    @php
        $ccy = $summary['currency'] ?? ($cashflowDirect['currency'] ?? 'USD');
    @endphp

    {{-- ════════════════════════════════════════════════════════════════
         DIRECT CASH FLOW STATEMENT (GL-sourced, not P&L proxy)
         Equation:  Opening + Collections − Σ(outflows) − Other = Closing
       ════════════════════════════════════════════════════════════════ --}}
    @if (!empty($cashflowDirect))
        @php
            $cf       = $cashflowDirect;
            $year     = $cf['period']['year'] ?? date('Y');
            $openAt   = $cf['period']['opening_as_of'] ?? '';
            $closeAt  = $cf['period']['closing_as_of'] ?? '';
            $netMove  = ($cf['closing_balance'] ?? 0) - ($cf['opening_balance'] ?? 0);
            $otherAbs = abs($cf['other'] ?? 0);
            $totalIn  = (float) ($cf['collections'] ?? 0);
            $totalOut = (float) ($cf['total_outflows'] ?? 0);
        @endphp
        <div class="mb-7">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-2">
                    <div class="w-1 h-4 rounded bg-brand-400"></div>
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">Direct Cash Flow Statement · {{ $year }} YTD</h2>
                </div>
                <span class="text-[10px] text-[#a09d95]">From GL movement on cash &amp; bank accounts</span>
            </div>

            <div class="section-card p-0 overflow-hidden">
                {{-- Headline strip: net change at-a-glance --}}
                <div class="grid grid-cols-2 lg:grid-cols-4 border-b border-[#e8e4de] bg-[#faf8f3]">
                    <div class="p-4 border-r border-[#e8e4de]">
                        <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Opening</div>
                        <div class="text-lg font-bold text-[#1e3d44] mt-1 tabular-nums">{{ ea_money_short((float)($cf['opening_balance'] ?? 0), $ccy) }}</div>
                        <div class="text-[10px] text-[#a09d95] mt-0.5">as of {{ $openAt }}</div>
                    </div>
                    <div class="p-4 border-r border-[#e8e4de]">
                        <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Closing</div>
                        <div class="text-lg font-bold text-[#1e3d44] mt-1 tabular-nums">{{ ea_money_short((float)($cf['closing_balance'] ?? 0), $ccy) }}</div>
                        <div class="text-[10px] text-[#a09d95] mt-0.5">as of {{ $closeAt }}</div>
                    </div>
                    <div class="p-4 border-r border-[#e8e4de]">
                        <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Net Movement</div>
                        <div class="text-lg font-bold {{ $netMove >= 0 ? 'text-sage-600' : 'text-coral-600' }} mt-1 tabular-nums">
                            {{ ($netMove >= 0 ? '+' : '−') }}{{ ea_money_short(abs($netMove), $ccy) }}
                        </div>
                        <div class="text-[10px] text-[#a09d95] mt-0.5">closing − opening</div>
                    </div>
                    <div class="p-4">
                        <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Net Cash Flow</div>
                        <div class="text-lg font-bold text-[#1e3d44] mt-1 tabular-nums">{{ ea_money_short($totalIn - $totalOut - ($cf['other'] ?? 0), $ccy) }}</div>
                        <div class="text-[10px] text-[#a09d95] mt-0.5">collections − outflows − other</div>
                    </div>
                </div>

                {{-- Statement table --}}
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[10px] text-[#8a8578] uppercase tracking-wider border-b border-[#e8e4de] bg-[#fdfcf8]">
                                <th class="px-5 py-3 font-semibold">Description</th>
                                <th class="px-5 py-3 text-right font-semibold w-48">Amount ({{ $ccy }})</th>
                            </tr>
                        </thead>
                        <tbody>
                            {{-- Opening (subtotal) --}}
                            <tr class="bg-brand-50/40 border-b border-[#e8e4de]">
                                <td class="px-5 py-3 font-semibold text-[#1e3d44]">Opening balance — cash &amp; bank</td>
                                <td class="px-5 py-3 text-right tabular-nums font-bold text-[#1e3d44]">{{ ea_money((float)($cf['opening_balance'] ?? 0), $ccy) }}</td>
                            </tr>

                            {{-- Collections --}}
                            <tr class="border-b border-[#f0ede7] hover:bg-sage-50/30">
                                <td class="px-5 py-2.5 text-[#2d3748]">
                                    <span class="text-sage-600 font-semibold mr-2">+</span>Collections from customers
                                </td>
                                <td class="px-5 py-2.5 text-right tabular-nums text-sage-700">
                                    +{{ ea_money($totalIn, $ccy) }}
                                </td>
                            </tr>

                            {{-- Outflows --}}
                            @foreach ($cf['outflows'] ?? [] as $row)
                                <tr class="border-b border-[#f0ede7] hover:bg-coral-50/30">
                                    <td class="px-5 py-2.5 text-[#2d3748] pl-9">
                                        <span class="text-coral-600 font-semibold mr-2">−</span>{{ $row['label'] }}
                                    </td>
                                    <td class="px-5 py-2.5 text-right tabular-nums {{ ($row['amount'] ?? 0) > 0 ? 'text-coral-700' : 'text-[#a09d95]' }}">
                                        @if (($row['amount'] ?? 0) > 0)−@endif{{ ea_money((float)($row['amount'] ?? 0), $ccy) }}
                                    </td>
                                </tr>
                            @endforeach

                            {{-- Other (balancing figure) --}}
                            <tr class="border-b border-[#f0ede7] hover:bg-warm-50/30">
                                <td class="px-5 py-2.5 text-[#2d3748] pl-9">
                                    <span class="font-semibold mr-2 {{ ($cf['other'] ?? 0) >= 0 ? 'text-coral-600' : 'text-sage-600' }}">{{ ($cf['other'] ?? 0) >= 0 ? '−' : '+' }}</span>
                                    Other
                                    <span class="text-[10px] text-[#a09d95] ml-1.5">(balancing — non-categorised cash movement)</span>
                                </td>
                                <td class="px-5 py-2.5 text-right tabular-nums {{ ($cf['other'] ?? 0) >= 0 ? 'text-coral-700' : 'text-sage-700' }}">
                                    {{ ($cf['other'] ?? 0) >= 0 ? '−' : '+' }}{{ ea_money($otherAbs, $ccy) }}
                                </td>
                            </tr>

                            {{-- Closing (subtotal) --}}
                            <tr class="bg-brand-50/40 border-t-2 border-[#1e3d44]">
                                <td class="px-5 py-3 font-semibold text-[#1e3d44]">Closing balance — cash &amp; bank (today)</td>
                                <td class="px-5 py-3 text-right tabular-nums font-bold text-[#1e3d44]">{{ ea_money((float)($cf['closing_balance'] ?? 0), $ccy) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                {{-- Methodology footer --}}
                <div class="px-5 py-2.5 bg-[#fdfcf8] border-t border-[#e8e4de] text-[10px] text-[#8a8578] leading-relaxed">
                    Direct method · Opening = Trial balance of cash &amp; bank ({{ $openAt }}) · Collections = cash debits to bank with corresponding AR credit · Outflows = cash credits with corresponding debit to the named expense category · Other plugs the equation to today's actual closing balance.
                </div>
            </div>
        </div>
    @endif

    {{-- ── Section: Working Capital KPIs ──────────────────────────────── --}}
    <div class="mb-7">
        <div class="flex items-center gap-2 mb-3">
            <div class="w-1 h-4 rounded bg-brand-400"></div>
            <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">Working Capital Status</h2>
        </div>
        <div class="grid grid-cols-2 xl:grid-cols-4 gap-3">
            <div class="kpi-card bg-white rounded-xl border border-[#e8e4de] shadow-sm overflow-hidden flex">
                <div class="accent-bar bg-brand-500 shrink-0"></div>
                <div class="p-4 flex-1">
                    <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Working Capital</div>
                    <div class="text-xl font-bold text-[#1e3d44] mt-1 tabular-nums">{{ ea_money_short((float)($summary['working_capital'] ?? 0), $ccy) }}</div>
                    <div class="text-[10px] text-[#a09d95] mt-0.5">AR – AP obligations</div>
                </div>
            </div>
            <div class="kpi-card bg-white rounded-xl border border-[#e8e4de] shadow-sm overflow-hidden flex">
                <div class="accent-bar bg-sage-500 shrink-0"></div>
                <div class="p-4 flex-1">
                    <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Net Cash Outcome</div>
                    <div class="text-xl font-bold text-[#1e3d44] mt-1 tabular-nums">{{ ea_money_short((float)($summary['net_cash'] ?? 0), $ccy) }}</div>
                    <div class="text-[10px] text-[#a09d95] mt-0.5">YTD net profit signal</div>
                </div>
            </div>
            <div class="kpi-card bg-white rounded-xl border border-[#e8e4de] shadow-sm overflow-hidden flex">
                <div class="accent-bar bg-warm-500 shrink-0"></div>
                <div class="p-4 flex-1">
                    <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Collection Health</div>
                    <div class="text-xl font-bold text-[#1e3d44] mt-1 tabular-nums">{{ number_format((float)($summary['collection_score'] ?? 0), 1) }}%</div>
                    <div class="text-[10px] text-[#a09d95] mt-0.5">100 − overdue rate</div>
                </div>
            </div>
            <div class="kpi-card bg-white rounded-xl border border-[#e8e4de] shadow-sm overflow-hidden flex">
                <div class="accent-bar bg-coral-500 shrink-0"></div>
                <div class="p-4 flex-1">
                    <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Runway</div>
                    <div class="text-xl font-bold text-[#1e3d44] mt-1 tabular-nums">
                        @if ($summary['runway_months'] ?? null)
                            {{ number_format((float) $summary['runway_months'], 1) }} mo
                        @else
                            —
                        @endif
                    </div>
                    <div class="text-[10px] text-[#a09d95] mt-0.5">AR coverage ÷ monthly burn</div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Section: Cash Balance Evolution (GL) + Journal Balances ─────── --}}
    <div class="grid lg:grid-cols-[1.4fr_1fr] gap-4 mb-7">
        {{-- Cash Balance Evolution Chart (GL-sourced, month-end 52* + 57*) --}}
        <div class="section-card p-5">
            <div class="flex items-center gap-2 mb-4">
                <div class="w-1 h-4 rounded bg-sage-400"></div>
                <div>
                    <h2 class="text-[13px] font-semibold text-[#1e3d44]">Cash Balance Evolution · YTD</h2>
                    <p class="text-[11px] text-[#8a8578]">Cumulative cash &amp; bank balance at month-end · accounts 52* + 57* ({{ $ccy }})</p>
                </div>
            </div>
            <div class="h-56"><canvas id="cashEvolutionChart"></canvas></div>
        </div>

        {{-- Journal Balances Table (bank & cash journals as of today) --}}
        @php
            $jb = $journalBalances ?? null;
            $jbRows  = $jb['rows']  ?? [];
            $jbTotal = $jb['total'] ?? 0;
            $jbAsOf  = $jb['as_of'] ?? '';
        @endphp
        <div class="section-card p-5">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-2">
                    <div class="w-1 h-4 rounded bg-brand-400"></div>
                    <div>
                        <h2 class="text-[13px] font-semibold text-[#1e3d44]">Bank &amp; Cash Journals</h2>
                        <p class="text-[11px] text-[#8a8578]">Closing balance per journal · {{ $jbAsOf }}</p>
                    </div>
                </div>
                <span class="text-[10px] text-[#a09d95] font-medium">{{ count($jbRows) }} journal{{ count($jbRows) === 1 ? '' : 's' }}</span>
            </div>

            @if (empty($jbRows))
                <div class="text-[12px] text-[#a09d95] italic py-6 text-center">No bank or cash journals configured.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[10px] text-[#8a8578] uppercase tracking-wider border-b border-[#e8e4de]">
                                <th class="pb-2.5 font-semibold">Journal</th>
                                <th class="pb-2.5 text-right font-semibold">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($jbRows as $j)
                                <tr class="table-row-hover border-b border-[#f0ede7]">
                                    <td class="py-2.5">
                                        <div class="text-[13px] font-medium text-[#2d3748] flex items-center gap-2">
                                            <span class="inline-block w-1.5 h-1.5 rounded-full {{ $j['type'] === 'bank' ? 'bg-brand-400' : 'bg-sage-400' }}"></span>
                                            {{ $j['name'] }}
                                        </div>
                                        <div class="text-[10px] text-[#a09d95] uppercase tracking-wider">{{ $j['type'] }}</div>
                                    </td>
                                    <td class="py-2.5 text-right tabular-nums text-[13px] {{ $j['balance'] < 0 ? 'text-coral-700' : 'text-[#1e3d44]' }}">
                                        {{ ea_money((float) $j['balance'], $ccy) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-[#1e3d44]">
                                <td class="pt-2.5 text-[12px] font-bold text-[#1e3d44] uppercase tracking-wider">Total</td>
                                <td class="pt-2.5 text-right tabular-nums text-[14px] font-bold text-[#1e3d44]">{{ ea_money((float) $jbTotal, $ccy) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                @if (!empty($cashflowDirect) && abs($jbTotal - ($cashflowDirect['closing_balance'] ?? 0)) > 0.5)
                    <div class="mt-3 text-[10px] text-warm-700 bg-warm-50 border border-warm-200 px-3 py-2 rounded">
                        Reconciliation note: total above differs from cash-flow closing by
                        <span class="font-semibold tabular-nums">{{ ea_money(abs($jbTotal - ($cashflowDirect['closing_balance'] ?? 0)), $ccy) }}</span>.
                        This usually means a journal posts to a non-52*/57* default account.
                    </div>
                @endif
            @endif
        </div>
    </div>

    {{-- ── Section: Conversion Cycle ──────────────────────────────────── --}}
    <div class="mb-7">
        <div class="flex items-center gap-2 mb-3">
            <div class="w-1 h-4 rounded bg-brand-400"></div>
            <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">Cash Conversion Cycle</h2>
        </div>
        <div class="grid grid-cols-3 gap-3">
            <div class="section-card p-4 text-center">
                <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">DSO</div>
                <div class="text-2xl font-bold text-brand-600 mt-1">{{ number_format((float)($summary['dso'] ?? 0), 0) }}</div>
                <div class="text-[10px] text-[#a09d95]">days to collect</div>
            </div>
            <div class="section-card p-4 text-center">
                <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">DPO</div>
                <div class="text-2xl font-bold text-sage-600 mt-1">{{ number_format((float)($summary['dpo'] ?? 0), 0) }}</div>
                <div class="text-[10px] text-[#a09d95]">days to pay</div>
            </div>
            <div class="section-card p-4 text-center">
                <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Net Cycle</div>
                @php $cycle = (float)($summary['conversion_cycle'] ?? 0); @endphp
                <div class="text-2xl font-bold {{ $cycle > 0 ? 'text-coral-600' : 'text-sage-600' }} mt-1">{{ number_format($cycle, 0) }}</div>
                <div class="text-[10px] text-[#a09d95]">days gap (DSO − DPO)</div>
            </div>
        </div>
    </div>

    {{-- ── Section: Receivables & Payables Posture ────────────────────── --}}
    <div class="grid lg:grid-cols-2 gap-4 mb-7">
        <div class="section-card p-5">
            <div class="flex items-center gap-2 mb-4">
                <div class="w-1 h-4 rounded bg-brand-400"></div>
                <div>
                    <h2 class="text-[13px] font-semibold text-[#1e3d44]">Receivables Posture</h2>
                    <p class="text-[11px] text-[#8a8578]">DSO {{ number_format((float)($summary['dso'] ?? 0), 0) }}d · overdue {{ number_format((float)($receivables['overdue_rate'] ?? 0), 1) }}%</p>
                </div>
            </div>
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div class="rounded-lg bg-brand-50 p-3 border border-brand-100">
                    <dt class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Total AR</dt>
                    <dd class="text-lg font-bold text-[#1e3d44] mt-1">{{ ea_money_short((float)($receivables['total_ar'] ?? 0), $ccy) }}</dd>
                </div>
                <div class="rounded-lg bg-coral-50 p-3 border border-coral-100">
                    <dt class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Overdue</dt>
                    <dd class="text-lg font-bold text-[#1e3d44] mt-1">{{ ea_money_short((float)($receivables['overdue_ar'] ?? 0), $ccy) }}</dd>
                </div>
                <div class="rounded-lg bg-warm-50 p-3 border border-warm-100">
                    <dt class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Avg Age</dt>
                    <dd class="text-lg font-bold text-[#1e3d44] mt-1">{{ number_format((float)($receivables['avg_age'] ?? 0), 0) }} d</dd>
                </div>
                <div class="rounded-lg bg-sage-50 p-3 border border-sage-100">
                    <dt class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Top-5 Share</dt>
                    <dd class="text-lg font-bold text-[#1e3d44] mt-1">{{ number_format((float)($receivables['top5_pct'] ?? 0), 1) }}%</dd>
                </div>
            </dl>
        </div>

        <div class="section-card p-5">
            <div class="flex items-center gap-2 mb-4">
                <div class="w-1 h-4 rounded bg-coral-400"></div>
                <div>
                    <h2 class="text-[13px] font-semibold text-[#1e3d44]">Payables Commitments</h2>
                    <p class="text-[11px] text-[#8a8578]">DPO {{ number_format((float)($summary['dpo'] ?? 0), 0) }} days</p>
                </div>
            </div>
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div class="rounded-lg bg-coral-50 p-3 border border-coral-100">
                    <dt class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Total AP</dt>
                    <dd class="text-lg font-bold text-[#1e3d44] mt-1">{{ ea_money_short((float)($payables['kpis']['total_ap'] ?? 0), $ccy) }}</dd>
                </div>
                <div class="rounded-lg bg-brand-50 p-3 border border-brand-100">
                    <dt class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Overdue AP</dt>
                    <dd class="text-lg font-bold text-[#1e3d44] mt-1">{{ ea_money_short((float)($payables['kpis']['overdue_ap'] ?? 0), $ccy) }}</dd>
                </div>
                <div class="rounded-lg bg-sage-50 p-3 border border-sage-100">
                    <dt class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Avg Age</dt>
                    <dd class="text-lg font-bold text-[#1e3d44] mt-1">{{ number_format((float)($payables['kpis']['avg_age_days'] ?? 0), 0) }} d</dd>
                </div>
                <div class="rounded-lg bg-warm-50 p-3 border border-warm-100">
                    <dt class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Top-5 Share</dt>
                    <dd class="text-lg font-bold text-[#1e3d44] mt-1">{{ number_format((float)($payables['kpis']['top5_concentration_pct'] ?? 0), 1) }}%</dd>
                </div>
            </dl>
        </div>
    </div>

    {{-- ── Section: Top Debtors & Suppliers ───────────────────────────── --}}
    @if (!empty($receivables['top_partners']) || !empty($payables['top_creditors']))
        <div class="grid lg:grid-cols-2 gap-4">
            @if (!empty($receivables['top_partners']))
                <div class="section-card p-5">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <div class="w-1 h-4 rounded bg-brand-400"></div>
                            <h3 class="text-[13px] font-semibold text-[#1e3d44]">Top Debtors</h3>
                        </div>
                        <span class="text-[10px] text-[#a09d95] font-medium">Worst 6 by AR</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-[10px] text-[#8a8578] uppercase tracking-wider border-b border-[#e8e4de]">
                                    <th class="pb-2.5 font-semibold">Customer</th>
                                    <th class="pb-2.5 text-right font-semibold">AR</th>
                                    <th class="pb-2.5 text-right font-semibold">Overdue</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach (array_slice($receivables['top_partners'], 0, 6) as $partner)
                                    <tr class="table-row-hover border-b border-[#f0ede7]">
                                        <td class="py-2.5">
                                            <div class="text-[13px] font-medium text-[#2d3748]">{{ $partner['name'] ?? '—' }}</div>
                                            <div class="text-[10px] text-[#a09d95]">{{ $partner['rating_label'] ?? '' }}</div>
                                        </td>
                                        <td class="py-2.5 text-right tabular-nums text-[13px]">{{ ea_money((float)($partner['ar'] ?? 0), $ccy) }}</td>
                                        <td class="py-2.5 text-right tabular-nums text-[13px] {{ ($partner['overdue'] ?? 0) > 0 ? 'text-coral-600' : 'text-[#a09d95]' }}">
                                            {{ ($partner['overdue'] ?? 0) > 0 ? ea_money((float) $partner['overdue'], $ccy) : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if (!empty($payables['top_creditors']))
                <div class="section-card p-5">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <div class="w-1 h-4 rounded bg-coral-400"></div>
                            <h3 class="text-[13px] font-semibold text-[#1e3d44]">Top Suppliers Payable</h3>
                        </div>
                        <span class="text-[10px] text-[#a09d95] font-medium">Greatest leverage</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-[10px] text-[#8a8578] uppercase tracking-wider border-b border-[#e8e4de]">
                                    <th class="pb-2.5 font-semibold">Supplier</th>
                                    <th class="pb-2.5 text-right font-semibold">AP</th>
                                    <th class="pb-2.5 text-right font-semibold">Overdue</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach (array_slice($payables['top_creditors'], 0, 6) as $cred)
                                    <tr class="table-row-hover border-b border-[#f0ede7]">
                                        <td class="py-2.5">
                                            <div class="text-[13px] font-medium text-[#2d3748]">{{ $cred['name'] ?? '—' }}</div>
                                            <div class="text-[10px] text-[#a09d95]">Invoices: {{ $cred['invoices'] ?? 0 }}</div>
                                        </td>
                                        <td class="py-2.5 text-right tabular-nums text-[13px]">{{ ea_money((float)($cred['residual'] ?? 0), $ccy) }}</td>
                                        <td class="py-2.5 text-right tabular-nums text-[13px] {{ ($cred['overdue'] ?? 0) > 0 ? 'text-coral-600' : 'text-[#a09d95]' }}">
                                            {{ ($cred['overdue'] ?? 0) > 0 ? ea_money((float) $cred['overdue'], $ccy) : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    @endif

@endsection

@push('scripts')
<script>
(function () {
    // ── Cash Balance Evolution (Area Chart) ──────────────────────────
    const evoData = @json($cashEvolution ?? []);
    const evoCtx = document.getElementById('cashEvolutionChart');
    if (evoCtx && evoData.length) {
        new Chart(evoCtx, {
            type: 'line',
            data: {
                labels: evoData.map(d => d.label),
                datasets: [{
                    label: 'Cash Balance',
                    data: evoData.map(d => d.balance),
                    borderColor: '#3b8f9c',
                    backgroundColor: 'rgba(59,143,156,.1)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3,
                    pointBackgroundColor: '#3b8f9c',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    borderWidth: 2,
                }],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: { display: false },
                    tooltip: { backgroundColor: '#1c2637', titleFont: { size: 11 }, bodyFont: { size: 11 }, padding: 10, cornerRadius: 8 }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 10 }, color: '#8a8578' } },
                    y: { grid: { color: '#f0ede7' }, ticks: { font: { size: 10 }, color: '#8a8578',
                          callback: v => v >= 1e6 ? (v/1e6).toFixed(1)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'K' : v } },
                },
            },
        });
    }

})();
</script>
@endpush
