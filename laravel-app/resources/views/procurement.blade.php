@extends('layouts.app')
@section('title', 'Procurement')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="text-[10px] uppercase tracking-[0.35em] text-[#8a8578] font-medium">{{ $periodLabel ?? 'Procurement' }} · {{ ea_last_sync_label() }}</span>
            </div>
            <h1 class="text-2xl font-bold text-[#1e3d44]">Procurement</h1>
            <div class="text-xs text-[#7c7466] mt-1">PO lifecycle — purchasing volume, cycle time, and PO compliance. Sourced from your Odoo Purchase &amp; Accounting modules.</div>
        </div>
    </div>
@endsection

@section('content')
    <x-period-filter :period="$period" :options="[['ytd','YTD'],['mtd','MTD']]" />

    @if ($error)
        <div class="bg-coral-50 border border-coral-200 text-coral-800 rounded-xl p-4 text-sm mb-6">
            <strong>Could not reach Odoo:</strong> {{ $error }}
            <div class="text-xs mt-1 text-coral-600">Verify credentials in <a href="{{ tenant_url('/settings') }}" class="underline">Settings</a>.</div>
        </div>
    @endif

    @if (!$procurement || !empty($procurement['pending_overall']))
        <div class="bg-warm-50 border border-warm-200 rounded-xl p-5 mb-6">
            <div class="flex items-start gap-3">
                <div class="w-8 h-8 rounded-lg bg-warm-100 border border-warm-200 flex items-center justify-center shrink-0">
                    <svg class="w-4 h-4 text-warm-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4l3 2M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z"/></svg>
                </div>
                <div class="flex-1">
                    <div class="text-sm font-semibold text-[#7a5a1a]">Awaiting data</div>
                    <p class="text-xs text-[#7c7466] mt-1 leading-relaxed">
                        No POs or vendor bills found for the period, or the Odoo <em>purchase</em> module isn't installed/reachable on this tenant. Try switching the period or check Settings.
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{-- ── KPI metadata ────────────────────────────────────────────────── --}}
    @php
        $proposedKpis = [
            [
                'group' => 'Purchasing Lifecycle',
                'cards' => [
                    [
                        'key'    => 'po_volume',
                        'title'  => 'PO Volume &amp; Spend',
                        'source' => "count + sum(amount_total) of purchase.order in period",
                        'icon'   => 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z',
                        'color'  => 'brand',
                    ],
                    [
                        'key'    => 'po_cycle_time',
                        'title'  => 'PO Cycle Time',
                        'source' => 'avg(effective_date − date_order); falls back to earliest invoice_date when receipt undated',
                        'icon'   => 'M12 8v4l3 2M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z',
                        'color'  => 'sage',
                    ],
                ],
            ],
        ];

        $procurementKpis = $procurement['kpis'] ?? [];
    @endphp

    @foreach ($proposedKpis as $section)
        <div class="mb-7">
            <div class="flex items-center gap-2 mb-3">
                <div class="w-1 h-4 rounded bg-brand-400"></div>
                <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">{{ $section['group'] }}</h2>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                @foreach ($section['cards'] as $card)
                    @php
                        $palettes = [
                            'brand' => ['bg-brand-500',  'bg-brand-50',  'border-brand-100',  'text-brand-600'],
                            'sage'  => ['bg-sage-500',   'bg-sage-50',   'border-sage-100',   'text-sage-600'],
                            'warm'  => ['bg-warm-500',   'bg-warm-50',   'border-warm-100',   'text-warm-600'],
                            'coral' => ['bg-coral-500',  'bg-coral-50',  'border-coral-100',  'text-coral-600'],
                            'slate' => ['bg-slate-400',  'bg-slate-50',  'border-slate-100',  'text-slate-500'],
                        ];
                        [$bar, $bg, $bdr, $txt] = $palettes[$card['color']] ?? $palettes['brand'];

                        $live    = $procurementKpis[$card['key']] ?? null;
                        $pending = !$live || !empty($live['pending']);
                        $value   = $pending ? '— —' : ($live['value'] ?? '— —');
                        $sub     = $live['sub'] ?? '';
                        $vsPy    = !$pending && isset($live['vsPy']) && is_numeric($live['vsPy']) ? (float) $live['vsPy'] : null;
                        $vsPyColor = $vsPy === null ? 'text-slate-400'
                                  : ($vsPy >= 0 ? 'text-sage-700' : 'text-coral-700');
                        $vsPyArrow = $vsPy === null ? null
                                  : ($vsPy >= 0 ? 'M12 6l5 5H7z' : 'M12 18l5-5H7z');
                    @endphp
                    <div class="kpi-card bg-white rounded-xl border border-[#e8e4de] shadow-sm relative overflow-hidden flex">
                        <div class="accent-bar {{ $bar }} shrink-0 {{ $pending ? 'opacity-60' : '' }}"></div>
                        <div class="flex-1 min-w-0 p-4">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-1.5 mb-1">
                                        <span class="text-[11px] uppercase tracking-wider text-[#8a8578] font-medium truncate">{!! $card['title'] !!}</span>
                                        @if ($pending)
                                          <span class="inline-flex items-center text-[9px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded bg-warm-100 text-warm-700 border border-warm-200">Pending data</span>
                                        @endif
                                    </div>
                                    <div class="text-xl font-bold {{ $pending ? 'text-[#a09d95]' : 'text-[#2d3748]' }} mt-1 tabular-nums truncate">{{ $value }}</div>
                                    @if ($sub)
                                      <div class="text-[11px] {{ $pending ? 'text-[#a09d95]' : 'text-[#7c7466]' }} mt-0.5">{!! $sub !!}</div>
                                    @endif
                                    @if ($vsPy !== null)
                                      <div class="mt-2 text-[11px] font-semibold flex items-center gap-1 {{ $vsPyColor }}">
                                          <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor">
                                              <path d="{{ $vsPyArrow }}"/>
                                          </svg>
                                          {{ ($vsPy >= 0 ? '+' : '') . number_format($vsPy, 1) . '%' }}
                                          <span class="font-normal text-[#7c7466]">vs PY</span>
                                      </div>
                                    @endif
                                </div>
                                <div class="w-7 h-7 rounded-md {{ $bg }} {{ $bdr }} border flex items-center justify-center shrink-0">
                                    <svg class="w-3.5 h-3.5 {{ $txt }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="{{ $card['icon'] }}"/>
                                    </svg>
                                </div>
                            </div>
                            <div class="mt-3 pt-3 border-t border-[#f1ede5]">
                                <code class="text-[10px] text-[#7c7466] block leading-snug font-mono">{{ $card['source'] }}</code>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    {{-- ── Open POs Sandbox ──────────────────────────────────────────────
         Snapshot of POs that are confirmed but not yet closed. Not period-
         filtered: this is "what's open right now". Top-10 oldest gives the
         buying team a remediation list. --}}
    @php
        $openPOs = $procurement['open_pos'] ?? null;
    @endphp
    @if ($openPOs && (($openPOs['count'] ?? 0) > 0 || !empty($openPOs['oldest'])))
      <div class="bg-white border border-[#e8e4de] rounded-xl p-5 mb-6">
          <div class="flex items-center gap-2 mb-3">
              <div class="w-1 h-4 rounded bg-warm-400"></div>
              <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">Open POs Sandbox</h2>
          </div>

          {{-- Three-stat strip --}}
          <div class="grid grid-cols-3 gap-3 mb-4">
              <div class="bg-[#faf8f5] border border-[#ece7df] rounded-lg px-4 py-3">
                  <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium mb-1">Open POs</div>
                  <div class="text-2xl font-bold text-[#1e3d44] tabular-nums">{{ number_format($openPOs['count'] ?? 0) }}</div>
                  <div class="text-[11px] text-[#7c7466] mt-0.5">Confirmed, not yet closed</div>
              </div>
              <div class="bg-[#faf8f5] border border-[#ece7df] rounded-lg px-4 py-3">
                  <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium mb-1">Avg ageing</div>
                  <div class="text-2xl font-bold text-[#1e3d44] tabular-nums">
                      {{ isset($openPOs['avg_age_days']) ? number_format($openPOs['avg_age_days'], 1) . ' d' : '—' }}
                  </div>
                  <div class="text-[11px] text-[#7c7466] mt-0.5">Days since PO creation</div>
              </div>
              <div class="bg-[#faf8f5] border border-[#ece7df] rounded-lg px-4 py-3">
                  <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium mb-1">Oldest open PO</div>
                  <div class="text-2xl font-bold {{ ($openPOs['oldest_age_days'] ?? 0) > 90 ? 'text-coral-700' : 'text-[#1e3d44]' }} tabular-nums">
                      {{ isset($openPOs['oldest_age_days']) ? number_format($openPOs['oldest_age_days']) . ' d' : '—' }}
                  </div>
                  <div class="text-[11px] text-[#7c7466] mt-0.5">Worst single age</div>
              </div>
          </div>

          @if (!empty($openPOs['oldest']))
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="text-[10px] uppercase tracking-wider text-[#8a8578] border-b border-[#e8e4de]">
                            <th class="text-left  py-2 pr-3 font-medium">Top 10 oldest open POs</th>
                            <th class="text-left  py-2 px-3 font-medium">Vendor</th>
                            <th class="text-right py-2 px-3 font-medium">Created</th>
                            <th class="text-right py-2 px-3 font-medium">Age</th>
                            <th class="text-right py-2 pl-3 font-medium">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#f1ede5]">
                        @foreach ($openPOs['oldest'] as $po)
                            <tr class="text-[#2d3748]">
                                <td class="py-2 pr-3 font-medium">{{ $po['name'] ?: '#' . $po['id'] }}</td>
                                <td class="py-2 px-3">{{ $po['partner'] ?: '—' }}</td>
                                <td class="py-2 px-3 text-right tabular-nums text-[#7c7466]">
                                    {{ \Carbon\Carbon::parse($po['date_order'])->format('Y-m-d') }}
                                </td>
                                <td class="py-2 px-3 text-right tabular-nums font-semibold {{ $po['age_days'] > 90 ? 'text-coral-700' : ($po['age_days'] > 30 ? 'text-warm-700' : 'text-[#2d3748]') }}">
                                    {{ number_format($po['age_days']) }} d
                                </td>
                                <td class="py-2 pl-3 text-right tabular-nums">{{ number_format($po['amount']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
          @endif

          <div class="text-[11px] text-[#7c7466] mt-3 leading-relaxed">
              <strong>Reading the ageing:</strong> POs older than 30 days warrant a chase; over 90 days usually means either a stuck supplier, a forgotten partial receipt, or a PO that should be cancelled. Periodic clean-up here directly improves cycle-time and removes phantom commitments from the books.
          </div>
      </div>
    @endif

    {{-- ── Spend & Compliance ────────────────────────────────────────────
         Two paired KPI cards with native <details>-based dropdowns. Click
         the chevron to reveal the top-10 detail tables. --}}
    @php
        $p3 = $procurementKpis['po_compliance'] ?? null;
        $p4 = $procurementKpis['total_spend']   ?? null;
        $bd = (!empty($p3) && empty($p3['pending'])) ? ($p3['breakdown'] ?? null) : null;
        $unlinkedBills = $procurement['unlinked_bills'] ?? [];
        $topSuppliers  = $procurement['top_suppliers']  ?? [];
    @endphp

    <div class="mb-7">
        <div class="flex items-center gap-2 mb-3">
            <div class="w-1 h-4 rounded bg-coral-400"></div>
            <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">Spend &amp; Compliance</h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">

            {{-- ── P3: PO Compliance ── --}}
            @php
                $pendingP3 = !$p3 || !empty($p3['pending']);
                $valueP3   = $pendingP3 ? '— —' : ($p3['value'] ?? '— —');
                $subP3     = $p3['sub'] ?? '';
                $vsPyP3    = !$pendingP3 && isset($p3['vsPy']) && is_numeric($p3['vsPy']) ? (float) $p3['vsPy'] : null;
                $vsPyColorP3 = $vsPyP3 === null ? 'text-slate-400'
                              : ($vsPyP3 >= 0 ? 'text-sage-700' : 'text-coral-700');
                $vsPyArrowP3 = $vsPyP3 === null ? null
                              : ($vsPyP3 >= 0 ? 'M12 6l5 5H7z' : 'M12 18l5-5H7z');
            @endphp
            <details class="kpi-details bg-white rounded-xl border border-[#e8e4de] shadow-sm overflow-hidden group">
                <summary class="cursor-pointer list-none p-4 flex items-start gap-3 hover:bg-[#fafaf7] transition">
                    <div class="accent-bar bg-coral-500 w-1 self-stretch rounded-full {{ $pendingP3 ? 'opacity-60' : '' }}"></div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-1.5 mb-1">
                                    <span class="text-[11px] uppercase tracking-wider text-[#8a8578] font-medium truncate">PO Compliance</span>
                                    @if ($pendingP3)
                                      <span class="inline-flex items-center text-[9px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded bg-warm-100 text-warm-700 border border-warm-200">Pending data</span>
                                    @endif
                                </div>
                                <div class="text-xl font-bold {{ $pendingP3 ? 'text-[#a09d95]' : 'text-[#2d3748]' }} mt-1 tabular-nums truncate">{{ $valueP3 }}</div>
                                @if ($subP3)
                                  <div class="text-[11px] {{ $pendingP3 ? 'text-[#a09d95]' : 'text-[#7c7466]' }} mt-0.5">{!! $subP3 !!}</div>
                                @endif
                                @if ($vsPyP3 !== null)
                                  <div class="mt-2 text-[11px] font-semibold flex items-center gap-1 {{ $vsPyColorP3 }}">
                                      <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor"><path d="{{ $vsPyArrowP3 }}"/></svg>
                                      {{ ($vsPyP3 >= 0 ? '+' : '') . number_format($vsPyP3, 1) . '%' }}
                                      <span class="font-normal text-[#7c7466]">vs PY</span>
                                  </div>
                                @endif
                                <div class="mt-3 pt-3 border-t border-[#f1ede5]">
                                    <code class="text-[10px] text-[#7c7466] block leading-snug font-mono">vendor bills WITHOUT a PO link ÷ all posted vendor bills</code>
                                </div>
                            </div>
                            <div class="flex flex-col items-end gap-2 shrink-0">
                                <div class="w-7 h-7 rounded-md bg-coral-50 border border-coral-100 flex items-center justify-center">
                                    <svg class="w-3.5 h-3.5 text-coral-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                </div>
                                <div class="flex items-center gap-1 text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">
                                    <span>Top 10</span>
                                    <svg class="w-3 h-3 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
                                </div>
                            </div>
                        </div>
                    </div>
                </summary>
                @if (!empty($bd))
                  @php
                      $total    = max(1, (int) $bd['total']);
                      $linked   = (int) $bd['linked'];
                      $unlinked = (int) $bd['unlinked'];
                      $pctLinked   = round(100 * $linked / $total, 1);
                      $pctUnlinked = round(100 * $unlinked / $total, 1);
                  @endphp
                  <div class="px-4 pb-4 border-t border-[#f1ede5] pt-3 bg-[#fafaf7]">
                      <div class="flex h-6 rounded overflow-hidden border border-[#ece7df] mb-2">
                          @if ($linked > 0)
                            <div class="bg-sage-300/80 flex items-center justify-center text-[10px] font-semibold text-sage-800" style="width: {{ $pctLinked }}%">
                                {{ $pctLinked >= 12 ? "{$pctLinked}% with PO" : '' }}
                            </div>
                          @endif
                          @if ($unlinked > 0)
                            <div class="bg-coral-300/80 flex items-center justify-center text-[10px] font-semibold text-coral-800" style="width: {{ $pctUnlinked }}%">
                                {{ $pctUnlinked >= 12 ? "{$pctUnlinked}% no PO" : '' }}
                            </div>
                          @endif
                      </div>
                      <div class="flex justify-between text-[10px] text-[#7c7466] mb-3">
                          <span><strong class="text-sage-700">{{ number_format($linked) }}</strong> with PO</span>
                          <span><strong class="text-coral-700">{{ number_format($unlinked) }}</strong> maverick</span>
                          <span class="text-[#a09d95]">{{ number_format($total) }} total</span>
                      </div>
                      @if (!empty($unlinkedBills))
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs">
                                <thead>
                                    <tr class="text-[10px] uppercase tracking-wider text-[#8a8578] border-b border-[#e8e4de]">
                                        <th class="text-left  py-1.5 pr-3 font-medium">Top 10 non-compliant bills</th>
                                        <th class="text-left  py-1.5 px-3 font-medium">Vendor</th>
                                        <th class="text-right py-1.5 px-3 font-medium">Date</th>
                                        <th class="text-right py-1.5 pl-3 font-medium">Amount</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#f1ede5]">
                                    @foreach ($unlinkedBills as $b)
                                        <tr class="text-[#2d3748]">
                                            <td class="py-1.5 pr-3 font-medium">{{ $b['name'] ?: '#' . $b['id'] }}</td>
                                            <td class="py-1.5 px-3 truncate max-w-[200px]">{{ $b['partner'] ?: '—' }}</td>
                                            <td class="py-1.5 px-3 text-right tabular-nums text-[#7c7466]">{{ $b['invoice_date'] }}</td>
                                            <td class="py-1.5 pl-3 text-right tabular-nums font-semibold">{{ number_format($b['amount']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                      @endif
                  </div>
                @endif
            </details>

            {{-- ── P4: Total Vendor Spend ── --}}
            @php
                $pendingP4 = !$p4 || !empty($p4['pending']);
                $valueP4   = $pendingP4 ? '— —' : ($p4['value'] ?? '— —');
                $subP4     = $p4['sub'] ?? '';
                $vsPyP4    = !$pendingP4 && isset($p4['vsPy']) && is_numeric($p4['vsPy']) ? (float) $p4['vsPy'] : null;
                $vsPyColorP4 = $vsPyP4 === null ? 'text-slate-400'
                              : ($vsPyP4 >= 0 ? 'text-sage-700' : 'text-coral-700');
                $vsPyArrowP4 = $vsPyP4 === null ? null
                              : ($vsPyP4 >= 0 ? 'M12 6l5 5H7z' : 'M12 18l5-5H7z');
            @endphp
            <details class="kpi-details bg-white rounded-xl border border-[#e8e4de] shadow-sm overflow-hidden group">
                <summary class="cursor-pointer list-none p-4 flex items-start gap-3 hover:bg-[#fafaf7] transition">
                    <div class="accent-bar bg-brand-500 w-1 self-stretch rounded-full {{ $pendingP4 ? 'opacity-60' : '' }}"></div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-1.5 mb-1">
                                    <span class="text-[11px] uppercase tracking-wider text-[#8a8578] font-medium truncate">Total Vendor Spend</span>
                                    @if ($pendingP4)
                                      <span class="inline-flex items-center text-[9px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded bg-warm-100 text-warm-700 border border-warm-200">Pending data</span>
                                    @endif
                                </div>
                                <div class="text-xl font-bold {{ $pendingP4 ? 'text-[#a09d95]' : 'text-[#2d3748]' }} mt-1 tabular-nums truncate">{{ $valueP4 }}</div>
                                @if ($subP4)
                                  <div class="text-[11px] {{ $pendingP4 ? 'text-[#a09d95]' : 'text-[#7c7466]' }} mt-0.5">{!! $subP4 !!}</div>
                                @endif
                                @if ($vsPyP4 !== null)
                                  <div class="mt-2 text-[11px] font-semibold flex items-center gap-1 {{ $vsPyColorP4 }}">
                                      <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor"><path d="{{ $vsPyArrowP4 }}"/></svg>
                                      {{ ($vsPyP4 >= 0 ? '+' : '') . number_format($vsPyP4, 1) . '%' }}
                                      <span class="font-normal text-[#7c7466]">vs PY</span>
                                  </div>
                                @endif
                                <div class="mt-3 pt-3 border-t border-[#f1ede5]">
                                    <code class="text-[10px] text-[#7c7466] block leading-snug font-mono">sum(amount_total_signed) of posted in_invoice in period</code>
                                </div>
                            </div>
                            <div class="flex flex-col items-end gap-2 shrink-0">
                                <div class="w-7 h-7 rounded-md bg-brand-50 border border-brand-100 flex items-center justify-center">
                                    <svg class="w-3.5 h-3.5 text-brand-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M12 1v22M2 12h20"/>
                                    </svg>
                                </div>
                                <div class="flex items-center gap-1 text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">
                                    <span>Top 10</span>
                                    <svg class="w-3 h-3 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
                                </div>
                            </div>
                        </div>
                    </div>
                </summary>
                @if (!empty($topSuppliers))
                  <div class="px-4 pb-4 border-t border-[#f1ede5] pt-3 bg-[#fafaf7]">
                      <div class="overflow-x-auto">
                          <table class="w-full text-xs">
                              <thead>
                                  <tr class="text-[10px] uppercase tracking-wider text-[#8a8578] border-b border-[#e8e4de]">
                                      <th class="text-left  py-1.5 pr-3 font-medium">Top 10 suppliers by billed spend</th>
                                      <th class="text-right py-1.5 px-3 font-medium">Bills</th>
                                      <th class="text-right py-1.5 pl-3 font-medium">Amount</th>
                                  </tr>
                              </thead>
                              <tbody class="divide-y divide-[#f1ede5]">
                                  @foreach ($topSuppliers as $s)
                                      <tr class="text-[#2d3748]">
                                          <td class="py-1.5 pr-3 font-medium truncate max-w-[280px]">{{ $s['partner'] ?: '—' }}</td>
                                          <td class="py-1.5 px-3 text-right tabular-nums text-[#7c7466]">{{ number_format($s['count']) }}</td>
                                          <td class="py-1.5 pl-3 text-right tabular-nums font-semibold">{{ number_format($s['amount']) }}</td>
                                      </tr>
                                  @endforeach
                              </tbody>
                          </table>
                      </div>
                  </div>
                @endif
            </details>
        </div>

        <div class="text-[11px] text-[#7c7466] mt-3 leading-relaxed">
            <strong>Reading the rate:</strong> "Maverick spend" is procurement jargon for purchases that bypassed the PO process. Below 5% is best-in-class; 5–15% is normal for most organisations; &gt;15% is an audit concern (no spending controls upstream of the bill). Click each card's chevron to reveal the top-10 lists for remediation focus.
        </div>
    </div>

    {{-- ── Schema notes ────────────────────────────────────────────────── --}}
    <div class="bg-white border border-[#e8e4de] rounded-xl p-5">
        <h3 class="text-sm font-semibold text-[#1e3d44] mb-3">Data sources &amp; design notes</h3>
        <div class="text-xs text-[#5a6a70] leading-relaxed space-y-3">
            <div>
                <span class="font-semibold text-[#1e3d44]">Models in use</span> —
                <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">purchase.order</code> (PO header, source for volume &amp; cycle time),
                <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">account.move</code> (vendor bills, <code>move_type='in_invoice'</code>),
                <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">account.move.line.purchase_line_id</code> (primary PO link), with <code>purchase.order.invoice_ids</code> as fallback.
            </div>
            <div>
                <span class="font-semibold text-[#1e3d44]">Period anchors</span> — PO Volume &amp; Cycle Time anchor on <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">date_order</code>; PO Compliance and Total Spend anchor on <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">invoice_date</code> (the moment the "did it follow process?" / "what did it cost?" questions crystallise). The Open POs sandbox is anchored on <em>today</em> — it asks "what's open right now?", not "what was open in the period?".
            </div>
            <div>
                <span class="font-semibold text-[#1e3d44]">Cycle-time scope</span> — POs with <code>date_order</code> are included if either (a) <code>effective_date</code> is populated (Odoo's computed first-receipt anchor) or (b) at least one linked invoice has been posted (we use the earliest <code>invoice_date</code>). POs with neither are still in flight and are excluded from the average.
            </div>
            <div>
                <span class="font-semibold text-[#1e3d44]">PO Volume vs Total Spend</span> — P1 measures spend you've <em>committed</em> via POs (whether received yet or not); Total Spend measures what you've actually been <em>billed</em> for (whether through a PO or maverick). The two together let you see the gap between authorised commitments and actual liabilities.
            </div>
            <div>
                <span class="font-semibold text-[#1e3d44]">Distinct from Payables</span> — Payables answers "what we owe vendors and how aged". Procurement answers "are purchases controlled, fast, and properly authorised?". Together they cover the full P2P cycle without overlap.
            </div>
        </div>
    </div>
@endsection
