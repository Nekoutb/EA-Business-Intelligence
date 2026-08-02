@extends('layouts.app')
@section('title', 'Treasury')
@section('page-title', 'Treasury')

{{-- ════════════════════════════════════════════════════════════════════
     Treasury · as-of-now snapshot

     Four KPI sandboxes at the top — same visual language as the
     /dashboard cards (.v5-card, --surface, --text variables) — driven
     by GLAccountService. No filters: every figure is the latest
     posted balance. Methodology footer at the bottom spells out each
     OHADA prefix the engine reads.
   ════════════════════════════════════════════════════════════════════ --}}

@push('styles')
<style>
  /* Page-local dashboard-language overrides — copied verbatim from
     dashboard.blade.php so the four sandboxes match exactly. */
  .topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; flex-shrink: 0; gap: 12px; }
  .topbar__t  { display: flex; align-items: baseline; gap: 12px; }
  .topbar__eb { font: 600 10px var(--font); color: var(--text-subtle); letter-spacing: .12em; text-transform: uppercase; display: flex; align-items: center; gap: 6px; }
  .topbar__eb::before { content:''; width:5px; height:5px; border-radius: 50%; background: var(--ea-primary); }
  .topbar__h  { font: 700 20px var(--font); color: var(--text); letter-spacing: -0.02em; margin-top: 2px; }
  .topbar__c  { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .topbar__meta { font: 500 11px var(--font); color: var(--text-subtle); }
  .topbar__meta b { color: var(--text); font-weight: 600; }

  /* Two-row grid: row 1 = the four KPI cards, row 2 reserved for
     the liquidity snapshot under col 1 only. grid-auto-rows:
     min-content keeps each row as tall as its tallest item, so the
     snapshot grows with its account list and the empty cells in
     row 2 cols 2-4 collapse to nothing. */
  .grid-treasury { display: grid; gap: 8px; grid-template-columns: repeat(4, 1fr); grid-auto-rows: min-content; margin-bottom: 12px; }
  @media (max-width: 1100px) { .grid-treasury { grid-template-columns: repeat(2, 1fr); } }
  @media (max-width: 600px)  { .grid-treasury { grid-template-columns: 1fr; } }

  .v5-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 12px 14px; display: flex; flex-direction: column; min-height: 0; min-height: 130px; }
  .v5-card__h { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 4px; flex-shrink: 0; }
  .v5-card__t { font: 700 12px var(--font); color: var(--text); }
  .v5-card__sub { font: 400 10.5px var(--font); color: var(--text-subtle); margin-top: 1px; }
  .v5-card__b { flex: 1; min-height: 0; width: 100%; position: relative; padding: 8px 0; }
  .v5-card__f { font: 500 10px var(--font); color: var(--text-subtle); margin-top: 4px; padding-top: 6px; border-top: 1px solid var(--border-faint); flex-shrink: 0; line-height: 1.4; }
  .v5-card__f b { color: var(--text); font-weight: 600; }

  /* Headline number — bigger than the strip__v on dashboard so the four
     KPIs read at a glance. Same font + color tokens as the dashboard. */
  .kpi-hl { font: 700 26px var(--font); color: var(--text); line-height: 1.1; letter-spacing: -.02em; tabular-nums: true; }
  .kpi-hl .u { font: 500 12px var(--font); color: var(--text-subtle); margin-left: 4px; }
  .kpi-hl.is-pos { color: var(--pos); }
  .kpi-hl.is-neg { color: var(--neg); }
  .kpi-hl.is-warn { color: var(--warn); }

  .kpi-pair { display: flex; align-items: baseline; gap: 18px; }
  .kpi-pair__c { font: 700 22px var(--font); color: var(--text); line-height: 1.1; letter-spacing: -.02em; }
  .kpi-pair__c .l { display: block; font: 700 9px var(--font); color: var(--text-subtle); letter-spacing: .06em; text-transform: uppercase; margin-bottom: 2px; }

  .breakdown { font: 500 10px var(--font); color: var(--text-subtle); }
  .breakdown b { color: var(--text); font-weight: 600; }

  .empty-state { display: flex; align-items: center; justify-content: center; min-height: 60px; color: var(--text-faint); font: 500 11px var(--font); text-align: center; padding: 8px; }
  .error-card { padding: 12px 16px; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: var(--r); font-size: 12px; margin-bottom: 8px; }

  /* ── Liquidity snapshot · narrow column under the Cash card ──────
     Sits in row 2 col 1 of .grid-treasury so it aligns directly under
     "Cash & cash equivalents". One flat list of debit-positive 52+57
     accounts sorted high→low, with a single TOTAL that ties to the
     Cash headline above. Type sizes are 0.5px tighter than the full-
     width version so 30-char account names fit a 1/4-viewport column.
     The grid placement (rows 2-3 col 1) means tall lists overflow the
     row and stay narrow rather than stretching the row. */
  /* ── Cash conversion cycle chart ─ full-width row 2 ──────────────
     Sits between the row-1 KPI cards and the row-3 snapshot panels.
     Uses the same styling vocabulary as the liquidity-ratio chart
     so they read as a pair. */
  .ccc-card { grid-column: 1 / -1; grid-row: 2 / span 1; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 12px 14px; display: flex; flex-direction: column; min-height: 240px; }
  @media (max-width: 1100px) { .ccc-card { grid-column: 1 / -1; grid-row: auto; } }

  .snap { grid-column: 1 / 2; grid-row: 3 / span 1; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); }
  .snap__h { padding: 10px 14px 8px; border-bottom: 1px solid var(--border-faint); }
  .snap__t { font: 700 12px var(--font); color: var(--text); }
  .snap__sub { font: 400 10.5px var(--font); color: var(--text-subtle); margin-top: 1px; }
  .snap__hint { font: 500 9px var(--font); color: var(--text-faint); letter-spacing: .04em; margin-top: 4px; text-transform: uppercase; }
  .snap__list { padding: 4px 0; }
  .snap__row { display: grid; grid-template-columns: 1fr max-content; gap: 8px; align-items: baseline; padding: 5px 14px; }
  .snap__row + .snap__row { border-top: 1px solid var(--border-faint); }
  .snap__row.is-zero .snap__n,
  .snap__row.is-zero .snap__v { color: var(--text-faint); }
  .snap__n { font: 500 11.5px var(--font); color: var(--text); min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .snap__v { font: 600 11.5px var(--font); color: var(--text); text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  .snap__tot { padding: 8px 14px; border-top: 2px solid var(--text); background: var(--border-faint); display: grid; grid-template-columns: 1fr max-content; gap: 8px; align-items: baseline; border-bottom-left-radius: var(--r-lg); border-bottom-right-radius: var(--r-lg); }
  .snap__tot .snap__n { font: 700 10px var(--font); letter-spacing: .08em; text-transform: uppercase; color: var(--text); }
  .snap__tot .snap__v { font: 700 13px var(--font); color: var(--text); }
  /* On narrow viewports where the grid collapses to 1-2 columns, the
     snapshot loses its column-1 anchor — let it span the available
     width naturally instead. */
  @media (max-width: 1100px) { .snap { grid-column: 1 / -1; grid-row: auto; } }

  /* ── Next 30-day disbursements · cols 2-3 of row 2 ────────────────
     Wider than the liquidity snapshot (spans 2 columns) because the
     row labels are longer ("Social insurance contributions",
     "Supplier invoices due (next 30d)"). Same vertical row layout
     and total-bar treatment as .snap so the two cards visually
     pair. Each row shows the projected amount on the right with an
     optional "ytd avg · N mo" basis hint underneath for the auditor. */
  .disb { grid-column: 2 / 4; grid-row: 3 / span 1; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); }
  .disb__h { padding: 10px 14px 8px; border-bottom: 1px solid var(--border-faint); display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
  .disb__t { font: 700 12px var(--font); color: var(--text); }
  .disb__sub { font: 400 10.5px var(--font); color: var(--text-subtle); margin-top: 1px; }
  .disb__hint { font: 500 9px var(--font); color: var(--text-faint); letter-spacing: .04em; margin-top: 4px; text-transform: uppercase; text-align: right; }
  .disb__list { padding: 4px 0; }
  .disb__row { display: grid; grid-template-columns: 1fr max-content; gap: 12px; align-items: baseline; padding: 6px 14px; }
  .disb__row + .disb__row { border-top: 1px solid var(--border-faint); }
  .disb__n { font: 500 11.5px var(--font); color: var(--text); min-width: 0; }
  .disb__nb { display: block; font: 500 9.5px var(--font); color: var(--text-subtle); margin-top: 1px; }
  .disb__v { font: 600 11.5px var(--font); color: var(--text); text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  .disb__tag { font: 600 8.5px var(--font); padding: 1px 5px; border-radius: 3px; margin-left: 6px; letter-spacing: .04em; vertical-align: middle; }
  .disb__tag.is-due { color: #ad1f1f; background: #fee2e2; border: 1px solid #fca5a5; }
  .disb__tot { padding: 8px 14px; border-top: 2px solid var(--text); background: var(--border-faint); display: grid; grid-template-columns: 1fr max-content; gap: 12px; align-items: baseline; border-bottom-left-radius: var(--r-lg); border-bottom-right-radius: var(--r-lg); }
  .disb__tot .disb__n { font: 700 10px var(--font); letter-spacing: .08em; text-transform: uppercase; color: var(--text); }
  .disb__tot .disb__v { font: 700 13px var(--font); color: var(--text); }
  /* Mobile: drop the column anchor so the card spans full width. */
  @media (max-width: 1100px) { .disb { grid-column: 1 / -1; grid-row: auto; } }

  .methodology { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 14px 16px; font: 500 11.5px var(--font); color: var(--text-muted); line-height: 1.6; }
  .methodology h3 { font: 700 12px var(--font); color: var(--text); margin-bottom: 6px; letter-spacing: -.01em; }
  .methodology code { background: var(--border-faint); color: var(--text); padding: 1px 5px; border-radius: 3px; font-size: 10.5px; }
  .methodology dl { display: grid; grid-template-columns: max-content 1fr; gap: 4px 14px; margin-top: 4px; }
  .methodology dt { font-weight: 600; color: var(--text); }
  .methodology dd { color: var(--text-muted); }
</style>
@endpush

@section('content')

@php
  // Defensive pulls — every figure can be null when Odoo hiccups.
  $t   = $treasury ?? null;
  $ccy = $t['currency'] ?? 'XAF';
  $asOf = $t['as_of'] ?? '';

  // Compact number formatter — same rules as the dashboard's
  // $shortNum / $shortUnit pair so the two pages format identically.
  $shortNum = function (?float $v): string {
      if ($v === null) return '—';
      $a = abs($v);
      if ($a >= 1_000_000_000) return number_format($v / 1_000_000_000, 2);
      if ($a >= 1_000_000)     return number_format($v / 1_000_000, 2);
      if ($a >= 1_000)         return number_format($v / 1_000, 0);
      return number_format($v, 0);
  };
  $shortUnit = function (?float $v, string $ccy = 'XAF'): string {
      if ($v === null) return '';
      $a = abs($v);
      $scale = $a >= 1_000_000_000 ? 'B ' : ($a >= 1_000_000 ? 'M ' : ($a >= 1_000 ? 'K ' : ''));
      return $scale . $ccy;
  };
  $ratio = function (?float $v): string {
      if ($v === null) return '—';
      return number_format($v, 2);
  };
@endphp

@if ($error)
  <div class="error-card">
    <strong>Error:</strong> {{ $error }} ·
    <a href="{{ tenant_url('/settings') }}" style="color:#991b1b; text-decoration:underline;">Check Odoo settings</a>
  </div>
@endif

<div class="topbar">
  <div class="topbar__t">
    <div>
      <div class="topbar__eb">Treasury · live</div>
      <div class="topbar__h">Cash, debt &amp; liquidity — as of now</div>
    </div>
  </div>
  <div class="topbar__c">
    <span class="topbar__meta">As of <b>{{ $asOf ?: 'now' }}</b> · live GL · no period filter</span>
  </div>
</div>

@if ($t)
<div class="grid-treasury">

  {{-- ── 1. Cash & obligations (merged 2026-05-08) ─────────────
       Single card with two stacked KPI blocks: cash on top, debt
       on bottom. Frees columns 2-4 of row 1 for the wider
       liquidity-ratio trend chart. Same typography (.kpi-hl,
       .breakdown) as the previous standalone cards so visual
       weight matches the rest of the dashboard. --}}
  <div class="v5-card">
    <div class="v5-card__h">
      <div>
        <div class="v5-card__t">Cash &amp; obligations</div>
        <div class="v5-card__sub">As of now · live GL</div>
      </div>
    </div>
    <div class="v5-card__b">
      {{-- Cash block --}}
      @php
        $cash = $t['cash_equivalents'] ?? null;
        $cb   = $t['cash_breakdown']  ?? [];
      @endphp
      <div style="font: 600 9px var(--font); color: var(--text-subtle); letter-spacing: .06em; text-transform: uppercase; margin-bottom: 2px;">Cash &amp; cash equivalents</div>
      <div class="kpi-hl {{ $cash !== null && $cash > 0 ? 'is-pos' : '' }}">
        {{ $shortNum($cash) }}<span class="u">{{ $shortUnit($cash, $ccy) }}</span>
      </div>
      <div class="breakdown" style="margin-top: 4px;">
        <span>Banks (52):</span> <b>{{ $shortNum($cb['banks'] ?? null) }}{{ $shortUnit($cb['banks'] ?? null, $ccy) }}</b><br>
        <span>Caisse (57):</span> <b>{{ $shortNum($cb['vault'] ?? null) }}{{ $shortUnit($cb['vault'] ?? null, $ccy) }}</b>
      </div>

      {{-- Hairline divider --}}
      <div style="height: 1px; background: var(--border-faint); margin: 12px 0;"></div>

      {{-- Debt block --}}
      @php
        $ltd       = $t['long_term_debt']  ?? null;
        $std       = $t['short_term_debt'] ?? null;
        $totalDebt = ($ltd !== null && $std !== null) ? ($ltd + $std) : null;
      @endphp
      <div style="font: 600 9px var(--font); color: var(--text-subtle); letter-spacing: .06em; text-transform: uppercase; margin-bottom: 2px;">Debt · long + short</div>
      <div class="kpi-hl {{ $totalDebt !== null && $totalDebt > 0 ? 'is-warn' : '' }}">
        {{ $shortNum($totalDebt) }}<span class="u">{{ $shortUnit($totalDebt, $ccy) }}</span>
      </div>
      <div class="breakdown" style="margin-top: 4px;">
        <span>Long-term (16):</span> <b>{{ $shortNum($ltd) }}{{ $shortUnit($ltd, $ccy) }}</b><br>
        <span>Short-term (4 + 52):</span> <b>{{ $shortNum($std) }}{{ $shortUnit($std, $ccy) }}</b>
      </div>
    </div>
    <div class="v5-card__f">
      Cash = Σ debit-positive 52 + 57 · Debt = Σ net credit on 16 + per-account credit on 4 + 52.
    </div>
  </div>

  {{-- ── 3. Liquidity ratios trend — 12 prior months + today ──
       Replaces the static current/quick KPI tile (2026-05-08 spec).
       Spans 2 columns so the 13-point line chart has room to
       breathe. Series painted on the chart:
         • Current ratio  (CA ÷ CL)              — black solid
         • Quick   ratio  ((CA − Inv) ÷ CL)      — rose solid
         • Cash    ratio  ((52+57) ÷ CL)         — slate dotted
         • Threshold      = 1.0×                 — amber dashed
       Hover any point for month label + all 3 ratio values.
       The right-most point evolves day-to-day as new entries post. --}}
  <div class="v5-card" style="grid-column: 2 / 5;">
    <div class="v5-card__h">
      <div>
        <div class="v5-card__t">Liquidity ratios · 12 months</div>
        <div class="v5-card__sub">Current, Quick &amp; Cash with 1.0× threshold</div>
      </div>
      <div class="v5-card__leg" style="font-size: 10px;">
        <span><span class="lline" style="background: #1e3d44;"></span>Current</span>
        <span><span class="lline" style="background: #b91c1c;"></span>Quick</span>
        <span><span class="lline" style="background: #0d9488;"></span>Cash</span>
        <span><span class="lline" style="background: transparent; border-top: 1px dashed #c97a1f;"></span>1.0×</span>
      </div>
    </div>
    <div class="v5-card__b" id="ec-liq-trend" style="min-height: 220px;"></div>
    <div class="v5-card__f">
      Cash = Σ debit-positive 52 + 57 ÷ Current liabilities · prior months at month-end · current month evolving day-to-day.
    </div>
  </div>

  {{-- ── Cash conversion cycle · row 2, full width (12 cols) ─────
       Same 12-month + today window as the liquidity-ratio chart so
       both can be read together. Single black line for CCC; hover
       tooltip exposes the underlying DSO + DIO + DPO so the auditor
       can see what's driving the cycle up or down at any point. --}}
  <div class="ccc-card">
    <div class="v5-card__h">
      <div>
        <div class="v5-card__t">Cash conversion cycle</div>
        <div class="v5-card__sub">Working capital efficiency over time.</div>
      </div>
      <div class="v5-card__leg" style="font-size: 10px;">
        <span style="font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--text-subtle);">Days · DSO + DIO − DPO</span>
      </div>
    </div>
    <div class="v5-card__b" id="ec-ccc-trend" style="min-height: 220px;"></div>
    <div class="v5-card__f">
      DSO = AR ÷ (TTM revenue ÷ 365) · DIO = inventory ÷ (TTM COGS ÷ 365) · DPO = AP ÷ (TTM COGS ÷ 365). Service businesses (no inventory) drop DIO, so CCC simplifies to DSO − DPO.
    </div>
  </div>

  {{-- ── Liquidity snapshot · per-account list under the Cash card ──
       Lives INSIDE .grid-treasury so its CSS grid-column/grid-row
       placement (1/2, 2/3) anchors it directly under the Cash card
       in column 1, row 2. Cells in row 2 cols 2-4 stay empty — the
       grid's `grid-auto-rows: min-content` collapses them so the
       methodology footer sits right under whichever ends taller
       (the snapshot or the row-1 cards). Detailed copy:
        • flat list, no subtotals
        • account name only (no code)
        • full-precision XAF amounts with thousands separators
        • sorted high→low; zeros greyed and sink to the bottom
        • TOTAL ties exactly to Cash & cash equivalents headline
        • overdrafts excluded (they live on the ST-debt card) --}}
  @php
    $snap   = $t['liquidity_snapshot'] ?? null;
    $rows   = $snap['rows'] ?? [];
    $snTot  = (float) ($snap['total'] ?? 0);
    // "XAF 47,250,000" formatter — same XAF-prefix style every row,
    // matching the user spec: "Description : XAF XXX amount".
    $xaf = function (float $v) use ($ccy): string {
        return $ccy . ' ' . number_format($v, 0, '.', ',');
    };
  @endphp
  @if (!empty($rows))
  <div class="snap">
    <div class="snap__h">
      <div class="snap__t">Liquidity snapshot</div>
      <div class="snap__sub">Per-account · sorted high → low</div>
      <div class="snap__hint">All amounts {{ $ccy }}</div>
    </div>
    <div class="snap__list">
      @foreach ($rows as $r)
        @php $isZero = ((float) $r['balance']) <= 0.5; @endphp
        <div class="snap__row {{ $isZero ? 'is-zero' : '' }}">
          <div class="snap__n" title="{{ $r['name'] }}">{{ $r['name'] }}</div>
          <div class="snap__v">{{ $xaf((float) $r['balance']) }}</div>
        </div>
      @endforeach
    </div>
    <div class="snap__tot">
      <div class="snap__n">Total</div>
      <div class="snap__v">{{ $xaf($snTot) }}</div>
    </div>
  </div>
  @endif

  {{-- ── Next 30-day disbursements · cols 2-3 of row 2 ────────────
       Forward-looking projection of cash outflows. Each row's
       "projection" is the YTD-monthly-average (Source 1+2 in
       methodology) or — for the supplier-invoices-due line —
       the actual amount due from the AP ledger (Source 3).
       Pairs visually with the liquidity snapshot in col 1: same
       row, same total-bar treatment, similar typography. --}}
  @php
    $disb       = $t['disbursements'] ?? null;
    $disbRows   = $disb['rows'] ?? [];
    $disbTotal  = (float) ($disb['total'] ?? 0);
    $disbBasis  = (int)   ($disb['months_basis'] ?? 0);
  @endphp
  @if (!empty($disbRows))
  <div class="disb">
    <div class="disb__h">
      <div>
        <div class="disb__t">Next 30-day disbursements</div>
        <div class="disb__sub">YTD-monthly-average projection · {{ $disbBasis }} mo basis · sorted high → low</div>
      </div>
      <div class="disb__hint">All amounts {{ $ccy }}</div>
    </div>
    <div class="disb__list">
      @foreach ($disbRows as $r)
        @php
          $hasAPDue = (bool) ($r['includes_ap_due'] ?? false);
          $basisHint = $disbBasis > 0
              ? 'YTD avg · ' . $disbBasis . ' mo basis'
              : 'YTD avg';
          // The supplier-payments row folds in actual AP invoices due
          // in the next 30 days on top of the YTD average. Surface
          // this in the row hint so the auditor knows the projection
          // is "average + due", not pure historical average.
          if ($hasAPDue) {
              $basisHint .= ' · plus AP due 30d (' . $xaf((float) $r['ap_due_30d']) . ')';
          }
        @endphp
        <div class="disb__row">
          <div class="disb__n">
            {{ $r['label'] }}
            <span class="disb__nb">{{ $basisHint }}</span>
          </div>
          <div class="disb__v">{{ $xaf((float) $r['projection']) }}</div>
        </div>
      @endforeach
    </div>
    <div class="disb__tot">
      <div class="disb__n">Total projected · next 30d</div>
      <div class="disb__v">{{ $xaf($disbTotal) }}</div>
    </div>
  </div>
  @endif

</div>

{{-- ── Collections by revenue stream — Product Revenue TSD categories ── --}}
@if (!empty($collections['streams']))
@php
    $cFmt = function ($v) {
        if ($v === null) return '—';
        return number_format((float) $v, 0);
    };
    $cCats = (array) ($collections['categories'] ?? []);
    $cCatT = (array) ($collections['cat_totals'] ?? []);
    $cUn   = (array) ($collections['unmatched'] ?? []);
    $cOth  = (array) ($collections['other_income'] ?? []);
    $cRec  = (array) ($collections['recon'] ?? []);
    $cByCat = [];
    foreach ((array) $collections['streams'] as $cs) { $cByCat[$cs['category']][] = $cs; }
    $cTh = 'text-align:right;padding:6px 8px;font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:var(--text-subtle);';
    // Columns follow the master period filter: 'sel' = the selected window
    // (July when Month·July is picked; MTD on the default view), 'ytd' =
    // year-to-date of the selected year. Field map: 'sel' figures travel in
    // the payload's 'mtd' slots.
    $cCols  = (array) ($collections['columns'] ?? ['sel', 'ytd']);
    $cLab   = (array) ($collections['labels'] ?? ['sel' => 'MTD', 'ytd' => 'YTD']);
    $cField = ['sel' => 'mtd', 'ytd' => 'ytd'];
@endphp
<div class="v5-card" style="margin-top: 14px;">
  <div class="v5-card__h">
    <div>
      <div class="v5-card__t">Collections by Revenue Stream</div>
      <div class="v5-card__sub">Paid share of customer invoices (HT · {{ $ccy }}) · Product Revenue TSD categories · as of {{ $collections['as_of'] ?? '' }}</div>
    </div>
  </div>
  <div class="v5-card__b" style="overflow-x: auto;">
    <table style="width:100%; border-collapse:collapse; font-size:12px;">
      <thead>
        <tr style="border-bottom:1px solid var(--border);">
          <th style="text-align:left;padding:6px 8px;font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:var(--text-subtle);">Revenue stream</th>
          @foreach ($cCols as $cw)
          <th style="{{ $cTh }}">{{ $cLab[$cw] ?? strtoupper($cw) }}</th>
          @endforeach
        </tr>
      </thead>
      <tbody>
        @foreach ($cCats as $ck => $cat)
          @php $cRows = $cByCat[$ck] ?? []; @endphp
          @if (!empty($cRows))
            <tr style="background: var(--surface-alt);">
              <td style="padding:6px 8px;font-weight:700;">
                <span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:{{ $cat['color'] ?? '#64748b' }};margin-right:6px;"></span>{{ $cat['label'] ?? $ck }}
              </td>
              @foreach ($cCols as $cw)
              <td style="padding:6px 8px;text-align:right;font-weight:700;">{{ $cFmt($cCatT[$ck][$cField[$cw]] ?? 0) }}</td>
              @endforeach
            </tr>
            @foreach ($cRows as $cs)
            <tr style="border-bottom:1px solid var(--border-faint);">
              <td style="padding:5px 8px 5px 26px;color:var(--text-muted);">{{ $cs['label'] }}</td>
              @foreach ($cCols as $cw)
              <td style="padding:5px 8px;text-align:right;">{{ $cFmt($cs[$cField[$cw]] ?? 0) }}</td>
              @endforeach
            </tr>
            @endforeach
          @endif
        @endforeach
        @if (abs((float) ($cUn['ytd'] ?? 0)) > 0.5 || abs((float) ($cUn['mtd'] ?? 0)) > 0.5)
        <tr style="border-bottom:1px solid var(--border-faint);">
          <td style="padding:5px 8px;color:var(--warn);">Unmatched class-70 accounts ({{ count($cUn['accounts'] ?? []) }})</td>
          @foreach ($cCols as $cw)
          <td style="padding:5px 8px;text-align:right;color:var(--warn);">{{ $cFmt($cUn[$cField[$cw]] ?? 0) }}</td>
          @endforeach
        </tr>
        @endif
        @if (abs((float) ($cOth['ytd'] ?? 0)) > 0.5 || abs((float) ($cOth['mtd'] ?? 0)) > 0.5)
        <tr style="border-bottom:1px solid var(--border-faint);">
          <td style="padding:5px 8px;color:var(--text-subtle);">Other income (non-70x invoice lines)</td>
          @foreach ($cCols as $cw)
          <td style="padding:5px 8px;text-align:right;color:var(--text-subtle);">{{ $cFmt($cOth[$cField[$cw]] ?? 0) }}</td>
          @endforeach
        </tr>
        @endif
      </tbody>
      <tfoot>
        <tr style="border-top:2px solid var(--border);">
          <td style="padding:7px 8px;font-weight:700;">Total collected (HT)</td>
          @foreach ($cCols as $cw)
          <td style="padding:7px 8px;text-align:right;font-weight:700;">{{ $cFmt($cRec[$cField[$cw]] ?? 0) }}</td>
          @endforeach
        </tr>
      </tfoot>
    </table>
  </div>
  <div class="v5-card__f">
    Collected = invoice HT × paid fraction ((total − residual) ÷ total) per posted customer invoice, windowed on invoice date — the same construction as the Scan → Cash reconciliation's "Collected", so the YTD total ties to the dashboard's collected figure. Streams map the named class-70 scanning accounts through the same matcher as Product Revenue TSD (shared accounts split by the same redistribution shares). Columns follow the master period filter — {{ $cLab['sel'] ?? 'MTD' }} = the selected period · {{ $cLab['ytd'] ?? 'YTD' }} = 1 Jan of the selected year to the period's end · partially-paid invoices contribute their paid share ({{ (int) ($collections['meta']['partials_ytd'] ?? 0) }} partial YTD).
  </div>
</div>
@endif

{{-- ── Methodology footer (single block, all four KPIs) ──────── --}}
<div class="methodology">
  <h3>How these figures are computed</h3>
  <p style="margin-bottom: 10px;">
    Every value reads <b>as of right now</b> from the latest Odoo refresh.
    No filters, no period selector. The engine queries posted move lines
    on the OHADA chart of accounts and aggregates server-side.
  </p>
  <dl>
    <dt>Cash &amp; equivalents</dt>
    <dd>Σ debit-positive balances per account, on classes <code>52</code> (banques) + <code>57</code> (caisse). Bank accounts in overdraft are excluded — their credit balances surface under Short-term debt instead.</dd>

    <dt>Debt</dt>
    <dd>Long-term + short-term liabilities combined into a single tile. <strong>Long-term</strong> = net credit balance on class <code>16</code> (emprunts et dettes assimilées). <strong>Short-term</strong> = Σ per-account credit-positive balances on class <code>4</code> (third parties) + class <code>52</code> (bank overdrafts). Per-account aggregation on class 4 prevents AR (411 in normal debit position) from offsetting AP (401 in normal credit position).</dd>

    <dt>Liquidity ratios · 12 months</dt>
    <dd>Three-line trend chart with a 1.0× threshold reference. <strong>Current</strong> = current assets ÷ current liabilities. <strong>Quick</strong> = (current assets − inventory) ÷ current liabilities. <strong>Cash</strong> = Σ debit-positive balances on classes <code>52</code> + <code>57</code> ÷ current liabilities (strictest view — bank + caisse only). The 12 prior data points are computed from cumulative GL balances at month-end; the right-most point evolves day-to-day as new entries post in the current month. Single-pass query (groupby account + month) so trend length is free; mirrors the as-of-now ratios on <a href="{{ tenant_url('/working-capital') }}" style="color: var(--text); text-decoration: underline;">Working Capital</a>.</dd>

    <dt>Cash conversion cycle · 12 months</dt>
    <dd>Trailing-twelve-month working capital efficiency in days. <strong>CCC = DSO + DIO − DPO</strong>, where DSO = AR ÷ (TTM revenue ÷ 365), DIO = inventory ÷ (TTM COGS ÷ 365), DPO = AP ÷ (TTM COGS ÷ 365). For service businesses (no material inventory), DIO is dropped and CCC simplifies to DSO − DPO. Computed in the same single-pass query as the liquidity ratios — both charts share the underlying data shape so they render together with no extra round-trips.</dd>

    <dt>Liquidity snapshot</dt>
    <dd>Per-account list of every <code>52</code> + <code>57</code> account with a positive debit balance, sorted high → low. Zero-balance and overdrawn accounts are excluded — overdrafts surface under Short-term debt instead.</dd>

    <dt>Next 30-day disbursements</dt>
    <dd>Forward-looking cash-outflow projection based on YTD history, by category. For each prefix (<code>16</code> long-term debt, <code>42</code> salaries, <code>43</code> social, <code>44</code> tax, <code>401</code> suppliers), sum debits posted via cash journals (type <code>cash</code> or <code>bank</code>) from <strong>1 January through the end of the last full month</strong>, divide by the number of full months elapsed → monthly run-rate. <strong>Supplier payments</strong> combine three components into a single row: (i) the <code>401</code> cash-journal average, (ii) the average of <em>direct expense-to-cash</em> entries (Dr 6XX / Cr 52|57 in the same move — petty-cash purchases that bypass the AP step), and (iii) the actual amount of AP invoices already posted with a due-date in the next 30 days (from the AP ledger). Class 2 (fixed assets) is excluded as non-recurrent; class 5 (cash) is excluded as internal transfers.</dd>
  </dl>
</div>

@else
  <div class="empty-state">
    Live GL data not available. Check Odoo connectivity.
  </div>
@endif

@endsection

@push('scripts')
<script>
(function () {
    const el = document.getElementById('ec-liq-trend');
    if (!el || typeof echarts === 'undefined') return;

    const trend = @json($treasury['liquidity_trend'] ?? ['series' => [], 'threshold' => 1.0]);
    const series = trend.series || [];
    if (series.length === 0) {
        el.innerHTML = '<div class="empty-state" style="height:200px; display:flex; align-items:center; justify-content:center; color: var(--text-faint); font: 500 11px var(--font);">No GL history available for trend.</div>';
        return;
    }

    // Format helpers — ratio always rendered as "X.XX×" to match the
    // user's screenshot. Null values show as em-dash.
    const fmtRatio = (v) => (v == null) ? '—' : v.toFixed(2) + '×';
    const labels   = series.map(p => p.label);
    const current  = series.map(p => p.current);
    const quick    = series.map(p => p.quick);
    const cash     = series.map(p => p.cash);

    // Y-axis: auto-scale but always include 0 and the 1.0 threshold so
    // the reference line stays visible even when all ratios are <1.0.
    const allVals = current.concat(quick, cash).filter(v => v != null);
    const dataMax = allVals.length ? Math.max(...allVals) : 1.0;
    const yMax    = Math.max(1.4, Math.ceil(dataMax * 10) / 10 + 0.1);

    const chart = echarts.init(el);
    chart.setOption({
        grid:    { left: 42, right: 12, top: 8, bottom: 36 },
        tooltip: {
            trigger: 'axis',
            backgroundColor: '#1e3d44', borderWidth: 0, padding: [8, 12],
            textStyle: { color: '#fff', fontSize: 11 },
            formatter: (params) => {
                if (!params || params.length === 0) return '';
                const ix = params[0].dataIndex;
                const point = series[ix];
                const lines = [
                    `<b>${point.label}</b>`,
                    `Current: ${fmtRatio(point.current)}`,
                    `Quick: ${fmtRatio(point.quick)}`,
                    `Cash: ${fmtRatio(point.cash)}`,
                ];
                return lines.join('<br>');
            },
            axisPointer: { type: 'cross', label: { show: false }, lineStyle: { color: '#94a3b8', type: 'dashed' } },
        },
        xAxis: {
            type: 'category', data: labels, boundaryGap: false,
            axisLabel: { color: '#8a8578', fontSize: 9, interval: 'auto' },
            axisLine:  { lineStyle: { color: '#cbd5e1' } },
            axisTick:  { show: false },
        },
        yAxis: {
            type: 'value', min: 0, max: yMax,
            axisLabel: { formatter: (v) => v.toFixed(2) + '×', color: '#8a8578', fontSize: 9 },
            splitLine: { lineStyle: { color: '#f0ede7', type: 'solid' } },
            axisLine:  { lineStyle: { color: '#cbd5e1' } },
        },
        series: [
            {
                name: 'Current ratio', type: 'line', data: current, smooth: false, showSymbol: true,
                symbol: 'circle', symbolSize: 5,
                itemStyle: { color: '#1e3d44' },
                lineStyle: { color: '#1e3d44', width: 2 },
                emphasis: { focus: 'series', scale: 1.5 },
                markLine: {
                    silent: true, symbol: 'none',
                    lineStyle: { type: 'dashed', color: '#c97a1f', width: 1.5, opacity: 0.7 },
                    label: { show: true, formatter: '1.0×', position: 'end', color: '#c97a1f', fontSize: 10, fontWeight: 600 },
                    data: [{ yAxis: 1.0 }],
                },
            },
            {
                name: 'Quick ratio', type: 'line', data: quick, smooth: false, showSymbol: true,
                symbol: 'circle', symbolSize: 5,
                itemStyle: { color: '#b91c1c' },
                lineStyle: { color: '#b91c1c', width: 2 },
                emphasis: { focus: 'series', scale: 1.5 },
            },
            {
                // Cash ratio — recoloured 2026-05-08 from faint slate-400
                // (#94a3b8) to teal-600 (#0d9488), line dashed instead of
                // dotted, width 2 instead of 1.5. Now distinguishable at
                // a glance from current (black) and quick (rose).
                name: 'Cash ratio', type: 'line', data: cash, smooth: false, showSymbol: true,
                symbol: 'circle', symbolSize: 5,
                itemStyle: { color: '#0d9488' },
                lineStyle: { color: '#0d9488', width: 2, type: 'dashed' },
                emphasis: { focus: 'series', scale: 1.5 },
            },
        ],
    });
    window.addEventListener('resize', () => chart.resize());
})();

// ── Cash conversion cycle ────────────────────────────────────────────
// Single-line trend over the same 12-month + today window as the
// liquidity-ratio chart above. Reads dso/dio/dpo/ccc from the same
// liquidity_trend payload (added in WorkingCapitalService::liquidityTrend
// 2026-05-08). Hover exposes the three components so the auditor can
// see what's driving the cycle up or down at any point.
(function () {
    const el = document.getElementById('ec-ccc-trend');
    if (!el || typeof echarts === 'undefined') return;

    const trend = @json($treasury['liquidity_trend'] ?? ['series' => []]);
    const series = trend.series || [];
    if (series.length === 0) {
        el.innerHTML = '<div class="empty-state" style="height:200px; display:flex; align-items:center; justify-content:center; color: var(--text-faint); font: 500 11px var(--font);">No GL history available for cash conversion cycle.</div>';
        return;
    }

    // Tooltip uses 1 decimal place — user 2026-05-09 spotted that
    // integer rounding hid sub-day CCC movements (14.2 / 14.7 / 15.1
    // all rendered as "14d", chart looked flat). Y-axis labels stay
    // integer (less clutter on gridlines); precision lives in tooltip
    // where the auditor reads exact values.
    const fmtDays = (v) => (v == null) ? '—' : v.toFixed(1) + 'd';

    // ── Filter pre-TTM-complete points ────────────────────────────
    // The service marks ccc/dso/dio/dpo as null for snapshots whose
    // trailing-12-month window predates the company's earliest P&L
    // activity. Those points carry no meaningful CCC value — plotting
    // them would distort the y-axis scale (early divisions by tiny
    // partial-TTM denominators inflate days into the tens of thousands).
    // Drop both the null values AND their x-axis labels so the chart
    // starts at the first valid TTM-complete point. Bug from 2026-05-08
    // Kissywears feedback: chart showed phantom 60,000d May-25 point.
    const validPoints = series.filter(p => p.ccc != null);
    if (validPoints.length === 0) {
        el.innerHTML = '<div class="empty-state" style="height:200px; display:flex; align-items:center; justify-content:center; color: var(--text-faint); font: 500 11px var(--font); text-align:center; padding: 8px;">Cash conversion cycle requires 12 months of revenue history.<br>Available data covers ' + series.length + ' month(s) — first valid CCC point will appear once a full TTM window is reached.</div>';
        return;
    }
    const labels    = validPoints.map(p => p.label);
    const cccPoints = validPoints.map(p => p.ccc);

    const chart = echarts.init(el);
    chart.setOption({
        grid: { left: 42, right: 12, top: 8, bottom: 36 },
        tooltip: {
            trigger: 'axis',
            backgroundColor: '#1e3d44', borderWidth: 0, padding: [8, 12],
            textStyle: { color: '#fff', fontSize: 11 },
            formatter: (params) => {
                if (!params || !params.length) return '';
                const ix = params[0].dataIndex;
                const point = validPoints[ix];
                // Show the full DSO + DIO − DPO breakdown so the auditor
                // can read at a glance which lever moved.
                const lines = [`<b>${point.label}</b>`];
                lines.push(`DSO &nbsp;${fmtDays(point.dso)}`);
                if (!point.is_service) lines.push(`DIO &nbsp;${fmtDays(point.dio)}`);
                else                   lines.push(`DIO &nbsp;n/a (service)`);
                lines.push(`DPO &nbsp;${fmtDays(point.dpo)}`);
                lines.push('<span style="color:#cbd5e1">─────────</span>');
                lines.push(`<b>CCC ${fmtDays(point.ccc)}</b>`);
                return lines.join('<br>');
            },
            axisPointer: { type: 'cross', label: { show: false }, lineStyle: { color: '#94a3b8', type: 'dashed' } },
        },
        xAxis: {
            type: 'category', data: labels, boundaryGap: false,
            axisLabel: { color: '#8a8578', fontSize: 9, interval: 'auto' },
            axisLine:  { lineStyle: { color: '#cbd5e1' } },
            axisTick:  { show: false },
        },
        yAxis: {
            type: 'value',
            // Tight auto-scaling around actual data so the trend is
            // immediately readable. ECharts callbacks return the
            // computed min/max from the rendered series — pad ±2 days
            // for visual breathing room, ceil/floor to integers so the
            // axis labels read cleanly.
            min: (val) => Math.floor(val.min - 2),
            max: (val) => Math.ceil(val.max + 2),
            axisLabel: { formatter: (v) => Math.round(v) + 'd', color: '#8a8578', fontSize: 9 },
            splitLine: { lineStyle: { color: '#f0ede7', type: 'solid' } },
            axisLine:  { lineStyle: { color: '#cbd5e1' } },
        },
        series: [
            {
                name: 'Cash conversion cycle', type: 'line', data: cccPoints,
                showSymbol: true, symbol: 'circle', symbolSize: 5,
                itemStyle: { color: '#1e3d44' },
                lineStyle: { color: '#1e3d44', width: 2 },
                emphasis:  { focus: 'series', scale: 1.5 },
                areaStyle: {
                    // Subtle area fill to match the screenshot vibe
                    // without dominating the line.
                    color: {
                        type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
                        colorStops: [
                            { offset: 0, color: 'rgba(30, 61, 68, 0.10)' },
                            { offset: 1, color: 'rgba(30, 61, 68, 0.00)' },
                        ],
                    },
                },
            },
        ],
    });
    window.addEventListener('resize', () => chart.resize());
})();
</script>
@endpush
