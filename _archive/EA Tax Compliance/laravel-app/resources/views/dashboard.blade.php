@extends('layouts.app')
@section('title', 'Dashboard')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="inline-block w-2 h-2 rounded-full bg-sage-400 animate-pulse"></span>
                <span class="text-[10px] uppercase tracking-[0.35em] text-[#8a8578] font-medium">Live · {{ ea_last_sync_label() }}</span>
            </div>
            <h1 class="text-2xl font-bold text-[#1e3d44]">Executive Overview</h1>
        </div>
        <form method="POST" action="/sync" class="flex items-center gap-2">
            @csrf
            <a href="/treasury" class="text-[11px] font-medium text-[#5a6a70] px-3 py-1.5 rounded-lg border border-[#e8e4de] bg-white hover:bg-[#faf8f5] transition">Treasury →</a>
            <button class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-white bg-brand-600 hover:bg-brand-700 rounded-lg px-3 py-1.5 shadow-sm transition">
                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                Refresh
            </button>
        </form>
    </div>
@endsection

@section('content')

    @if ($error)
        <div class="bg-coral-50 border border-coral-200 text-coral-800 rounded-xl p-4 text-sm mb-6">
            <strong>Error:</strong> {{ $error }}
            <div class="text-xs mt-1 text-coral-600">Check your Odoo credentials in <a href="/settings" class="underline">Settings</a>.</div>
        </div>
    @elseif (!$data)
        <div class="bg-warm-50 border border-warm-200 text-warm-800 rounded-xl p-5 text-sm">Loading data…</div>
    @else
        @php
            $cur    = $data['current']  ?? [];
            $prev   = $data['previous'] ?? [];
            $ccy    = $data['currency'] ?? 'USD';
            $yoy    = $data['yoy_growth_pct'] ?? null;
            $plCur  = $pl['current']  ?? null;
            $budgetData   = $data['budget'] ?? [];
            $budgetTotals = $budgetData['totals'] ?? [];
            $budgetDays   = (int) ($budgetData['working_days'] ?? ($cur['working_days'] ?? 1));
            $budgetDays   = max($budgetDays, 1);

            $safeDiv = function ($num, $den) {
                if (!is_numeric($num) || !is_numeric($den) || (float) $den === 0.0) {
                    return null;
                }
                return (float) $num / (float) $den;
            };
            $planRevenue   = $budgetTotals['revenue']   ?? null;
            $planOutfits   = $budgetTotals['outfits']   ?? null;
            $planDiscounts = $budgetTotals['discounts'] ?? null;
            $planCustomers = $budgetTotals['customers'] ?? null;
            $planCostBase  = $budgetTotals['cost_base'] ?? null;
            $planMetrics = [
                'total' => $planRevenue,
                'rpd'   => $safeDiv($planRevenue, $budgetDays),
                'rpo'   => $safeDiv($planRevenue, $planOutfits),
                'opd'   => $safeDiv($planOutfits, $budgetDays),
                'rpc'   => $safeDiv($planRevenue, $planCustomers),
                'dp_ot' => $safeDiv($planDiscounts, $planOutfits),
                'dp_c'  => $safeDiv($planDiscounts, $planCustomers),
                'cpo'   => $safeDiv($planCostBase, $planOutfits),
            ];

            $variance = function ($current, $reference) {
                if (!is_numeric($current) || !is_numeric($reference)) {
                    return null;
                }
                if ((float) $reference === 0.0) {
                    return (float) $current === 0.0 ? 0.0 : null;
                }
                return (($current - $reference) / abs($reference)) * 100.0;
            };
        @endphp

        {{-- ── Section: Revenue & Profitability KPIs ─────────────────────── --}}
        <div class="mb-7">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-1 h-4 rounded bg-brand-400"></div>
                <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">Revenue & Profitability</h2>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-3">
                <x-kpi title="Revenue YTD"
                       :value="ea_money_short((float)($cur['total'] ?? 0), $ccy)"
                       :delta="$yoy"
                       :vsPy="$variance($cur['total'] ?? null, $prev['total'] ?? null)"
                       :vsPlan="$variance($cur['total'] ?? null, $planMetrics['total'])"
                       color="brand"
                       icon="M3 3v18h18M7 14l4-4 3 3 5-5"/>

                <div title="Revenue per day can be highlighted">
                    <x-kpi title="RpD"
                           :value="ea_money_short((float)($cur['rpd'] ?? 0), $ccy)"
                           :sub="($cur['working_days'] ?? 0) . ' working days'"
                           :vsPy="$variance($cur['rpd'] ?? null, $prev['rpd'] ?? null)"
                           :vsPlan="$variance($cur['rpd'] ?? null, $planMetrics['rpd'])"
                           color="sage"
                           icon="M12 8v4l3 2M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z"/>
                </div>

                <div title="Revenue per day can be highlighted">
                    <x-kpi title="RpO"
                           :value="ea_money_short((float)($cur['rpo'] ?? 0), $ccy)"
                           :sub="number_format((int)($cur['outfits'] ?? 0)) . ' outfits'"
                           :vsPy="$variance($cur['rpo'] ?? null, $prev['rpo'] ?? null)"
                           :vsPlan="$variance($cur['rpo'] ?? null, $planMetrics['rpo'])"
                           color="brand"
                           icon="M3 3v18h18M8 12h8"/>
                </div>

                <x-kpi title="OpD"
                       :value="number_format((float)($cur['opd'] ?? 0), 1)"
                       :sub="'Outfits / working day'"
                       :vsPy="$variance($cur['opd'] ?? null, $prev['opd'] ?? null)"
                       :vsPlan="$variance($cur['opd'] ?? null, $planMetrics['opd'])"
                       color="slate"
                       icon="M4 6h16M4 12h10M4 18h6"/>

                <x-kpi title="RpC"
                       :value="ea_money_short((float)($cur['per_customer'] ?? 0), $ccy)"
                       :sub="number_format((int)($cur['customers'] ?? 0)) . ' customers'"
                       :vsPy="$variance($cur['per_customer'] ?? null, $prev['per_customer'] ?? null)"
                       :vsPlan="$variance($cur['per_customer'] ?? null, $planMetrics['rpc'])"
                       color="coral"
                       icon="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>

                <x-kpi title="DpOT"
                       :value="ea_money_short((float)($cur['dp_ot'] ?? 0), $ccy)"
                       :sub="'Discount per outfit'"
                       :vsPy="$variance($cur['dp_ot'] ?? null, $prev['dp_ot'] ?? null)"
                       :vsPlan="$variance($cur['dp_ot'] ?? null, $planMetrics['dp_ot'])"
                       prefer="down"
                       color="warm"
                       icon="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82zM7 7h.01"/>

                <x-kpi title="DpC"
                       :value="ea_money_short((float)($cur['dp_c'] ?? 0), $ccy)"
                       :sub="'Discount per customer'"
                       :vsPy="$variance($cur['dp_c'] ?? null, $prev['dp_c'] ?? null)"
                       :vsPlan="$variance($cur['dp_c'] ?? null, $planMetrics['dp_c'])"
                       prefer="down"
                       color="warm"
                       icon="M9 14l6-6M9 8h.01M15 14h.01"/>

                <x-kpi title="CpO"
                       :value="ea_money_short((float)($cur['cpo'] ?? 0), $ccy)"
                       :sub="'603 + 7019 basis'"
                       :vsPy="$variance($cur['cpo'] ?? null, $prev['cpo'] ?? null)"
                       :vsPlan="$variance($cur['cpo'] ?? null, $planMetrics['cpo'])"
                       prefer="down"
                       color="sage"
                       icon="M5 12h14M12 5l7 7-7 7"/>
            </div>
        </div>

        @if ($plCur)
        <div class="mb-7">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-1 h-4 rounded bg-sage-400"></div>
                <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">Margin Structure</h2>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <x-kpi title="Gross Profit"
                       :value="ea_money_short((float)($plCur['gross_profit'] ?? 0), $ccy)"
                       :sub="'Margin ' . number_format((float)($plCur['gross_margin_pct'] ?? $plCur['gross_margin'] ?? 0), 1) . '%'"
                       color="sage"
                       icon="M12 2l3 7h7l-5.5 4L18 20l-6-4-6 4 1.5-7L2 9h7z"/>

                <x-kpi title="EBITDA"
                       :value="ea_money_short((float)($plCur['ebitda'] ?? 0), $ccy)"
                       :sub="'Margin ' . number_format((float)($plCur['ebitda_margin_pct'] ?? $plCur['ebitda_margin'] ?? 0), 1) . '%'"
                       color="brand"
                       icon="M3 3v18h18M7 14l4-4 3 3 5-5"/>

                <x-kpi title="Net Profit"
                       :value="ea_money_short((float)($plCur['net_profit'] ?? 0), $ccy)"
                       :sub="'Margin ' . number_format((float)($plCur['net_margin_pct'] ?? $plCur['net_margin'] ?? 0), 1) . '%'"
                       :color="($plCur['net_profit'] ?? 0) >= 0 ? 'sage' : 'coral'"
                       icon="M22 11V3h-8M14 3l8 8-11 11-8-8z"/>

                <x-kpi title="OpEx"
                       :value="ea_money_short((float)($plCur['opex'] ?? 0), $ccy)"
                       color="slate"
                       icon="M4 6h16M4 12h16M4 18h16"/>
            </div>
        </div>
        @endif

        {{-- ── Section: Revenue Trend ────────────────────────────────────── --}}
        @if (!empty($cur['series']))
          <div class="grid lg:grid-cols-3 gap-4 mb-7">
              <div class="lg:col-span-2 section-card p-5">
                  <div class="flex items-center justify-between mb-4">
                      <div>
                          <h2 class="text-[13px] font-semibold text-[#1e3d44]">{{ $data['period']['label'] ?? 'Period' }} Revenue vs Budget</h2>
                          <p class="text-[11px] text-[#8a8578]">Cumulative actuals vs cumulative budget ({{ $ccy }})</p>
                      </div>
                      <div class="flex gap-3 text-[10px] text-[#8a8578]">
                          <div class="flex items-center gap-1.5"><span class="w-3 h-[3px] bg-brand-400 rounded"></span>Actual (cum)</div>
                          <div class="flex items-center gap-1.5"><span class="w-3 h-[3px] bg-warm-500 rounded"></span>Budget (cum)</div>
                      </div>
                  </div>
                  <div class="h-56"><canvas id="revenueTrendChart"></canvas></div>
              </div>

              <div class="space-y-4">
                  <div class="section-card p-4 bg-brand-50/50">
                      <p class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Commercial Sentiment</p>
                      <h3 class="text-lg font-bold text-[#1e3d44] mt-1.5">{{ number_format((float)($cur['customers'] ?? 0)) }} <span class="text-sm font-normal text-[#5a6a70]">customers</span></h3>
                      <p class="text-[11px] text-[#8a8578] mt-1.5 leading-relaxed">Avg spend {{ ea_money_short((float)($cur['per_customer'] ?? 0), $ccy) }} — track VIP accounts in Receivables.</p>
                  </div>
                  <div class="section-card p-4 bg-sage-50/50">
                      <p class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Margin Glance</p>
                      <h3 class="text-lg font-bold text-[#1e3d44] mt-1.5">
                          {{ $plCur ? number_format((float)($plCur['gross_margin_pct'] ?? $plCur['gross_margin'] ?? 0),1) : '—' }}%
                          <span class="text-sm font-normal text-[#5a6a70]">gross</span> ·
                          {{ $plCur ? number_format((float)($plCur['ebitda_margin_pct'] ?? $plCur['ebitda_margin'] ?? 0),1) : '—' }}%
                          <span class="text-sm font-normal text-[#5a6a70]">EBITDA</span>
                      </h3>
                      <p class="text-[11px] text-[#8a8578] mt-1.5 leading-relaxed">Monitor OpEx in Profit &amp; Loss to defend margins.</p>
                  </div>
              </div>
          </div>

          @push('scripts')
          <script>
          (function () {
              const cy = @json($cur['series'] ?? []);
              const budget = @json($data['budget']['series'] ?? []);
              const labels = Object.keys(cy);
              let runningActual = 0;
              const cumulativeActual = labels.map(day => {
                  runningActual += cy[day] ?? 0;
                  return runningActual;
              });
              let runningBudget = 0;
              const cumulativeBudget = labels.map(day => {
                  runningBudget += budget[day] ?? 0;
                  return runningBudget;
              });
              const ctx = document.getElementById('revenueTrendChart');
              if (!ctx) return;
              new Chart(ctx, {
                  type: 'line',
                  data: {
                      labels,
                      datasets: [
                          {
                              label: 'Actual (cum)', data: cumulativeActual,
                              borderColor: '#4da8b4', backgroundColor: 'rgba(77,168,180,.12)',
                              fill: true, tension: 0.35, pointRadius: 0, borderWidth: 2,
                          },
                          {
                              label: 'Budget (cum)', data: cumulativeBudget,
                              borderColor: '#f0a43c', borderWidth: 1.5, borderDash: [4, 4],
                              fill: false, tension: 0.2, pointRadius: 0,
                          },
                      ],
                  },
                  options: {
                      responsive: true, maintainAspectRatio: false,
                      interaction: { intersect: false, mode: 'index' },
                      plugins: {
                          legend: { display: false },
                          tooltip: { backgroundColor: '#1c2637', titleFont: { size: 11 }, bodyFont: { size: 11 }, padding: 10, cornerRadius: 8 }
                      },
                      scales: {
                          x: { grid: { display: false }, ticks: { maxTicksLimit: 8, font: { size: 10 }, color: '#8a8578' } },
                          y: { grid: { color: '#f0ede7' }, ticks: { font: { size: 10 }, color: '#8a8578',
                                callback: v => v >= 1e6 ? (v/1e6).toFixed(1)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'K' : v } },
                      },
                  },
              });
          })();
          </script>
          @endpush
        @endif

        {{-- ── Section: Treasury Snapshot ─────────────────────────────────── --}}
        @if ($receivables)
            @php
                $treasuryCards = [
                    ['label' => 'Total AR',           'value' => ea_money_short((float)($receivables['total_ar'] ?? 0), $ccy), 'hint' => 'Outstanding including current', 'accent' => 'bg-brand-400'],
                    ['label' => 'Overdue Ratio',      'value' => number_format((float)($receivables['overdue_rate'] ?? 0), 1) . '%', 'hint' => 'Target < 8%', 'accent' => 'bg-coral-400'],
                    ['label' => 'DSO',                'value' => number_format((float)($receivables['dso'] ?? $receivables['kpis']['dso_days'] ?? 0), 0) . ' days', 'hint' => 'Collection velocity', 'accent' => 'bg-sage-400'],
                    ['label' => 'Top-5 Concentration','value' => number_format((float)($receivables['top5_pct'] ?? 0), 1) . '%', 'hint' => 'Customer risk', 'accent' => 'bg-warm-400'],
                ];
            @endphp
            <div class="section-card p-5 mb-7">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <div class="w-1 h-4 rounded bg-warm-400"></div>
                        <div>
                            <h2 class="text-[13px] font-semibold text-[#1e3d44]">Treasury Pulse</h2>
                            <p class="text-[11px] text-[#8a8578]">Receivables quality feeds liquidity planning</p>
                        </div>
                    </div>
                    <a href="/treasury" class="text-[10px] font-medium text-brand-600 hover:text-brand-800 transition">Open treasury →</a>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    @foreach ($treasuryCards as $card)
                        <div class="rounded-xl border border-[#e8e4de] bg-[#faf8f5] p-3.5 flex gap-3">
                            <div class="w-1 rounded {{ $card['accent'] }} shrink-0"></div>
                            <div>
                                <p class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">{{ $card['label'] }}</p>
                                <div class="text-lg font-bold text-[#1e3d44] mt-0.5">{{ $card['value'] }}</div>
                                <p class="text-[10px] text-[#a09d95] mt-0.5">{{ $card['hint'] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ── Quick Links ────────────────────────────────────────────────── --}}
        <div class="grid md:grid-cols-3 gap-3">
            <a href="/revenue" class="section-card p-4 hover:shadow-md transition group">
                <div class="flex items-center justify-between mb-1.5">
                    <p class="text-[11px] uppercase tracking-wider text-[#8a8578] font-medium">Revenue</p>
                    <span class="text-[10px] text-brand-600 group-hover:translate-x-0.5 transition">View →</span>
                </div>
                <p class="text-[11px] text-[#8a8578] leading-relaxed">CY vs PY trends, daily run rate and customer mix.</p>
            </a>
            <a href="/customer-insights" class="section-card p-4 hover:shadow-md transition group">
                <div class="flex items-center justify-between mb-1.5">
                    <p class="text-[11px] uppercase tracking-wider text-[#8a8578] font-medium">Customer Insights</p>
                    <span class="text-[10px] text-brand-600 group-hover:translate-x-0.5 transition">Explore →</span>
                </div>
                <p class="text-[13px] font-semibold text-[#1e3d44]">Customer growth & loyalty</p>
                <p class="text-[11px] text-[#8a8578] mt-1">Identify profitable, repeat, and at-risk accounts.</p>
            </a>
            <a href="/profit-loss" class="section-card p-4 hover:shadow-md transition group">
                <div class="flex items-center justify-between mb-1.5">
                    <h3 class="text-[13px] font-semibold text-[#1e3d44]">Profit &amp; Loss</h3>
                    <svg class="w-3.5 h-3.5 text-[#cdc7bc] group-hover:translate-x-0.5 group-hover:text-brand-500 transition" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                </div>
                <p class="text-[11px] text-[#8a8578] leading-relaxed">Waterfall from revenue → gross → EBITDA → net.</p>
            </a>
            <a href="/receivables" class="section-card p-4 hover:shadow-md transition group">
                <div class="flex items-center justify-between mb-1.5">
                    <p class="text-[11px] uppercase tracking-wider text-[#8a8578] font-medium">Receivables</p>
                    <span class="text-[10px] text-brand-600 group-hover:translate-x-0.5 transition">Open →</span>
                </div>
                <p class="text-[11px] text-[#8a8578] leading-relaxed">Ageing buckets, DSO tracking and top offender scorecard.</p>
            </a>
        </div>
    @endif

@endsection
