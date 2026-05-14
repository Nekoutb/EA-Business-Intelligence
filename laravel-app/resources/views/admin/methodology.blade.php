@extends('admin.layout')
@section('title', 'Methodology')

@section('header')
    <h1 class="text-2xl font-bold text-slate-900">Methodology — Assumptions reference</h1>
    <p class="text-xs text-slate-500 mt-1">
        Per-line documentation of every KPI / box / row in the BMS. One source of truth for what each value means, where it comes from, and how it's computed.
        <span class="ml-1 inline-flex items-center text-[10px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded bg-rose-100 text-rose-700 border border-rose-200">Internal — not shown to clients</span>
    </p>
@endsection

@section('content')

<div class="panel p-4 mb-5 border-l-4 border-amber-400">
    <div class="flex items-start gap-3">
        <svg class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        <div class="text-xs text-slate-700 leading-relaxed">
            <strong>Confidential — internal reference.</strong> This page documents the exact data sources, filters, and formulas behind every box in the BMS. The intent is that a tax accountant or financial controller can read a line, understand where the number comes from, and confidently request a change. Use it to onboard new operators, audit assumptions, and plan rule changes.
            <br><br>
            <em>Status:</em> Currently documenting <strong>Tax Return sections §7 (Acomptes à reverser), §8 (Acomptes à déduire), §9 (IR Liquidation) and §12 (Impôts retenus sur salaires)</strong>, plus a cross-cutting rules panel at the top. Other sections will be added on request.
        </div>
    </div>
</div>

{{-- Quick anchors --}}
<div class="panel p-3 mb-5 flex gap-2 flex-wrap">
    <span class="text-[10px] uppercase tracking-wider text-slate-500 font-medium px-2 py-1">Jump to</span>
    <a href="#cross-cutting-rules" class="text-xs px-3 py-1 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-700 transition">Cross-cutting rules</a>
    <a href="#tax-return-s7"  class="text-xs px-3 py-1 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-700 transition">Tax Return · §7 Acomptes à reverser</a>
    <a href="#tax-return-s8"  class="text-xs px-3 py-1 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-700 transition">Tax Return · §8 Acomptes à déduire</a>
    <a href="#tax-return-s9"  class="text-xs px-3 py-1 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-700 transition">Tax Return · §9 IR Liquidation</a>
    <a href="#tax-return-s12" class="text-xs px-3 py-1 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-700 transition">Tax Return · §12 RTS</a>
</div>

{{-- ─────────────────────────────────────── Cross-cutting rules ──── --}}
<section id="cross-cutting-rules" class="panel p-6 mb-5 scroll-mt-6">
    <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <div>
            <h2 class="text-base font-bold text-slate-900">Cross-cutting rules · apply to every line on the tax return</h2>
            <p class="text-[11px] text-slate-500 mt-0.5">General conventions that govern how lines are sourced, split and propagated. Documented once here so per-line cards don't have to repeat them.</p>
        </div>
    </div>

    <div class="space-y-3 text-[12.5px] text-slate-700 leading-relaxed">
        <div class="border border-slate-200 rounded-md bg-white p-4">
            <h3 class="text-[12px] font-semibold text-slate-900 mb-1.5">Reference-line carry-through</h3>
            <p>
                When a line's label says <em>"= L<other>"</em>, the row carries forward <strong>every column</strong> of the referenced line — Principal, CAC, Pénalités and Total. None are dropped.
            </p>
            <p class="mt-1.5">
                Example: §9 L52 "Déductions à opérer = L49" must display L49's Principal AND L49's CAC AND L49's Total — not just the Principal. Same rule applies to L36 (= L32), L53 (= L55 prior month), and any future reference line.
            </p>
        </div>

        <div class="border border-slate-200 rounded-md bg-white p-4">
            <h3 class="text-[12px] font-semibold text-slate-900 mb-1.5">Period anchor</h3>
            <p>
                Every line that sources from the GL is restricted to the declaration window — first to last calendar day of the chosen month. Only posted entries (<code class="font-mono text-[11px] bg-slate-100 px-1 rounded">account.move.line.parent_state = 'posted'</code>). Drafts and cancelled moves are excluded.
            </p>
        </div>

        <div class="border border-slate-200 rounded-md bg-white p-4">
            <h3 class="text-[12px] font-semibold text-slate-900 mb-1.5">Principal / CAC split variants</h3>
            <p>The form mixes two CAC conventions; both currently coexist depending on the line:</p>
            <ul class="mt-1.5 pl-5 list-disc space-y-1">
                <li><strong>Total/1.10 split</strong> (used on §7 L40, §8 L45, §12 L67): the GL amount IS the Total. Principal = Total ÷ 1.10, CAC = Principal × 10%. Principal + CAC ≈ Total.</li>
                <li><strong>Principal + 10% on top</strong> (legacy — used on §6 L36, §7 L41–L43, §10–§11 lines): the GL amount IS the Principal. CAC = Principal × 10% added on top. Total = Principal + CAC = Principal × 1.10.</li>
            </ul>
            <p class="mt-1.5 text-slate-600">Each line's card below specifies which variant applies.</p>
        </div>

        <div class="border border-slate-200 rounded-md bg-white p-4">
            <h3 class="text-[12px] font-semibold text-slate-900 mb-1.5">Section header total</h3>
            <p>The figure shown next to each <code class="font-mono text-[11px] bg-slate-100 px-1 rounded">§N</code> collapsed header equals that section's TOTAL line's Total column (L9 / L15 / L22 / L32 / L39 / L44 / L49 / L54 / L62 / L66 / L73 / L77 / L80 depending on the section). §8 is deductive — its header is shown for visibility but does NOT feed Total à payer (the deductible already flows through §9 L52).</p>
        </div>
    </div>
</section>

