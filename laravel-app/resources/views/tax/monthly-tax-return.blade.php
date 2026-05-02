@extends('layouts.app')
@section('title', 'Monthly Tax Return')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Monthly Tax Return</h1>
            <p class="text-xs text-slate-500 mt-1">DGI portal monthly declaration · all withholdings, advance payments, and indirect taxes due in the period</p>
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
            $categories   = $analysis['categories']   ?? [];
            $totalDue     = (float) ($analysis['total_due']  ?? 0);
            $months       = $analysis['available_months'] ?? [];
            $statusBadge  = match ($dueStatus) {
                'past_due' => ['Past due', 'rose-700', 'rose-100', 'rose-300'],
                'pending'  => ['Pending',  'amber-700','amber-100','amber-300'],
                'future'   => ['Future',   'slate-600','slate-100','slate-300'],
                default    => ['—',        'slate-600','slate-100','slate-300'],
            };
            $fmt = fn ($n) => number_format((float) $n, 0, '.', ',');
        @endphp

        {{-- ─────────── Month filter + filing window header ─────────── --}}
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
                    <div class="text-[11px] text-slate-500 mt-0.5">15th of M+1 · DGI portal monthly declaration</div>
                </div>
                <noscript>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">Apply</button>
                </noscript>
            </form>
        </div>

        {{-- ─────────── Total due headline card ─────────── --}}
        <div class="bg-gradient-to-br from-slate-900 to-slate-800 text-white rounded-xl shadow-lg p-6 mb-4">
            <div class="flex items-baseline justify-between flex-wrap gap-4">
                <div>
                    <p class="text-[10px] uppercase tracking-widest text-slate-300 font-semibold">Total tax due · {{ $monthLabel }}</p>
                    <p class="text-[11px] text-slate-400 mt-0.5">Sum of all {{ count($categories) }} categories declared this month</p>
                </div>
                <div class="text-right">
                    <p class="text-3xl font-bold tabular-nums">
                        {{ $fmt($totalDue) }}
                        <span class="text-base font-semibold text-slate-300">{{ $cur }}</span>
                    </p>
                </div>
            </div>
        </div>

        {{-- ─────────── Categories — collapsible accordion ─────────── --}}
        <div class="space-y-2 mb-4">
            @foreach ($categories as $idx => $cat)
                @php
                    $catId    = (string) ($cat['id']    ?? ('cat-'.$idx));
                    $catCode  = (string) ($cat['code']  ?? '');
                    $catLabel = (string) ($cat['label'] ?? '');
                    $catTotal = (float)  ($cat['total'] ?? 0);
                    $details  = $cat['details'] ?? [];
                    $note     = (string) ($cat['note']  ?? '');
                @endphp
                <details class="group bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
                    {{-- Header row (always visible): caret · code badge · label · total ---}}
                    <summary class="cursor-pointer select-none px-5 py-4 flex items-center gap-4 hover:bg-slate-50/60 transition list-none">
                        {{-- Caret --}}
                        <svg class="w-4 h-4 shrink-0 text-slate-400 group-open:rotate-90 transition-transform"
                             viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/>
                        </svg>
                        {{-- Index number --}}
                        <span class="font-mono text-[10px] tracking-widest text-slate-400 w-6 shrink-0">{{ str_pad((string)($idx + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        {{-- Code badge --}}
                        @if ($catCode !== '')
                            <span class="font-mono text-[10px] uppercase tracking-widest font-semibold text-indigo-700 bg-indigo-50 border border-indigo-200 rounded-md px-2 py-0.5 shrink-0">{{ $catCode }}</span>
                        @endif
                        {{-- Label (uppercase, kept legible) --}}
                        <h3 class="flex-1 min-w-0 text-[13px] font-semibold uppercase tracking-wide text-slate-900 truncate" title="{{ $catLabel }}">
                            {{ $catLabel }}
                        </h3>
                        {{-- Total --}}
                        <div class="text-right tabular-nums shrink-0">
                            <span class="text-base font-bold text-slate-900">{{ $fmt($catTotal) }}</span>
                            <span class="text-[11px] font-semibold text-slate-500 ml-1">{{ $cur }}</span>
                        </div>
                    </summary>

                    {{-- Body (revealed on open): detail rows + note ---}}
                    <div class="border-t border-slate-200 bg-slate-50/40">
                        @if (!empty($details))
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="text-left text-[10px] text-slate-500 uppercase tracking-wider bg-slate-50">
                                            <th class="px-5 py-2.5 font-semibold w-10">#</th>
                                            <th class="px-3 py-2.5 font-semibold">Line item</th>
                                            <th class="px-3 py-2.5 font-semibold w-24">Account</th>
                                            <th class="px-3 py-2.5 font-semibold w-20 text-center">Rate</th>
                                            <th class="px-5 py-2.5 font-semibold text-right w-44">Amount ({{ $cur }})</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($details as $i => $row)
                                            @php
                                                $rowLabel   = (string) ($row['label']   ?? '');
                                                $rowAmt     = (float)  ($row['amount']  ?? 0);
                                                $rowAcc     = (string) ($row['account'] ?? '');
                                                $rowRate    = (string) ($row['rate']    ?? '');
                                                $rowSign    = (string) ($row['sign']    ?? '');
                                                $rowNote    = (string) ($row['note']    ?? '');
                                                $isSubtotal = $rowSign === 'subtotal';
                                                $isMemo     = $rowSign === 'memo';
                                                $isDeduct   = $rowSign === 'deduction';
                                            @endphp
                                            <tr class="border-t border-slate-200 {{ $isSubtotal ? 'bg-slate-100 font-semibold' : ($isMemo ? 'bg-slate-50/50 italic text-slate-600' : 'bg-white') }}">
                                                <td class="px-5 py-2.5 font-mono text-[10px] text-slate-400">{{ str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) }}</td>
                                                <td class="px-3 py-2.5 text-slate-700">
                                                    {{ $rowLabel }}
                                                    @if ($rowNote)
                                                        <span class="block text-[10px] text-slate-400 mt-0.5">{{ $rowNote }}</span>
                                                    @endif
                                                </td>
                                                <td class="px-3 py-2.5 font-mono text-[11px] text-slate-500">{{ $rowAcc ?: '—' }}</td>
                                                <td class="px-3 py-2.5 text-center text-[11px] text-slate-600 tabular-nums">{{ $rowRate ?: '—' }}</td>
                                                <td class="px-5 py-2.5 text-right tabular-nums {{ $isSubtotal ? 'text-slate-900 font-bold' : ($isMemo ? 'text-slate-500' : 'text-slate-800') }}">
                                                    @if ($isDeduct && $rowAmt != 0)
                                                        −{{ $fmt($rowAmt) }}
                                                    @else
                                                        {{ $fmt($rowAmt) }}
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="px-5 py-4 text-[12px] text-slate-500 italic">No detail lines for this category.</div>
                        @endif

                        @if ($note !== '')
                            <div class="px-5 py-3 border-t border-slate-200 bg-white text-[11.5px] text-slate-600 leading-relaxed">
                                <span class="font-semibold text-slate-700">Note.</span> {{ $note }}
                            </div>
                        @endif
                    </div>
                </details>
            @endforeach
        </div>

        {{-- ─────────── Methodology ─────────── --}}
        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm text-[12px] text-slate-600 leading-relaxed">
            <strong class="text-slate-900">Methodology.</strong>
            Posted move lines in the selected calendar month, mapped to DGI categories per Cameroon
            <a href="https://www.impots.cm" target="_blank" rel="noopener" class="underline decoration-slate-300 hover:decoration-slate-700">CGI</a>
            and the SYSCOHADA AUDCIF chart of accounts:
            <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">4421-4426</code> retenues IRPP,
            <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">4423</code> TSR,
            <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">4431/4452/4456</code> TVA,
            <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">4441-4442</code> IS,
            <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">4471</code> CAC,
            <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">4475</code> droits d'enregistrement,
            <code class="text-[11px] bg-slate-100 px-1.5 py-0.5 rounded">4477</code> autres taxes.
            Each section opens to reveal the rate-band or sub-account split. Filing reference: monthly DGI declaration, due 15th of M+1.
        </div>
    @endif
@endsection
