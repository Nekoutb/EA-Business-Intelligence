@extends('layouts.app')
@section('title', 'Income Tax Compliance')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Income Tax Compliance</h1>
            <p class="text-xs text-slate-500 mt-1">Disallowed expenditures and exposure under Cameroon CGI</p>
        </div>
        @if(!empty($analysis['as_of']))
            <div class="text-[11px] text-slate-500">As of <span class="tabular-nums font-medium text-slate-700">{{ $analysis['as_of'] }}</span></div>
        @endif
    </div>
@endsection

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
                            Posted credits to cash-on-hand (account 57 — Caisse, SYSCOHADA) above
                            <span class="font-medium text-slate-700 tabular-nums">{{ number_format($thresh, 0, '.', ',') }} {{ $cur }}</span>
                            per transaction
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
                        <p class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Number of transactions</p>
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

                {{-- Stacking disclosure --}}
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
                        </div>
                    </div>
                </div>
            </div>

            {{-- ═══════════════════ Rule reference (collapsible) ═══════════════════ --}}
            <details class="bg-white border border-slate-200 rounded-xl shadow-sm group">
                <summary class="flex items-center justify-between px-5 py-4 cursor-pointer list-none">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">Rule reference — Articles 7-A, 17, 26, 91 CGI</h3>
                        <p class="text-[11px] text-slate-500 mt-0.5">Why these payments are flagged · click to expand</p>
                    </div>
                    <svg class="w-4 h-4 text-slate-500 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                    </svg>
                </summary>
                <div class="border-t border-slate-200 px-5 py-4 text-sm text-slate-700 leading-relaxed space-y-3">
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
                    <p class="text-[12px] text-slate-600 bg-slate-50 border-l-2 border-slate-300 pl-3 py-2">
                        Stacked exposure = <span class="tabular-nums">{{ number_format($citPct, 1) }} % + {{ number_format($ircmPct, 1) }} % = {{ number_format($totPct, 1) }} %</span>
                        of the disallowed amount. The IRCM leg is contingent on whether the DGI pursues the requalification.
                    </p>
                </div>
            </details>

            {{-- ═══════════════════ Qualifying transactions table ═══════════════════ --}}
            @if (!empty($groups))
                <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-200 flex flex-wrap items-baseline justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-900">Qualifying transactions</h3>
                            <p class="text-[11px] text-slate-500 mt-0.5">Aggregated by counterparty &amp; date · sorted by amount descending</p>
                        </div>
                        <a href="{{ tenant_url('/income-tax-compliance/export') }}"
                           class="text-[11px] font-medium text-brand-700 hover:text-brand-900 inline-flex items-center gap-1.5">
                            Full line-level detail in the XLSX export
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                        </a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-[10px] text-slate-500 uppercase tracking-wider border-b border-slate-200 bg-slate-50">
                                    <th class="px-4 py-3 font-semibold">Date</th>
                                    <th class="px-4 py-3 font-semibold">Counterparty</th>
                                    <th class="px-4 py-3 text-right font-semibold">Lines</th>
                                    <th class="px-4 py-3 text-right font-semibold">Total ({{ $cur }})</th>
                                    <th class="px-4 py-3 font-semibold">Doc on file</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($groups as $g)
                                    <tr class="border-b border-slate-100 hover:bg-slate-50/60">
                                        <td class="px-4 py-2.5 tabular-nums text-slate-900">{{ $g['date'] }}</td>
                                        <td class="px-4 py-2.5">
                                            @if ($g['partner'])
                                                <span class="text-slate-900">{{ $g['partner'] }}</span>
                                            @else
                                                <span class="text-rose-700 italic">— unrecorded —</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ number_format($g['count']) }}</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums font-semibold text-slate-900">{{ number_format($g['total'], 0, '.', ',') }}</td>
                                        <td class="px-4 py-2.5">
                                            @if ($g['any_missing_doc'])
                                                <span class="inline-flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-widest text-rose-700 bg-rose-50 border border-rose-200 rounded-full px-2 py-0.5">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                                                    Missing
                                                </span>
                                            @else
                                                <span class="text-[11px] text-slate-500">On file</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="border-t-2 border-slate-700 bg-slate-50">
                                    <td class="px-4 py-3 text-[10px] uppercase tracking-widest font-bold text-slate-700" colspan="2">Total</td>
                                    <td class="px-4 py-3 text-right tabular-nums font-bold text-slate-700">{{ number_format($count) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums font-bold text-slate-900">{{ number_format($total, 0, '.', ',') }}</td>
                                    <td class="px-4 py-3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            @else
                <div class="bg-white border border-slate-200 rounded-xl p-8 text-center text-slate-500 text-sm">
                    No qualifying high-value cash payments in the YTD window.
                </div>
            @endif

            {{-- Methodology footer --}}
            <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm text-[12px] text-slate-600 leading-relaxed">
                <strong class="text-slate-900">Methodology.</strong>
                Posted move lines whose <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">account_id.code</code>
                starts with <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">57</code>
                (Caisse, SYSCOHADA), with line credit greater than
                <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">{{ number_format($thresh, 0, '.', ',') }}</code>,
                in the YTD window. Class 52 (Banques) is intentionally excluded.
                Threshold reference: {{ $thRef }}.
                Stacking assumes the DGI requalifies the disallowed amount in full as a hidden distribution.
                "Missing" supporting doc indicates the originating <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">account.move</code>
                has zero <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">ir.attachment</code>
                records on file.
            </div>
        </div>
    @endif
@endsection
