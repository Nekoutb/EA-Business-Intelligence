@extends('layouts.app')
@section('title', 'Billing')

@section('page-title', 'Billing')
@section('content')
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

    {{-- ── Revenue Reconciliation (Expected → Billed → Collected) ────────── --}}
    @php
        $recon = $billing['reconciliation'] ?? null;
        $reconHasError = $recon && !empty($recon['error']);
        $reconHas = $recon && !$reconHasError && (
            ($recon['headline']['expected'] ?? 0) > 0 ||
            ($recon['headline']['billed']   ?? 0) > 0 ||
            ($recon['headline']['collected'] ?? 0) > 0
        );

        // Pre-format headline numbers so the table doesn't re-call helpers.
        if ($reconHas) {
            $h = $recon['headline'];
            $reconRows = [
                [
                    'label'    => 'Expected',
                    'sub'      => 'From operational scans (containers + vehicles + bois)',
                    'value'    => $h['expected'],
                    'pctOfExp' => 100.0,
                    'color'    => 'sage',
                ],
                [
                    'label'    => 'Billed',
                    'sub'      => 'Posted out_invoices in period (HT)',
                    'value'    => $h['billed'],
                    'pctOfExp' => $h['billing_realisation_pct'],
                    'color'    => 'brand',
                ],
                [
                    'label'    => 'Collected',
                    'sub'      => 'Billed × payment progress (HT, pro-rata)',
                    'value'    => $h['collected'],
                    'pctOfExp' => $h['expected'] > 0 ? 100.0 * $h['collected'] / $h['expected'] : null,
                    'color'    => 'warm',
                ],
            ];

            // Build the JSON payload for the chart in one shot — labels +
            // three series stacked or grouped depending on view choice.
            $chartLabels = [];
            $chartExpected = [];
            $chartBilled = [];
            $chartCollected = [];
            foreach (($recon['buckets'] ?? []) as $b) {
                $chartLabels[]    = $b['label'];
                $chartExpected[]  = round((float) $b['expected'],  0);
                $chartBilled[]    = round((float) $b['billed'],    0);
                $chartCollected[] = round((float) $b['collected'], 0);
            }
            $chartPayload = [
                'labels'    => $chartLabels,
                'expected'  => $chartExpected,
                'billed'    => $chartBilled,
                'collected' => $chartCollected,
                'currency'  => 'XAF',
            ];
        }
    @endphp
    @if ($reconHas)
      <div class="bg-white border border-[#e8e4de] rounded-xl p-5 mb-6">
          <div class="flex items-center justify-between gap-3 mb-4 flex-wrap">
              <div>
                  <h3 class="text-sm font-semibold text-[#1e3d44]">
                      Revenue Reconciliation
                      <span class="text-[10px] uppercase tracking-wider text-[#a09d95] font-medium ml-1">FCFA · HT · {{ $period['type'] === 'ytd' ? 'YTD' : ($period['type'] === 'mtd' ? 'MTD' : 'Month') }}</span>
                  </h3>
                  <p class="text-[11px] text-[#7c7466] mt-1.5 leading-relaxed max-w-3xl">
                      Walks revenue across three operational stages:
                      <strong>Expected</strong> (scan-derived, from /operations) →
                      <strong>Billed</strong> (posted invoices) →
                      <strong>Collected</strong> (paid portion). Gaps surface
                      missed billings and outstanding receivables in one view.
                  </p>
              </div>
          </div>

          {{-- Stacked headline cards --}}
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-5">
              @foreach ($reconRows as $row)
                  @php
                      $palette = [
                          'sage'  => ['bar' => 'bg-sage-500',  'bg' => 'bg-sage-50',  'bdr' => 'border-sage-100',  'txt' => 'text-sage-700'],
                          'brand' => ['bar' => 'bg-brand-500', 'bg' => 'bg-brand-50', 'bdr' => 'border-brand-100', 'txt' => 'text-brand-700'],
                          'warm'  => ['bar' => 'bg-warm-500',  'bg' => 'bg-warm-50',  'bdr' => 'border-warm-100',  'txt' => 'text-warm-700'],
                      ][$row['color']];
                  @endphp
                  <div class="bg-white rounded-lg border border-[#e8e4de] flex overflow-hidden shadow-sm">
                      <div class="w-1 {{ $palette['bar'] }} shrink-0"></div>
                      <div class="flex-1 min-w-0 p-3">
                          <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">{{ $row['label'] }}</div>
                          <div class="text-xl font-bold text-[#2d3748] mt-1 tabular-nums truncate">
                              {{ ea_money_short($row['value'], 'XAF') }}
                          </div>
                          <div class="text-[10.5px] text-[#7c7466] mt-0.5 leading-snug">{{ $row['sub'] }}</div>
                          @if ($row['pctOfExp'] !== null)
                              <div class="mt-2 inline-flex items-center text-[10.5px] font-semibold rounded {{ $palette['bg'] }} {{ $palette['bdr'] }} {{ $palette['txt'] }} border px-1.5 py-0.5">
                                  {{ number_format($row['pctOfExp'], 1) }}% of Expected
                              </div>
                          @endif
                      </div>
                  </div>
              @endforeach
          </div>

          {{-- Sandbox table: gaps with the maths exposed --}}
          @php
              $h = $recon['headline'];
              $billGap = $h['billing_gap'];
              $collGap = $h['collection_gap'];
              $billGapPct = $h['expected'] > 0 ? 100.0 * abs($billGap) / $h['expected'] : null;
              $collGapPct = $h['billed']   > 0 ? 100.0 * abs($collGap) / $h['billed']   : null;
          @endphp
          <div class="overflow-x-auto mb-5">
              <table class="w-full text-xs">
                  <thead>
                      <tr class="text-[10px] uppercase tracking-wider text-[#8a8578] border-b border-[#e8e4de]">
                          <th class="text-left  py-2 pr-3 font-medium">Stage</th>
                          <th class="text-right py-2 px-3 font-medium">Amount (XAF HT)</th>
                          <th class="text-right py-2 px-3 font-medium">% of Expected</th>
                          <th class="text-left  py-2 pl-3 font-medium">Reading</th>
                      </tr>
                  </thead>
                  <tbody class="divide-y divide-[#f1ede5] text-[#2d3748]">
                      <tr>
                          <td class="py-2 pr-3 font-medium">Expected (scans × tariff)</td>
                          <td class="py-2 px-3 text-right tabular-nums">{{ number_format($h['expected']) }}</td>
                          <td class="py-2 px-3 text-right tabular-nums">100.0%</td>
                          <td class="py-2 pl-3 text-[#7c7466]">Operational baseline</td>
                      </tr>
                      <tr class="bg-[#fcfbf8]">
                          <td class="py-2 pr-3 pl-3 italic text-[#a37020]">— Billing gap (Expected − Billed)</td>
                          <td class="py-2 px-3 text-right tabular-nums {{ abs($billGap) > 0 ? 'text-coral-700 font-semibold' : 'text-[#7c7466]' }}">
                              {{ ($billGap >= 0 ? '−' : '+') . number_format(abs($billGap)) }}
                          </td>
                          <td class="py-2 px-3 text-right tabular-nums text-[#7c7466]">
                              {{ $billGapPct !== null ? number_format($billGapPct, 1) . '%' : '—' }}
                          </td>
                          <td class="py-2 pl-3 text-[#7c7466]">{{ $billGap > 0 ? 'Earned but not invoiced' : ($billGap < 0 ? 'Invoiced more than scanned (prior-period catch-up)' : 'Invoicing matches operations') }}</td>
                      </tr>
                      <tr>
                          <td class="py-2 pr-3 font-medium">Billed (out_invoice posted)</td>
                          <td class="py-2 px-3 text-right tabular-nums">{{ number_format($h['billed']) }}</td>
                          <td class="py-2 px-3 text-right tabular-nums">{{ $h['billing_realisation_pct'] !== null ? number_format($h['billing_realisation_pct'], 1) . '%' : '—' }}</td>
                          <td class="py-2 pl-3 text-[#7c7466]">Posted out_invoices · invoice_date in period</td>
                      </tr>
                      <tr class="bg-[#fcfbf8]">
                          <td class="py-2 pr-3 pl-3 italic text-[#a37020]">— Collection gap (Billed − Collected)</td>
                          <td class="py-2 px-3 text-right tabular-nums {{ abs($collGap) > 0 ? 'text-coral-700 font-semibold' : 'text-[#7c7466]' }}">
                              −{{ number_format(abs($collGap)) }}
                          </td>
                          <td class="py-2 px-3 text-right tabular-nums text-[#7c7466]">
                              {{ $collGapPct !== null ? number_format($collGapPct, 1) . '% of Billed' : '—' }}
                          </td>
                          <td class="py-2 pl-3 text-[#7c7466]">Outstanding receivables on this period's invoices</td>
                      </tr>
                      <tr>
                          <td class="py-2 pr-3 font-medium">Collected (HT, pro-rata)</td>
                          <td class="py-2 px-3 text-right tabular-nums">{{ number_format($h['collected']) }}</td>
                          <td class="py-2 px-3 text-right tabular-nums">
                              {{ $h['expected'] > 0 ? number_format(100.0 * $h['collected'] / $h['expected'], 1) . '%' : '—' }}
                          </td>
                          <td class="py-2 pl-3 text-[#7c7466]">Billed × payment_progress per invoice</td>
                      </tr>
                  </tbody>
              </table>
          </div>

          {{-- Chart: grouped bars per bucket (month or week) --}}
          @if (!empty($recon['buckets']))
            <div class="border-t border-[#f1ede5] pt-4">
                <div class="flex items-center justify-between gap-3 mb-2 flex-wrap">
                    <h4 class="text-xs font-semibold text-[#1e3d44]">
                        Trend
                        <span class="text-[10px] uppercase tracking-wider text-[#a09d95] font-medium ml-1">{{ $recon['granularity'] === 'month' ? 'Monthly' : 'Weekly' }} · FCFA HT</span>
                    </h4>
                    <div class="flex items-center gap-3 text-[10.5px] text-[#7c7466]">
                        <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-sage-500"></span>Expected</span>
                        <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-brand-500"></span>Billed</span>
                        <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-warm-500"></span>Collected</span>
                    </div>
                </div>
                <div id="ea-recon-chart"
                     class="bg-[#faf8f5] border border-[#ece7df] rounded-lg p-3 min-h-[260px]"
                     data-recon='@json($chartPayload)'></div>
            </div>

            <script>
            (function () {
                const root = document.getElementById('ea-recon-chart');
                if (!root) return;
                let payload;
                try { payload = JSON.parse(root.dataset.recon || '{}'); } catch (e) { return; }
                const labels = payload.labels || [];
                if (labels.length === 0) {
                    root.innerHTML = '<div class="text-xs text-[#7c7466] text-center py-8">No data in this period</div>';
                    return;
                }
                const series = [
                    { key: 'expected',  label: 'Expected',  color: '#7eaa8e', values: payload.expected  || [] },
                    { key: 'billed',    label: 'Billed',    color: '#5a7e8a', values: payload.billed    || [] },
                    { key: 'collected', label: 'Collected', color: '#c89e58', values: payload.collected || [] },
                ];
                const W = 760, H = 230, padL = 60, padR = 12, padT = 14, padB = 30;
                const innerW = W - padL - padR, innerH = H - padT - padB;
                const all = series.flatMap(s => s.values);
                const max = Math.max(1, ...all);
                const niceMax = Math.ceil(max / Math.pow(10, Math.floor(Math.log10(max)))) * Math.pow(10, Math.floor(Math.log10(max)));
                const groupW = innerW / labels.length;
                const barW = Math.max(4, (groupW - 8) / series.length);
                const fmtAxis = v => {
                    if (v >= 1e9) return (v / 1e9).toFixed(1) + 'B';
                    if (v >= 1e6) return (v / 1e6).toFixed(0) + 'M';
                    if (v >= 1e3) return (v / 1e3).toFixed(0) + 'K';
                    return String(v);
                };
                const fmtFull = v => new Intl.NumberFormat('en-US').format(Math.round(v));

                let svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H}" preserveAspectRatio="xMidYMid meet" class="w-full h-auto" style="font-family:inherit;">`;
                // Y-axis grid + labels
                for (let i = 0; i <= 4; i++) {
                    const y = padT + (innerH * i) / 4;
                    const v = niceMax * (1 - i / 4);
                    svg += `<line x1="${padL}" y1="${y}" x2="${W - padR}" y2="${y}" stroke="#e8e4de" stroke-width="1" stroke-dasharray="2,3"/>`;
                    svg += `<text x="${padL - 6}" y="${y + 3}" text-anchor="end" font-size="9" fill="#8a8578">${fmtAxis(v)}</text>`;
                }
                // Bars
                labels.forEach((label, i) => {
                    const xBase = padL + i * groupW + 4;
                    series.forEach((s, j) => {
                        const v = s.values[i] || 0;
                        const h = (v / niceMax) * innerH;
                        const x = xBase + j * (barW + 1);
                        const y = padT + innerH - h;
                        svg += `<rect x="${x}" y="${y}" width="${barW}" height="${h}" fill="${s.color}" rx="1"><title>${s.label}: ${fmtFull(v)} XAF (${label})</title></rect>`;
                    });
                    // X-axis label
                    const cx = padL + (i + 0.5) * groupW;
                    svg += `<text x="${cx}" y="${H - 10}" text-anchor="middle" font-size="10" fill="#5a6a70">${label}</text>`;
                });
                // Y-axis line
                svg += `<line x1="${padL}" y1="${padT}" x2="${padL}" y2="${padT + innerH}" stroke="#cbc7be" stroke-width="1"/>`;
                svg += `<line x1="${padL}" y1="${padT + innerH}" x2="${W - padR}" y2="${padT + innerH}" stroke="#cbc7be" stroke-width="1"/>`;
                svg += '</svg>';
                root.innerHTML = svg;
            })();
            </script>
          @endif

          <div class="text-[11px] text-[#7c7466] mt-4 leading-relaxed">
              <strong>Reading:</strong> Expected = scans × tariff (HT). Billed = posted out_invoice amount_untaxed_signed in period. Collected = Billed × payment_progress (where payment_progress = (amount_total − amount_residual) / amount_total). All figures HT to keep the comparison apples-to-apples.
          </div>

          {{-- ── Transaction details (expandable + CSV export) ─────────── --}}
          @php
              $details = $billing['reconciliation_details'] ?? null;
              $detailRows = ($details && empty($details['error'])) ? ($details['rows'] ?? []) : [];
              $detailCount = count($detailRows);
              $previewRows = array_slice($detailRows, 0, 50);
              $exportQuery = http_build_query([
                  'period' => $period['type'] ?? 'ytd',
                  'year'   => $period['year']  ?? now()->year,
                  'month'  => $period['month'] ?? now()->month,
              ]);
          @endphp
          @if ($detailCount > 0)
            <details class="border-t border-[#f1ede5] mt-4 pt-4 group">
                <summary class="cursor-pointer text-xs font-semibold text-[#1e3d44] hover:text-brand-700 select-none flex items-center justify-between gap-3 flex-wrap">
                    <span class="inline-flex items-center gap-1.5">
                        <svg class="w-3 h-3 transition-transform group-open:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M9 6l6 6-6 6"/></svg>
                        Reconciliation details — {{ number_format($detailCount) }} invoices
                        <span class="text-[10px] uppercase tracking-wider text-[#a09d95] font-medium ml-1">{{ $period['type'] === 'ytd' ? 'YTD' : ($period['type'] === 'mtd' ? 'MTD' : 'Month') }}</span>
                    </span>
                    <a href="{{ tenant_url('/billing/reconciliation-export?' . $exportQuery) }}"
                       class="text-[10.5px] font-medium text-brand-700 hover:text-brand-900 border border-brand-200 hover:border-brand-400 rounded px-2 py-1 inline-flex items-center gap-1 normal-case"
                       onclick="event.stopPropagation();">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17V8a2 2 0 012-2h6l2 2h5a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                        Download Excel ({{ number_format($detailCount) }} rows)
                    </a>
                </summary>
                <div class="overflow-x-auto mt-3">
                    <table class="w-full text-[11px]">
                        <thead>
                            <tr class="text-[10px] uppercase tracking-wider text-[#8a8578] border-b border-[#e8e4de]">
                                <th class="text-left  py-1.5 pr-3 font-medium">Invoice</th>
                                <th class="text-left  py-1.5 px-3 font-medium">Date</th>
                                <th class="text-left  py-1.5 px-3 font-medium">Customer</th>
                                <th class="text-right py-1.5 px-3 font-medium">HT</th>
                                <th class="text-right py-1.5 px-3 font-medium">Tax</th>
                                <th class="text-right py-1.5 px-3 font-medium">TTC</th>
                                <th class="text-right py-1.5 px-3 font-medium">Open</th>
                                <th class="text-right py-1.5 px-3 font-medium">Coll. HT</th>
                                <th class="text-right py-1.5 px-3 font-medium">% Coll.</th>
                                <th class="text-left  py-1.5 px-3 font-medium">State</th>
                                <th class="text-right py-1.5 pl-3 font-medium">Days OD</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#f1ede5] text-[#2d3748]">
                            @foreach ($previewRows as $r)
                                @php
                                    $stateColor = match ($r['payment_state'] ?? '') {
                                        'paid'        => 'bg-sage-50 text-sage-700 border-sage-200',
                                        'in_payment'  => 'bg-brand-50 text-brand-700 border-brand-200',
                                        'partial'     => 'bg-warm-50 text-warm-700 border-warm-200',
                                        'not_paid'    => 'bg-coral-50 text-coral-700 border-coral-200',
                                        'reversed'    => 'bg-slate-100 text-slate-600 border-slate-200',
                                        default       => 'bg-slate-50 text-slate-600 border-slate-200',
                                    };
                                @endphp
                                <tr>
                                    <td class="py-1.5 pr-3 font-medium tabular-nums whitespace-nowrap">{{ $r['name'] ?: '#' . $r['id'] }}</td>
                                    <td class="py-1.5 px-3 tabular-nums text-[#7c7466] whitespace-nowrap">{{ $r['invoice_date'] }}</td>
                                    <td class="py-1.5 px-3 truncate max-w-[180px]" title="{{ $r['partner'] }}">{{ $r['partner'] ?: '—' }}</td>
                                    <td class="py-1.5 px-3 text-right tabular-nums">{{ number_format($r['amount_untaxed']) }}</td>
                                    <td class="py-1.5 px-3 text-right tabular-nums text-[#7c7466]">{{ number_format($r['amount_tax']) }}</td>
                                    <td class="py-1.5 px-3 text-right tabular-nums">{{ number_format($r['amount_total']) }}</td>
                                    <td class="py-1.5 px-3 text-right tabular-nums {{ $r['amount_residual'] > 0 ? 'text-coral-700 font-medium' : 'text-[#7c7466]' }}">{{ number_format($r['amount_residual']) }}</td>
                                    <td class="py-1.5 px-3 text-right tabular-nums">{{ number_format($r['collected_ht']) }}</td>
                                    <td class="py-1.5 px-3 text-right tabular-nums text-[#7c7466]">{{ number_format($r['collection_pct'], 1) }}%</td>
                                    <td class="py-1.5 px-3">
                                        <span class="inline-flex text-[9.5px] uppercase tracking-wider rounded px-1.5 py-0.5 border {{ $stateColor }}">
                                            {{ str_replace('_', ' ', $r['payment_state'] ?: 'unknown') }}
                                        </span>
                                    </td>
                                    <td class="py-1.5 pl-3 text-right tabular-nums {{ $r['days_overdue'] > 0 ? 'text-coral-700 font-semibold' : 'text-[#7c7466]' }}">
                                        {{ $r['days_overdue'] > 0 ? $r['days_overdue'] : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if ($detailCount > count($previewRows))
                      <div class="text-[11px] text-[#7c7466] mt-2 text-center italic">
                          Showing first {{ count($previewRows) }} of {{ number_format($detailCount) }} invoices · download Excel for the full list
                      </div>
                    @endif
                </div>
            </details>
          @endif
      </div>
    @elseif ($reconHasError)
      <div class="bg-coral-50 border border-coral-200 text-coral-800 rounded-xl p-3 text-xs mb-6">
          <strong>Reconciliation unavailable:</strong> {{ $recon['error'] }}
      </div>
    @endif

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

@endsection
