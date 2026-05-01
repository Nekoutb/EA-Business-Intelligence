@extends('layouts.app')
@section('title', 'OHADA Financial Statements')

@section('header')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="text-[10px] uppercase tracking-[0.35em] text-[#8a8578] font-medium">{{ $data['period_label'] ?? '—' }} · {{ ea_last_sync_label() }}</span>
            </div>
            <h1 class="text-2xl font-bold text-[#1e3d44]">OHADA Financial Statements · Système Normal</h1>
            <div class="text-[11px] text-[#7c7466] mt-1">
                Bilan + Compte de Résultat in the OHADA Système Normal layout. Pulled from the Trial Balance, mapped via SYSCOHADA code-prefix conventions.
            </div>
        </div>
        <form method="get" class="flex items-end gap-2 flex-wrap">
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[#8a8578] font-medium mb-1">Period</label>
                <select name="period_type" class="text-xs border border-[#e8e4de] rounded-lg px-3 py-2 bg-white text-[#1e3d44] focus:ring-2 focus:ring-brand-300 focus:border-brand-300 outline-none" onchange="this.form.submit()">
                    <option value="year"  {{ $periodType === 'year'  ? 'selected' : '' }}>Full Year</option>
                    <option value="ytd"   {{ $periodType === 'ytd'   ? 'selected' : '' }}>YTD</option>
                    <option value="month" {{ $periodType === 'month' ? 'selected' : '' }}>Single Month</option>
                </select>
            </div>
            @if ($periodType !== 'year')
                <div>
                    <label class="block text-[10px] uppercase tracking-wider text-[#8a8578] font-medium mb-1">Month</label>
                    <select name="month" class="text-xs border border-[#e8e4de] rounded-lg px-3 py-2 bg-white text-[#1e3d44] focus:ring-2 focus:ring-brand-300 focus:border-brand-300 outline-none" onchange="this.form.submit()">
                        @foreach (['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as $i => $name)
                            <option value="{{ $i + 1 }}" {{ ($i + 1) === $month ? 'selected' : '' }}>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div>
                <label class="block text-[10px] uppercase tracking-wider text-[#8a8578] font-medium mb-1">Year</label>
                <select name="year" class="text-xs border border-[#e8e4de] rounded-lg px-3 py-2 bg-white text-[#1e3d44] focus:ring-2 focus:ring-brand-300 focus:border-brand-300 outline-none" onchange="this.form.submit()">
                    @php $thisYear = (int) date('Y'); @endphp
                    @for ($y = $thisYear + 1; $y >= $thisYear - 5; $y--)
                        <option value="{{ $y }}" {{ $y === $year ? 'selected' : '' }}>{{ $y }}</option>
                    @endfor
                </select>
            </div>
        </form>
    </div>
@endsection

@section('content')
    @if ($error)
        <div class="bg-coral-50 border border-coral-200 text-coral-800 rounded-xl p-4 text-sm mb-6"><strong>Error:</strong> {{ $error }}</div>
    @endif

    @if (!$data)
        <div class="bg-warm-50 border border-warm-200 rounded-xl p-5"><div class="text-sm font-semibold text-[#7a5a1a]">No data</div></div>
    @else
        @php
            $fmt = function ($v) {
                if ($v === null) return '—';
                $v = (float) $v;
                if (abs($v) < 0.5) return '—';
                $abs = number_format(abs($v), 0);
                return $v < 0 ? '(' . $abs . ')' : $abs;
            };
            $totals = $data['totals'];
            $cdr    = $data['compte_de_resultat'];
        @endphp

        <div class="flex items-center justify-between gap-3 mb-4 flex-wrap">
            <div class="text-[11px] text-[#7c7466]">
                Period: <strong class="text-[#1e3d44]">{{ $data['period_start'] }} → {{ $data['period_end'] }}</strong> · Figures in <strong>XAF</strong>
            </div>
            <div class="text-[11px]">
                Bilan equilibrium:
                <span class="font-semibold {{ $totals['balance'] ? 'text-sage-700' : 'text-coral-700' }}">
                    Actif {{ $fmt($totals['actif']) }} {{ $totals['balance'] ? '✓=' : '≠' }} Passif {{ $fmt($totals['passif']) }}
                </span>
            </div>
        </div>

        {{-- BILAN — Actif & Passif side-by-side --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
            @foreach ([
                ['title' => 'BILAN — ACTIF',  'data' => $data['bilan_actif'],  'total' => $totals['actif']],
                ['title' => 'BILAN — PASSIF', 'data' => $data['bilan_passif'], 'total' => $totals['passif']],
            ] as $panel)
                <div class="bg-white border border-[#e8e4de] rounded-xl overflow-hidden">
                    <div class="bg-[#1e3d44] text-white px-4 py-2.5 text-sm font-bold">{{ $panel['title'] }}</div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-[11.5px]">
                            <thead class="border-b border-[#cbc7be] bg-[#fcfbf8]">
                                <tr>
                                    <th class="text-left py-2 pl-4 pr-2 text-[10px] font-semibold text-[#5a6a70]" style="width:3rem;">Réf</th>
                                    <th class="text-left py-2 px-2 text-[10px] font-semibold text-[#5a6a70]">Libellé</th>
                                    <th class="text-right py-2 px-2 text-[10px] font-semibold text-[#5a6a70]" style="width:7rem;">Montant</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#f1ede5]">
                                @foreach ($panel['data']['sections'] as $section)
                                    <tr class="bg-[#f4efe6] border-y border-[#cbc7be]">
                                        <td colspan="3" class="py-1.5 pl-4 pr-2 text-[10.5px] font-bold text-[#1e3d44] uppercase tracking-wider">{{ $section['title'] }}</td>
                                    </tr>
                                    @foreach ($section['lines'] as $line)
                                        <tr class="hover:bg-[#faf8f5]">
                                            <td class="py-1 pl-4 pr-2 font-mono text-[10px] text-[#a09d95]">{{ $line['code'] }}</td>
                                            <td class="py-1 px-2 text-[#2d3748]">{{ $line['label'] }}</td>
                                            <td class="py-1 px-2 text-right tabular-nums {{ ($line['amount'] ?? 0) > 0.5 ? 'text-[#2d3748]' : 'text-[#a09d95]' }}">{{ $fmt($line['amount'] ?? 0) }}</td>
                                        </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="bg-[#1e3d44] text-white border-t-2 border-[#1e3d44]">
                                    <td colspan="2" class="py-2 pl-4 pr-2 font-bold">TOTAL</td>
                                    <td class="py-2 px-2 text-right tabular-nums font-bold">{{ $fmt($panel['total']) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- COMPTE DE RÉSULTAT --}}
        <div class="bg-white border border-[#e8e4de] rounded-xl overflow-hidden">
            <div class="bg-[#1e3d44] text-white px-4 py-2.5 text-sm font-bold">COMPTE DE RÉSULTAT</div>
            <div class="overflow-x-auto">
                <table class="w-full text-[11.5px]">
                    <thead class="border-b border-[#cbc7be] bg-[#fcfbf8]">
                        <tr>
                            <th class="text-left py-2 pl-4 pr-2 text-[10px] font-semibold text-[#5a6a70]" style="width:4rem;">Réf</th>
                            <th class="text-left py-2 px-2 text-[10px] font-semibold text-[#5a6a70]">Libellé</th>
                            <th class="text-right py-2 px-2 text-[10px] font-semibold text-[#5a6a70]" style="width:8rem;">Montant ({{ $year }})</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#f1ede5]">
                        @foreach ($cdr['rows'] as $row)
                            @if ($row['kind'] === 'subtotal')
                                <tr class="bg-[#fcfbf8] border-y border-[#cbc7be]">
                                    <td class="py-1.5 pl-4 pr-2 font-mono text-[10.5px] font-bold text-[#1e3d44]">{{ $row['code'] }}</td>
                                    <td class="py-1.5 px-2 font-bold text-[#1e3d44] uppercase tracking-wider text-[10.5px]">{{ $row['label'] }}</td>
                                    <td class="py-1.5 px-2 text-right tabular-nums font-bold text-[#1e3d44]">{{ $fmt($row['amount']) }}</td>
                                </tr>
                            @else
                                <tr class="hover:bg-[#faf8f5]">
                                    <td class="py-1 pl-4 pr-2 font-mono text-[10px] text-[#a09d95]">{{ $row['code'] }}</td>
                                    <td class="py-1 px-2 text-[#2d3748] {{ ($row['side'] ?? '') === 'expense' ? 'text-[#5a6a70]' : '' }}">
                                        @if (($row['side'] ?? '') === 'expense')
                                            <span class="text-[#a09d95]">−</span>
                                        @endif
                                        {{ $row['label'] }}
                                    </td>
                                    <td class="py-1 px-2 text-right tabular-nums {{ abs($row['amount']) > 0.5 ? 'text-[#2d3748]' : 'text-[#a09d95]' }}">{{ $fmt($row['amount']) }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- TABLEAU DES FLUX DE TRESORERIE --}}
        @if (!empty($data['tresorerie']))
            <div class="bg-white border border-[#e8e4de] rounded-xl overflow-hidden mt-6">
                <div class="bg-[#1e3d44] text-white px-4 py-2.5 text-sm font-bold">TABLEAU DES FLUX DE TRESORERIE</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-[11.5px]">
                        <thead class="border-b border-[#cbc7be] bg-[#fcfbf8]">
                            <tr>
                                <th class="text-left py-2 pl-4 pr-2 text-[10px] font-semibold text-[#5a6a70]" style="width:4rem;">Réf</th>
                                <th class="text-left py-2 px-2 text-[10px] font-semibold text-[#5a6a70]">Libellés</th>
                                <th class="text-right py-2 px-2 text-[10px] font-semibold text-[#5a6a70]" style="width:8rem;">Exercice {{ $year }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#f1ede5]">
                            @foreach ($data['tresorerie']['rows'] as $row)
                                @if ($row['kind'] === 'group')
                                    <tr class="bg-[#f4efe6] border-y border-[#cbc7be]">
                                        <td colspan="3" class="py-1.5 pl-4 pr-2 text-[10.5px] font-bold text-[#1e3d44] uppercase tracking-wider italic">{{ $row['label'] }}</td>
                                    </tr>
                                @elseif ($row['kind'] === 'subtotal')
                                    <tr class="bg-[#fcfbf8] border-y border-[#cbc7be]">
                                        <td class="py-1.5 pl-4 pr-2 font-mono text-[10.5px] font-bold text-[#1e3d44]">{{ $row['code'] }}</td>
                                        <td class="py-1.5 px-2 font-bold text-[#1e3d44] text-[11px]">{{ $row['label'] }}</td>
                                        <td class="py-1.5 px-2 text-right tabular-nums font-bold text-[#1e3d44]">{{ $fmt($row['amount']) }}</td>
                                    </tr>
                                @elseif ($row['kind'] === 'total')
                                    <tr class="bg-[#1e3d44] text-white">
                                        <td class="py-2 pl-4 pr-2 font-mono text-[11px] font-bold">{{ $row['code'] }}</td>
                                        <td class="py-2 px-2 font-bold uppercase tracking-wider">{{ $row['label'] }}</td>
                                        <td class="py-2 px-2 text-right tabular-nums font-bold">{{ $row['amount'] !== null ? $fmt($row['amount']) : '—' }}</td>
                                    </tr>
                                @elseif ($row['kind'] === 'header')
                                    <tr class="hover:bg-[#faf8f5]">
                                        <td class="py-1 pl-4 pr-2 font-mono text-[10px] text-[#a09d95]">{{ $row['code'] }}</td>
                                        <td class="py-1 px-2 italic text-[#5a6a70]">{{ $row['label'] }}</td>
                                        <td class="py-1 px-2 text-right tabular-nums text-[#a09d95]">{{ $row['amount'] !== null ? $fmt($row['amount']) : '—' }}</td>
                                    </tr>
                                @else
                                    <tr class="hover:bg-[#faf8f5]">
                                        <td class="py-1 pl-4 pr-2 font-mono text-[10px] text-[#a09d95]">{{ $row['code'] }}</td>
                                        <td class="py-1 px-2 text-[#2d3748]">{{ $row['label'] }}</td>
                                        <td class="py-1 px-2 text-right tabular-nums {{ abs($row['amount']) > 0.5 ? 'text-[#2d3748]' : 'text-[#a09d95]' }}">{{ $fmt($row['amount']) }}</td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if (!empty($data['tresorerie']['note']))
                    <div class="px-4 py-2 border-t border-[#e8e4de] bg-[#faf8f5] text-[10px] text-[#7c7466] italic">{{ $data['tresorerie']['note'] }}</div>
                @endif
            </div>
        @endif

        <div class="bg-white border border-[#e8e4de] rounded-xl p-4 mt-4 text-[10.5px] text-[#7c7466] leading-relaxed">
            <strong class="text-[#1e3d44]">Méthodologie:</strong>
            Bilan = soldes de fin (Trial Balance closing_dr − closing_cr) mappés sur les lignes du Système Normal AUDCIF (pages 980-982 / 1049). Sections: Immobilisations Incorporelles (AE-AH) · Corporelles (AJ-AN) · Avances (AP) · Financières (AR-AS) · Circulant (BA-BJ) · Trésorerie Actif (BQ-BS) côté Actif ; Capitaux Propres (CA-CM) · Dettes Financières (DA-DC) · Passif Circulant (DH-DN) · Trésorerie Passif (DQ-DR) côté Passif.
            Compte de Résultat = mouvements de la période mappés sur les lignes TA-RS per page 986/1050 ; soldes intermédiaires de gestion (XA Marge Commerciale → XB CA → XC Valeur Ajoutée → XD EBE → XE Résultat d'Exploitation → XF Résultat Financier → XG Résultat AO → XH Résultat HAO → XI Résultat Net) calculés selon AUDCIF.
            TFT = méthode indirecte basée sur la CAFG selon page 997. Variations de BFR et flux d'investissement/financement nécessitent comparaison N/N-1 — itération suivante.
            Table de mapping centralisée dans <code class="text-[10px] bg-[#faf8f5] border border-[#ece7df] rounded px-1 py-0.5">app/Services/OhadaMappingService.php</code>.
        </div>
    @endif
@endsection
