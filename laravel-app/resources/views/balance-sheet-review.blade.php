@extends('layouts.app')
@section('title', 'Balance Sheet Review')

@section('page-title', 'Balance Sheet Review · Bilan')
@section('content')
    @if ($error)
        <div class="bg-coral-50 border border-coral-200 text-coral-800 rounded-xl p-4 text-sm mb-6"><strong>Error:</strong> {{ $error }}</div>
    @endif

    @if (!$data)
        <div class="bg-warm-50 border border-warm-200 rounded-xl p-5">
            <div class="text-sm font-semibold text-[#7a5a1a]">No data</div>
        </div>
    @else
        @php
            $fmt = function ($v) {
                if ($v === null) return '—';
                $v = (float) $v;
                if (abs($v) < 0.5) return '—';
                return number_format($v, 0);
            };
            $totals = $data['totals'];
        @endphp

        {{-- Equilibrium check pill --}}
        <div class="flex items-center justify-between gap-3 mb-4 flex-wrap">
            <div class="text-[11px] text-[#7c7466]">
                Period: <strong class="text-[#1e3d44]">{{ $data['period_start'] }} → {{ $data['period_end'] }}</strong> ·
                Figures in <strong>XAF</strong> ·
                {{ $data['unmapped_count'] }} unmapped account(s)
            </div>
            <div class="text-[11px]">
                Bilan equilibrium:
                <span class="font-semibold {{ $totals['balance'] ? 'text-sage-700' : 'text-coral-700' }}">
                    Total Actif = {{ $fmt($totals['actif']) }}
                    {{ $totals['balance'] ? '✓=' : '≠' }}
                    Total Passif = {{ $fmt($totals['passif']) }}
                </span>
                @if (!$totals['balance'])
                    <span class="text-coral-700">(diff: {{ $fmt($totals['actif'] - $totals['passif']) }})</span>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            {{-- ── ACTIF ── --}}
            <div class="bg-white border border-[#e8e4de] rounded-xl overflow-hidden">
                <div class="bg-[#1e3d44] text-white px-4 py-2.5 text-sm font-bold">BILAN — ACTIF</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-[11.5px]">
                        <thead class="border-b border-[#cbc7be] bg-[#fcfbf8]">
                            <tr>
                                <th class="text-left py-2 pl-4 pr-2 text-[10px] font-semibold text-[#5a6a70]" style="width:3rem;">Réf</th>
                                <th class="text-left py-2 px-2 text-[10px] font-semibold text-[#5a6a70]">Libellé</th>
                                <th class="text-right py-2 px-2 text-[10px] font-semibold text-[#5a6a70]">Montant</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#f1ede5]">
                            @foreach ($data['bilan_actif']['sections'] as $section)
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
                                <tr class="bg-[#fcfbf8]">
                                    <td colspan="2" class="py-1.5 pl-4 pr-2 text-[11px] font-semibold text-[#5a6a70] italic">Sous-total {{ $section['title'] }}</td>
                                    <td class="py-1.5 px-2 text-right tabular-nums font-semibold text-[#2d3748]">{{ $fmt($section['total']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="bg-[#1e3d44] text-white border-t-2 border-[#1e3d44]">
                                <td colspan="2" class="py-2 pl-4 pr-2 font-bold">TOTAL ACTIF</td>
                                <td class="py-2 px-2 text-right tabular-nums font-bold">{{ $fmt($totals['actif']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            {{-- ── PASSIF ── --}}
            <div class="bg-white border border-[#e8e4de] rounded-xl overflow-hidden">
                <div class="bg-[#1e3d44] text-white px-4 py-2.5 text-sm font-bold">BILAN — PASSIF</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-[11.5px]">
                        <thead class="border-b border-[#cbc7be] bg-[#fcfbf8]">
                            <tr>
                                <th class="text-left py-2 pl-4 pr-2 text-[10px] font-semibold text-[#5a6a70]" style="width:3rem;">Réf</th>
                                <th class="text-left py-2 px-2 text-[10px] font-semibold text-[#5a6a70]">Libellé</th>
                                <th class="text-right py-2 px-2 text-[10px] font-semibold text-[#5a6a70]">Montant</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#f1ede5]">
                            @foreach ($data['bilan_passif']['sections'] as $section)
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
                                <tr class="bg-[#fcfbf8]">
                                    <td colspan="2" class="py-1.5 pl-4 pr-2 text-[11px] font-semibold text-[#5a6a70] italic">Sous-total {{ $section['title'] }}</td>
                                    <td class="py-1.5 px-2 text-right tabular-nums font-semibold text-[#2d3748]">{{ $fmt($section['total']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="bg-[#1e3d44] text-white border-t-2 border-[#1e3d44]">
                                <td colspan="2" class="py-2 pl-4 pr-2 font-bold">TOTAL PASSIF</td>
                                <td class="py-2 px-2 text-right tabular-nums font-bold">{{ $fmt($totals['passif']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        @if (($data['unmapped_count'] ?? 0) > 0)
            <details class="mt-4 bg-warm-50 border border-warm-200 rounded-xl p-4">
                <summary class="text-xs font-semibold text-[#7a5a1a] cursor-pointer">{{ $data['unmapped_count'] }} accounts didn't map to a BS line — click to inspect</summary>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-[11px]">
                        <thead><tr class="text-[10px] text-[#5a6a70]"><th class="text-left pr-3">Code</th><th class="text-left">Name</th><th class="text-right">Closing balance</th></tr></thead>
                        <tbody class="divide-y divide-[#f1ede5]">
                            @foreach ($data['unmapped_accounts'] as $a)
                                <tr><td class="py-1 pr-3 font-mono">{{ $a['code'] }}</td><td>{{ $a['name'] }}</td><td class="text-right tabular-nums">{{ $fmt(($a['closing_dr'] ?? 0) - ($a['closing_cr'] ?? 0)) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @endif

        <div class="bg-white border border-[#e8e4de] rounded-xl p-4 mt-4 text-[10.5px] text-[#7c7466] leading-relaxed">
            <strong class="text-[#1e3d44]">Mapping:</strong> SYSCOHADA code-prefix → OHADA Système Normal BS line. Class 4 (tiers) accounts are split by sign — debit balances appear under "Clients" / "Autres créances" on the Actif side; credit balances under "Fournisseurs d'exploitation" / "Dettes fiscales et sociales" on the Passif side.
            Equilibrium check at the top: Total Actif should equal Total Passif when the trial balance is closed and double-entry is intact.
        </div>
    @endif
@endsection
