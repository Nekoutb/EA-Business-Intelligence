@extends('layouts.app')
@section('title', 'Income Tax Compliance')

@section('page-title', 'Income Tax Compliance')
@section('content')
    @if ($error)
        <div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-xl p-4 text-sm">
            <div class="font-medium mb-1">Could not load data from Odoo</div>
            <div>{{ $error }}</div>
        </div>
    @elseif ($analysis)
        @php
            $cur     = $analysis['currency'] ?? 'XAF';
            $hv      = $analysis['high_value_cash'] ?? [];
            $thresh  = (float) ($hv['threshold_xaf'] ?? 100000);
            $thRef   = (string)($hv['threshold_reference'] ?? 'LF current — Article 7-A CGI');
            $count   = (int)   ($hv['count'] ?? 0);
            $grpCnt  = (int)   ($hv['group_count'] ?? 0);
            $total   = (float) ($hv['total_amount'] ?? 0);
            $missing = (int)   ($hv['missing_doc_count'] ?? 0);
            $isPct   = (float) ($hv['is_rate_pct'] ?? 30.0);
            $cacPct  = (float) ($hv['cac_rate_pct'] ?? 10.0);
            $citPct  = (float) ($hv['cit_rate_pct'] ?? 33.0);
            $ircmBP  = (float) ($hv['ircm_base_pct'] ?? 15.0);
            $ircmPct = (float) ($hv['ircm_rate_pct'] ?? 16.5);
            $totPct  = (float) ($hv['total_rate_pct'] ?? 49.5);
            $citImp  = (float) ($hv['cit_impact'] ?? 0);
            $ircmImp = (float) ($hv['ircm_impact'] ?? 0);
            $totImp  = (float) ($hv['total_impact'] ?? 0);
            $year    = (int)   ($analysis['period']['year'] ?? date('Y'));
            $groups  = $hv['groups'] ?? [];
        @endphp

        <div class="space-y-4">
            {{-- ═══════════════════ Summary Box ═══════════════════ --}}
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">

                {{-- Title bar --}}
                <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 border-b border-slate-200 bg-gradient-to-r from-rose-50/60 to-white">
                    <div>
                        <div class="text-[10px] uppercase tracking-widest text-rose-700 font-semibold">A · Disallowed expenditures · YTD {{ $year }}</div>
                        <h2 class="text-lg font-semibold text-slate-900 mt-1">High-value cash expenditures</h2>
                        <p class="text-[11px] text-slate-500 mt-1">
                            Posted journals that credit account 57 (Caisse, SYSCOHADA) AND debit class 6 / 1 / 2 / 3 / 4,
                            with the SUM of 57-credits per journal above
                            <span class="font-medium text-slate-700 tabular-nums">{{ number_format($thresh, 0, '.', ',') }} {{ $cur }}</span>
                        </p>
                    </div>
                    <div class="flex flex-col items-end gap-1.5">
                        <span class="inline-flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-widest text-rose-700 bg-rose-100 border border-rose-300 rounded-full px-2.5 py-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                            Non-deductible · Articles 7-A, 17, 26, 91 CGI
                        </span>
                        @if ($count > 0)
                            <a href="{{ tenant_url('/income-tax-compliance/export') }}"
                               class="inline-flex items-center gap-1.5 text-[11px] font-medium text-brand-700 border border-brand-300 hover:border-brand-500 bg-white hover:bg-brand-50 rounded-md px-3 py-1.5 transition">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17V7a2 2 0 012-2h11l5 5v7a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                                Download XLSX
                            </a>
                        @endif
                    </div>
                </div>

                {{-- Headline metrics --}}
                <div class="grid grid-cols-1 md:grid-cols-3 divide-y md:divide-y-0 md:divide-x divide-slate-200">
                    <div class="p-5">
                        <p class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Number of journals flagged</p>
                        <p class="text-3xl font-bold text-slate-900 tabular-nums mt-2">{{ number_format($count) }}</p>
                        <p class="text-[11px] text-slate-500 mt-1">
                            Across <span class="tabular-nums">{{ number_format($grpCnt) }}</span> counterparty/date pair{{ $grpCnt === 1 ? '' : 's' }}
                            @if($missing > 0)
                                · <span class="text-rose-700">{{ $missing }} missing supporting doc{{ $missing === 1 ? '' : 's' }}</span>
                            @endif
                        </p>
                    </div>
                    <div class="p-5">
                        <p class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Total amount</p>
                        <p class="text-3xl font-bold text-slate-900 tabular-nums mt-2">
                            {{ number_format($total, 0, '.', ',') }} <span class="text-base font-semibold text-slate-500">{{ $cur }}</span>
                        </p>
                        <p class="text-[11px] text-slate-500 mt-1">Sum of qualifying credit amounts</p>
                    </div>
                    <div class="p-5 bg-rose-50/50">
                        <p class="text-[10px] uppercase tracking-widest text-rose-700 font-semibold">Tax exposure ({{ number_format($totPct, 1) }} %)</p>
                        <p class="text-3xl font-bold text-rose-900 tabular-nums mt-2">
                            {{ number_format($totImp, 0, '.', ',') }} <span class="text-base font-semibold text-rose-700">{{ $cur }}</span>
                        </p>
                        <p class="text-[11px] text-rose-700/80 mt-1">
                            <span class="tabular-nums">{{ number_format($citPct, 1) }} %</span> CIT
                            + <span class="tabular-nums">{{ number_format($ircmPct, 1) }} %</span> IRCM, stacked
                        </p>
                    </div>
                </div>

                {{-- Stacking disclosure (now embeds the Rule-reference dropdown) --}}
                <div class="border-t border-slate-200 bg-slate-50/60 px-5 py-4">
                    <div class="flex items-start gap-3">
                        <div class="text-amber-600 shrink-0 mt-0.5">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <div class="flex-1 text-sm text-slate-700 leading-relaxed">
                            <p class="font-medium text-slate-900 mb-2">Stacking assumption</p>
                            <p>
                                The 49.5 % exposure stacks <strong>{{ number_format($citPct, 1) }} % statutory income tax</strong>
                                ({{ number_format($isPct, 0) }} % IS + {{ number_format($cacPct, 0) }} % CAC, Article 17 CGI)
                                on the reintegration of the non-deductible charge,
                                <strong>plus {{ number_format($ircmPct, 1) }} % IRCM</strong>
                                ({{ number_format($ircmBP, 0) }} % + {{ number_format($cacPct, 0) }} % CAC, Article 91 CGI)
                                on the same amount treated as a hidden distribution under the doctrine of
                                <em>revenus réputés distribués</em> (Article 26 CGI).
                            </p>
                            <p class="mt-2 text-[12px] text-slate-600">
                                If the DGI does not pursue the dividend requalification, only the
                                {{ number_format($citPct, 1) }} % income-tax leg ({{ number_format($citImp, 0, '.', ',') }} {{ $cur }})
                                applies; the IRCM leg ({{ number_format($ircmImp, 0, '.', ',') }} {{ $cur }}) is then
                                contingent.
                            </p>

                            {{-- Component breakdown --}}
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-4 max-w-2xl">
                                <div class="bg-white border border-slate-200 rounded-lg p-3">
                                    <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Income tax — Article 17 CGI</div>
                                    <div class="text-base font-bold text-slate-900 tabular-nums mt-1">
                                        {{ number_format($citPct, 1) }} % <span class="text-[11px] font-normal text-slate-500">(IS + CAC)</span>
                                    </div>
                                    <div class="text-[12px] text-slate-700 tabular-nums mt-0.5">
                                        = {{ number_format($citImp, 0, '.', ',') }} {{ $cur }}
                                    </div>
                                </div>
                                <div class="bg-white border border-slate-200 rounded-lg p-3">
                                    <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">IRCM on hidden distribution — Articles 26, 91 CGI</div>
                                    <div class="text-base font-bold text-slate-900 tabular-nums mt-1">
                                        {{ number_format($ircmPct, 1) }} % <span class="text-[11px] font-normal text-slate-500">(IRCM + CAC)</span>
                                    </div>
                                    <div class="text-[12px] text-slate-700 tabular-nums mt-0.5">
                                        = {{ number_format($ircmImp, 0, '.', ',') }} {{ $cur }}
                                    </div>
                                </div>
                            </div>

                            {{-- Inline rule reference dropdown — now embedded inside the stacking panel --}}
                            <details class="mt-5 group">
                                <summary class="inline-flex items-center gap-2 cursor-pointer list-none text-[12px] font-semibold text-slate-900 hover:text-slate-700 select-none">
                                    <svg class="w-3.5 h-3.5 text-slate-500 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                    Rule reference — Articles 7-A, 17, 26, 91 CGI
                                    <span class="text-[11px] font-normal text-slate-500">· click to expand</span>
                                </summary>
                                <div class="mt-3 pl-5 border-l-2 border-slate-200 text-sm text-slate-700 leading-relaxed space-y-3">
                                    <p>
                                        <strong class="text-slate-900">Article 7-A CGI.</strong>
                                        Cash payments above the threshold set by the
                                        <em>Loi de Finances</em> in force are
                                        <strong>excluded from deductible charges</strong> for corporate
                                        income tax purposes. The current threshold is
                                        <span class="tabular-nums font-medium">{{ number_format($thresh, 0, '.', ',') }} {{ $cur }}</span>
                                        per transaction with the same counterparty
                                        <span class="text-[11px] text-slate-500">({{ $thRef }})</span>.
                                    </p>
                                    <p>
                                        <strong class="text-slate-900">Article 17 CGI.</strong>
                                        Statutory income tax rate of <span class="tabular-nums">33 %</span>
                                        ({{ number_format($isPct, 0) }} % IS + {{ number_format($cacPct, 0) }} % CAC)
                                        applies on reintegration of the non-deductible charge into taxable income.
                                    </p>
                                    <p>
                                        <strong class="text-slate-900">Articles 26 + 91 CGI.</strong>
                                        Beyond the income-tax reintegration, the DGI may additionally
                                        <strong>requalify the disallowed amount as a hidden distribution</strong>
                                        (<em>revenus réputés distribués</em>, Article 26 CGI). In that case,
                                        the same amount is taxed as a dividend at <span class="tabular-nums">16.5 %</span>
                                        IRCM ({{ number_format($ircmBP, 0) }} % + {{ number_format($cacPct, 0) }} % CAC, Article 91 CGI).
                                    </p>
                                    <p class="text-[12px] text-slate-600 bg-white border-l-2 border-slate-300 pl-3 py-2">
                                        Stacked exposure = <span class="tabular-nums">{{ number_format($citPct, 1) }} % + {{ number_format($ircmPct, 1) }} % = {{ number_format($totPct, 1) }} %</span>
                                        of the disallowed amount. The IRCM leg is contingent on whether the DGI pursues the requalification.
                                    </p>
                                </div>
                            </details>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Qualifying transactions list removed — full line-level detail is in the XLSX export --}}
            @if (empty($groups))
                <div class="bg-white border border-slate-200 rounded-xl p-6 text-center text-slate-500 text-sm">
                    No qualifying high-value cash payments in the YTD window.
                </div>
            @endif

            {{-- Methodology footer (rework 2026-05 — per-journal aggregation) --}}
            <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm text-[12px] text-slate-600 leading-relaxed">
                <strong class="text-slate-900">Methodology.</strong>
                Per-journal aggregation. Qualifying entries are posted
                <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">account.move</code>
                records that <strong>(a)</strong> have at least one line crediting an account whose
                <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">code</code>
                starts with <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">57</code>
                (Caisse, SYSCOHADA), AND <strong>(b)</strong> have at least one line debiting an account
                starting with <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">6</code> (charges)
                or <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">1</code>,
                <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">2</code>,
                <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">3</code>,
                <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">4</code>
                (balance-sheet items). The cash-paid figure per journal is the
                <strong>sum of credit</strong> on every 57-line of that journal. The journal qualifies
                when that sum exceeds
                <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">{{ number_format($thresh, 0, '.', ',') }}</code>.
                Class 52 (Banques) and 5XX cash transfers are intentionally excluded by the debit-side filter.
                Threshold reference: {{ $thRef }}.
                Stacking (49.5 %) assumes the DGI requalifies the disallowed amount in full as a hidden distribution.
                The XLSX export lists <strong>every debit and credit line</strong> of every qualifying journal,
                grouped by journal — so a tax inspector can read each entry without cross-referencing back to Odoo.
                "Missing" supporting doc indicates the originating
                <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">account.move</code>
                has zero <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">ir.attachment</code>
                records on file.
            </div>
        </div>

        {{-- ═══════════════════════════════════════════════════════════════════
             B. Donations and liberalities — deductibility test
           ═══════════════════════════════════════════════════════════════════ --}}
        @if (!empty($donations))
            @php
                $don         = $donations['donations'] ?? [];
                $donCur      = $donations['currency'] ?? $cur;
                $donYear     = (int) ($donations['period']['year'] ?? date('Y'));
                $bens        = $don['beneficiaries'] ?? [];
                $totalDon    = (float) ($don['total_donations'] ?? 0);
                $cat1Tot     = (float) ($don['cat1_total'] ?? 0);
                $cat2Tot     = (float) ($don['cat2_total'] ?? 0);
                $cat3Tot     = (float) ($don['cat3_total'] ?? 0);
                $unresTot    = (float) ($don['unresolved_total'] ?? 0);
                $caNet       = (float) ($don['ca_net_ytd']  ?? 0);
                $ceilingPct  = (float) ($don['ceiling_pct'] ?? 0.5);
                $ceilingXaf  = (float) ($don['ceiling_xaf'] ?? 0);
                $totDed      = (float) ($don['total_deductible']   ?? 0);
                $totRein     = (float) ($don['total_reintegrated'] ?? 0);
                $expStack    = (float) ($don['exposure_stacked']   ?? 0);
                $expTsr      = (float) ($don['exposure_tsr']       ?? 0);
                $expTotal    = (float) ($don['exposure_total']     ?? 0);
                $stackPct    = (float) ($don['stacked_rate_pct']   ?? 49.5);
                $tsrPct      = (float) ($don['tsr_rate_pct']       ?? 15.0);
                $unresolvedCount = collect($bens)->filter(fn ($b) => empty($b['category']))->count();
            @endphp

            <div class="space-y-4 mt-8">
                {{-- ─────── Section divider ─────── --}}
                <div class="flex items-center gap-3 mb-1">
                    <div class="h-px flex-1 bg-slate-200"></div>
                    <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Section B</div>
                    <div class="h-px flex-1 bg-slate-200"></div>
                </div>

                {{-- ─────── Summary Box ─────── --}}
                <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
                    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 border-b border-slate-200 bg-gradient-to-r from-amber-50/60 to-white">
                        <div>
                            <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold">B · Donations made (Dons + Mécenat) · YTD {{ $donYear }}</div>
                            <h2 class="text-lg font-semibold text-slate-900 mt-1">Donations made — deductibility test</h2>
                            <p class="text-[11px] text-slate-500 mt-1">
                                Posted debit balances on
                                <code class="text-[10.5px] bg-slate-100 px-1.5 py-0.5 rounded">6582XX</code> (Dons) +
                                <code class="text-[10.5px] bg-slate-100 px-1.5 py-0.5 rounded">6583XX</code> (Mécenat) ·
                                grouped by beneficiary
                            </p>
                            <div class="mt-2 inline-flex items-start gap-1.5 text-[10.5px] text-rose-700 bg-rose-50 border border-rose-200 rounded-md px-2 py-1">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mt-0.5 flex-shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3m0 4h.01M5 19h14a2 2 0 001.732-3L13.732 4a2 2 0 00-3.464 0L3.268 16A2 2 0 005 19z"/></svg>
                                <span>
                                    <b>Account scope corrected (May 2026).</b> Was previously reading 6713 / 6588 / 638 (currency-loss + misc-charge buckets). Re-export any reviews dated before this change.
                                </span>
                            </div>
                        </div>
                        <div class="flex flex-col items-end gap-1.5">
                            <span class="inline-flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-widest text-amber-700 bg-amber-100 border border-amber-300 rounded-full px-2.5 py-1">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                Articles 7, 17, 26, 91, 225 CGI
                            </span>
                            @if (!empty($bens))
                                <a href="{{ tenant_url('/income-tax-compliance/donations/export') }}"
                                   class="inline-flex items-center gap-1.5 text-[11px] font-medium text-brand-700 border border-brand-300 hover:border-brand-500 bg-white hover:bg-brand-50 rounded-md px-3 py-1.5 transition">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17V7a2 2 0 012-2h11l5 5v7a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                                    Download XLSX
                                </a>
                            @endif
                        </div>
                    </div>

                    {{-- Headline metrics row 1 --}}
                    <div class="grid grid-cols-2 md:grid-cols-4 divide-y md:divide-y-0 md:divide-x divide-slate-200">
                        <div class="p-4">
                            <p class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Total donations</p>
                            <p class="text-2xl font-bold text-slate-900 tabular-nums mt-1">{{ number_format($totalDon, 0, '.', ',') }}</p>
                            <p class="text-[10px] text-slate-500 mt-0.5">{{ $donCur }}</p>
                        </div>
                        <div class="p-4">
                            <p class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">CA net YTD</p>
                            <p class="text-2xl font-bold text-slate-900 tabular-nums mt-1">{{ number_format($caNet, 0, '.', ',') }}</p>
                            <p class="text-[10px] text-slate-500 mt-0.5">|Σ 70 ex 709| − |Σ 709|</p>
                        </div>
                        <div class="p-4 bg-amber-50/40">
                            <p class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold">Ceiling ({{ number_format($ceilingPct, 1) }} %)</p>
                            <p class="text-2xl font-bold text-amber-900 tabular-nums mt-1">{{ number_format($ceilingXaf, 0, '.', ',') }}</p>
                            <p class="text-[10px] text-amber-700/70 mt-0.5">Collective cap on non-Cat-1 spend</p>
                        </div>
                        <div class="p-4 bg-rose-50/50">
                            <p class="text-[10px] uppercase tracking-widest text-rose-700 font-semibold">Tax exposure</p>
                            <p class="text-2xl font-bold text-rose-900 tabular-nums mt-1">{{ number_format($expTotal, 0, '.', ',') }}</p>
                            <p class="text-[10px] text-rose-700/80 mt-0.5">{{ number_format($stackPct, 1) }} % stacked + TSR</p>
                        </div>
                    </div>

                    {{-- Headline metrics row 2 — category split --}}
                    <div class="grid grid-cols-2 md:grid-cols-4 border-t border-slate-200 divide-y md:divide-y-0 md:divide-x divide-slate-200">
                        <div class="p-4">
                            <p class="text-[10px] uppercase tracking-widest text-emerald-700 font-semibold">Cat 1 — Fully deductible</p>
                            <p class="text-lg font-bold text-emerald-900 tabular-nums mt-1">{{ number_format($cat1Tot, 0, '.', ',') }}</p>
                        </div>
                        <div class="p-4">
                            <p class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold">Cat 2 — Public-utility</p>
                            <p class="text-lg font-bold text-amber-900 tabular-nums mt-1">{{ number_format($cat2Tot, 0, '.', ',') }}</p>
                        </div>
                        <div class="p-4">
                            <p class="text-[10px] uppercase tracking-widest text-rose-700 font-semibold">Cat 3 — Other</p>
                            <p class="text-lg font-bold text-rose-900 tabular-nums mt-1">{{ number_format($cat3Tot, 0, '.', ',') }}</p>
                        </div>
                        <div class="p-4 {{ $unresTot > 0 ? 'bg-amber-50/30' : '' }}">
                            <p class="text-[10px] uppercase tracking-widest {{ $unresTot > 0 ? 'text-amber-700' : 'text-slate-500' }} font-semibold">Unresolved</p>
                            <p class="text-lg font-bold {{ $unresTot > 0 ? 'text-amber-900' : 'text-slate-900' }} tabular-nums mt-1">{{ number_format($unresTot, 0, '.', ',') }}</p>
                            @if ($unresolvedCount > 0)
                                <p class="text-[10px] text-amber-700/80 mt-0.5">{{ $unresolvedCount }} beneficiar{{ $unresolvedCount === 1 ? 'y needs' : 'ies need' }} resolution</p>
                            @endif
                        </div>
                    </div>

                    {{-- Stacking + TSR disclosure (now embeds the Rule-reference dropdown) --}}
                    <div class="border-t border-slate-200 bg-slate-50/60 px-5 py-4">
                        <div class="flex items-start gap-3">
                            <div class="text-amber-600 shrink-0 mt-0.5">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            </div>
                            <div class="flex-1 text-sm text-slate-700 leading-relaxed">
                                <p class="font-medium text-slate-900 mb-2">Stacking assumption</p>
                                @php
                                    $nonCat1 = $cat2Tot + $cat3Tot + $unresTot;
                                @endphp
                                @if ($totRein <= 0)
                                    <p class="text-emerald-800">
                                        <strong>No exposure.</strong>
                                        Combined non-Cat-1 spend
                                        (<span class="tabular-nums font-medium">{{ number_format($nonCat1, 0, '.', ',') }} {{ $donCur }}</span>)
                                        is at or below the
                                        <span class="tabular-nums font-medium">{{ number_format($ceilingPct, 1) }} % × CA_net</span>
                                        ceiling
                                        (<span class="tabular-nums font-medium">{{ number_format($ceilingXaf, 0, '.', ',') }} {{ $donCur }}</span>) —
                                        the actual disbursement is within the deductibility cap, so no reintegration applies.
                                    </p>
                                @else
                                    <p>
                                        Excess over ceiling
                                        (<span class="tabular-nums font-medium">{{ number_format($totRein, 0, '.', ',') }} {{ $donCur }}</span>)
                                        is reintegrated and bears <strong>{{ number_format($stackPct, 1) }} %</strong>
                                        stacked exposure = 33 % CIT (Article 17 CGI) + 16.5 % IRCM on
                                        <em>revenus réputés distribués</em> (Articles 26, 91 CGI)
                                        = <span class="tabular-nums font-medium">{{ number_format($expStack, 0, '.', ',') }} {{ $donCur }}</span>.
                                    </p>
                                    @if ($expTsr > 0)
                                        <p class="mt-2">
                                            Plus <strong>{{ number_format($tsrPct, 0) }} % TSR</strong>
                                            (Article 225 CGI) on the reintegrated portion of Cat 3 / Unresolved donations
                                            paid to non-resident beneficiaries =
                                            <span class="tabular-nums font-medium">{{ number_format($expTsr, 0, '.', ',') }} {{ $donCur }}</span>.
                                            <span class="text-[12px] text-slate-600">DTA-reduced rates may apply.</span>
                                        </p>
                                    @endif
                                @endif

                                {{-- Inline rule-reference dropdown — formerly a separate box --}}
                                <details class="mt-5 group">
                                    <summary class="inline-flex items-center gap-2 cursor-pointer list-none text-[12px] font-semibold text-slate-900 hover:text-slate-700 select-none">
                                        <svg class="w-3.5 h-3.5 text-slate-500 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                        </svg>
                                        Rule reference — Article 7 CGI &amp; libéralités
                                        <span class="text-[11px] font-normal text-slate-500">· click to expand</span>
                                    </summary>
                                    <div class="mt-3 pl-5 border-l-2 border-slate-200 text-sm text-slate-700 leading-relaxed space-y-3">
                                        <p>
                                            <strong class="text-slate-900">Article 7 CGI — Charges déductibles.</strong>
                                            Donations, gifts and liberalities are deductible only when the beneficiary
                                            falls within an eligible category, the donor holds written documentation,
                                            and the payment is made by traceable means (bank transfer, cheque, electronic
                                            payment — not cash above the LF threshold).
                                        </p>
                                        <div class="bg-white border border-slate-200 rounded-lg p-4 space-y-2.5 text-[13px]">
                                            <p class="font-medium text-slate-900">Cumulative conditions for deductibility</p>
                                            <ol class="list-decimal list-inside space-y-1 text-slate-700">
                                                <li>The beneficiary holds <strong>recognised legal status</strong> in one of the eligible categories.</li>
                                                <li>The donor holds <strong>written supporting documentation</strong> (statuts, attestation d'utilité publique, accord ministériel, etc.).</li>
                                                <li>Payment is made by <strong>traceable means</strong> (virement bancaire, chèque, monnaie électronique). Cash above the LF threshold is excluded under Article 7-A.</li>
                                            </ol>
                                        </div>
                                        <p>
                                            <strong class="text-emerald-700">Catégorie 1 — Fully deductible (no cap).</strong>
                                            Donations to the State, Collectivités Territoriales Décentralisées, public-utility
                                            scientific / educational / welfare establishments, the CNOSC, national sports
                                            federations, and named œuvres listed in the LF in force.
                                        </p>
                                        <p>
                                            <strong class="text-amber-700">Catégorie 2 — Public-utility associations.</strong>
                                            Other recognised public-utility associations and approved research / educational /
                                            cultural bodies.
                                        </p>
                                        <p>
                                            <strong class="text-rose-700">Catégorie 3 — Other recipients.</strong>
                                            Political parties, individuals, unregistered associations, undocumented donations,
                                            foreign recipients without specific accord.
                                        </p>
                                        <p class="bg-white border border-slate-200 rounded-lg p-3 text-[12px] text-slate-700">
                                            <strong class="text-slate-900">Ceiling test (refined):</strong>
                                            non-Cat-1 spend (Cat 2 + Cat 3 + Unresolved) is tested
                                            <strong>collectively</strong> against
                                            <span class="tabular-nums font-medium">{{ number_format($ceilingPct, 1) }} % × CA_net</span>.
                                            Below ceiling → no reintegration. Above ceiling → only the excess is reintegrated,
                                            distributed pro-rata across the contributing categories. Cat 1 is always exempt.
                                            For non-resident Cat 3 / Unresolved beneficiaries with reintegrated amounts,
                                            an additional 15 % TSR applies (Article 225 CGI).
                                        </p>
                                        <p class="text-[12px] text-slate-600 bg-white border-l-2 border-slate-300 pl-3 py-2">
                                            Sources: Cameroon CGI 2025 — Articles 7, 17, 26, 91, 225 ·
                                            SYSCOHADA AUDCIF 2017 — Classe 7, comptes 70x &amp; 709.
                                        </p>
                                    </div>
                                </details>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ─────── Beneficiaries table with inline forms ─────── --}}
                @if (!empty($bens))
                    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
                        <div class="px-5 py-4 border-b border-slate-200">
                            <h3 class="text-sm font-semibold text-slate-900">Beneficiaries · resolution &amp; supporting documentation</h3>
                            <p class="text-[11px] text-slate-500 mt-0.5">Resolve the category for each beneficiary · upload the supporting status documentation · saved to <code class="text-[10px] bg-slate-100 px-1 py-0.5 rounded">res.partner.x_donation_category_cm</code> (best-effort to Odoo, locally cached)</p>
                        </div>

                        @if (session('flash.type') === 'success')
                            <div class="px-5 py-2.5 bg-emerald-50 border-b border-emerald-200 text-[12px] text-emerald-800">{{ session('flash.msg') }}</div>
                        @endif
                        @if ($errors->any())
                            <div class="px-5 py-2.5 bg-rose-50 border-b border-rose-200 text-[12px] text-rose-700">{{ $errors->first() }}</div>
                        @endif

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-left text-[10px] text-slate-500 uppercase tracking-wider border-b border-slate-200 bg-slate-50">
                                        <th class="px-4 py-3 font-semibold">Beneficiary</th>
                                        <th class="px-4 py-3 font-semibold">NIU</th>
                                        <th class="px-4 py-3 text-right font-semibold">Amount ({{ $donCur }})</th>
                                        <th class="px-4 py-3 font-semibold">Category</th>
                                        <th class="px-4 py-3 font-semibold text-center">Non-resident</th>
                                        <th class="px-4 py-3 font-semibold">Doc</th>
                                        <th class="px-4 py-3 font-semibold text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach ($bens as $b)
                                        @php
                                            $isUnresolved = empty($b['category']);
                                            $catBadgeClass = match ((int)($b['category'] ?? 0)) {
                                                1 => 'bg-emerald-100 text-emerald-800 border-emerald-300',
                                                2 => 'bg-amber-100 text-amber-800 border-amber-300',
                                                3 => 'bg-rose-100 text-rose-800 border-rose-300',
                                                default => 'bg-slate-100 text-slate-600 border-slate-300',
                                            };
                                            $rowId = 'bene-' . (int)$b['partner_id'];
                                        @endphp
                                        <tr class="hover:bg-slate-50/40 {{ $isUnresolved ? 'bg-rose-50/30' : '' }}">
                                            <td class="px-4 py-2.5 align-top">
                                                <div class="font-medium text-slate-900">{{ $b['name'] }}</div>
                                                @if (!empty($b['country']))
                                                    <div class="text-[10px] text-slate-500">{{ $b['country'] }}</div>
                                                @endif
                                                @if ($b['line_count'] > 1)
                                                    <div class="text-[10px] text-slate-400 mt-0.5">{{ $b['line_count'] }} lines</div>
                                                @endif
                                            </td>
                                            <td class="px-4 py-2.5 align-top text-[11px] tabular-nums text-slate-700">
                                                {{ $b['niu'] ?: '—' }}
                                            </td>
                                            <td class="px-4 py-2.5 align-top text-right tabular-nums font-semibold text-slate-900">
                                                {{ number_format((float)$b['total'], 0, '.', ',') }}
                                            </td>
                                            <td class="px-4 py-2.5 align-top">
                                                <span class="inline-flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-widest border rounded-full px-2 py-0.5 {{ $catBadgeClass }}">
                                                    {{ (int)($b['category'] ?? 0) === 0 ? 'Unresolved' : 'Cat ' . (int)$b['category'] }}
                                                </span>
                                                <div class="text-[10px] text-slate-500 mt-1">
                                                    Deductible {{ number_format((float)($b['deductible'] ?? 0), 0, '.', ',') }} · Reintegrated {{ number_format((float)($b['reintegrated'] ?? 0), 0, '.', ',') }}
                                                </div>
                                            </td>
                                            <td class="px-4 py-2.5 align-top text-center">
                                                @if ($b['is_non_resident'])
                                                    <span class="text-[10px] font-semibold text-rose-700">Yes (TSR)</span>
                                                @else
                                                    <span class="text-[10px] text-slate-500">No</span>
                                                @endif
                                            </td>
                                            <td class="px-4 py-2.5 align-top">
                                                @if ($b['has_supporting_doc'])
                                                    <span class="text-[10px] text-emerald-700 font-medium">📎 On file</span>
                                                    @if (!empty($b['supporting_doc_type']))
                                                        <div class="text-[10px] text-slate-500">{{ $b['supporting_doc_type'] }}</div>
                                                    @endif
                                                @else
                                                    <span class="text-[10px] text-slate-400">— none —</span>
                                                @endif
                                            </td>
                                            <td class="px-4 py-2.5 align-top text-right">
                                                @if ((int)$b['partner_id'] > 0)
                                                    <button type="button" onclick="document.getElementById('{{ $rowId }}').classList.toggle('hidden')"
                                                            class="text-[11px] text-brand-700 hover:text-brand-900 font-medium">
                                                        Resolve →
                                                    </button>
                                                @else
                                                    <span class="text-[10px] text-rose-700">Set partner in Odoo</span>
                                                @endif
                                            </td>
                                        </tr>
                                        @if ((int)$b['partner_id'] > 0)
                                            <tr id="{{ $rowId }}" class="hidden bg-slate-50/60">
                                                <td colspan="7" class="px-4 py-4">
                                                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                                        {{-- Metadata form --}}
                                                        <form method="POST" action="{{ tenant_url('/income-tax-compliance/donations/category') }}" class="space-y-2.5">
                                                            @csrf
                                                            <input type="hidden" name="odoo_partner_id" value="{{ $b['partner_id'] }}">
                                                            <input type="hidden" name="partner_name" value="{{ $b['name'] }}">
                                                            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Resolution</div>
                                                            <div class="grid grid-cols-2 gap-2">
                                                                <label class="block">
                                                                    <span class="text-[10px] text-slate-500 uppercase tracking-wider">Category</span>
                                                                    <select name="category" class="mt-0.5 w-full text-[12px] border border-slate-300 rounded px-2 py-1.5 focus:border-brand-500 focus:outline-none">
                                                                        <option value="" {{ is_null($b['category']) ? 'selected' : '' }}>— Unresolved —</option>
                                                                        <option value="1" {{ (int)$b['category'] === 1 ? 'selected' : '' }}>Cat 1 — Fully deductible</option>
                                                                        <option value="2" {{ (int)$b['category'] === 2 ? 'selected' : '' }}>Cat 2 — Capped at ceiling</option>
                                                                        <option value="3" {{ (int)$b['category'] === 3 ? 'selected' : '' }}>Cat 3 — Non-deductible</option>
                                                                    </select>
                                                                </label>
                                                                <label class="block">
                                                                    <span class="text-[10px] text-slate-500 uppercase tracking-wider">NIU</span>
                                                                    <input type="text" name="partner_niu" maxlength="64" value="{{ $b['niu'] }}"
                                                                           class="mt-0.5 w-full text-[12px] border border-slate-300 rounded px-2 py-1.5 focus:border-brand-500 focus:outline-none">
                                                                </label>
                                                            </div>
                                                            <div class="grid grid-cols-2 gap-2">
                                                                <label class="block">
                                                                    <span class="text-[10px] text-slate-500 uppercase tracking-wider">Doc type</span>
                                                                    <input type="text" name="supporting_doc_type" maxlength="64" value="{{ $b['supporting_doc_type'] }}" placeholder="Statuts / Attestation utilité publique / Accord ministériel"
                                                                           class="mt-0.5 w-full text-[12px] border border-slate-300 rounded px-2 py-1.5 focus:border-brand-500 focus:outline-none">
                                                                </label>
                                                                <label class="block">
                                                                    <span class="text-[10px] text-slate-500 uppercase tracking-wider">Doc reference</span>
                                                                    <input type="text" name="supporting_doc_reference" maxlength="191" value="{{ $b['supporting_doc_reference'] }}"
                                                                           class="mt-0.5 w-full text-[12px] border border-slate-300 rounded px-2 py-1.5 focus:border-brand-500 focus:outline-none">
                                                                </label>
                                                            </div>
                                                            <label class="inline-flex items-center gap-2 text-[12px] text-slate-700">
                                                                <input type="checkbox" name="is_non_resident" value="1" {{ $b['is_non_resident'] ? 'checked' : '' }}
                                                                       class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                                                Non-resident (apply 15 % TSR if Cat 3)
                                                            </label>
                                                            <button type="submit" class="text-[11px] font-semibold text-white bg-brand-600 hover:bg-brand-700 rounded px-3 py-1.5">
                                                                Save resolution
                                                            </button>
                                                        </form>

                                                        {{-- Upload form --}}
                                                        <form method="POST" action="{{ tenant_url('/income-tax-compliance/donations/upload') }}" enctype="multipart/form-data" class="space-y-2.5">
                                                            @csrf
                                                            <input type="hidden" name="odoo_partner_id" value="{{ $b['partner_id'] }}">
                                                            <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Supporting document</div>
                                                            <p class="text-[11px] text-slate-600">
                                                                Accepted: PDF, JPG, PNG, DOC, DOCX, XLS, XLSX. Max 10 MB.
                                                            </p>
                                                            <input type="file" name="document" required
                                                                   accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"
                                                                   class="block w-full text-[12px] text-slate-700 file:mr-3 file:py-1 file:px-2 file:rounded file:border-0 file:text-[11px] file:font-semibold file:bg-slate-200 file:text-slate-700 hover:file:bg-slate-300">
                                                            @if ($b['has_supporting_doc'])
                                                                <p class="text-[10px] text-emerald-700">📎 Existing document on file (uploading replaces it).</p>
                                                            @endif
                                                            <button type="submit" class="text-[11px] font-semibold text-slate-700 bg-slate-200 hover:bg-slate-300 rounded px-3 py-1.5">
                                                                Upload document
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="border-t-2 border-slate-700 bg-slate-50">
                                        <td class="px-4 py-3 text-[10px] uppercase tracking-widest font-bold text-slate-700" colspan="2">Total</td>
                                        <td class="px-4 py-3 text-right tabular-nums font-bold text-slate-900">{{ number_format($totalDon, 0, '.', ',') }}</td>
                                        <td class="px-4 py-3 text-[11px] text-slate-700" colspan="4">
                                            Deductible <span class="tabular-nums font-semibold">{{ number_format($totDed, 0, '.', ',') }}</span> ·
                                            Reintegrated <span class="tabular-nums font-semibold">{{ number_format($totRein, 0, '.', ',') }}</span> ·
                                            Exposure <span class="tabular-nums font-semibold text-rose-700">{{ number_format($expTotal, 0, '.', ',') }}</span>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                @else
                    <div class="bg-white border border-slate-200 rounded-xl p-8 text-center text-slate-500 text-sm">
                        No donations recorded on accounts <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">6582XX</code>
                        or <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">6583XX</code> in the YTD window.
                    </div>
                @endif

                {{-- ─────── Line-level GL detail with per-line categorisation ─────── --}}
                @php
                    $lines           = $don['lines'] ?? [];
                    $catComplete     = (bool) ($don['categorisation_complete'] ?? true);
                    $fbCount         = (int)  ($don['cat3_fallback_count']  ?? 0);
                    $fbTotal         = (float)($don['cat3_fallback_total']  ?? 0);
                    $totalLineCount  = (int)  ($don['line_count'] ?? count($lines));

                    // Per-line label / pill metadata derived once on the server
                    // so the JS only deals with state changes, not first-render
                    // formatting.
                    $catLabel = function (int $cat, string $src): string {
                        if ($src === 'line_override')   return 'Cat ' . $cat . ' · override';
                        if ($src === 'partner_default') return 'Cat ' . $cat . ' · partner';
                        return 'Cat 3 · fallback';
                    };
                    $catPillClasses = function (int $cat, string $src): string {
                        if ($src === 'cat3_fallback')                  return 'text-amber-800 bg-amber-100 border border-amber-300';
                        if ($cat === 1)                                return 'text-emerald-700 bg-emerald-50 border border-emerald-200';
                        if ($cat === 2)                                return 'text-amber-700 bg-amber-50 border border-amber-200';
                        return 'text-rose-700 bg-rose-50 border border-rose-200';
                    };
                @endphp
                @if (!empty($lines))
                <div id="don-line-table"
                    class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden mt-4"
                    data-endpoint-single="{{ tenant_url('/income-tax-compliance/donations/lines') }}"
                    data-endpoint-bulk="{{ tenant_url('/income-tax-compliance/donations/lines/bulk-category') }}"
                    data-currency="{{ $donCur }}"
                    data-fallback-count="{{ $fbCount }}"
                    data-fallback-total="{{ $fbTotal }}"
                    data-total-count="{{ $totalLineCount }}"
                >
                    {{-- Header --}}
                    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 border-b border-slate-200 bg-gradient-to-r from-indigo-50/60 to-white">
                        <div>
                            <div class="text-[10px] uppercase tracking-widest text-indigo-700 font-semibold">B · GL line detail · YTD {{ $donYear }}</div>
                            <h2 class="text-lg font-semibold text-slate-900 mt-1">Per-line categorisation (Article 7 CGI)</h2>
                            <p class="text-[11px] text-slate-500 mt-1">
                                Each line resolves through:
                                <code class="text-[10.5px] bg-slate-100 px-1.5 py-0.5 rounded">line override</code> →
                                <code class="text-[10.5px] bg-slate-100 px-1.5 py-0.5 rounded">partner default</code> →
                                <code class="text-[10.5px] bg-slate-100 px-1.5 py-0.5 rounded">Cat 3 fallback</code>.
                                Cat 3 is the conservative default — <em>non-deductible until proven otherwise</em>.
                            </p>
                        </div>
                        <div class="flex flex-col items-end gap-1.5">
                            <span data-don-status="complete" class="@if(!$catComplete) hidden @endif inline-flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-widest text-emerald-700 bg-emerald-50 border border-emerald-300 rounded-full px-2.5 py-1">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                Fully categorised
                            </span>
                            <span data-don-status="incomplete" class="@if($catComplete) hidden @endif inline-flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-widest text-amber-700 bg-amber-50 border border-amber-300 rounded-full px-2.5 py-1">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3m0 4h.01M5 19h14a2 2 0 001.732-3L13.732 4a2 2 0 00-3.464 0L3.268 16A2 2 0 005 19z"/></svg>
                                <span data-don-fallback-pill>{{ $fbCount }} line{{ $fbCount === 1 ? '' : 's' }} on Cat 3 fallback</span>
                            </span>
                            <span class="text-[10px] text-slate-500">{{ number_format($totalLineCount) }} line{{ $totalLineCount === 1 ? '' : 's' }} in scope</span>
                        </div>
                    </div>

                    {{-- Cat 3 fallback banner (visible when not fully categorised) --}}
                    <div data-don-fallback-banner class="@if($catComplete) hidden @endif flex flex-wrap items-center justify-between gap-3 px-5 py-3 border-b border-amber-200 bg-amber-50/70">
                        <div class="text-[12px] text-amber-900 leading-relaxed">
                            <strong data-don-fallback-count>{{ $fbCount }}</strong>
                            of <strong>{{ number_format($totalLineCount) }}</strong> lines resolving via
                            <strong>Cat 3 fallback</strong>
                            (<span class="tabular-nums" data-don-fallback-total>{{ number_format($fbTotal, 0, '.', ',') }}</span> {{ $donCur }}) —
                            no line override and no partner default. Review and confirm each line's category, or
                            lock them all in as Cat 3 with one click.
                        </div>
                        <button type="button" data-don-confirm-all
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[11px] font-semibold text-white bg-amber-700 hover:bg-amber-800 disabled:opacity-60 rounded-md shadow-sm whitespace-nowrap">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            <span data-don-confirm-label>Confirm all as Cat 3</span>
                        </button>
                    </div>

                    {{-- Selection toolbar (hidden by default; shown when ≥1 row selected) --}}
                    <div data-don-selection-bar class="hidden flex-wrap items-center justify-between gap-3 px-5 py-3 border-b border-indigo-200 bg-indigo-50/60">
                        <div class="text-[12px] text-indigo-900">
                            <strong data-don-sel-count>0</strong> lines selected ·
                            <span class="tabular-nums" data-don-sel-total>0</span> {{ $donCur }}
                        </div>
                        <div class="flex items-center gap-2">
                            <select data-don-bulk-cat class="text-[12px] border-slate-300 rounded-md px-2 py-1.5">
                                <option value="3" selected>Apply: Cat 3 — Non-deductible (default)</option>
                                <option value="1">Apply: Cat 1 — Fully deductible</option>
                                <option value="2">Apply: Cat 2 — Ceiling-tested</option>
                            </select>
                            <button type="button" data-don-bulk-apply
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[11px] font-semibold text-white bg-indigo-700 hover:bg-indigo-800 disabled:opacity-60 rounded-md shadow-sm">
                                <span data-don-bulk-label>Apply</span>
                            </button>
                            <button type="button" data-don-bulk-clear
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[11px] font-medium text-indigo-700 bg-white border border-indigo-300 hover:bg-indigo-50 rounded-md">
                                Clear
                            </button>
                        </div>
                    </div>

                    {{-- Line table --}}
                    <div class="overflow-x-auto max-h-[640px]">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 sticky top-0 z-10">
                                <tr class="text-left text-[10px] uppercase tracking-widest text-slate-500 font-semibold">
                                    <th class="px-3 py-2.5 w-8">
                                        <input type="checkbox" data-don-toggle-all class="rounded border-slate-400 text-indigo-600 focus:ring-indigo-500">
                                    </th>
                                    <th class="px-3 py-2.5">Date</th>
                                    <th class="px-3 py-2.5">Description</th>
                                    <th class="px-3 py-2.5">Account</th>
                                    <th class="px-3 py-2.5">Partner</th>
                                    <th class="px-3 py-2.5 text-right">Amount ({{ $donCur }})</th>
                                    <th class="px-3 py-2.5">Category</th>
                                    <th class="px-3 py-2.5 w-32">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($lines as $ln)
                                    @php
                                        $lnId       = (int)   $ln['id'];
                                        $lnSrc      = (string)$ln['category_source'];
                                        $lnEff      = (int)   $ln['effective_category'];
                                        $lnOverride = $ln['line_override_category'] ?? null;
                                        $lnPDef     = $ln['partner_default_category'] ?? null;
                                        $rowBg      = $lnSrc === 'cat3_fallback' ? 'bg-amber-50/40' : '';
                                        $emptyOptionLabel = $lnPDef !== null
                                            ? 'Inherit from partner — Cat ' . (int) $lnPDef
                                            : '— No partner default — fallback Cat 3';
                                    @endphp
                                    <tr data-don-row="{{ $lnId }}" class="{{ $rowBg }}"
                                        data-line-id="{{ $lnId }}"
                                        data-move-id="{{ (int) $ln['move_id'] }}"
                                        data-partner-id="{{ $ln['partner_id'] !== null ? (int) $ln['partner_id'] : '' }}"
                                        data-account-code="{{ $ln['account_code'] ?? '' }}"
                                        data-line-date="{{ $ln['date'] }}"
                                        data-amount="{{ (float) $ln['amount'] }}"
                                        data-partner-default="{{ $lnPDef ?? '' }}"
                                    >
                                        <td class="px-3 py-2.5">
                                            <input type="checkbox" data-don-row-check class="rounded border-slate-400 text-indigo-600 focus:ring-indigo-500">
                                        </td>
                                        <td class="px-3 py-2.5 text-[12px] text-slate-700 tabular-nums whitespace-nowrap">{{ $ln['date'] }}</td>
                                        <td class="px-3 py-2.5 text-[12px] text-slate-900" title="{{ $ln['narration'] ?? '' }}">
                                            <span class="block max-w-xs truncate">{{ $ln['narration'] ?: '—' }}</span>
                                        </td>
                                        <td class="px-3 py-2.5 text-[11px] tabular-nums text-slate-600 whitespace-nowrap">{{ $ln['account'] }}</td>
                                        <td class="px-3 py-2.5 text-[12px] text-slate-700">{{ $ln['partner_name'] ?: '— No partner —' }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums font-semibold text-slate-900">{{ number_format((float) $ln['amount'], 0, '.', ',') }}</td>
                                        <td class="px-3 py-2.5">
                                            <select data-don-row-select class="text-[12px] border-slate-300 rounded-md px-2 py-1 max-w-[18rem]">
                                                <option value="" {{ $lnOverride === null ? 'selected' : '' }}>{{ $emptyOptionLabel }}</option>
                                                <option value="1" {{ $lnOverride === 1 ? 'selected' : '' }}>Cat 1 — Fully deductible</option>
                                                <option value="2" {{ $lnOverride === 2 ? 'selected' : '' }}>Cat 2 — Ceiling-tested</option>
                                                <option value="3" {{ $lnOverride === 3 ? 'selected' : '' }}>Cat 3 — Non-deductible</option>
                                            </select>
                                        </td>
                                        <td class="px-3 py-2.5">
                                            <span data-don-row-pill class="inline-flex items-center text-[10px] font-medium px-2 py-0.5 rounded-full whitespace-nowrap {{ $catPillClasses($lnEff, $lnSrc) }}">
                                                {{ $catLabel($lnEff, $lnSrc) }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <script>
                (function () {
                    const root = document.getElementById('don-line-table');
                    if (!root) return;

                    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
                              || document.querySelector('input[name="_token"]')?.value || '';
                    const epSingle = root.dataset.endpointSingle;
                    const epBulk   = root.dataset.endpointBulk;
                    const totalCount = parseInt(root.dataset.totalCount || '0', 10);

                    const selBar       = root.querySelector('[data-don-selection-bar]');
                    const selCountEl   = root.querySelector('[data-don-sel-count]');
                    const selTotalEl   = root.querySelector('[data-don-sel-total]');
                    const bulkCatSel   = root.querySelector('[data-don-bulk-cat]');
                    const bulkApplyBtn = root.querySelector('[data-don-bulk-apply]');
                    const bulkClearBtn = root.querySelector('[data-don-bulk-clear]');
                    const bulkLabel    = root.querySelector('[data-don-bulk-label]');
                    const toggleAll    = root.querySelector('[data-don-toggle-all]');
                    const fbBanner     = root.querySelector('[data-don-fallback-banner]');
                    const fbCountEl    = root.querySelector('[data-don-fallback-count]');
                    const fbTotalEl    = root.querySelector('[data-don-fallback-total]');
                    const fbPillEl     = root.querySelector('[data-don-fallback-pill]');
                    const statusComplete   = root.querySelector('[data-don-status="complete"]');
                    const statusIncomplete = root.querySelector('[data-don-status="incomplete"]');
                    const confirmAllBtn    = root.querySelector('[data-don-confirm-all]');
                    const confirmAllLabel  = root.querySelector('[data-don-confirm-label]');

                    const fmt = (v) => Math.round(v).toLocaleString('en-US');

                    function rowsAll() {
                        return Array.from(root.querySelectorAll('tr[data-don-row]'));
                    }
                    function rowMeta(tr) {
                        return {
                            line_id:      parseInt(tr.dataset.lineId, 10),
                            move_id:      parseInt(tr.dataset.moveId, 10),
                            partner_id:   tr.dataset.partnerId !== '' ? parseInt(tr.dataset.partnerId, 10) : null,
                            account_code: tr.dataset.accountCode,
                            line_date:    tr.dataset.lineDate,
                            amount:       parseFloat(tr.dataset.amount),
                            partner_default: tr.dataset.partnerDefault !== '' ? parseInt(tr.dataset.partnerDefault, 10) : null,
                        };
                    }
                    function selectedRows() {
                        return rowsAll().filter(tr => tr.querySelector('[data-don-row-check]').checked);
                    }
                    function refreshSelection() {
                        const sel = selectedRows();
                        if (sel.length === 0) {
                            selBar.classList.add('hidden');
                            selBar.classList.remove('flex');
                            return;
                        }
                        selBar.classList.remove('hidden');
                        selBar.classList.add('flex');
                        selCountEl.textContent = sel.length;
                        const total = sel.reduce((s, tr) => s + parseFloat(tr.dataset.amount || '0'), 0);
                        selTotalEl.textContent = fmt(total);
                    }
                    function refreshFallbackTallies() {
                        // Walk every row, count those whose pill currently shows fallback.
                        let count = 0, total = 0;
                        for (const tr of rowsAll()) {
                            const pill = tr.querySelector('[data-don-row-pill]');
                            if (pill && pill.textContent.includes('fallback')) {
                                count += 1;
                                total += parseFloat(tr.dataset.amount || '0');
                            }
                        }
                        fbCountEl.textContent = count;
                        fbTotalEl.textContent = fmt(total);
                        fbPillEl.textContent = count + ' line' + (count === 1 ? '' : 's') + ' on Cat 3 fallback';

                        if (count === 0) {
                            fbBanner.classList.add('hidden');
                            statusComplete.classList.remove('hidden');
                            statusIncomplete.classList.add('hidden');
                        } else {
                            fbBanner.classList.remove('hidden');
                            statusComplete.classList.add('hidden');
                            statusIncomplete.classList.remove('hidden');
                        }
                    }
                    function pillForCategory(cat, source) {
                        if (source === 'line_override')   return { label: 'Cat ' + cat + ' · override', src: 'line_override' };
                        if (source === 'partner_default') return { label: 'Cat ' + cat + ' · partner',  src: 'partner_default' };
                        return { label: 'Cat 3 · fallback', src: 'cat3_fallback' };
                    }
                    function applyPill(tr, cat, source) {
                        const pill = tr.querySelector('[data-don-row-pill]');
                        const meta = pillForCategory(cat, source);
                        pill.textContent = meta.label;
                        pill.className = 'inline-flex items-center text-[10px] font-medium px-2 py-0.5 rounded-full whitespace-nowrap ';
                        if (source === 'cat3_fallback')        pill.className += 'text-amber-800 bg-amber-100 border border-amber-300';
                        else if (cat === 1)                    pill.className += 'text-emerald-700 bg-emerald-50 border border-emerald-200';
                        else if (cat === 2)                    pill.className += 'text-amber-700 bg-amber-50 border border-amber-200';
                        else                                    pill.className += 'text-rose-700 bg-rose-50 border border-rose-200';

                        if (source === 'cat3_fallback') tr.classList.add('bg-amber-50/40');
                        else                            tr.classList.remove('bg-amber-50/40');
                    }
                    function applyRowState(tr, override, partnerDefault) {
                        let cat, source;
                        if (override !== null) { cat = override; source = 'line_override'; }
                        else if (partnerDefault !== null) { cat = partnerDefault; source = 'partner_default'; }
                        else { cat = 3; source = 'cat3_fallback'; }
                        applyPill(tr, cat, source);
                    }
                    async function postSingle(tr, category) {
                        const meta = rowMeta(tr);
                        const url  = epSingle + '/' + meta.line_id + '/category';
                        const body = new FormData();
                        if (category !== null) body.append('category', String(category));
                        body.append('odoo_move_id',    String(meta.move_id));
                        if (meta.partner_id !== null) body.append('odoo_partner_id', String(meta.partner_id));
                        body.append('account_code', meta.account_code);
                        body.append('line_date',    meta.line_date);
                        body.append('amount',       String(meta.amount));
                        const r = await fetch(url, {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            body: body,
                        });
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        return r.json();
                    }
                    async function postBulk(rows, category) {
                        const body = new FormData();
                        body.append('category', String(category));
                        rows.forEach((tr, i) => {
                            const m = rowMeta(tr);
                            body.append(`lines[${i}][odoo_move_line_id]`, String(m.line_id));
                            body.append(`lines[${i}][odoo_move_id]`,      String(m.move_id));
                            if (m.partner_id !== null) body.append(`lines[${i}][odoo_partner_id]`, String(m.partner_id));
                            body.append(`lines[${i}][account_code]`, m.account_code);
                            body.append(`lines[${i}][line_date]`,    m.line_date);
                            body.append(`lines[${i}][amount]`,       String(m.amount));
                        });
                        const r = await fetch(epBulk, {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            body: body,
                        });
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        return r.json();
                    }

                    // After any successful save, reload the page so the
                    // headline tiles (Total deductible / Reintegrated /
                    // Stacked exposure / TSR) recompute server-side from
                    // the new line-level resolution. The optimistic in-
                    // place pill update keeps the row state visible until
                    // the reload completes.
                    function reloadSoon() {
                        // Tiny delay so the pill change is visible before
                        // the reload jumps the user back to the top.
                        setTimeout(() => window.location.reload(), 250);
                    }

                    // Wire up per-row dropdowns.
                    for (const tr of rowsAll()) {
                        const sel = tr.querySelector('[data-don-row-select]');
                        sel.addEventListener('change', async (e) => {
                            const raw = e.target.value;
                            const override = raw === '' ? null : parseInt(raw, 10);
                            const meta = rowMeta(tr);
                            sel.disabled = true;
                            try {
                                await postSingle(tr, override);
                                applyRowState(tr, override, meta.partner_default);
                                refreshFallbackTallies();
                                reloadSoon();
                            } catch (err) {
                                alert('Failed to save category — ' + err.message);
                                sel.disabled = false;
                            }
                        });
                        tr.querySelector('[data-don-row-check]').addEventListener('change', refreshSelection);
                    }

                    // "Toggle all" header checkbox.
                    toggleAll.addEventListener('change', (e) => {
                        const checked = e.target.checked;
                        for (const tr of rowsAll()) tr.querySelector('[data-don-row-check]').checked = checked;
                        refreshSelection();
                    });

                    // Bulk apply.
                    bulkApplyBtn.addEventListener('click', async () => {
                        const sel = selectedRows();
                        if (sel.length === 0) return;
                        const cat = parseInt(bulkCatSel.value, 10);
                        bulkApplyBtn.disabled = true; bulkLabel.textContent = 'Saving…';
                        try {
                            await postBulk(sel, cat);
                            for (const tr of sel) {
                                const meta = rowMeta(tr);
                                tr.querySelector('[data-don-row-select]').value = String(cat);
                                applyRowState(tr, cat, meta.partner_default);
                            }
                            refreshFallbackTallies();
                            reloadSoon();
                        } catch (err) {
                            alert('Bulk save failed — ' + err.message);
                            bulkApplyBtn.disabled = false; bulkLabel.textContent = 'Apply';
                        }
                    });
                    bulkClearBtn.addEventListener('click', () => {
                        for (const tr of rowsAll()) tr.querySelector('[data-don-row-check]').checked = false;
                        toggleAll.checked = false;
                        refreshSelection();
                    });

                    // "Confirm all as Cat 3" — bulk-set every fallback row to Cat 3.
                    if (confirmAllBtn) {
                        confirmAllBtn.addEventListener('click', async () => {
                            const fbRows = rowsAll().filter(tr => {
                                const pill = tr.querySelector('[data-don-row-pill]');
                                return pill && pill.textContent.includes('fallback');
                            });
                            if (fbRows.length === 0) return;
                            confirmAllBtn.disabled = true; confirmAllLabel.textContent = 'Saving…';
                            try {
                                await postBulk(fbRows, 3);
                                for (const tr of fbRows) {
                                    tr.querySelector('[data-don-row-select]').value = '3';
                                    applyRowState(tr, 3, rowMeta(tr).partner_default);
                                }
                                refreshFallbackTallies();
                                reloadSoon();
                            } catch (err) {
                                alert('Confirm-all failed — ' + err.message);
                                confirmAllBtn.disabled = false; confirmAllLabel.textContent = 'Confirm all as Cat 3';
                            }
                        });
                    }
                })();
                </script>
                @endif
                {{-- /Line-level GL detail --}}

                {{-- Methodology footer (B) --}}
                <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm text-[12px] text-slate-600 leading-relaxed">
                    <strong class="text-slate-900">Methodology.</strong>
                    Posted debit balances on
                    <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">6582XX</code> (Dons) and
                    <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">6583XX</code> (Mécenat) in YTD,
                    resolved per line by precedence
                    <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">line override</code> →
                    <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">partner default</code> →
                    <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">Cat 3 fallback</code> (conservative).
                    CA net YTD computed as
                    <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">|Σ balance(70 ex 709)| − |Σ balance(709)|</code>
                    on posted lines in YTD (SYSCOHADA AUDCIF 2017).
                    Ceiling = {{ number_format($ceilingPct, 1) }} % × CA net. Cat 2 + Cat 3 are collectively tested
                    against the ceiling; the excess is reintegrated pro-rata. Stacked exposure on the
                    reintegrated portion = 49.5 % (33 % CIT + 16.5 % IRCM); +15 % TSR (Article 225) on Cat 3
                    amounts paid to non-residents. Resolved partner categories cached locally and best-effort
                    written to
                    <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">res.partner.x_donation_category_cm</code>;
                    if the custom field is not defined in your Odoo, the local cache remains the source of truth.
                </div>
            </div>
        @endif
    @endif
@endsection
