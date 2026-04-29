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
                        'source' => 'avg(effective_date − date_order) over closed POs',
                        'icon'   => 'M12 8v4l3 2M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z',
                        'color'  => 'sage',
                    ],
                    [
                        'key'    => 'po_compliance',
                        'title'  => 'PO Compliance',
                        'source' => "vendor bills WITHOUT a PO link ÷ all posted vendor bills",
                        'icon'   => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
                        'color'  => 'coral',
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

    {{-- ── PO Compliance breakdown + Top unlinked bills ─────────────────── --}}
    @php
        $p3 = $procurementKpis['po_compliance'] ?? null;
        $bd = (!empty($p3) && empty($p3['pending'])) ? ($p3['breakdown'] ?? null) : null;
        $unlinkedBills = $procurement['unlinked_bills'] ?? [];
    @endphp
    @if (!empty($bd) || !empty($unlinkedBills))
      <div class="bg-white border border-[#e8e4de] rounded-xl p-5 mb-6">
          <div class="flex items-center justify-between mb-3">
              <h3 class="text-sm font-semibold text-[#1e3d44]">
                  PO Compliance — Breakdown &amp; Top Unlinked Bills
                  <span class="text-[10px] uppercase tracking-wider text-[#a09d95] font-medium ml-1">{{ $period['type'] === 'mtd' ? 'MTD' : 'YTD' }}</span>
              </h3>
          </div>

          @if (!empty($bd))
            @php
                $total    = max(1, (int) $bd['total']);
                $linked   = (int) $bd['linked'];
                $unlinked = (int) $bd['unlinked'];
                $pctLinked   = round(100 * $linked / $total, 1);
                $pctUnlinked = round(100 * $unlinked / $total, 1);
            @endphp
            <div class="mb-4">
                <div class="flex h-7 rounded overflow-hidden border border-[#ece7df]">
                    @if ($linked > 0)
                      <div class="bg-sage-300/80 flex items-center justify-center text-[11px] font-semibold text-sage-800" style="width: {{ $pctLinked }}%">
                          {{ $pctLinked >= 8 ? "{$pctLinked}% with PO" : '' }}
                      </div>
                    @endif
                    @if ($unlinked > 0)
                      <div class="bg-coral-300/80 flex items-center justify-center text-[11px] font-semibold text-coral-800" style="width: {{ $pctUnlinked }}%">
                          {{ $pctUnlinked >= 8 ? "{$pctUnlinked}% no PO" : '' }}
                      </div>
                    @endif
                </div>
                <div class="flex justify-between text-[11px] text-[#7c7466] mt-1.5">
                    <span><strong class="text-sage-700">{{ number_format($linked) }}</strong> bills with linked PO</span>
                    <span><strong class="text-coral-700">{{ number_format($unlinked) }}</strong> maverick bills</span>
                    <span class="text-[#a09d95]">{{ number_format($total) }} total</span>
                </div>
            </div>
          @endif

          @if (!empty($unlinkedBills))
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="text-[10px] uppercase tracking-wider text-[#8a8578] border-b border-[#e8e4de]">
                            <th class="text-left  py-2 pr-3 font-medium">Top non-compliant bills</th>
                            <th class="text-left  py-2 px-3 font-medium">Vendor</th>
                            <th class="text-right py-2 px-3 font-medium">Invoice date</th>
                            <th class="text-right py-2 pl-3 font-medium">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#f1ede5]">
                        @foreach ($unlinkedBills as $b)
                            <tr class="text-[#2d3748]">
                                <td class="py-2 pr-3 font-medium">{{ $b['name'] ?: '#' . $b['id'] }}</td>
                                <td class="py-2 px-3">{{ $b['partner'] ?: '—' }}</td>
                                <td class="py-2 px-3 text-right tabular-nums text-[#7c7466]">{{ $b['invoice_date'] }}</td>
                                <td class="py-2 pl-3 text-right tabular-nums font-semibold">{{ number_format($b['amount']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
          @endif

          <div class="text-[11px] text-[#7c7466] mt-3 leading-relaxed">
              <strong>Reading the rate:</strong> "Maverick spend" is procurement jargon for purchases that bypassed the PO process. Below 5% is best-in-class; 5–15% is normal for most organisations; &gt;15% is an audit concern (no spending controls upstream of the bill). Use the table above to focus remediation on the highest-value offenders first.
          </div>
      </div>
    @endif

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
                <span class="font-semibold text-[#1e3d44]">Period anchors</span> — PO Volume &amp; Cycle Time anchor on <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">date_order</code>; PO Compliance anchors on <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">invoice_date</code> (the moment the "did it follow process?" question crystallises).
            </div>
            <div>
                <span class="font-semibold text-[#1e3d44]">Cycle-time scope</span> — only POs with both <code>date_order</code> and <code>effective_date</code> populated are included in the average. Open POs (not yet received) are excluded — they don't yet have a cycle to measure.
            </div>
            <div>
                <span class="font-semibold text-[#1e3d44]">Distinct from Payables</span> — Payables answers "what we owe vendors and how aged". Procurement answers "are purchases controlled, fast, and properly authorised?". Together they cover the full P2P cycle without overlap.
            </div>
        </div>
    </div>
@endsection
