@extends('layouts.app')
@section('title', 'Billing')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="text-[10px] uppercase tracking-[0.35em] text-[#8a8578] font-medium">{{ $periodLabel ?? 'Billing' }} · {{ ea_last_sync_label() }}</span>
            </div>
            <h1 class="text-2xl font-bold text-[#1e3d44]">Billing</h1>
            <div class="text-xs text-[#7c7466] mt-1">Invoicing operations — issuance volume, time-to-invoice (shipment basis) and accuracy. Sourced from your Odoo accounting module.</div>
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

    @if (!$billing || !empty($billing['pending_overall']))
        <div class="bg-warm-50 border border-warm-200 rounded-xl p-5 mb-6">
            <div class="flex items-start gap-3">
                <div class="w-8 h-8 rounded-lg bg-warm-100 border border-warm-200 flex items-center justify-center shrink-0">
                    <svg class="w-4 h-4 text-warm-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4l3 2M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z"/></svg>
                </div>
                <div class="flex-1">
                    <div class="text-sm font-semibold text-[#7a5a1a]">Awaiting data</div>
                    <p class="text-xs text-[#7c7466] mt-1 leading-relaxed">
                        No customer invoices found in the current period, or the accounting module isn't reachable yet. Try switching the period or check Settings.
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{-- ── KPI metadata ────────────────────────────────────────────────── --}}
    @php
        $proposedKpis = [
            [
                'group' => 'Issuance & Quality',
                'cards' => [
                    [
                        'key'    => 'invoice_volume',
                        'title'  => 'Invoice Issuance Volume',
                        'source' => "count(account.move) where move_type='out_invoice' AND state='posted'",
                        'icon'   => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 12h6m-6 4h4',
                        'color'  => 'brand',
                    ],
                    [
                        'key'    => 'time_to_invoice',
                        'title'  => 'Time-to-Invoice (median)',
                        'source' => 'median(invoice_date − MAX(scanned_date in cargo line items))',
                        'icon'   => 'M12 8v4l3 2M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z',
                        'color'  => 'sage',
                    ],
                    [
                        'key'    => 'credit_note_rate',
                        'title'  => 'Credit Note Rate',
                        'source' => "count(out_refund) ÷ count(out_invoice) in period",
                        'icon'   => 'M3 10h11M9 21V3l-6 9 6 9zM21 14l-4 4-4-4',
                        'color'  => 'coral',
                    ],
                ],
            ],
        ];

        $billingKpis = $billing['kpis'] ?? [];
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

                        $live    = $billingKpis[$card['key']] ?? null;
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
                                        <span class="text-[11px] uppercase tracking-wider text-[#8a8578] font-medium truncate">{{ $card['title'] }}</span>
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

    {{-- ── Time-to-Invoice distribution + slowest billings ──────────────── --}}
    @php
        $b2 = $billingKpis['time_to_invoice'] ?? null;
        $dist = (!empty($b2) && empty($b2['pending'])) ? ($b2['distribution'] ?? []) : [];
        $slow = $billing['slowest_billings'] ?? [];
    @endphp
    @if (!empty($dist) || !empty($slow))
      <div class="bg-white border border-[#e8e4de] rounded-xl p-5 mb-6">
          <div class="flex items-center justify-between mb-3">
              <h3 class="text-sm font-semibold text-[#1e3d44]">
                  Time-to-Invoice — Distribution &amp; Slowest Billings
                  <span class="text-[10px] uppercase tracking-wider text-[#a09d95] font-medium ml-1">{{ $period['type'] === 'mtd' ? 'MTD' : 'YTD' }}</span>
              </h3>
          </div>

          @if (!empty($dist))
            @php
                $maxBucket = max(array_values($dist) ?: [1]);
                $maxBucket = $maxBucket > 0 ? $maxBucket : 1;
                $bucketLabels = [
                    '<=0'   => 'Billed before/on completion',
                    '1-3'   => '1–3 days',
                    '4-7'   => '4–7 days',
                    '8-14'  => '8–14 days',
                    '15-30' => '15–30 days',
                    '30+'   => '30+ days',
                ];
            @endphp
            <div class="space-y-1.5 mb-4">
                @foreach ($dist as $key => $count)
                    @php $w = (int) round(100 * $count / $maxBucket); @endphp
                    <div class="flex items-center gap-3 text-xs">
                        <div class="w-44 text-[#5a6a70] shrink-0">{{ $bucketLabels[$key] ?? $key }}</div>
                        <div class="flex-1 bg-[#faf8f5] rounded h-5 relative overflow-hidden border border-[#ece7df]">
                            <div class="absolute inset-y-0 left-0 bg-sage-300/70" style="width: {{ $w }}%"></div>
                        </div>
                        <div class="w-12 text-right tabular-nums font-medium text-[#2d3748]">{{ number_format($count) }}</div>
                    </div>
                @endforeach
            </div>
          @endif

          @if (!empty($slow))
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="text-[10px] uppercase tracking-wider text-[#8a8578] border-b border-[#e8e4de]">
                            <th class="text-left  py-2 pr-3 font-medium">Slowest billings</th>
                            <th class="text-left  py-2 px-3 font-medium">BL</th>
                            <th class="text-left  py-2 px-3 font-medium">Vessel · Journey</th>
                            <th class="text-right py-2 px-3 font-medium">Last scan</th>
                            <th class="text-right py-2 px-3 font-medium">Invoice date</th>
                            <th class="text-right py-2 pl-3 font-medium">Latency</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#f1ede5]">
                        @foreach ($slow as $r)
                            <tr class="text-[#2d3748]">
                                <td class="py-2 pr-3 font-medium">{{ $r['invoice_name'] ?: '#' . $r['invoice_id'] }}</td>
                                <td class="py-2 px-3">{{ $r['transport_ref'] ?: '—' }}</td>
                                <td class="py-2 px-3">
                                    {{ $r['vessel_name'] ?: '—' }}
                                    @if (!empty($r['journey_ref']))
                                      <span class="text-[#a09d95]"> · {{ $r['journey_ref'] }}</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3 text-right tabular-nums text-[#7c7466]">{{ \Carbon\Carbon::parse($r['last_scan'])->format('Y-m-d') }}</td>
                                <td class="py-2 px-3 text-right tabular-nums">{{ $r['invoice_date'] }}</td>
                                <td class="py-2 pl-3 text-right tabular-nums font-semibold {{ $r['latency_days'] > 14 ? 'text-coral-700' : 'text-[#2d3748]' }}">
                                    {{ $r['latency_days'] }} d
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
          @endif

          <div class="text-[11px] text-[#7c7466] mt-3 leading-relaxed">
              <strong>Reading the latency:</strong> 0 or negative = invoice issued on or before the last scan (advance billing). Positive = days waited after operation completion before billing. Industry good = single-digit days; &gt;14 days starts to drag working capital.
          </div>
      </div>
    @endif

    {{-- ── Schema notes ────────────────────────────────────────────────── --}}
    <div class="bg-white border border-[#e8e4de] rounded-xl p-5">
        <h3 class="text-sm font-semibold text-[#1e3d44] mb-3">Data sources &amp; design notes</h3>
        <div class="text-xs text-[#5a6a70] leading-relaxed space-y-3">
            <div>
                <span class="font-semibold text-[#1e3d44]">Models in use</span> —
                <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">account.move</code> (invoices &amp; credit notes),
                <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">aw_cargo.cargo</code> (shipment ↔ invoice link via <code>invoice_ids</code>),
                <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">aw_cargo.equipment / aw_vehicule.vehicule / aw_bois.bois</code> (line items, source of <code>scanned_date</code>).
            </div>
            <div>
                <span class="font-semibold text-[#1e3d44]">Period anchor</span> — invoice volume and credit-note rate filter on <code class="text-[11px] bg-[#faf8f5] border border-[#ece7df] rounded px-1.5 py-0.5">invoice_date</code> (the actual billing event). Time-to-Invoice computes <code>invoice_date − MAX(scanned_date)</code> per shipment.
            </div>
            <div>
                <span class="font-semibold text-[#1e3d44]">Why MAX(scanned_date)</span> — the moment the operation is complete on the last container/vehicle/wood line in the cargo. Earliest defensible point at which billing can be issued. Negative latency = advance billing; we surface it rather than mask it.
            </div>
            <div>
                <span class="font-semibold text-[#1e3d44]">Distinct from Receivables</span> — Receivables answers "what's outstanding and how aged". Billing answers "are we issuing invoices on time, in volume, accurately?". The two together cover the full O2C cycle without overlap.
            </div>
        </div>
    </div>
@endsection
