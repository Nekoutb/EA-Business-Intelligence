@extends('layouts.app')
@section('title', 'Revenue')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="text-[10px] uppercase tracking-[0.35em] text-[#8a8578] font-medium">{{ $periodLabel }} · {{ ea_last_sync_label() }}</span>
            </div>
            <h1 class="text-2xl font-bold text-[#1e3d44]">Revenue Analysis</h1>
        </div>
        <form method="POST" action="/sync">@csrf
            <button class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-white bg-brand-600 hover:bg-brand-700 rounded-lg px-3 py-1.5 shadow-sm transition">
                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                Refresh
            </button>
        </form>
    </div>
@endsection

@section('content')
    <x-period-filter :period="$period"/>

    @if ($error)
        <div class="bg-coral-50 border border-coral-200 text-coral-800 rounded-xl p-4 text-sm">{{ $error }}</div>
    @elseif ($data)
        @php
            $cur = $data['current'] ?? [];
            $prev = $data['previous'] ?? [];
            $ccy = $data['currency'] ?? 'USD';
            $yoy = $data['yoy_growth_pct'] ?? null;
            $budget = $data['budget'] ?? [];
            $budgetTotals = $budget['totals'] ?? [];
            $budgetDays = (int) ($budget['working_days'] ?? ($cur['working_days'] ?? 1));
            $budgetDays = max($budgetDays, 1);
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

        {{-- KPI Strip --}}
        <div class="mb-7">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-1 h-4 rounded bg-brand-400"></div>
                <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">Revenue KPIs</h2>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-3">
                <x-kpi title="Total Revenue"
                       :value="ea_money_short((float)($cur['total'] ?? 0), $ccy)"
                       :delta="$yoy"
                       :vsPy="$variance($cur['total'] ?? null, $prev['total'] ?? null)"
                       :vsPlan="$variance($cur['total'] ?? null, $planMetrics['total'])"
                       color="brand"/>
                <div title="Revenue per day can be highlighted">
                    <x-kpi title="RpD"
                           :value="ea_money_short((float)($cur['rpd'] ?? 0), $ccy)"
                           :sub="($cur['working_days'] ?? 0).' working days'"
                           :vsPy="$variance($cur['rpd'] ?? null, $prev['rpd'] ?? null)"
                           :vsPlan="$variance($cur['rpd'] ?? null, $planMetrics['rpd'])"
                           color="sage"/>
                </div>
                <div title="Revenue per day can be highlighted">
                    <x-kpi title="RpO"
                           :value="ea_money_short((float)($cur['rpo'] ?? 0), $ccy)"
                           :sub="number_format((int)($cur['outfits'] ?? 0)).' outfits'"
                           :vsPy="$variance($cur['rpo'] ?? null, $prev['rpo'] ?? null)"
                           :vsPlan="$variance($cur['rpo'] ?? null, $planMetrics['rpo'])"
                           color="brand"/>
                </div>
                <x-kpi title="OpD"
                       :value="number_format((float)($cur['opd'] ?? 0),1)"
                       :sub="'Outfits / working day'"
                       :vsPy="$variance($cur['opd'] ?? null, $prev['opd'] ?? null)"
                       :vsPlan="$variance($cur['opd'] ?? null, $planMetrics['opd'])"
                       color="slate"/>
                <x-kpi title="RpC"
                       :value="ea_money_short((float)($cur['per_customer'] ?? 0), $ccy)"
                       :sub="number_format((int)($cur['customers'] ?? 0)).' customers'"
                       :vsPy="$variance($cur['per_customer'] ?? null, $prev['per_customer'] ?? null)"
                       :vsPlan="$variance($cur['per_customer'] ?? null, $planMetrics['rpc'])"
                       color="coral"/>
                <x-kpi title="DpOT"
                       :value="ea_money_short((float)($cur['dp_ot'] ?? 0), $ccy)"
                       :sub="'Discount per outfit'"
                       :vsPy="$variance($cur['dp_ot'] ?? null, $prev['dp_ot'] ?? null)"
                       :vsPlan="$variance($cur['dp_ot'] ?? null, $planMetrics['dp_ot'])"
                       prefer="down"
                       color="warm"/>
                <x-kpi title="DpC"
                       :value="ea_money_short((float)($cur['dp_c'] ?? 0), $ccy)"
                       :sub="'Discount per customer'"
                       :vsPy="$variance($cur['dp_c'] ?? null, $prev['dp_c'] ?? null)"
                       :vsPlan="$variance($cur['dp_c'] ?? null, $planMetrics['dp_c'])"
                       prefer="down"
                       color="warm"/>
                <x-kpi title="CpO"
                       :value="ea_money_short((float)($cur['cpo'] ?? 0), $ccy)"
                       :sub="'603 + 7019 basis'"
                       :vsPy="$variance($cur['cpo'] ?? null, $prev['cpo'] ?? null)"
                       :vsPlan="$variance($cur['cpo'] ?? null, $planMetrics['cpo'])"
                       prefer="down"
                       color="sage"/>
            </div>
        </div>

        {{-- YTD Cumulative Trend --}}
        @if (!empty($cur['series']))
          <div class="section-card p-5 mb-7">
              <div class="flex items-center justify-between mb-3">
                  <div class="flex items-center gap-2">
                      <div class="w-1 h-4 rounded bg-sage-400"></div>
                      <h2 class="text-[13px] font-semibold text-[#1e3d44]">{{ $data['period']['label'] ?? 'Period' }} Revenue vs Budget</h2>
                  </div>
                  <div class="flex gap-3 text-[10px] text-[#8a8578]">
                      <div class="flex items-center gap-1.5"><span class="w-3 h-[3px] bg-brand-400 rounded"></span>Actual (cum)</div>
                      <div class="flex items-center gap-1.5"><span class="w-3 h-[3px] bg-warm-500 rounded"></span>Budget (cum)</div>
                  </div>
              </div>
              <div class="h-64"><canvas id="revChart"></canvas></div>
          </div>
          @push('scripts')
          <script>
            (function(){
              const cy = @json($cur['series']);
              const budgetSeries = @json($budget['series'] ?? []);
              const labels = Object.keys(cy);
              let runningActual = 0;
              const cumulativeActual = labels.map(day => {
                  runningActual += cy[day] ?? 0;
                  return runningActual;
              });
              let runningBudget = 0;
              const cumulativeBudget = labels.map(day => {
                  runningBudget += budgetSeries[day] ?? 0;
                  return runningBudget;
              });
              new Chart(document.getElementById('revChart'), {
                type:'line',
                data:{ labels,
                       datasets:[
                         { data: cumulativeActual, borderColor:'#4da8b4', backgroundColor:'rgba(77,168,180,.12)', label:'Actual (cum)', fill:true, tension:0.35, pointRadius:0, borderWidth:2 },
                         { data: cumulativeBudget, borderColor:'#f0a43c', borderWidth:1.5, borderDash:[4,4], label:'Budget (cum)', fill:false, tension:0.2, pointRadius:0 }
                       ]},
                options:{responsive:true,maintainAspectRatio:false,
                  plugins:{legend:{display:false},tooltip:{backgroundColor:'#1c2637',titleFont:{size:11},bodyFont:{size:11},padding:10,cornerRadius:8}},
                  scales:{x:{grid:{display:false},ticks:{font:{size:10},color:'#8a8578',maxTicksLimit:10}},
                          y:{grid:{color:'#f0ede7'},ticks:{font:{size:10},color:'#8a8578',
                              callback:v=>v>=1e6?(v/1e6).toFixed(1)+'M':v>=1e3?(v/1e3).toFixed(0)+'K':v}}}}
              });
            })();
          </script>
          @endpush
        @endif

        {{-- Product Profitability --}}
        @if (!empty($profitability))
            @php
                $mostProfitable  = $profitability['most_profitable'] ?? [];
                $leastProfitable = $profitability['least_profitable'] ?? [];
                // Format: floor to 1 decimal in millions, no currency per figure
                $fmtM = function(float $v): string {
                    $sign = $v < 0 ? '-' : '';
                    $abs  = abs($v);
                    return $sign . number_format(round($abs / 1e6, 2), 2) . 'M';
                };
            @endphp

            @if (!empty($mostProfitable) || !empty($leastProfitable))
            <div class="grid lg:grid-cols-2 gap-4">
                {{-- Most Profitable --}}
                @if (!empty($mostProfitable))
                <div class="section-card p-5">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <div class="w-1 h-4 rounded bg-sage-400"></div>
                            <div>
                                <h2 class="text-[13px] font-semibold text-[#1e3d44]">Top 5 Most Profitable Products</h2>
                                <p class="text-[11px] text-[#8a8578]">By margin (sales price vs standard cost)</p>
                            </div>
                        </div>
                        <span class="text-[10px] text-[#a09d95] font-medium">Amounts in {{ $ccy }}</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-[10px] text-[#8a8578] uppercase tracking-wider border-b border-[#e8e4de]">
                                    <th class="pb-2.5 font-semibold">Product</th>
                                    <th class="pb-2.5 text-right font-semibold">Revenue</th>
                                    <th class="pb-2.5 text-right font-semibold">Cost</th>
                                    <th class="pb-2.5 text-right font-semibold">Margin</th>
                                    <th class="pb-2.5 text-right font-semibold">Margin %</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($mostProfitable as $p)
                                    <tr class="table-row-hover border-b border-[#f0ede7]">
                                        <td class="py-2.5">
                                            <div class="text-[13px] font-medium text-[#2d3748]">{{ $p['name'] }}</div>
                                            <div class="text-[10px] text-[#a09d95]">Qty: {{ number_format($p['qty'], 0) }}</div>
                                        </td>
                                        <td class="py-2.5 text-right tabular-nums text-[13px]">{{ $fmtM($p['revenue']) }}</td>
                                        <td class="py-2.5 text-right tabular-nums text-[13px] text-[#8a8578]">{{ $fmtM($p['cost']) }}</td>
                                        <td class="py-2.5 text-right tabular-nums text-[13px] font-semibold text-sage-700">{{ $fmtM($p['margin']) }}</td>
                                        <td class="py-2.5 text-right">
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-sage-50 text-sage-700">{{ $p['margin_pct'] }}%</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @endif

                {{-- Least Profitable --}}
                @if (!empty($leastProfitable))
                <div class="section-card p-5">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <div class="w-1 h-4 rounded bg-coral-400"></div>
                            <div>
                                <h2 class="text-[13px] font-semibold text-[#1e3d44]">Top 5 Least Profitable Products</h2>
                                <p class="text-[11px] text-[#8a8578]">Lowest / negative margin (sales price vs standard cost)</p>
                            </div>
                        </div>
                        <span class="text-[10px] text-[#a09d95] font-medium">Amounts in {{ $ccy }}</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-[10px] text-[#8a8578] uppercase tracking-wider border-b border-[#e8e4de]">
                                    <th class="pb-2.5 font-semibold">Product</th>
                                    <th class="pb-2.5 text-right font-semibold">Revenue</th>
                                    <th class="pb-2.5 text-right font-semibold">Cost</th>
                                    <th class="pb-2.5 text-right font-semibold">Margin</th>
                                    <th class="pb-2.5 text-right font-semibold">Margin %</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($leastProfitable as $p)
                                    <tr class="table-row-hover border-b border-[#f0ede7]">
                                        <td class="py-2.5">
                                            <div class="text-[13px] font-medium text-[#2d3748]">{{ $p['name'] }}</div>
                                            <div class="text-[10px] text-[#a09d95]">Qty: {{ number_format($p['qty'], 0) }}</div>
                                        </td>
                                        <td class="py-2.5 text-right tabular-nums text-[13px]">{{ $fmtM($p['revenue']) }}</td>
                                        <td class="py-2.5 text-right tabular-nums text-[13px] text-[#8a8578]">{{ $fmtM($p['cost']) }}</td>
                                        <td class="py-2.5 text-right tabular-nums text-[13px] font-semibold {{ $p['margin'] < 0 ? 'text-coral-600' : 'text-warm-700' }}">{{ $fmtM($p['margin']) }}</td>
                                        <td class="py-2.5 text-right">
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold {{ $p['margin_pct'] < 0 ? 'bg-coral-50 text-coral-700' : 'bg-warm-50 text-warm-700' }}">{{ $p['margin_pct'] }}%</span>
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
        @endif

        {{-- Top 5 Discounted Products --}}
        @if (!empty($topDiscounted))
        <div class="mt-4">
            <div class="section-card p-5">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <div class="w-1 h-4 rounded bg-warm-400"></div>
                        <div>
                            <h2 class="text-[13px] font-semibold text-[#1e3d44]">Top 5 Most Discounted Products</h2>
                            <p class="text-[11px] text-[#8a8578]">Ranked by total discount amount granted</p>
                        </div>
                    </div>
                    <span class="text-[10px] text-[#a09d95] font-medium">Amounts in {{ $ccy }}</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[10px] text-[#8a8578] uppercase tracking-wider border-b border-[#e8e4de]">
                                <th class="pb-2.5 font-semibold">Product</th>
                                <th class="pb-2.5 text-right font-semibold">Discount Amount</th>
                                <th class="pb-2.5 text-right font-semibold">Avg Discount %</th>
                                <th class="pb-2.5 text-right font-semibold">Lines</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($topDiscounted as $d)
                                <tr class="table-row-hover border-b border-[#f0ede7]">
                                    <td class="py-2.5">
                                        <div class="text-[13px] font-medium text-[#2d3748]">{{ $d['name'] }}</div>
                                        <div class="text-[10px] text-[#a09d95]">Qty: {{ number_format($d['qty'], 0) }}</div>
                                    </td>
                                    <td class="py-2.5 text-right tabular-nums text-[13px] font-semibold text-warm-700">{{ $fmtM($d['discount_amount']) }}</td>
                                    <td class="py-2.5 text-right">
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-warm-50 text-warm-700">{{ $d['avg_discount_pct'] }}%</span>
                                    </td>
                                    <td class="py-2.5 text-right tabular-nums text-[13px] text-[#5a6a70]">{{ number_format($d['lines']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        @endif
    @endif
@endsection
