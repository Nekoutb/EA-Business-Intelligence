@extends('layouts.app')
@section('title', 'Working Capital')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="text-[10px] uppercase tracking-[0.35em] text-[#8a8578] font-medium">As of {{ $asOf }} · {{ ea_last_sync_label() }}</span>
            </div>
            <h1 class="text-2xl font-bold text-[#1e3d44]">Working Capital</h1>
            <div class="text-xs text-[#7c7466] mt-1">Liquidity ratios and cash conversion cycle. Balance-sheet snapshot uses date ≤ as-of; DSO / DPO / DIO denominators use trailing-12-month revenue / COGS so the days figures are honestly annualised.</div>
        </div>

        {{-- As-of date picker (vanilla form, no JS dependency) --}}
        <form method="get" class="flex items-end gap-2">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[#8a8578] font-medium mb-1">As of</label>
                <input type="date" name="as_of" value="{{ $asOf }}" max="{{ now()->format('Y-m-d') }}"
                       class="text-xs border border-[#e8e4de] rounded-lg px-3 py-2 bg-white text-[#1e3d44] focus:ring-2 focus:ring-brand-300 focus:border-brand-300 outline-none">
            </div>
            <button type="submit"
                    class="text-xs font-medium px-3 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white">
                Apply
            </button>
        </form>
    </div>
@endsection

@section('content')
    @if ($error)
        <div class="bg-coral-50 border border-coral-200 text-coral-800 rounded-xl p-4 text-sm mb-6">
            <strong>Could not reach Odoo:</strong> {{ $error }}
            <div class="text-xs mt-1 text-coral-600">Verify credentials in <a href="{{ tenant_url('/settings') }}" class="underline">Settings</a>.</div>
        </div>
    @endif

    @if (!$wc)
        <div class="bg-warm-50 border border-warm-200 rounded-xl p-5 mb-6">
            <div class="flex items-start gap-3">
                <div class="w-8 h-8 rounded-lg bg-warm-100 border border-warm-200 flex items-center justify-center shrink-0">
                    <svg class="w-4 h-4 text-warm-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4l3 2M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z"/></svg>
                </div>
                <div class="flex-1">
                    <div class="text-sm font-semibold text-[#7a5a1a]">Awaiting data</div>
                    <p class="text-xs text-[#7c7466] mt-1 leading-relaxed">No GL data available for the selected as-of date. Try moving the date back to a closed-books month-end, or verify accounting setup.</p>
                </div>
            </div>
        </div>
    @else
      @php
          $r   = $wc['ratios']     ?? [];
          $c   = $wc['components'] ?? [];
          $ccy = $wc['currency']   ?? 'XAF';
          $isService    = ($wc['business_type'] ?? 'goods') === 'service';
          $businessType = $wc['business_type'] ?? 'goods';
          $btExplainer  = $wc['business_type_explainer'] ?? '';
          $cogsBasis    = $wc['cogs_basis'] ?? '';

          // Threshold-driven colour for each ratio (good = sage, fair = warm,
          // weak = coral, neutral / missing = slate). Tunable per industry.
          $tone = function (?float $v, array $thresholds, bool $higherIsBetter = true) {
              if ($v === null) return 'slate';
              [$good, $fair] = $thresholds;
              if ($higherIsBetter) {
                  if ($v >= $good) return 'sage';
                  if ($v >= $fair) return 'warm';
                  return 'coral';
              }
              if ($v <= $good) return 'sage';
              if ($v <= $fair) return 'warm';
              return 'coral';
          };

          $palettes = [
              'sage'  => ['bar' => 'bg-sage-500',  'bg' => 'bg-sage-50',  'bdr' => 'border-sage-100',  'txt' => 'text-sage-700'],
              'brand' => ['bar' => 'bg-brand-500', 'bg' => 'bg-brand-50', 'bdr' => 'border-brand-100', 'txt' => 'text-brand-700'],
              'warm'  => ['bar' => 'bg-warm-500',  'bg' => 'bg-warm-50',  'bdr' => 'border-warm-100',  'txt' => 'text-warm-700'],
              'coral' => ['bar' => 'bg-coral-500', 'bg' => 'bg-coral-50', 'bdr' => 'border-coral-100', 'txt' => 'text-coral-700'],
              'slate' => ['bar' => 'bg-slate-400', 'bg' => 'bg-slate-50', 'bdr' => 'border-slate-100', 'txt' => 'text-slate-600'],
          ];

          $fmt = function (?float $v, int $decimals = 2): string {
              if ($v === null) return '—';
              return number_format($v, $decimals);
          };
          $fmtDays = function (?float $v): string {
              if ($v === null) return '—';
              return number_format($v, 0) . ' d';
          };
      @endphp

      {{-- ── Business-type banner ──────────────────────────────────────────
           Tells the operator how the dashboard auto-classified the company
           (goods vs service) based on inventory presence, why it matters
           for the COGS basis, and which SYSCOHADA classes feed each ratio.
           Helps avoid the "why is Quick Ratio missing?" / "why is COGS so
           high?" questions before they're asked. --}}
      <div class="mb-7">
          @php
              $btTone   = $isService ? 'brand' : 'sage';
              $btPalette = $palettes[$btTone];
          @endphp
          <div class="rounded-xl border {{ $btPalette['bdr'] }} {{ $btPalette['bg'] }} p-4 flex items-start gap-3">
              <div class="w-8 h-8 rounded-lg bg-white border {{ $btPalette['bdr'] }} flex items-center justify-center shrink-0">
                  @if ($isService)
                    {{-- briefcase icon for services --}}
                    <svg class="w-4 h-4 {{ $btPalette['txt'] }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 012 2v12a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2M9 4h6v4H9z"/></svg>
                  @else
                    {{-- box icon for goods --}}
                    <svg class="w-4 h-4 {{ $btPalette['txt'] }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16zM3.27 6.96L12 12.01l8.73-5.05M12 22.08V12"/></svg>
                  @endif
              </div>
              <div class="flex-1">
                  <div class="flex items-center gap-2 mb-1 flex-wrap">
                      <h3 class="text-sm font-semibold {{ $btPalette['txt'] }}">
                          Detected: {{ $isService ? 'Service business' : 'Goods business' }}
                      </h3>
                      <span class="inline-flex text-[9.5px] uppercase tracking-wider rounded {{ $btPalette['txt'] }} bg-white border {{ $btPalette['bdr'] }} px-1.5 py-0.5 font-semibold">
                          COGS basis: {{ $cogsBasis }}
                      </span>
                  </div>
                  <p class="text-[11.5px] text-[#5a6a70] leading-relaxed">
                      {{ $btExplainer }}
                  </p>
              </div>
          </div>
      </div>

      {{-- ── Section 1: Liquidity Ratios ─────────────────────────────────── --}}
      <div class="mb-7">
          <div class="flex items-center gap-2 mb-3">
              <div class="w-1 h-4 rounded bg-brand-400"></div>
              <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">Liquidity Ratios</h2>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 {{ $isService ? 'xl:grid-cols-3' : 'xl:grid-cols-4' }} gap-3">

              {{-- Current Ratio --}}
              @php
                  $colour = $tone($r['current_ratio'] ?? null, [2.0, 1.5], true);
                  $p = $palettes[$colour];
              @endphp
              <div class="bg-white rounded-xl border border-[#e8e4de] shadow-sm flex overflow-hidden">
                  <div class="w-1 {{ $p['bar'] }} shrink-0"></div>
                  <div class="flex-1 p-4">
                      <div class="text-[11px] uppercase tracking-wider text-[#8a8578] font-medium">Current Ratio</div>
                      <div class="text-2xl font-bold text-[#2d3748] mt-1 tabular-nums">{{ $fmt($r['current_ratio'] ?? null, 2) }}</div>
                      <div class="text-[11px] text-[#7c7466] mt-0.5">Current Assets ÷ Current Liabilities</div>
                      <div class="mt-2 inline-flex items-center text-[10px] font-semibold rounded {{ $p['bg'] }} {{ $p['bdr'] }} {{ $p['txt'] }} border px-1.5 py-0.5">
                          @if (($r['current_ratio'] ?? null) === null) — pending
                          @elseif ($r['current_ratio'] >= 2.0) Healthy (≥ 2.0)
                          @elseif ($r['current_ratio'] >= 1.5) Adequate (1.5–2.0)
                          @else Caution (&lt; 1.5)
                          @endif
                      </div>
                      <div class="mt-3 pt-3 border-t border-[#f1ede5]">
                          <code class="text-[10px] text-[#7c7466] block leading-snug font-mono">Short-term solvency — can the firm meet 12-month obligations?</code>
                      </div>
                  </div>
              </div>

              {{-- Quick Ratio — hidden for service businesses where it
                   would mathematically equal Current Ratio. The banner
                   above already explains why. --}}
              @if (($r['quick_ratio'] ?? null) !== null)
              @php
                  $colour = $tone($r['quick_ratio'], [1.0, 0.7], true);
                  $p = $palettes[$colour];
              @endphp
              <div class="bg-white rounded-xl border border-[#e8e4de] shadow-sm flex overflow-hidden">
                  <div class="w-1 {{ $p['bar'] }} shrink-0"></div>
                  <div class="flex-1 p-4">
                      <div class="text-[11px] uppercase tracking-wider text-[#8a8578] font-medium">Quick Ratio (Acid Test)</div>
                      <div class="text-2xl font-bold text-[#2d3748] mt-1 tabular-nums">{{ $fmt($r['quick_ratio'], 2) }}</div>
                      <div class="text-[11px] text-[#7c7466] mt-0.5">(Current Assets − Inventory) ÷ Current Liabilities</div>
                      <div class="mt-2 inline-flex items-center text-[10px] font-semibold rounded {{ $p['bg'] }} {{ $p['bdr'] }} {{ $p['txt'] }} border px-1.5 py-0.5">
                          @if ($r['quick_ratio'] >= 1.0) Healthy (≥ 1.0)
                          @elseif ($r['quick_ratio'] >= 0.7) Adequate (0.7–1.0)
                          @else Caution (&lt; 0.7)
                          @endif
                      </div>
                      <div class="mt-3 pt-3 border-t border-[#f1ede5]">
                          <code class="text-[10px] text-[#7c7466] block leading-snug font-mono">Stricter liquidity — excludes inventory which may not convert quickly</code>
                      </div>
                  </div>
              </div>
              @endif

              {{-- Cash Ratio --}}
              @php
                  $colour = $tone($r['cash_ratio'] ?? null, [0.5, 0.2], true);
                  $p = $palettes[$colour];
              @endphp
              <div class="bg-white rounded-xl border border-[#e8e4de] shadow-sm flex overflow-hidden">
                  <div class="w-1 {{ $p['bar'] }} shrink-0"></div>
                  <div class="flex-1 p-4">
                      <div class="text-[11px] uppercase tracking-wider text-[#8a8578] font-medium">Cash Ratio</div>
                      <div class="text-2xl font-bold text-[#2d3748] mt-1 tabular-nums">{{ $fmt($r['cash_ratio'] ?? null, 2) }}</div>
                      <div class="text-[11px] text-[#7c7466] mt-0.5">Cash &amp; Equivalents ÷ Current Liabilities</div>
                      <div class="mt-2 inline-flex items-center text-[10px] font-semibold rounded {{ $p['bg'] }} {{ $p['bdr'] }} {{ $p['txt'] }} border px-1.5 py-0.5">
                          @if (($r['cash_ratio'] ?? null) === null) — pending
                          @elseif ($r['cash_ratio'] >= 0.5) Healthy (≥ 0.5)
                          @elseif ($r['cash_ratio'] >= 0.2) Adequate (0.2–0.5)
                          @else Caution (&lt; 0.2)
                          @endif
                      </div>
                      <div class="mt-3 pt-3 border-t border-[#f1ede5]">
                          <code class="text-[10px] text-[#7c7466] block leading-snug font-mono">Most conservative — pure cash coverage of short-term debt</code>
                      </div>
                  </div>
              </div>

              {{-- Working Capital (absolute amount, not a ratio) --}}
              @php
                  $wcVal = (float) ($r['working_capital'] ?? 0);
                  $colour = $wcVal > 0 ? 'sage' : ($wcVal === 0.0 ? 'slate' : 'coral');
                  $p = $palettes[$colour];
              @endphp
              <div class="bg-white rounded-xl border border-[#e8e4de] shadow-sm flex overflow-hidden">
                  <div class="w-1 {{ $p['bar'] }} shrink-0"></div>
                  <div class="flex-1 p-4">
                      <div class="text-[11px] uppercase tracking-wider text-[#8a8578] font-medium">Working Capital</div>
                      <div class="text-2xl font-bold text-[#2d3748] mt-1 tabular-nums">{{ ea_money_short($wcVal, $ccy) }}</div>
                      <div class="text-[11px] text-[#7c7466] mt-0.5">Current Assets − Current Liabilities</div>
                      <div class="mt-2 inline-flex items-center text-[10px] font-semibold rounded {{ $p['bg'] }} {{ $p['bdr'] }} {{ $p['txt'] }} border px-1.5 py-0.5">
                          @if ($wcVal > 0) Positive cushion
                          @elseif ($wcVal === 0.0) Break-even
                          @else Negative — funding gap
                          @endif
                      </div>
                      <div class="mt-3 pt-3 border-t border-[#f1ede5]">
                          <code class="text-[10px] text-[#7c7466] block leading-snug font-mono">Absolute liquidity buffer in {{ $ccy }}</code>
                      </div>
                  </div>
              </div>
          </div>
      </div>

      {{-- ── Section 2: Cash Conversion Cycle ────────────────────────────── --}}
      <div class="mb-7">
          <div class="flex items-center gap-2 mb-3">
              <div class="w-1 h-4 rounded bg-warm-400"></div>
              <h2 class="text-xs font-semibold uppercase tracking-wider text-[#5a6a70]">Cash Conversion Cycle</h2>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 {{ $isService ? 'xl:grid-cols-3' : 'xl:grid-cols-4' }} gap-3">

              {{-- DSO --}}
              @php
                  $colour = $tone($r['dso'] ?? null, [45.0, 75.0], false);
                  $p = $palettes[$colour];
              @endphp
              <div class="bg-white rounded-xl border border-[#e8e4de] shadow-sm flex overflow-hidden">
                  <div class="w-1 {{ $p['bar'] }} shrink-0"></div>
                  <div class="flex-1 p-4">
                      <div class="text-[11px] uppercase tracking-wider text-[#8a8578] font-medium">DSO</div>
                      <div class="text-2xl font-bold text-[#2d3748] mt-1 tabular-nums">{{ $fmtDays($r['dso'] ?? null) }}</div>
                      <div class="text-[11px] text-[#7c7466] mt-0.5">(AR ÷ Revenue<sub>TTM</sub>) × 365</div>
                      <div class="mt-2 inline-flex items-center text-[10px] font-semibold rounded {{ $p['bg'] }} {{ $p['bdr'] }} {{ $p['txt'] }} border px-1.5 py-0.5">
                          @if (($r['dso'] ?? null) === null) — no revenue
                          @elseif ($r['dso'] <= 45) Fast collection
                          @elseif ($r['dso'] <= 75) Average
                          @else Slow — review collections
                          @endif
                      </div>
                      <div class="mt-3 pt-3 border-t border-[#f1ede5]">
                          <code class="text-[10px] text-[#7c7466] block leading-snug font-mono">Days customers take to pay</code>
                      </div>
                  </div>
              </div>

              {{-- DPO --}}
              @php
                  // DPO is balanced — too low (paying too quickly, unused
                  // supplier credit) and too high (supplier risk) are both
                  // unfavourable. Use a window: 30..60 days = sage,
                  // 20..30 or 60..90 = warm, outside that = coral.
                  $dpo = $r['dpo'] ?? null;
                  if ($dpo === null) $colour = 'slate';
                  elseif ($dpo >= 30 && $dpo <= 60) $colour = 'sage';
                  elseif ($dpo >= 20 && $dpo <= 90) $colour = 'warm';
                  else $colour = 'coral';
                  $p = $palettes[$colour];
              @endphp
              <div class="bg-white rounded-xl border border-[#e8e4de] shadow-sm flex overflow-hidden">
                  <div class="w-1 {{ $p['bar'] }} shrink-0"></div>
                  <div class="flex-1 p-4">
                      <div class="text-[11px] uppercase tracking-wider text-[#8a8578] font-medium">DPO</div>
                      <div class="text-2xl font-bold text-[#2d3748] mt-1 tabular-nums">{{ $fmtDays($r['dpo'] ?? null) }}</div>
                      <div class="text-[11px] text-[#7c7466] mt-0.5">(AP ÷ COGS<sub>TTM</sub>) × 365</div>
                      <div class="mt-2 inline-flex items-center text-[10px] font-semibold rounded {{ $p['bg'] }} {{ $p['bdr'] }} {{ $p['txt'] }} border px-1.5 py-0.5">
                          @if (($r['dpo'] ?? null) === null) — no COGS
                          @elseif ($r['dpo'] >= 30 && $r['dpo'] <= 60) Balanced
                          @elseif ($r['dpo'] < 20) Paying too quickly
                          @elseif ($r['dpo'] > 90) Supplier risk
                          @else Slightly off-target
                          @endif
                      </div>
                      <div class="mt-3 pt-3 border-t border-[#f1ede5]">
                          <code class="text-[10px] text-[#7c7466] block leading-snug font-mono">Days the firm takes to pay suppliers</code>
                      </div>
                  </div>
              </div>

              {{-- DIO — hidden when no inventory (service business). The
                   banner at the top of the page already explains why. --}}
              @if (($r['dio'] ?? null) !== null)
              @php
                  $colour = $tone($r['dio'], [45.0, 90.0], false);
                  $p = $palettes[$colour];
              @endphp
              <div class="bg-white rounded-xl border border-[#e8e4de] shadow-sm flex overflow-hidden">
                  <div class="w-1 {{ $p['bar'] }} shrink-0"></div>
                  <div class="flex-1 p-4">
                      <div class="text-[11px] uppercase tracking-wider text-[#8a8578] font-medium">DIO</div>
                      <div class="text-2xl font-bold text-[#2d3748] mt-1 tabular-nums">{{ $fmtDays($r['dio']) }}</div>
                      <div class="text-[11px] text-[#7c7466] mt-0.5">(Inventory ÷ COGS<sub>TTM</sub>) × 365</div>
                      <div class="mt-2 inline-flex items-center text-[10px] font-semibold rounded {{ $p['bg'] }} {{ $p['bdr'] }} {{ $p['txt'] }} border px-1.5 py-0.5">
                          @if ($r['dio'] <= 45) Lean
                          @elseif ($r['dio'] <= 90) Average
                          @else Slow turn — review SKUs
                          @endif
                      </div>
                      <div class="mt-3 pt-3 border-t border-[#f1ede5]">
                          <code class="text-[10px] text-[#7c7466] block leading-snug font-mono">Days inventory sits before sale</code>
                      </div>
                  </div>
              </div>
              @endif

              {{-- CCC --}}
              @php
                  $colour = $tone($r['ccc'] ?? null, [30.0, 75.0], false);
                  $p = $palettes[$colour];
              @endphp
              <div class="bg-white rounded-xl border border-[#e8e4de] shadow-sm flex overflow-hidden">
                  <div class="w-1 {{ $p['bar'] }} shrink-0"></div>
                  <div class="flex-1 p-4">
                      <div class="text-[11px] uppercase tracking-wider text-[#8a8578] font-medium">Cash Conversion Cycle</div>
                      <div class="text-2xl font-bold text-[#2d3748] mt-1 tabular-nums">{{ $fmtDays($r['ccc'] ?? null) }}</div>
                      <div class="text-[11px] text-[#7c7466] mt-0.5">{{ $isService ? 'DSO − DPO (no DIO — service business)' : 'DSO + DIO − DPO' }}</div>
                      <div class="mt-2 inline-flex items-center text-[10px] font-semibold rounded {{ $p['bg'] }} {{ $p['bdr'] }} {{ $p['txt'] }} border px-1.5 py-0.5">
                          @if (($r['ccc'] ?? null) === null) — pending
                          @elseif ($r['ccc'] <= 30) Excellent
                          @elseif ($r['ccc'] <= 75) Average
                          @else Long cycle — capital tied up
                          @endif
                      </div>
                      <div class="mt-3 pt-3 border-t border-[#f1ede5]">
                          <code class="text-[10px] text-[#7c7466] block leading-snug font-mono">Days of working capital tied up in operations</code>
                      </div>
                  </div>
              </div>
          </div>
      </div>

      {{-- ── Section 3: Components Breakdown ─────────────────────────────── --}}
      <div class="bg-white border border-[#e8e4de] rounded-xl p-5 mb-6">
          <h3 class="text-sm font-semibold text-[#1e3d44] mb-3">Components driving the ratios</h3>
          <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
              <div class="bg-[#fcfbf8] rounded-lg p-3 border border-[#f1ede5]">
                  <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Current Assets</div>
                  <div class="text-lg font-bold text-[#2d3748] mt-1 tabular-nums">{{ ea_money_short((float)($c['current_assets'] ?? 0), $ccy) }}</div>
                  <div class="text-[10px] text-[#7c7466] mt-0.5">Class 3 + 4 (debit) + 5 (debit)</div>
              </div>
              <div class="bg-[#fcfbf8] rounded-lg p-3 border border-[#f1ede5]">
                  <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Current Liabilities</div>
                  <div class="text-lg font-bold text-[#2d3748] mt-1 tabular-nums">{{ ea_money_short((float)($c['current_liabilities'] ?? 0), $ccy) }}</div>
                  <div class="text-[10px] text-[#7c7466] mt-0.5">Class 4 (credit) + 5 (overdraft)</div>
              </div>
              <div class="bg-[#fcfbf8] rounded-lg p-3 border border-[#f1ede5]">
                  <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Cash &amp; Equivalents</div>
                  <div class="text-lg font-bold text-[#2d3748] mt-1 tabular-nums">{{ ea_money_short((float)($c['cash_and_equivalents'] ?? 0), $ccy) }}</div>
                  <div class="text-[10px] text-[#7c7466] mt-0.5">Class 5* (banks, caisse, investments)</div>
              </div>
              <div class="bg-[#fcfbf8] rounded-lg p-3 border border-[#f1ede5]">
                  <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Accounts Receivable</div>
                  <div class="text-lg font-bold text-[#2d3748] mt-1 tabular-nums">{{ ea_money_short((float)($c['accounts_receivable'] ?? 0), $ccy) }}</div>
                  <div class="text-[10px] text-[#7c7466] mt-0.5">Class 41* clients (debit balance)</div>
              </div>
              <div class="bg-[#fcfbf8] rounded-lg p-3 border border-[#f1ede5]">
                  <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Inventory</div>
                  <div class="text-lg font-bold {{ ((float)($c['inventory'] ?? 0)) > 0 ? 'text-[#2d3748]' : 'text-[#a09d95]' }} mt-1 tabular-nums">{{ ea_money_short((float)($c['inventory'] ?? 0), $ccy) }}</div>
                  <div class="text-[10px] text-[#7c7466] mt-0.5">{{ $isService ? 'No stock — service business' : 'Class 3* (stocks & en-cours)' }}</div>
              </div>
              <div class="bg-[#fcfbf8] rounded-lg p-3 border border-[#f1ede5]">
                  <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Accounts Payable</div>
                  <div class="text-lg font-bold text-[#2d3748] mt-1 tabular-nums">{{ ea_money_short((float)($c['accounts_payable'] ?? 0), $ccy) }}</div>
                  <div class="text-[10px] text-[#7c7466] mt-0.5">Class 40* fournisseurs (credit balance)</div>
              </div>
              <div class="bg-[#fcfbf8] rounded-lg p-3 border border-[#f1ede5]">
                  <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">Revenue (TTM)</div>
                  <div class="text-lg font-bold text-[#2d3748] mt-1 tabular-nums">{{ ea_money_short((float)($c['revenue_ttm'] ?? 0), $ccy) }}</div>
                  <div class="text-[10px] text-[#7c7466] mt-0.5">Class 7 ex 79 · trailing 12 months</div>
              </div>
              <div class="bg-[#fcfbf8] rounded-lg p-3 border border-[#f1ede5]">
                  <div class="text-[10px] uppercase tracking-wider text-[#8a8578] font-medium">{{ $isService ? 'Cost of Revenue (TTM)' : 'COGS (TTM)' }}</div>
                  <div class="text-lg font-bold text-[#2d3748] mt-1 tabular-nums">{{ ea_money_short((float)($c['cogs_ttm'] ?? 0), $ccy) }}</div>
                  <div class="text-[10px] text-[#7c7466] mt-0.5">{{ $cogsBasis }} · trailing 12 months</div>
                  @if (!is_null($c['cogs_ttm_alt_basis'] ?? null) && abs((float)$c['cogs_ttm_alt_basis']) > 0)
                    <div class="text-[9.5px] text-[#a09d95] mt-1 italic">
                        Alt basis: {{ ea_money_short((float)$c['cogs_ttm_alt_basis'], $ccy) }}
                        ({{ $isService ? 'Class 60 only' : 'all class-6 ex 68/69' }})
                    </div>
                  @endif
              </div>
          </div>

          {{-- CCC visual decomposition. For service businesses we skip the
               DIO row since it isn't part of the formula (CCC = DSO − DPO). --}}
          @if (($r['dso'] ?? null) !== null && ($r['dpo'] ?? null) !== null)
            @php
                $dso = (float) $r['dso'];
                $dpo = (float) $r['dpo'];
                $dio = ($r['dio'] ?? null) !== null ? (float) $r['dio'] : null;
                $ccc = (float) ($r['ccc'] ?? 0);
                $maxBar = max($dso, $dpo, abs($ccc), $dio ?? 0, 1);

                $rowsCcc = [['DSO', $dso, '+', 'sage']];
                if ($dio !== null) {
                    $rowsCcc[] = ['DIO', $dio, '+', 'brand'];
                }
                $rowsCcc[] = ['DPO', $dpo, '−', 'warm'];
                $rowsCcc[] = ['CCC', $ccc, '=', $ccc <= 30 ? 'sage' : ($ccc <= 75 ? 'warm' : 'coral')];
            @endphp
            <div class="border-t border-[#f1ede5] pt-4">
                <h4 class="text-xs font-semibold text-[#1e3d44] mb-3">
                    Cash Conversion Cycle decomposition
                    @if ($isService)
                      <span class="text-[10px] text-[#7c7466] font-normal italic ml-1">— DSO − DPO (no inventory term)</span>
                    @endif
                </h4>
                <div class="space-y-1.5">
                    @foreach ($rowsCcc as $row)
                        @php
                            [$label, $value, $sign, $colour] = $row;
                            $w = (int) round(100 * abs((float)$value) / $maxBar);
                            $cp = $palettes[$colour];
                        @endphp
                        <div class="flex items-center gap-3 text-xs">
                            <div class="w-10 text-[#5a6a70] font-medium shrink-0">{{ $sign }} {{ $label }}</div>
                            <div class="flex-1 bg-[#faf8f5] rounded h-5 relative overflow-hidden border border-[#ece7df]">
                                <div class="absolute inset-y-0 left-0 {{ $cp['bar'] }} opacity-70" style="width: {{ $w }}%"></div>
                            </div>
                            <div class="w-16 text-right tabular-nums font-medium text-[#2d3748]">{{ number_format((float)$value, 0) }} d</div>
                        </div>
                    @endforeach
                </div>
            </div>
          @endif

          <div class="text-[11px] text-[#7c7466] mt-4 leading-relaxed">
              <strong>Methodology:</strong>
              Balance-sheet items use SYSCOHADA code prefixes — Class 3 (inventory), 4 (tiers / counterparties; sub-class 40 = AP, 41 = AR), 5 (cash &amp; banks). Each balance is summed across posted move-lines with date ≤ {{ $asOf }}. Revenue = −Σ class-7 (excl. 79), trailing 12 months. {{ $isService
                  ? 'Cost of revenue = Σ classes 60+61+62+63+64+65+66+67 (every class-6 operating expense except 68 D&A and 69 HAO/income tax) — chosen automatically because no inventory was detected, so Class 60 alone would not capture the cost of services rendered.'
                  : 'COGS = Σ class-60 (Achats — direct purchases), trailing 12 months. Quick Ratio and DIO are shown because inventory is present on the balance sheet.'
              }} Days metrics multiply by 365 to annualise.
          </div>
      </div>
    @endif
@endsection
