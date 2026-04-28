@extends('layouts.app')
@section('title', 'Monthly VAT Return')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Monthly VAT Return</h1>
            <p class="text-xs text-slate-500 mt-1">DGI portal monthly declaration · Output VAT − Input VAT = net due to State</p>
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
            $monthLabel   = $period['label']      ?? '—';
            $monthValue   = sprintf('%04d-%02d', (int)($period['year'] ?? 0), (int)($period['month'] ?? 0));
            $dueDate      = $period['due_date']   ?? '';
            $dueStatus    = $period['due_status'] ?? 'pending';
            $output       = (float) ($analysis['output_vat']         ?? 0);
            $inputCapex   = (float) ($analysis['input_vat_capex']    ?? 0);
            $inputOpex    = (float) ($analysis['input_vat_opex']     ?? 0);
            $input        = (float) ($analysis['input_vat']          ?? 0);
            $netDue       = (float) ($analysis['net_vat_due']        ?? 0);
            $creditCarry  = (float) ($analysis['vat_credit_carry']   ?? 0);
            $salesBase    = (float) ($analysis['sales_taxable_base'] ?? 0);
            $breakdown    = $analysis['breakdown'] ?? [];
            $months       = $analysis['available_months'] ?? [];
            $statusBadge  = match ($dueStatus) {
                'past_due' => ['Past due', 'rose-700', 'rose-100', 'rose-300'],
                'pending'  => ['Pending',  'amber-700','amber-100','amber-300'],
                'future'   => ['Future',   'slate-600','slate-100','slate-300'],
                default    => ['—',        'slate-600','slate-100','slate-300'],
            };
        @endphp

        {{-- ─────────── Month filter + status header ─────────── --}}
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5 mb-4">
            <form method="GET" action="{{ tenant_url('/monthly-vat-return') }}" class="flex flex-wrap items-end gap-4">
                <div class="flex-1 min-w-[220px]">
                    <label class="block text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-1.5">Month</label>
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
                    <div class="text-[11px] text-slate-500 mt-0.5">15th of M+1 · DGI portal monthly declaration</div>
                </div>
                <noscript>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">Apply</button>
                </noscript>
            </form>
        </div>

        {{-- ─────────── Summary cards ─────────── --}}
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
            <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
                <p class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">VAT Collected · Output</p>
                <p class="text-[11px] text-slate-500 mt-0.5">Account 4431 (TVA facturée)</p>
                <p class="text-2xl font-bold text-slate-900 tabular-nums mt-2">
                    {{ number_format($output, 0, '.', ',') }}
                    <span class="text-sm font-semibold text-slate-500">{{ $cur }}</span>
                </p>
            </div>
            <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
                <p class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">VAT Deductible · Input</p>
                <p class="text-[11px] text-slate-500 mt-0.5">Accounts 4452 + 4456</p>
                <p class="text-2xl font-bold text-slate-900 tabular-nums mt-2">
                    {{ number_format($input, 0, '.', ',') }}
                    <span class="text-sm font-semibold text-slate-500">{{ $cur }}</span>
                </p>
                <p class="text-[10px] text-slate-500 mt-1 tabular-nums">
                    immo {{ number_format($inputCapex, 0, '.', ',') }} ·
                    autres B&amp;S {{ number_format($inputOpex, 0, '.', ',') }}
                </p>
            </div>
            <div class="bg-gradient-to-br {{ $netDue >= 0 ? 'from-rose-50 to-rose-100 border-rose-300' : 'from-emerald-50 to-emerald-100 border-emerald-300' }} border rounded-xl p-5 shadow-sm">
                <p class="text-[10px] uppercase tracking-widest font-semibold {{ $netDue >= 0 ? 'text-rose-700' : 'text-emerald-700' }}">
                    {{ $netDue >= 0 ? 'Net VAT Due (4433)' : 'VAT Credit (4434)' }}
                </p>
                <p class="text-[11px] mt-0.5 {{ $netDue >= 0 ? 'text-rose-700/70' : 'text-emerald-700/70' }}">Output − Input</p>
                <p class="text-2xl font-bold tabular-nums mt-2 {{ $netDue >= 0 ? 'text-rose-900' : 'text-emerald-900' }}">
                    {{ $netDue >= 0 ? '' : '+' }}{{ number_format(abs($netDue), 0, '.', ',') }}
                    <span class="text-sm font-semibold {{ $netDue >= 0 ? 'text-rose-700' : 'text-emerald-700' }}">{{ $cur }}</span>
                </p>
                @if ($creditCarry > 0)
                    <p class="text-[10px] mt-1 tabular-nums text-emerald-700">Carry-forward credit on file: {{ number_format($creditCarry, 0, '.', ',') }} {{ $cur }}</p>
                @endif
            </div>
            <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
                <p class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Sales · Taxable Base</p>
                <p class="text-[11px] text-slate-500 mt-0.5">Class 70 (excl. 709)</p>
                <p class="text-2xl font-bold text-slate-900 tabular-nums mt-2">
                    {{ number_format($salesBase, 0, '.', ',') }}
                    <span class="text-sm font-semibold text-slate-500">{{ $cur }}</span>
                </p>
                @if ($salesBase > 0)
                    <p class="text-[10px] text-slate-500 mt-1 tabular-nums">
                        effective rate: {{ number_format($salesBase > 0 ? ($output / $salesBase) * 100 : 0, 2) }} %
                    </p>
                @endif
            </div>
        </div>

        {{-- ─────────── Account-level breakdown ─────────── --}}
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden mb-4">
            <div class="px-5 py-4 border-b border-slate-200">
                <h3 class="text-sm font-semibold text-slate-900">Account-level breakdown · {{ $monthLabel }}</h3>
                <p class="text-[11px] text-slate-500 mt-0.5">Posted lines on each VAT account in the selected month</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[10px] text-slate-500 uppercase tracking-wider border-b border-slate-200 bg-slate-50">
                            <th class="px-4 py-3 font-semibold">Account</th>
                            <th class="px-4 py-3 font-semibold">Description</th>
                            <th class="px-4 py-3 font-semibold">Side</th>
                            <th class="px-4 py-3 text-right font-semibold">Amount ({{ $cur }})</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($breakdown as $row)
                            <tr class="border-b border-slate-100 hover:bg-slate-50/40">
                                <td class="px-4 py-2.5 font-mono text-slate-900">{{ $row['account'] }}</td>
                                <td class="px-4 py-2.5 text-slate-700">{{ $row['label'] }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="inline-flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-widest {{ $row['side'] === 'credit' ? 'text-rose-700 bg-rose-50 border-rose-200' : 'text-emerald-700 bg-emerald-50 border-emerald-200' }} border rounded-full px-2 py-0.5">
                                        {{ $row['side'] }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums font-semibold text-slate-900">{{ number_format((float)$row['amount'], 0, '.', ',') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-slate-700 bg-slate-50">
                            <td class="px-4 py-3 text-[10px] uppercase tracking-widest font-bold text-slate-700" colspan="2">Net VAT due (4433) = 4431 − (4452 + 4456)</td>
                            <td class="px-4 py-3"></td>
                            <td class="px-4 py-3 text-right tabular-nums font-bold {{ $netDue >= 0 ? 'text-rose-700' : 'text-emerald-700' }}">
                                {{ $netDue >= 0 ? '' : '+' }}{{ number_format(abs($netDue), 0, '.', ',') }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        {{-- ─────────── Methodology ─────────── --}}
        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm text-[12px] text-slate-600 leading-relaxed">
            <strong class="text-slate-900">Methodology.</strong>
            Posted move lines in the selected calendar month, per SYSCOHADA AUDCIF 2017 chart:
            <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">4431</code> (TVA facturée — output, credit-positive),
            <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">4452</code> (TVA déductible / immobilisations) and
            <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">4456</code> (TVA déductible / autres B&amp;S) for input,
            <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">4434</code> for any carry-forward credit.
            Sales taxable base = |Σ class 70 except 709|.
            Net VAT due posts to <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">4433</code> (TVA à décaisser).
            Filing reference: Cameroon CGI monthly VAT return, due 15th of the following month via DGI portal.
        </div>
    @endif
@endsection
