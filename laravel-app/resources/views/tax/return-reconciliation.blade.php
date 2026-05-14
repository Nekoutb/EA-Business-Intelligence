@extends('layouts.app')
@section('title', 'Return Reconciliation')

@section('page-title', 'Return Reconciliation')
@section('content')
    @if ($error)
        <div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-xl p-4 text-sm">
            <div class="font-medium mb-1">Could not load data from Odoo</div>
            <div>{{ $error }}</div>
            <div class="mt-2">Go to <a href="{{ tenant_url('/settings') }}" class="underline">Settings</a> to verify your Odoo connection.</div>
        </div>
    @elseif ($analysis)
        @php
            $cur            = $analysis['currency'] ?? 'XAF';
            $period         = $analysis['period']   ?? [];
            $monthLabel     = $period['label']      ?? '—';
            $monthValue     = sprintf('%04d-%02d', (int)($period['year'] ?? 0), (int)($period['month'] ?? 0));
            $reconciliation = $analysis['reconciliation'] ?? null;
            $months         = $analysis['available_months'] ?? [];
            $fmt = fn ($n) => number_format((float) $n, 0, '.', ',');
        @endphp

        {{-- ─────────── Month filter + link to filed return ─────────── --}}
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5 mb-4">
            <form method="GET" action="{{ tenant_url('/return-reconciliation') }}" class="flex flex-wrap items-end gap-4">
                <div class="flex-1 min-w-[220px]">
                    <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1.5">Period · Month</label>
                    <select name="month" onchange="this.form.submit()"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                        @foreach ($months as $opt)
                            <option value="{{ $opt['value'] }}" {{ $monthValue === $opt['value'] ? 'selected' : '' }}>{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex-1 min-w-[220px]">
                    <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1.5">Reconciles against</div>
                    <div class="text-sm text-slate-700 leading-snug">
                        The <strong class="text-slate-900">Monthly Tax Return</strong> for {{ $monthLabel }} (§2 declared CA Global HT)
                        vs the underlying GL — class 70 movements and the sales journal.
                    </div>
                </div>
                <div>
                    <a href="{{ tenant_url('/monthly-tax-return') . ($monthValue ? '?month=' . urlencode($monthValue) : '') }}"
                       class="inline-flex items-center gap-2 bg-slate-900 hover:bg-slate-800 text-white text-sm font-medium px-4 py-2.5 rounded-lg shadow-sm transition">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        Open filed return
                    </a>
                </div>
                <noscript>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">Apply</button>
                </noscript>
            </form>
        </div>

        {{-- ─────────── Reconciliation card ─────────────────────────────
             Vertical card that performs two pre-filing checks against the
             GL ground truth (sum of class-70 movements for the period):

               A · GL Class 70 vs Sales Ledger (L10 + L14)
               B · GL Class 70 vs L15 CA Global HT (§2)

             Same logic / shape as MonthlyTaxReturnService::buildReconciliation()
             — this view is the dedicated home for that data after PR #142
             moved it out of /monthly-tax-return.
             ─────────────────────────────────────────────────────────── --}}
        @if ($reconciliation)
          @php
              $figs   = $reconciliation['figures'] ?? [];
              $checks = $reconciliation['checks'] ?? [];
              $sev    = $reconciliation['severity'] ?? 'ok';
              [$cardBorder, $chipBg, $chipText, $chipLabel] = match ($sev) {
                  'major' => ['border-rose-300',  'bg-rose-100',  'text-rose-800',  'Critique · diff > 5 %'],
                  'minor' => ['border-amber-300', 'bg-amber-100', 'text-amber-800', 'À investiguer · diff > 0.5 %'],
                  default => ['border-emerald-300','bg-emerald-100','text-emerald-800','Réconcilié · diff < 0.5 %'],
              };
              $checkChip = fn (string $s) => match ($s) {
                  'major' => ['Critique',       'bg-rose-100  text-rose-800  border-rose-200'],
                  'minor' => ['À investiguer',  'bg-amber-100 text-amber-800 border-amber-200'],
                  default => ['Réconcilié',     'bg-emerald-100 text-emerald-800 border-emerald-200'],
              };
          @endphp
          <div class="bg-white border-2 {{ $cardBorder }} rounded-xl shadow-sm mb-4 overflow-hidden">
              <div class="flex items-center justify-between px-5 py-3 bg-slate-50/60 border-b border-slate-200 flex-wrap gap-2">
                  <div class="flex items-center gap-3">
                      <svg class="w-5 h-5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M9 3h6a2 2 0 0 1 2 2v14l-5-3-5 3V5a2 2 0 0 1 2-2Z"/>
                      </svg>
                      <div>
                          <h3 class="text-[13px] font-semibold uppercase tracking-wider text-slate-900">Réconciliation des contrôles · {{ $monthLabel }}</h3>
                          <p class="text-[11px] text-slate-500 mt-0.5">Vérifications avant dépôt — GL ground truth vs §2 déclaré · {{ $cur }}</p>
                      </div>
                  </div>
                  <span class="inline-flex items-center px-2.5 py-1 rounded-full border text-[10px] font-semibold uppercase tracking-wider {{ $chipBg }} {{ $chipText }} border-slate-200">
                      {{ $chipLabel }}
                  </span>
              </div>

              {{-- Three figures shown horizontally at the top --}}
              <div class="grid grid-cols-1 sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-slate-200 border-b border-slate-200">
                  <div class="px-5 py-4">
                      <p class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">GL Class 70</p>
                      <p class="text-[11px] text-slate-400">Ground truth · excl. 709</p>
                      <p class="mt-1.5 text-xl font-bold tabular-nums text-slate-900">{{ $fmt($figs['gl70_total'] ?? 0) }} <span class="text-[11px] font-semibold text-slate-500">{{ $cur }}</span></p>
                  </div>
                  <div class="px-5 py-4">
                      <p class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Sales Ledger</p>
                      <p class="text-[11px] text-slate-400">L10 + L14 · sales journal only</p>
                      <p class="mt-1.5 text-xl font-bold tabular-nums text-slate-900">{{ $fmt($figs['sales_ledger_total'] ?? 0) }} <span class="text-[11px] font-semibold text-slate-500">{{ $cur }}</span></p>
                  </div>
                  <div class="px-5 py-4">
                      <p class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">L15 CA Global HT</p>
                      <p class="text-[11px] text-slate-400">§2 declared · L10 + L11 + L12 + L13</p>
                      <p class="mt-1.5 text-xl font-bold tabular-nums text-slate-900">{{ $fmt($figs['l15_ca_global'] ?? 0) }} <span class="text-[11px] font-semibold text-slate-500">{{ $cur }}</span></p>
                  </div>
              </div>

              {{-- Two checks stacked vertically — that's the "vertical
                   medium" requested. Each check is a panel with a header
                   row (label + diff + severity chip), a one-line
                   expectation, and an expandable list of discrepancy
                   origins for investigation. Defaults open on this
                   dedicated page (no need to hide content the user
                   navigated here specifically to see). --}}
              <div class="divide-y divide-slate-200">
                  @foreach ($checks as $i => $check)
                      @php
                          [$ckLabel, $ckChipCls] = $checkChip($check['severity'] ?? 'ok');
                          $diff = (float) ($check['diff'] ?? 0);
                          $pct  = (float) ($check['pct']  ?? 0);
                          $diffCls = abs($diff) < 1 ? 'text-slate-500'
                                   : ($diff >= 0 ? 'text-rose-700' : 'text-amber-700');
                      @endphp
                      <details class="group" open>
                          <summary class="cursor-pointer list-none px-5 py-4 hover:bg-slate-50/60 flex flex-wrap items-center gap-3">
                              <svg class="w-4 h-4 shrink-0 text-slate-400 group-open:rotate-90 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                  <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/>
                              </svg>
                              <span class="font-mono text-[10px] tracking-widest text-slate-400 w-6 shrink-0">{{ chr(64 + $i + 1) }}</span>
                              <div class="flex-1 min-w-0">
                                  <p class="text-[12.5px] font-semibold text-slate-900 truncate">{{ $check['label'] }}</p>
                                  <p class="text-[11px] text-slate-500 truncate mt-0.5">
                                      {{ $fmt($check['left'] ?? 0) }} · vs · {{ $fmt($check['right'] ?? 0) }}
                                  </p>
                              </div>
                              <div class="text-right tabular-nums shrink-0">
                                  <p class="text-sm font-bold {{ $diffCls }}">
                                      {{ ($diff > 0 ? '+' : '') . $fmt($diff) }} {{ $cur }}
                                  </p>
                                  <p class="text-[10px] text-slate-400">{{ ($pct > 0 ? '+' : '') . number_format($pct, 2) }} %</p>
                              </div>
                              <span class="inline-flex items-center px-2 py-0.5 rounded-full border text-[10px] font-semibold uppercase tracking-wider {{ $ckChipCls }} shrink-0">{{ $ckLabel }}</span>
                          </summary>
                          <div class="px-5 pb-4 pt-1 bg-slate-50/30 border-t border-slate-100">
                              @if (!empty($check['expectation']))
                                <p class="text-[11.5px] text-slate-700 leading-relaxed mb-2">
                                    <span class="text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Attendu · </span>
                                    {{ $check['expectation'] }}
                                </p>
                              @endif
                              @if (!empty($check['origins']))
                                <p class="text-[10px] uppercase tracking-wider text-slate-500 font-semibold mt-3 mb-1.5">Origines possibles à investiguer</p>
                                <ul class="space-y-1.5 text-[11.5px] text-slate-700 leading-relaxed pl-4">
                                    @foreach ($check['origins'] as $origin)
                                        <li class="relative before:content-['•'] before:absolute before:-left-3 before:text-slate-400">{{ $origin }}</li>
                                    @endforeach
                                </ul>
                              @endif
                          </div>
                      </details>
                  @endforeach
              </div>
          </div>

          {{-- ─────────── Methodology footer ─────────── --}}
          <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5 text-[12px] text-slate-700 leading-relaxed">
              <h3 class="text-[11px] uppercase tracking-widest text-slate-500 font-semibold mb-2">Methodology</h3>
              <p class="mb-2">
                  <strong>GL Class 70</strong> — sum of every <code class="text-[11px] bg-slate-100 px-1 rounded">account.move.line.balance</code> on class 70 accounts (excl. 709 "rabais, remises, ristournes accordés"), restricted to <code class="text-[11px] bg-slate-100 px-1 rounded">parent_state = posted</code> and the declaration window. Ground truth — every revenue posting must reach this total.
              </p>
              <p class="mb-2">
                  <strong>Sales Ledger</strong> — same window, restricted to class 70 credits originating in the <em>sales journal</em>. Computed as L10 (with VAT) + L14 (exempt) where the with-VAT / exempt split walks the journal looking for an accompanying class 4431 credit.
              </p>
              <p class="mb-2">
                  <strong>L15 CA Global HT</strong> — the §2 line: L10 + L11 + L12 + L13. This is what gets declared to the DGI. <em>By design</em>, L14 (CA exonéré) is not included, so a residual diff against GL = L14 is structural.
              </p>
              <p class="mt-3 text-[11px] text-slate-500 italic">
                  Severity bands: diff &lt; 0.5 % of GL = OK · 0.5–5 % = À investiguer · ≥ 5 % = Critique.
                  Both checks default open on this page so investigation lists are visible without further clicks.
              </p>
          </div>
        @else
          {{-- Service returned but no reconciliation payload — almost
               always because the Odoo fetch failed for the period.
               Show a clean placeholder instead of an empty page. --}}
          <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-4 text-sm">
              <div class="font-medium mb-1">No reconciliation data for {{ $monthLabel }}</div>
              <div>
                  The service couldn't compute the reconciliation for this period — typically because Odoo was unreachable or the period has no posted entries.
                  Try a different month, or open the <a href="{{ tenant_url('/monthly-tax-return') }}" class="underline">Monthly Tax Return</a> page directly to see whether the underlying data is available.
              </div>
          </div>
        @endif

    @else
        <div class="bg-slate-50 border border-slate-200 text-slate-700 rounded-xl p-4 text-sm">
            <div class="font-medium mb-1">No data</div>
            <div>The service didn't return an analysis. Try selecting a different month.</div>
        </div>
    @endif
@endsection
