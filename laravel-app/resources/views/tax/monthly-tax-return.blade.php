@extends('layouts.app')
@section('title', 'Monthly Tax Return')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Monthly Tax Return</h1>
            <p class="text-xs text-slate-500 mt-1">Cameroon DGI · Déclaration des taxes sur le chiffre d'affaires et des impôts sur le revenu (I/TVA-IR)</p>
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
            <div class="mt-2">Go to <a href="{{ tenant_url('/settings') }}" class="underline">Settings</a> to verify your Odoo connection.</div>
        </div>
    @elseif ($analysis)
        @php
            $cur          = $analysis['currency'] ?? 'XAF';
            $period       = $analysis['period']   ?? [];
            $declarant    = $analysis['declarant'] ?? [];
            $monthLabel   = $period['label']      ?? '—';
            $monthValue   = sprintf('%04d-%02d', (int)($period['year'] ?? 0), (int)($period['month'] ?? 0));
            $dueDate      = $period['due_date']   ?? '';
            $dueStatus    = $period['due_status'] ?? 'pending';
            $sections     = $analysis['sections'] ?? [];
            $recap        = $analysis['recap']    ?? ['rows'=>[], 'total'=>['principal'=>0,'cac'=>0,'penalites'=>0,'total'=>0]];
            $totalAPayer  = (float) ($analysis['total_a_payer'] ?? 0);
            $months       = $analysis['available_months'] ?? [];
            $statusBadge  = match ($dueStatus) {
                'past_due' => ['Past due', 'rose-700', 'rose-100', 'rose-300'],
                'pending'  => ['Pending',  'amber-700','amber-100','amber-300'],
                'future'   => ['Future',   'slate-600','slate-100','slate-300'],
                default    => ['—',        'slate-600','slate-100','slate-300'],
            };
            $fmt = fn ($n) => number_format((float) $n, 0, '.', ',');
            $hasDeclarantInfo = !empty($declarant['niu']) || !empty($declarant['raison_sociale']);
        @endphp

        {{-- ─────────── Month filter + filing window + download ─────────── --}}
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5 mb-4">
            <form method="GET" action="{{ tenant_url('/monthly-tax-return') }}" class="flex flex-wrap items-end gap-4">
                <div class="flex-1 min-w-[220px]">
                    <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1.5">Period · Month</label>
                    <select name="month" onchange="this.form.submit()"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                        @foreach ($months as $opt)
                            <option value="{{ $opt['value'] }}" {{ $monthValue === $opt['value'] ? 'selected' : '' }}>{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1.5">Filing window</div>
                    <div class="text-sm text-slate-900 tabular-nums">
                        Due <span class="font-medium">{{ $dueDate }}</span>
                        <span class="ml-2 inline-flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-widest text-{{ $statusBadge[1] }} bg-{{ $statusBadge[2] }} border border-{{ $statusBadge[3] }} rounded-full px-2 py-0.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                            {{ $statusBadge[0] }}
                        </span>
                    </div>
                    <div class="text-[11px] text-slate-500 mt-0.5">15th of M+1 · DGI portal</div>
                </div>
                <div>
                    <a href="{{ tenant_url('/monthly-tax-return/print') . ($monthValue ? '?month=' . urlencode($monthValue) : '') }}"
                       target="_blank" rel="noopener"
                       class="inline-flex items-center gap-2 bg-slate-900 hover:bg-slate-800 text-white text-sm font-medium px-4 py-2.5 rounded-lg shadow-sm transition">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17V7a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                        Download declaration
                    </a>
                </div>
                <noscript>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">Apply</button>
                </noscript>
            </form>
        </div>

        {{-- ─────────── Declarant header (entity info) ─────────── --}}
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5 mb-4">
            <div class="flex items-baseline justify-between mb-3">
                <h3 class="text-sm font-semibold text-slate-900">Declarant Information</h3>
                @if (!$hasDeclarantInfo)
                    <a href="{{ tenant_url('/settings') }}#tax_declarant" class="text-[11px] text-indigo-700 underline hover:no-underline">Configure in Settings →</a>
                @else
                    <a href="{{ tenant_url('/settings') }}" class="text-[11px] text-slate-500 underline hover:text-slate-700">Edit in Settings</a>
                @endif
            </div>
            @if (!$hasDeclarantInfo)
                <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-3 text-[12px]">
                    Declarant information (NIU, raison sociale, address, regime) is not yet configured. The printable declaration will leave those fields blank.
                </div>
            @else
                <div class="grid grid-cols-2 md:grid-cols-4 gap-x-6 gap-y-2 text-[12.5px]">
                    <div><span class="text-slate-500">NIU:</span> <span class="font-mono font-medium">{{ $declarant['niu'] ?: '—' }}</span></div>
                    <div><span class="text-slate-500">Régime:</span> <span class="font-medium">{{ $declarant['regime'] === 'reel' ? 'Réel' : ($declarant['regime'] === 'simplifie' ? 'Simplifié' : '—') }}</span></div>
                    <div><span class="text-slate-500">Exercice:</span> <span class="tabular-nums font-medium">{{ $declarant['exercice'] }}</span></div>
                    <div><span class="text-slate-500">Mois:</span> <span class="font-medium">{{ $declarant['mois_fr'] }}</span></div>
                    <div class="md:col-span-2"><span class="text-slate-500">Raison sociale:</span> <span class="font-medium">{{ $declarant['raison_sociale'] ?: '—' }}</span> @if($declarant['sigle']) <span class="text-slate-500">· Sigle:</span> <span class="font-medium">{{ $declarant['sigle'] }}</span> @endif</div>
                    <div><span class="text-slate-500">Centre:</span> <span class="font-medium">{{ trim(($declarant['dge_cri'] ?: '') . ' / ' . ($declarant['cdi_csi_cime'] ?: ''), ' /') ?: '—' }}</span></div>
                    <div><span class="text-slate-500">Ville:</span> <span class="font-medium">{{ $declarant['ville'] ?: '—' }}</span></div>
                </div>
            @endif
        </div>

        {{-- ─────────── Total à payer headline card ─────────── --}}
        <div class="bg-gradient-to-br from-slate-900 to-slate-800 text-white rounded-xl shadow-lg p-6 mb-4">
            <div class="flex items-baseline justify-between flex-wrap gap-4">
                <div>
                    <p class="text-[10px] uppercase tracking-widest text-slate-300 font-semibold">Total à payer · {{ $monthLabel }}</p>
                    <p class="text-[11px] text-slate-400 mt-0.5">Sum across all sections (Principal + CAC + Pénalités)</p>
                </div>
                <div class="text-right">
                    <p class="text-3xl font-bold tabular-nums">
                        {{ $fmt($totalAPayer) }}
                        <span class="text-base font-semibold text-slate-300">{{ $cur }}</span>
                    </p>
                </div>
            </div>
        </div>

        {{-- ─────────── Sections — collapsible accordion ─────────── --}}
        <div class="space-y-2 mb-4">
            @foreach ($sections as $sec)
                @php
                    $sCode  = (string) ($sec['code']  ?? '');
                    $sId    = (string) ($sec['id']    ?? '');
                    $sTitle = (string) ($sec['title'] ?? '');
                    $sSub   = (string) ($sec['subtitle'] ?? '');
                    $sTotal = (float)  ($sec['total'] ?? 0);
                    $sLayout = (string) ($sec['layout'] ?? 'full');
                    $lines  = $sec['lines'] ?? [];
                @endphp
                <details class="group bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
                    <summary class="cursor-pointer select-none px-5 py-4 flex items-center gap-4 hover:bg-slate-50/60 transition list-none">
                        <svg class="w-4 h-4 shrink-0 text-slate-400 group-open:rotate-90 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/>
                        </svg>
                        <span class="font-mono text-[10px] tracking-widest text-slate-400 w-8 shrink-0">§{{ $sCode }}</span>
                        <div class="flex-1 min-w-0">
                            <h3 class="text-[13px] font-semibold uppercase tracking-wide text-slate-900 truncate" title="{{ $sTitle }}">{{ $sTitle }}</h3>
                            @if ($sSub) <p class="text-[11px] text-slate-500 truncate mt-0.5">{{ $sSub }}</p> @endif
                        </div>
                        <div class="text-right tabular-nums shrink-0">
                            <span class="text-base font-bold text-slate-900">{{ $fmt($sTotal) }}</span>
                            <span class="text-[11px] font-semibold text-slate-500 ml-1">{{ $cur }}</span>
                        </div>
                    </summary>

                    <div class="border-t border-slate-200 bg-slate-50/40 overflow-x-auto">
                        @if ($sLayout === 'ca')
                            {{-- Form §2 layout: Code · Libellé · CA · Taux · Montant de la taxe --}}
                            <table class="w-full text-[12.5px]">
                                <thead>
                                    <tr class="text-left text-[10px] text-slate-500 uppercase tracking-wider bg-slate-50">
                                        <th class="px-4 py-2.5 font-semibold w-16">Code</th>
                                        <th class="px-3 py-2.5 font-semibold">Libellé</th>
                                        <th class="px-3 py-2.5 font-semibold w-32 text-right">CA</th>
                                        <th class="px-3 py-2.5 font-semibold w-20 text-center">Taux</th>
                                        <th class="px-4 py-2.5 font-semibold w-36 text-right">Montant de la taxe ({{ $cur }})</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($lines as $ln)
                                        @php $isTotal = str_starts_with(strtoupper((string) ($ln['label'] ?? '')), 'TOTAL') || str_contains((string) ($ln['code'] ?? ''), '15'); @endphp
                                        <tr class="border-t border-slate-200 {{ $isTotal ? 'bg-slate-100 font-semibold' : 'bg-white' }}">
                                            <td class="px-4 py-2 font-mono text-[11px] {{ $isTotal ? 'text-slate-900' : 'text-slate-500' }}">{{ $ln['code'] }}</td>
                                            <td class="px-3 py-2 text-slate-700">{{ $ln['label'] }}</td>
                                            <td class="px-3 py-2 text-right tabular-nums {{ $isTotal ? 'text-slate-900' : 'text-slate-700' }}">{{ $ln['base'] > 0 ? $fmt($ln['base']) : '—' }}</td>
                                            <td class="px-3 py-2 text-center text-[11px] text-slate-600 tabular-nums">{{ $ln['rate'] ?: '—' }}</td>
                                            <td class="px-4 py-2 text-right tabular-nums {{ $isTotal ? 'text-slate-900 font-bold' : 'text-slate-800' }}">{{ $ln['total'] > 0 ? $fmt($ln['total']) : '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @elseif ($sLayout === 'amount')
                            {{-- Form §5 layout: Code · Libellé · Montant — single amount column --}}
                            <table class="w-full text-[12.5px]">
                                <thead>
                                    <tr class="text-left text-[10px] text-slate-500 uppercase tracking-wider bg-slate-50">
                                        <th class="px-4 py-2.5 font-semibold w-16">Code</th>
                                        <th class="px-3 py-2.5 font-semibold">Libellé</th>
                                        <th class="px-4 py-2.5 font-semibold w-44 text-right">Montant ({{ $cur }})</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($lines as $ln)
                                        @php
                                            $code = (string) ($ln['code'] ?? '');
                                            $isTotal = in_array($code, ['L32', 'L36', 'L39', 'L44', 'L49', 'L62', 'L66', 'L73', 'L77', 'L80'], true)
                                                       || str_starts_with(strtoupper((string) ($ln['label'] ?? '')), 'TOTAL');
                                        @endphp
                                        <tr class="border-t border-slate-200 {{ $isTotal ? 'bg-slate-100 font-semibold' : 'bg-white' }}">
                                            <td class="px-4 py-2 font-mono text-[11px] {{ $isTotal ? 'text-slate-900' : 'text-slate-500' }}">{{ $code }}</td>
                                            <td class="px-3 py-2 text-slate-700">{{ $ln['label'] }}</td>
                                            <td class="px-4 py-2 text-right tabular-nums {{ $isTotal ? 'text-slate-900 font-bold' : 'text-slate-800' }}">{{ $fmt($ln['principal']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @else
                            {{-- Default 8-column layout: Code · Libellé · Base · Taux · Principal · CAC · Pénalités · Total --}}
                            <table class="w-full text-[12.5px]">
                                <thead>
                                    <tr class="text-left text-[10px] text-slate-500 uppercase tracking-wider bg-slate-50">
                                        <th class="px-4 py-2.5 font-semibold w-16">Code</th>
                                        <th class="px-3 py-2.5 font-semibold">Libellé</th>
                                        <th class="px-3 py-2.5 font-semibold w-28 text-right">Base</th>
                                        <th class="px-3 py-2.5 font-semibold w-20 text-center">Taux</th>
                                        <th class="px-3 py-2.5 font-semibold w-28 text-right">Principal</th>
                                        <th class="px-3 py-2.5 font-semibold w-20 text-right">CAC</th>
                                        <th class="px-3 py-2.5 font-semibold w-20 text-right">Pénalités</th>
                                        <th class="px-4 py-2.5 font-semibold w-28 text-right">Total ({{ $cur }})</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($lines as $ln)
                                        @php $isTotal = str_starts_with(strtoupper((string) ($ln['label'] ?? '')), 'TOTAL'); @endphp
                                        <tr class="border-t border-slate-200 {{ $isTotal ? 'bg-slate-100 font-semibold' : 'bg-white' }}">
                                            <td class="px-4 py-2 font-mono text-[11px] {{ $isTotal ? 'text-slate-900' : 'text-slate-500' }}">{{ $ln['code'] }}</td>
                                            <td class="px-3 py-2 text-slate-700">{{ $ln['label'] }}</td>
                                            <td class="px-3 py-2 text-right tabular-nums text-slate-600">{{ $ln['base'] > 0 ? $fmt($ln['base']) : '—' }}</td>
                                            <td class="px-3 py-2 text-center text-[11px] text-slate-600 tabular-nums">{{ $ln['rate'] ?: '—' }}</td>
                                            <td class="px-3 py-2 text-right tabular-nums {{ $isTotal ? 'text-slate-900' : 'text-slate-700' }}">{{ $fmt($ln['principal']) }}</td>
                                            <td class="px-3 py-2 text-right tabular-nums text-slate-600">{{ $ln['cac'] > 0 ? $fmt($ln['cac']) : '—' }}</td>
                                            <td class="px-3 py-2 text-right tabular-nums text-slate-600">{{ $ln['penalites'] > 0 ? $fmt($ln['penalites']) : '—' }}</td>
                                            <td class="px-4 py-2 text-right tabular-nums {{ $isTotal ? 'text-slate-900 font-bold' : 'text-slate-800' }}">{{ $fmt($ln['total']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </details>
            @endforeach
        </div>

        {{-- ─────────── Recap ─────────── --}}
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden mb-4">
            <div class="px-5 py-4 border-b border-slate-200 bg-slate-50">
                <h3 class="text-sm font-semibold text-slate-900">TOTAL RÉCAPITULATIF</h3>
                <p class="text-[11px] text-slate-500 mt-0.5">Synthèse des taxes dues par catégorie</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-[12.5px]">
                    <thead>
                        <tr class="text-left text-[10px] text-slate-500 uppercase tracking-wider border-b border-slate-200 bg-slate-50">
                            <th class="px-5 py-3 font-semibold">Catégorie</th>
                            <th class="px-3 py-3 font-semibold text-right w-28">Principal</th>
                            <th class="px-3 py-3 font-semibold text-right w-20">CAC</th>
                            <th class="px-3 py-3 font-semibold text-right w-24">Pénalités</th>
                            <th class="px-5 py-3 font-semibold text-right w-32">Total ({{ $cur }})</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recap['rows'] as $r)
                            <tr class="border-b border-slate-100 hover:bg-slate-50/40">
                                <td class="px-5 py-2.5 text-slate-700">{{ $r['label'] }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-slate-700">{{ $fmt($r['principal']) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-slate-600">{{ $fmt($r['cac']) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-slate-600">{{ $fmt($r['penalites']) }}</td>
                                <td class="px-5 py-2.5 text-right tabular-nums font-semibold text-slate-900">{{ $fmt($r['total']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-slate-700 bg-slate-50 font-bold">
                            <td class="px-5 py-3 text-slate-900 uppercase tracking-wider text-[11px]">Total à payer</td>
                            <td class="px-3 py-3 text-right tabular-nums text-slate-900">{{ $fmt($recap['total']['principal']) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-slate-900">{{ $fmt($recap['total']['cac']) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-slate-900">{{ $fmt($recap['total']['penalites']) }}</td>
                            <td class="px-5 py-3 text-right tabular-nums text-slate-900 text-base">{{ $fmt($recap['total']['total']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        {{-- ─────────── Methodology ─────────── --}}
        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm text-[12px] text-slate-600 leading-relaxed">
            <strong class="text-slate-900">Methodology.</strong>
            14 sections following the official Cameroon DGI form I/TVA-IR with line codes L0–L80.
            Where SYSCOHADA accounts map unambiguously, posted move lines for the selected month
            are auto-summed (TVA from <code class="text-[11px] bg-slate-100 px-1 py-0.5 rounded">4431/4452/4456</code>,
            sales from class 70, IRPP salaires from <code class="text-[11px] bg-slate-100 px-1 py-0.5 rounded">4421</code>,
            précompte loyers from <code class="text-[11px] bg-slate-100 px-1 py-0.5 rounded">4426</code>,
            TSR pool from <code class="text-[11px] bg-slate-100 px-1 py-0.5 rounded">4423</code>).
            Other lines remain at zero so the principal can review and complete before filing.
            CAC is computed as 10 % of the principal on direct-tax lines.
            Use <strong>Download declaration</strong> to open a print-friendly replica that you can save as PDF and submit at the DGI portal.
        </div>
    @endif
@endsection
