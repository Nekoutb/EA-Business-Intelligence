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
            <em>Status:</em> Currently documenting <strong>Tax Return sections §7 (Acomptes à reverser), §8 (Acomptes à déduire) and §12 (Impôts retenus sur salaires)</strong>. Other sections will be added on request.
        </div>
    </div>
</div>

{{-- Quick anchors --}}
<div class="panel p-3 mb-5 flex gap-2 flex-wrap">
    <span class="text-[10px] uppercase tracking-wider text-slate-500 font-medium px-2 py-1">Jump to</span>
    <a href="#tax-return-s7"  class="text-xs px-3 py-1 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-700 transition">Tax Return · §7 Acomptes à reverser</a>
    <a href="#tax-return-s8"  class="text-xs px-3 py-1 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-700 transition">Tax Return · §8 Acomptes à déduire</a>
    <a href="#tax-return-s12" class="text-xs px-3 py-1 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-700 transition">Tax Return · §12 RTS</a>
</div>

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
    <strong>Next:</strong> §7, §8, §12 are documented above. Pending: §0 (TSR), §1–§6 (TVA chain), §9 (IR liquidation), §10 (IRCM), §11 (IRNCS), §13 (Plus-values), §14 (Timbre aéroport). After tax return: BI modules (Dashboard / Revenue / Receivables / Treasury / etc.), then the Financial Reporting suite. Tell me which section to document next.
</div>

@endsection