{{-- ────────────────────────────── Tax Return · §7 IR à reverser ──── --}}
<section id="tax-return-s7" class="panel p-6 mb-5 scroll-mt-6">
    <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <div>
            <h2 class="text-base font-bold text-slate-900">Tax Return — Section 7 · Acomptes et précomptes à reverser</h2>
            <p class="text-[11px] text-slate-500 mt-0.5">Retentions effectuées par l'entreprise · owed to the State</p>
        </div>
        <span class="text-[10px] uppercase tracking-wider text-slate-400 font-medium font-mono">/monthly-tax-return</span>
    </div>

    <div class="bg-slate-50 border border-slate-200 rounded-md p-4 mb-5 text-[12.5px] text-slate-700 leading-relaxed">
        <h3 class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-2">Section context</h3>
        <p>
            §7 captures retentions the entity made <em>on others</em> during the declaration month — amounts the entity owes to the State. Form columns: <strong>Principal · CAC · Pénalités · Total</strong>. Posted entries only (<code class="text-[10.5px] bg-white px-1 rounded">parent_state = posted</code>), declaration-month window.
        </p>
    </div>

    <div class="space-y-3">

        {{-- L40 --}}
        <div class="border border-slate-200 rounded-md overflow-hidden bg-white">
            <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center gap-3 flex-wrap">
                <span class="font-mono text-[11px] font-semibold tracking-wider bg-slate-900 text-white px-2 py-0.5 rounded">L40</span>
                <span class="text-[13px] font-semibold text-slate-900">Acompte sur CA retenu à la source</span>
                <span class="ml-auto text-[10px] uppercase tracking-wider text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full font-semibold">Sourced from Odoo</span>
            </div>
            <div class="px-4 py-3 grid sm:grid-cols-12 gap-x-4 gap-y-2 text-[12px]">
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Source</div>
                <div class="sm:col-span-10 text-slate-800">
                    Account <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">44712XX</code> · sum of <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">account.move.line.balance</code> for the declaration month.
                </div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Filter</div>
                <div class="sm:col-span-10 text-slate-800">None — the whole 44712XX balance is taken. 44712 in the COA is dedicated to "Acompte sur CA retenu à la source — à reverser" (retentions made <em>by</em> the entity).</div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Formula</div>
                <div class="sm:col-span-10 text-slate-800 leading-relaxed">
                    The sourced amount is the <strong>Total</strong> (gross retention, including CAC). Principal and CAC are derived so they sum back to the Total:
                    <div class="mt-1.5 font-mono text-[11.5px] bg-slate-50 border border-slate-200 rounded p-2 leading-relaxed">
                        Total      = sum(44712XX) for month<br>
                        Principal  = Total ÷ 1.10<br>
                        CAC        = Principal × 10%<br>
                        Principal + CAC = Total  <span class="text-slate-500">(modulo float rounding ≤ 1 XAF)</span>
                    </div>
                    <p class="mt-2 text-[11.5px] text-slate-600">Same split as §12 L67 (IRPP) and §8 L45 — CAC is computed on the Principal, not on the Total.</p>
                </div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Notes</div>
                <div class="sm:col-span-10 text-slate-600">Was previously hardcoded to 0 (manual entry only). Now auto-sourced from the GL.</div>
            </div>
        </div>

        {{-- L41 --}}
        <div class="border border-slate-200 rounded-md overflow-hidden bg-white">
            <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center gap-3 flex-wrap">
                <span class="font-mono text-[11px] font-semibold tracking-wider bg-slate-900 text-white px-2 py-0.5 rounded">L41</span>
                <span class="text-[13px] font-semibold text-slate-900">Précomptes sur achats retenus (0,5 % / 1 % / 3 % / 5 % / 10 %)</span>
                <span class="ml-auto text-[10px] uppercase tracking-wider text-amber-700 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded-full font-semibold">Manual entry</span>
            </div>
            <div class="px-4 py-3 grid sm:grid-cols-12 gap-x-4 gap-y-2 text-[12px]">
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Source</div>
                <div class="sm:col-span-10 text-slate-800">No Odoo source mapped — defaults to 0 pending user direction.</div>
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Formula</div>
                <div class="sm:col-span-10 text-slate-800">Principal = entered value · CAC = Principal × 10% · Total = Principal + CAC. (Legacy "CAC added on top" model — different from L40's Total/1.10 split.)</div>
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Notes</div>
                <div class="sm:col-span-10 text-slate-600">Tell me which account hosts these précomptes and which model to apply (Total/1.10 split or Principal + 10% on top) and I'll wire it.</div>
            </div>
        </div>

        {{-- L42 --}}
        <div class="border border-slate-200 rounded-md overflow-hidden bg-white">
            <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center gap-3 flex-wrap">
                <span class="font-mono text-[11px] font-semibold tracking-wider bg-slate-900 text-white px-2 py-0.5 rounded">L42</span>
                <span class="text-[13px] font-semibold text-slate-900">Précomptes sur loyers (15 %)</span>
                <span class="ml-auto text-[10px] uppercase tracking-wider text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full font-semibold">Sourced from Odoo</span>
            </div>
            <div class="px-4 py-3 grid sm:grid-cols-12 gap-x-4 gap-y-2 text-[12px]">
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Source</div>
                <div class="sm:col-span-10 text-slate-800">Account <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">4426XX</code> · plain sum of <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">balance</code> for the month.</div>
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Formula</div>
                <div class="sm:col-span-10 text-slate-800">Principal = sum · CAC = Principal × 10% · Total = Principal + CAC. (Legacy model — same as L41/L43.)</div>
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Notes</div>
                <div class="sm:col-span-10 text-slate-600">If you want the L40 Total/1.10 split applied here too, say the word.</div>
            </div>
        </div>

        {{-- L43 --}}
        <div class="border border-slate-200 rounded-md overflow-hidden bg-white">
            <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center gap-3 flex-wrap">
                <span class="font-mono text-[11px] font-semibold tracking-wider bg-slate-900 text-white px-2 py-0.5 rounded">L43</span>
                <span class="text-[13px] font-semibold text-slate-900">Précomptes sur rémunérations et honoraires (5 %)</span>
                <span class="ml-auto text-[10px] uppercase tracking-wider text-amber-700 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded-full font-semibold">Manual entry</span>
            </div>
            <div class="px-4 py-3 grid sm:grid-cols-12 gap-x-4 gap-y-2 text-[12px]">
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Source</div>
                <div class="sm:col-span-10 text-slate-800">No Odoo source — defaults to 0.</div>
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Formula</div>
                <div class="sm:col-span-10 text-slate-800">Principal = entered value · CAC = Principal × 10% · Total = Principal + CAC.</div>
            </div>
        </div>

        {{-- L44 --}}
        <div class="border-2 border-slate-300 rounded-md overflow-hidden bg-white">
            <div class="px-4 py-3 bg-slate-100 border-b border-slate-200 flex items-center gap-3 flex-wrap">
                <span class="font-mono text-[11px] font-semibold tracking-wider bg-slate-900 text-white px-2 py-0.5 rounded">L44</span>
                <span class="text-[13px] font-semibold text-slate-900">TOTAL — sum of L40 to L43</span>
                <span class="ml-auto text-[10px] uppercase tracking-wider text-slate-700 bg-slate-200 border border-slate-300 px-2 py-0.5 rounded-full font-semibold">Aggregate row</span>
            </div>
            <div class="px-4 py-3 grid sm:grid-cols-12 gap-x-4 gap-y-2 text-[12px]">
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Formula</div>
                <div class="sm:col-span-10 text-slate-800">
                    <div class="font-mono text-[11.5px] bg-slate-50 border border-slate-200 rounded p-2 leading-relaxed">
                        Principal = L40.Principal + L41.Principal + L42.Principal + L43.Principal<br>
                        CAC       = L40.CAC + L41.CAC + L42.CAC + L43.CAC<br>
                        Total     = L40.Total + L41.Total + L42.Total + L43.Total
                    </div>
                </div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Section header</div>
                <div class="sm:col-span-10 text-slate-800">§7 collapsed header = L44.Total. Recap row "AC / IR + Précomptes (= L44)" also reads L44.Total.</div>
            </div>
        </div>

    </div>

    <div class="mt-5 pt-4 border-t border-slate-200 text-[11px] text-slate-500">
        <h3 class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1.5">Where the code lives</h3>
        <code class="font-mono bg-slate-50 border border-slate-200 px-1 rounded">app/Services/Tax/MonthlyTaxReturnService.php</code> · <code>autoExtract()</code> emits L40/L42 · <code>buildSections()</code> §7 applies the L40 split + L44 aggregation.
    </div>
</section>

{{-- ────────────────────────────── Tax Return · §8 IR à déduire ──── --}}
<section id="tax-return-s8" class="panel p-6 mb-5 scroll-mt-6">
    <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <div>
            <h2 class="text-base font-bold text-slate-900">Tax Return — Section 8 · Acomptes et précomptes à déduire</h2>
            <p class="text-[11px] text-slate-500 mt-0.5">Retentions effectuées par d'autres · claimable as a credit on the entity's IR</p>
        </div>
        <span class="text-[10px] uppercase tracking-wider text-slate-400 font-medium font-mono">/monthly-tax-return</span>
    </div>

    <div class="bg-slate-50 border border-slate-200 rounded-md p-4 mb-5 text-[12.5px] text-slate-700 leading-relaxed">
        <h3 class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-2">Section context</h3>
        <p>
            §8 captures retentions <em>made on the entity</em> by its customers / partners — credits the entity can deduct from its own IR liability. The deductible total feeds §9 L52 as the "déductions à opérer" against the monthly IR acompte. Form columns: <strong>Principal · CAC · Pénalités · Total</strong>. Posted-only, declaration-month window.
        </p>
        <p class="mt-2 text-[11.5px] text-slate-600">
            <strong>Note on the recap:</strong> §8 is intentionally excluded from <code class="text-[10.5px] bg-white px-1 rounded">total_a_payer</code> — the deductible amount is already credited via §9 L52, so adding it again would double-count. The section header total (L49) is shown for visibility only.
        </p>
    </div>

    <div class="space-y-3">

        {{-- L45 --}}
        <div class="border border-slate-200 rounded-md overflow-hidden bg-white">
            <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center gap-3 flex-wrap">
                <span class="font-mono text-[11px] font-semibold tracking-wider bg-slate-900 text-white px-2 py-0.5 rounded">L45</span>
                <span class="text-[13px] font-semibold text-slate-900">Acompte sur CA retenu à la source</span>
                <span class="ml-auto text-[10px] uppercase tracking-wider text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full font-semibold">Sourced from Odoo</span>
            </div>
            <div class="px-4 py-3 grid sm:grid-cols-12 gap-x-4 gap-y-2 text-[12px]">
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Source</div>
                <div class="sm:col-span-10 text-slate-800">
                    Account <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">44711XX</code> · sum of <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">account.move.line.balance</code> for the declaration month.
                    Was previously the broader <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">4471</code> prefix; narrowed to <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">44711</code> per user spec to keep the retention-side credits cleanly separate from §7's <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">44712</code> debits.
                </div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Filter</div>
                <div class="sm:col-span-10 text-slate-800">None — the whole 44711XX balance is taken. 44711 is dedicated to "Acompte sur CA retenu à la source — à déduire".</div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Formula</div>
                <div class="sm:col-span-10 text-slate-800 leading-relaxed">
                    Same split as §7 L40 and §12 L67:
                    <div class="mt-1.5 font-mono text-[11.5px] bg-slate-50 border border-slate-200 rounded p-2 leading-relaxed">
                        Total      = sum(44711XX) for month<br>
                        Principal  = Total ÷ 1.10<br>
                        CAC        = Principal × 10%<br>
                        Principal + CAC = Total
                    </div>
                </div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Notes</div>
                <div class="sm:col-span-10 text-slate-600">Previously used the legacy "CAC = Total × 10%, Principal = Total − CAC" split — corrected to the Principal-based CAC variant for consistency with L40 / L67.</div>
            </div>
        </div>

        {{-- L46 --}}
        <div class="border border-slate-200 rounded-md overflow-hidden bg-white">
            <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center gap-3 flex-wrap">
                <span class="font-mono text-[11px] font-semibold tracking-wider bg-slate-900 text-white px-2 py-0.5 rounded">L46</span>
                <span class="text-[13px] font-semibold text-slate-900">Précomptes sur achats (0,5 % / 1 % / 3 % / 5 %)</span>
                <span class="ml-auto text-[10px] uppercase tracking-wider text-amber-700 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded-full font-semibold">Manual entry</span>
            </div>
            <div class="px-4 py-3 grid sm:grid-cols-12 gap-x-4 gap-y-2 text-[12px]">
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Source</div>
                <div class="sm:col-span-10 text-slate-800">No Odoo source — defaults to 0.</div>
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Notes</div>
                <div class="sm:col-span-10 text-slate-600">Tell me which account to source from and which split model to apply.</div>
            </div>
        </div>

        {{-- L47 --}}
        <div class="border border-slate-200 rounded-md overflow-hidden bg-white">
            <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center gap-3 flex-wrap">
                <span class="font-mono text-[11px] font-semibold tracking-wider bg-slate-900 text-white px-2 py-0.5 rounded">L47</span>
                <span class="text-[13px] font-semibold text-slate-900">Précomptes 15 % sur loyers (déductible sur IR)</span>
                <span class="ml-auto text-[10px] uppercase tracking-wider text-amber-700 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded-full font-semibold">Manual entry</span>
            </div>
            <div class="px-4 py-3 grid sm:grid-cols-12 gap-x-4 gap-y-2 text-[12px]">
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Source</div>
                <div class="sm:col-span-10 text-slate-800">No Odoo source — defaults to 0.</div>
            </div>
        </div>

        {{-- L48 --}}
        <div class="border border-slate-200 rounded-md overflow-hidden bg-white">
            <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center gap-3 flex-wrap">
                <span class="font-mono text-[11px] font-semibold tracking-wider bg-slate-900 text-white px-2 py-0.5 rounded">L48</span>
                <span class="text-[13px] font-semibold text-slate-900">Précomptes sur rémunérations et honoraires (5 %)</span>
                <span class="ml-auto text-[10px] uppercase tracking-wider text-amber-700 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded-full font-semibold">Manual entry</span>
            </div>
            <div class="px-4 py-3 grid sm:grid-cols-12 gap-x-4 gap-y-2 text-[12px]">
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Source</div>
                <div class="sm:col-span-10 text-slate-800">No Odoo source — defaults to 0.</div>
            </div>
        </div>

        {{-- L49 --}}
        <div class="border-2 border-slate-300 rounded-md overflow-hidden bg-white">
            <div class="px-4 py-3 bg-slate-100 border-b border-slate-200 flex items-center gap-3 flex-wrap">
                <span class="font-mono text-[11px] font-semibold tracking-wider bg-slate-900 text-white px-2 py-0.5 rounded">L49</span>
                <span class="text-[13px] font-semibold text-slate-900">TOTAL — sum of L45 to L48</span>
                <span class="ml-auto text-[10px] uppercase tracking-wider text-slate-700 bg-slate-200 border border-slate-300 px-2 py-0.5 rounded-full font-semibold">Aggregate row</span>
            </div>
            <div class="px-4 py-3 grid sm:grid-cols-12 gap-x-4 gap-y-2 text-[12px]">
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Formula</div>
                <div class="sm:col-span-10 text-slate-800">
                    <div class="font-mono text-[11.5px] bg-slate-50 border border-slate-200 rounded p-2 leading-relaxed">
                        Principal = L45.Principal + L46 + L47 + L48<br>
                        CAC       = L45.CAC<br>
                        Total     = L45.Total + L46 + L47 + L48
                    </div>
                </div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Downstream</div>
                <div class="sm:col-span-10 text-slate-800">
                    §9 L52 ("Déductions à opérer") reads L49 column-wise. So the L49 figures flow into the IR liquidation as a credit against the monthly acompte.
                </div>
            </div>
        </div>

    </div>

    <div class="mt-5 pt-4 border-t border-slate-200 text-[11px] text-slate-500">
        <h3 class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1.5">Where the code lives</h3>
        <code class="font-mono bg-slate-50 border border-slate-200 px-1 rounded">app/Services/Tax/MonthlyTaxReturnService.php</code> · <code>autoExtract()</code> emits L45 (44711XX) · <code>buildSections()</code> §8 applies the L45 split.
    </div>
</section>

{{-- ────────────────────────────────── Tax Return · §9 IR Liquidation ──── --}}
<section id="tax-return-s9" class="panel p-6 mb-5 scroll-mt-6">
    <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <div>
            <h2 class="text-base font-bold text-slate-900">Tax Return — Section 9 · Liquidation des Acomptes Impôts sur le Revenu</h2>
            <p class="text-[11px] text-slate-500 mt-0.5">Monthly IR acompte computation · uses §2 L15 + §8 L49 + prior-month L55</p>
        </div>
        <span class="text-[10px] uppercase tracking-wider text-slate-400 font-medium font-mono">/monthly-tax-return</span>
    </div>

    <div class="bg-slate-50 border border-slate-200 rounded-md p-4 mb-5 text-[12.5px] text-slate-700 leading-relaxed">
        <h3 class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-2">Section context</h3>
        <p>
            §9 liquidates the monthly IR acompte. The base (L50) is 2 % of the §2 CA Global HT (L15). Adds the rental acompte (L51), subtracts §8 deductible retentions (L52 = L49) and any prior-month credit carry (L53). The result (L54) is either the amount due for the month or a credit to carry forward (L55).
        </p>
        <p class="mt-2 text-[11.5px] text-slate-600">
            <strong>Column-wise propagation:</strong> L52, L53, L54 and L55 all propagate Principal, CAC, Pénalités and Total <em>independently</em> through the (L50 + L51) − (L52 + L53) formula. The displayed row for L52 mirrors L49 column-by-column; for L53 the prior-month L55 column-by-column.
        </p>
    </div>

    <div class="space-y-3">

        {{-- L50 --}}
        <div class="border border-slate-200 rounded-md overflow-hidden bg-white">
            <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center gap-3 flex-wrap">
                <span class="font-mono text-[11px] font-semibold tracking-wider bg-slate-900 text-white px-2 py-0.5 rounded">L50</span>
                <span class="text-[13px] font-semibold text-slate-900">Acompte sur CA déclaré</span>
                <span class="ml-auto text-[10px] uppercase tracking-wider text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full font-semibold">Derived</span>
            </div>
            <div class="px-4 py-3 grid sm:grid-cols-12 gap-x-4 gap-y-2 text-[12px]">
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Source</div>
                <div class="sm:col-span-10 text-slate-800">§2 L15 (CA Global HT) — not from Odoo directly.</div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Formula</div>
                <div class="sm:col-span-10 text-slate-800">
                    <div class="font-mono text-[11.5px] bg-slate-50 border border-slate-200 rounded p-2 leading-relaxed">
                        Base       = L15<br>
                        Taux       = 2%<br>
                        Principal  = L15 × 2%<br>
                        CAC        = Principal × 10%<br>
                        Total      = Principal + CAC
                    </div>
                </div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Notes</div>
                <div class="sm:col-span-10 text-slate-600">Uses the "Principal + 10% on top" CAC variant (legacy model). 2% is the régime du réel rate; régime simplifié uses 5% — not currently parameterised.</div>
            </div>
        </div>

        {{-- L51 --}}
        <div class="border border-slate-200 rounded-md overflow-hidden bg-white">
            <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center gap-3 flex-wrap">
                <span class="font-mono text-[11px] font-semibold tracking-wider bg-slate-900 text-white px-2 py-0.5 rounded">L51</span>
                <span class="text-[13px] font-semibold text-slate-900">Acompte de 15 % sur loyers perçus</span>
                <span class="ml-auto text-[10px] uppercase tracking-wider text-amber-700 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded-full font-semibold">Manual entry</span>
            </div>
            <div class="px-4 py-3 grid sm:grid-cols-12 gap-x-4 gap-y-2 text-[12px]">
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Source</div>
                <div class="sm:col-span-10 text-slate-800">No Odoo source — Base defaults to 0 pending user direction.</div>
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Formula</div>
                <div class="sm:col-span-10 text-slate-800">Principal = Base × 15% · CAC = Principal × 10% · Total = Principal + CAC.</div>
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Notes</div>
                <div class="sm:col-span-10 text-slate-600">Tell me which account hosts rental income (typically class 706 sub-account) and I'll auto-source it.</div>
            </div>
        </div>

        {{-- L52 --}}
        <div class="border border-slate-200 rounded-md overflow-hidden bg-white">
            <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center gap-3 flex-wrap">
                <span class="font-mono text-[11px] font-semibold tracking-wider bg-slate-900 text-white px-2 py-0.5 rounded">L52</span>
                <span class="text-[13px] font-semibold text-slate-900">Déductions à opérer = L49</span>
                <span class="ml-auto text-[10px] uppercase tracking-wider text-indigo-700 bg-indigo-50 border border-indigo-200 px-2 py-0.5 rounded-full font-semibold">Reference line</span>
            </div>
            <div class="px-4 py-3 grid sm:grid-cols-12 gap-x-4 gap-y-2 text-[12px]">
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Source</div>
                <div class="sm:col-span-10 text-slate-800">§8 L49 — the TOTAL row of "Acomptes et précomptes à déduire" for the same month.</div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Carry-through</div>
                <div class="sm:col-span-10 text-slate-800">
                    <div class="font-mono text-[11.5px] bg-slate-50 border border-slate-200 rounded p-2 leading-relaxed">
                        L52.Principal = L49.Principal<br>
                        L52.CAC       = L49.CAC<br>
                        L52.Total     = L49.Total
                    </div>
                    All three columns are carried forward — see the cross-cutting <em>Reference-line carry-through</em> rule at the top of this page.
                </div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Notes</div>
                <div class="sm:col-span-10 text-slate-600">Was previously dropping the CAC column when rendering — fixed 2026-05-14 (PR #145). Now matches L49 column-by-column.</div>
            </div>
        </div>

        {{-- L53 --}}
        <div class="border border-slate-200 rounded-md overflow-hidden bg-white">
            <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center gap-3 flex-wrap">
                <span class="font-mono text-[11px] font-semibold tracking-wider bg-slate-900 text-white px-2 py-0.5 rounded">L53</span>
                <span class="text-[13px] font-semibold text-slate-900">Crédit antérieur = L55 déclaration précédente</span>
                <span class="ml-auto text-[10px] uppercase tracking-wider text-indigo-700 bg-indigo-50 border border-indigo-200 px-2 py-0.5 rounded-full font-semibold">Reference line</span>
            </div>
            <div class="px-4 py-3 grid sm:grid-cols-12 gap-x-4 gap-y-2 text-[12px]">
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Source</div>
                <div class="sm:col-span-10 text-slate-800">L55 of the <em>previous</em> month's declaration. Carries the prior-month credit forward to offset this month's acompte.</div>
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Carry-through</div>
                <div class="sm:col-span-10 text-slate-800">Principal / CAC / Total — all three columns. Currently 0 across the board (prior-month carry not yet implemented); the row still passes explicit zeros so the column contract is consistent.</div>
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Notes</div>
                <div class="sm:col-span-10 text-slate-600">Implementation backlog: pull last month's L55 from session storage or recompute. Tell me when you want this lit up.</div>
            </div>
        </div>

        {{-- L54 --}}
        <div class="border border-slate-200 rounded-md overflow-hidden bg-white">
            <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center gap-3 flex-wrap">
                <span class="font-mono text-[11px] font-semibold tracking-wider bg-slate-900 text-white px-2 py-0.5 rounded">L54</span>
                <span class="text-[13px] font-semibold text-slate-900">Acompte à payer = (L50 + L51) − (L52 + L53)</span>
                <span class="ml-auto text-[10px] uppercase tracking-wider text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full font-semibold">Derived</span>
            </div>
            <div class="px-4 py-3 grid sm:grid-cols-12 gap-x-4 gap-y-2 text-[12px]">
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Formula</div>
                <div class="sm:col-span-10 text-slate-800">
                    <div class="font-mono text-[11.5px] bg-slate-50 border border-slate-200 rounded p-2 leading-relaxed">
                        Principal = (L50.P + L51.P) − (L52.P + L53.P)<br>
                        CAC       = (L50.C + L51.C) − (L52.C + L53.C)<br>
                        Total     = (L50.T + L51.T) − (L52.T + L53.T)
                    </div>
                    Each column propagates independently. The section header total = max(L54.Total, 0) — only the net due is owed; if L54 is negative it becomes L55 (credit to carry).
                </div>
            </div>
        </div>

        {{-- L55 --}}
        <div class="border border-slate-200 rounded-md overflow-hidden bg-white">
            <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center gap-3 flex-wrap">
                <span class="font-mono text-[11px] font-semibold tracking-wider bg-slate-900 text-white px-2 py-0.5 rounded">L55</span>
                <span class="text-[13px] font-semibold text-slate-900">Crédit d'impôt à reporter (solde négatif L54)</span>
                <span class="ml-auto text-[10px] uppercase tracking-wider text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full font-semibold">Derived</span>
            </div>
            <div class="px-4 py-3 grid sm:grid-cols-12 gap-x-4 gap-y-2 text-[12px]">
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Formula</div>
                <div class="sm:col-span-10 text-slate-800">
                    <div class="font-mono text-[11.5px] bg-slate-50 border border-slate-200 rounded p-2 leading-relaxed">
                        If L54.Total &lt; 0:<br>
                        &nbsp;&nbsp;Principal = |L54.Principal|<br>
                        &nbsp;&nbsp;CAC       = |L54.CAC|<br>
                        &nbsp;&nbsp;Total     = |L54.Total|<br>
                        Otherwise all three = 0.
                    </div>
                </div>
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Notes</div>
                <div class="sm:col-span-10 text-slate-600">L55 of this month becomes L53 of the next month (carry-forward — backlog).</div>
            </div>
        </div>

    </div>

    <div class="mt-5 pt-4 border-t border-slate-200 text-[11px] text-slate-500">
        <h3 class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1.5">Where the code lives</h3>
        <code class="font-mono bg-slate-50 border border-slate-200 px-1 rounded">app/Services/Tax/MonthlyTaxReturnService.php</code> · <code>buildSections()</code> §9 builds L50–L55 with column-wise propagation. Recap row <em>"Acompte à payer (= L54)"</em> reads L54.Total via <code>buildRecap()</code>'s <code>$find('ir_liq', 'L54')</code>.
    </div>
</section>

{{-- ──────────────────────────────────── Tax Return · §12 RTS ──── --}}
<section id="tax-return-s12" class="panel p-6 mb-5 scroll-mt-6">
    <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <div>
            <h2 class="text-base font-bold text-slate-900">Tax Return — Section 12 · Impôts retenus sur salaires (RTS)</h2>
            <p class="text-[11px] text-slate-500 mt-0.5">Charges sociales et taxes salariales · Cameroon DGI monthly tax return</p>
        </div>
        <span class="text-[10px] uppercase tracking-wider text-slate-400 font-medium font-mono">/monthly-tax-return</span>
    </div>

    {{-- Section-level context --}}
    <div class="bg-slate-50 border border-slate-200 rounded-md p-4 mb-5 text-[12.5px] text-slate-700 leading-relaxed">
        <h3 class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-2">Section context</h3>
        <p>
            §12 captures every retention the employer applied to staff remunerations during the declaration month — IRPP on salaries, the two CFC contributions (employee + employer), FNE, the audiovisual royalty and the local development tax. The form displays four columns: <strong>Principal</strong>, <strong>CAC</strong>, <strong>Pénalités</strong>, <strong>Total</strong>. Base and Taux columns are not used in §12 (the rate is implicit in upstream payroll calculations).
        </p>
        <p class="mt-2">
            <strong>Declaration period:</strong> month-of-declaration window — first to last calendar day of the month (e.g. May 2026 → 2026-05-01 to 2026-05-31). Posted entries only (<code class="text-[10.5px] bg-white px-1 rounded">account.move.line.parent_state = posted</code>).
        </p>
    </div>

    {{-- Line-by-line documentation. Card per line — Source · Filter · Formula · Notes. --}}
    <div class="space-y-3">

        {{-- L67 ----------------------------------------------------- --}}
        <div class="border border-slate-200 rounded-md overflow-hidden bg-white">
            <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center gap-3 flex-wrap">
                <span class="font-mono text-[11px] font-semibold tracking-wider bg-slate-900 text-white px-2 py-0.5 rounded">L67</span>
                <span class="text-[13px] font-semibold text-slate-900">IRPP sur traitements et salaires</span>
                <span class="ml-auto text-[10px] uppercase tracking-wider text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full font-semibold">Sourced from Odoo</span>
            </div>
            <div class="px-4 py-3 grid sm:grid-cols-12 gap-x-4 gap-y-2 text-[12px]">
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Source</div>
                <div class="sm:col-span-10 text-slate-800">
                    Account <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">4472XX</code> · sum of <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">account.move.line.balance</code> for the declaration month.
                </div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Filter</div>
                <div class="sm:col-span-10 text-slate-800 leading-relaxed">
                    Line description (<code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">name</code> field) must contain — case-insensitive, accent-folded — any of:
                    <ul class="mt-1.5 pl-4 space-y-0.5 list-disc">
                        <li><code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">IRPP</code></li>
                        <li><code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">Impôt sur le Revenu des Personnes Physiques</code></li>
                        <li>or any close variant (<em>"impot sur le revenu", "revenu des personnes physiques", "traitements et salaires"</em>)</li>
                    </ul>
                    First-match-wins: a line tagged "IRPP + FNE adjustment" goes to IRPP (this line), not FNE.
                </div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Formula</div>
                <div class="sm:col-span-10 text-slate-800 leading-relaxed">
                    The sourced amount is the <strong>Total</strong> (what was withheld). Principal and CAC are then derived so they sum back to the Total:
                    <div class="mt-1.5 font-mono text-[11.5px] bg-slate-50 border border-slate-200 rounded p-2 leading-relaxed">
                        Total      = sum(4472XX with IRPP-keyword) for month<br>
                        Principal  = Total ÷ 1.10<br>
                        CAC        = Principal × 10%<br>
                        Principal + CAC = Total  <span class="text-slate-500">(modulo float rounding ≤ 1 XAF)</span>
                    </div>
                    <p class="mt-2 text-[11.5px] text-slate-600">
                        Example: if 4472XX shows 5,000,000 XAF tagged as IRPP for the month, the form shows
                        <strong>Principal 4,545,454</strong> · <strong>CAC 454,545</strong> · <strong>Total 5,000,000</strong>.
                    </p>
                </div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Notes</div>
                <div class="sm:col-span-10 text-slate-800 leading-relaxed">
                    Account 4472XX is shared with L70 (FNE), L71 (RAV) and L72 (TDL) — the description keyword is the only discriminator. The bucketing makes a single Odoo round-trip and splits in PHP. Unmatched 4472XX lines (no recognised keyword) are dropped from §12 entirely; flag them if you notice §12 reads low.
                </div>
            </div>
        </div>

        {{-- L68 ----------------------------------------------------- --}}
        <div class="border border-slate-200 rounded-md overflow-hidden bg-white">
            <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center gap-3 flex-wrap">
                <span class="font-mono text-[11px] font-semibold tracking-wider bg-slate-900 text-white px-2 py-0.5 rounded">L68</span>
                <span class="text-[13px] font-semibold text-slate-900">Crédit Foncier du Cameroun · Part Salariale</span>
                <span class="ml-auto text-[10px] uppercase tracking-wider text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full font-semibold">Sourced from Odoo</span>
            </div>
            <div class="px-4 py-3 grid sm:grid-cols-12 gap-x-4 gap-y-2 text-[12px]">
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Source</div>
                <div class="sm:col-span-10 text-slate-800">
                    Account <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">4474XX</code> · sum of <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">account.move.line.balance</code> for the declaration month.
                </div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Filter</div>
                <div class="sm:col-span-10 text-slate-800">
                    Line description must contain — case-insensitive, accent-folded — any of:
                    <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">CFC</code>,
                    <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">Crédit Foncier</code>,
                    <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">Contribution au Crédit Foncier</code>.
                    4474XX is dedicated to CFC in SYSCOHADA so this is usually a no-op safety filter.
                </div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Formula</div>
                <div class="sm:col-span-10 text-slate-800">Principal = filtered sum. No CAC. Total = Principal.</div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Notes</div>
                <div class="sm:col-span-10 text-slate-600">If the Principal/CAC split should be applied here too (same logic as L67), tell me and I'll mirror it.</div>
            </div>
        </div>

        {{-- L69 ----------------------------------------------------- --}}
        <div class="border border-slate-200 rounded-md overflow-hidden bg-white">
            <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center gap-3 flex-wrap">
                <span class="font-mono text-[11px] font-semibold tracking-wider bg-slate-900 text-white px-2 py-0.5 rounded">L69</span>
                <span class="text-[13px] font-semibold text-slate-900">Crédit Foncier du Cameroun · Part Patronale</span>
                <span class="ml-auto text-[10px] uppercase tracking-wider text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full font-semibold">Sourced from Odoo</span>
            </div>
            <div class="px-4 py-3 grid sm:grid-cols-12 gap-x-4 gap-y-2 text-[12px]">
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Source</div>
                <div class="sm:col-span-10 text-slate-800">
                    Account <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">6413XX</code> · sum of <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">account.move.line.balance</code> for the declaration month. No description filter — the whole 6413XX balance is taken.
                </div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Filter</div>
                <div class="sm:col-span-10 text-slate-800">None — 6413 in SYSCOHADA is "Charges sociales sur rémunérations" and is dedicated to the patronal CFC posting.</div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Formula</div>
                <div class="sm:col-span-10 text-slate-800">Principal = full account balance. No CAC. Total = Principal.</div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Notes</div>
                <div class="sm:col-span-10 text-slate-600">If 6413XX gets co-used for unrelated charges on a tenant, narrow the prefix (e.g. 64131, 64132) or add a description filter — tell me.</div>
            </div>
        </div>

        {{-- L70 ----------------------------------------------------- --}}
        <div class="border border-slate-200 rounded-md overflow-hidden bg-white">
            <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center gap-3 flex-wrap">
                <span class="font-mono text-[11px] font-semibold tracking-wider bg-slate-900 text-white px-2 py-0.5 rounded">L70</span>
                <span class="text-[13px] font-semibold text-slate-900">Fonds National de l'Emploi (FNE)</span>
                <span class="ml-auto text-[10px] uppercase tracking-wider text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full font-semibold">Sourced from Odoo</span>
            </div>
            <div class="px-4 py-3 grid sm:grid-cols-12 gap-x-4 gap-y-2 text-[12px]">
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Source</div>
                <div class="sm:col-span-10 text-slate-800">
                    Account <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">4472XX</code> · same pool as L67/L71/L72 · sum of <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">balance</code> for the declaration month.
                </div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Filter</div>
                <div class="sm:col-span-10 text-slate-800">
                    Line description must contain — case-insensitive, accent-folded — any of:
                    <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">FNE</code>,
                    <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">Fonds National d'Emploi</code>.
                </div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Formula</div>
                <div class="sm:col-span-10 text-slate-800">Principal = filtered sum. No CAC. Total = Principal.</div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Notes</div>
                <div class="sm:col-span-10 text-slate-600">Shares 4472XX with L67/L71/L72 — bucketing happens in a single PHP pass after one Odoo round-trip.</div>
            </div>
        </div>

        {{-- L71 ----------------------------------------------------- --}}
        <div class="border border-slate-200 rounded-md overflow-hidden bg-white">
            <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center gap-3 flex-wrap">
                <span class="font-mono text-[11px] font-semibold tracking-wider bg-slate-900 text-white px-2 py-0.5 rounded">L71</span>
                <span class="text-[13px] font-semibold text-slate-900">Redevance Audiovisuelle (RAV)</span>
                <span class="ml-auto text-[10px] uppercase tracking-wider text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full font-semibold">Sourced from Odoo</span>
            </div>
            <div class="px-4 py-3 grid sm:grid-cols-12 gap-x-4 gap-y-2 text-[12px]">
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Source</div>
                <div class="sm:col-span-10 text-slate-800">
                    Account <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">4472XX</code> · same pool as L67/L70/L72.
                </div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Filter</div>
                <div class="sm:col-span-10 text-slate-800">
                    Line description must contain — case-insensitive, accent-folded — any of:
                    <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">RAV</code>,
                    <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">Redevance Audio Visuel</code>,
                    <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">Redevance Audiovisuelle</code>.
                </div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Formula</div>
                <div class="sm:col-span-10 text-slate-800">Principal = filtered sum. No CAC. Total = Principal.</div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Notes</div>
                <div class="sm:col-span-10 text-slate-600">Typically a small fixed amount per employee — if the live figure looks too high or low, check the line descriptions in 4472XX.</div>
            </div>
        </div>

        {{-- L72 ----------------------------------------------------- --}}
        <div class="border border-slate-200 rounded-md overflow-hidden bg-white">
            <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center gap-3 flex-wrap">
                <span class="font-mono text-[11px] font-semibold tracking-wider bg-slate-900 text-white px-2 py-0.5 rounded">L72</span>
                <span class="text-[13px] font-semibold text-slate-900">Taxe de Développement Local (TDL)</span>
                <span class="ml-auto text-[10px] uppercase tracking-wider text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full font-semibold">Sourced from Odoo</span>
            </div>
            <div class="px-4 py-3 grid sm:grid-cols-12 gap-x-4 gap-y-2 text-[12px]">
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Source</div>
                <div class="sm:col-span-10 text-slate-800">
                    Account <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">4472XX</code> · same pool as L67/L70/L71.
                </div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Filter</div>
                <div class="sm:col-span-10 text-slate-800">
                    Line description must contain — case-insensitive, accent-folded — any of:
                    <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">TDL</code>,
                    <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">Taxe de Développement</code>,
                    <code class="font-mono text-[11px] bg-slate-100 px-1.5 rounded">Développement Local</code>.
                </div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Formula</div>
                <div class="sm:col-span-10 text-slate-800">Principal = filtered sum. No CAC. Total = Principal.</div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Notes</div>
                <div class="sm:col-span-10 text-slate-600">Communal tax with brackets — check that the underlying payroll computation in Odoo applies the right band for each employee.</div>
            </div>
        </div>

        {{-- L73 ----------------------------------------------------- --}}
        <div class="border-2 border-slate-300 rounded-md overflow-hidden bg-white">
            <div class="px-4 py-3 bg-slate-100 border-b border-slate-200 flex items-center gap-3 flex-wrap">
                <span class="font-mono text-[11px] font-semibold tracking-wider bg-slate-900 text-white px-2 py-0.5 rounded">L73</span>
                <span class="text-[13px] font-semibold text-slate-900">TOTAL — sum of L67 to L72</span>
                <span class="ml-auto text-[10px] uppercase tracking-wider text-slate-700 bg-slate-200 border border-slate-300 px-2 py-0.5 rounded-full font-semibold">Aggregate row</span>
            </div>
            <div class="px-4 py-3 grid sm:grid-cols-12 gap-x-4 gap-y-2 text-[12px]">
                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Source</div>
                <div class="sm:col-span-10 text-slate-800">No direct Odoo source — computed from L67 through L72.</div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Formula</div>
                <div class="sm:col-span-10 text-slate-800">
                    <div class="font-mono text-[11.5px] bg-slate-50 border border-slate-200 rounded p-2 leading-relaxed">
                        Principal = L67.Principal + L68 + L69 + L70 + L71 + L72<br>
                        CAC       = L67.CAC                       <span class="text-slate-500">(only L67 carries a CAC today)</span><br>
                        Total     = L67.Total + L68 + L69 + L70 + L71 + L72
                    </div>
                </div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Section header</div>
                <div class="sm:col-span-10 text-slate-800">The §12 collapsed header total in the tax-return page = L73.Total (above). Recap row "RTS / salaires (= L73)" also reads L73.Total.</div>

                <div class="sm:col-span-2 text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Notes</div>
                <div class="sm:col-span-10 text-slate-600">If L68/L70/L71/L72 ever pick up CAC, the CAC sum here will need to widen to include them. Currently a no-op since only L67 has one.</div>
            </div>
        </div>

    </div>

    {{-- File pointers --}}
    <div class="mt-5 pt-4 border-t border-slate-200 text-[11px] text-slate-500">
        <h3 class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1.5">Where the code lives</h3>
        <ul class="space-y-0.5">
            <li>Service: <code class="font-mono bg-slate-50 border border-slate-200 px-1 rounded">app/Services/Tax/MonthlyTaxReturnService.php</code> — see <code>autoExtract()</code> for 4472/4474/6413 bucketing, <code>buildSections()</code> §12 for the L67 Principal/CAC split, <code>bucketLinesByDescription()</code> for the keyword filter logic.</li>
            <li>Controller: <code class="font-mono bg-slate-50 border border-slate-200 px-1 rounded">app/Http/Controllers/Tax/MonthlyTaxReturnController.php</code></li>
            <li>View: <code class="font-mono bg-slate-50 border border-slate-200 px-1 rounded">resources/views/tax/monthly-tax-return.blade.php</code></li>
        </ul>
    </div>
</section>

{{-- Future-section placeholder --}}
<div class="panel p-4 border-l-4 border-slate-300 text-[12px] text-slate-600 leading-relaxed">
    <strong>Next:</strong> §7, §8, §9, §12 + cross-cutting rules are documented above. Pending: §0 (TSR), §1–§6 (TVA chain), §10 (IRCM), §11 (IRNCS), §13 (Plus-values), §14 (Timbre aéroport). After tax return: BI modules (Dashboard / Revenue / Receivables / Treasury / etc.), then the Financial Reporting suite. Tell me which section to document next.
</div>

@endsection
