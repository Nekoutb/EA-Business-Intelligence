@extends('demo.sandbox.layout')
@section('title', 'OHADA Statements')

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · OHADA Financial Statements</div>
        <h1 class="text-2xl font-bold text-slate-900">Bilan · Compte de Résultat</h1>
        <p class="text-[12px] text-slate-500 mt-1">SYSCOHADA-formatted statutory statements as of {{ $data['as_of'] }} · all figures in XAF</p>
    </div>
@endsection

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-1">BILAN · ACTIF</h2>
        <p class="text-[10px] text-slate-500 mb-3 uppercase tracking-wider">What the business owns</p>
        <table class="w-full text-[12px]">
            <thead class="text-[10px] uppercase tracking-widest text-slate-500">
                <tr class="border-b border-stone-200">
                    <th class="text-left py-2 pr-3 font-semibold w-12">Code</th>
                    <th class="text-left py-2 px-3 font-semibold">Rubrique</th>
                    <th class="text-right py-2 px-3 font-semibold">Net (XAF)</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            @foreach ($data['bilan_actif'] as $row)
                <tr>
                    <td class="py-2 pr-3 mono font-semibold text-slate-700">{{ $row['code'] }}</td>
                    <td class="py-2 px-3 text-slate-800">{{ $row['label'] }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-900">{{ number_format($row['amount'], 0) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-stone-300 font-bold">
                    <td colspan="2" class="py-2 pr-3 text-slate-900">TOTAL ACTIF</td>
                    <td class="py-2 px-3 text-right mono text-emerald-700">{{ number_format($data['bilan_actif_total'], 0) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="panel p-5">
        <h2 class="text-sm font-semibold text-slate-900 mb-1">BILAN · PASSIF</h2>
        <p class="text-[10px] text-slate-500 mb-3 uppercase tracking-wider">What the business owes + equity</p>
        <table class="w-full text-[12px]">
            <thead class="text-[10px] uppercase tracking-widest text-slate-500">
                <tr class="border-b border-stone-200">
                    <th class="text-left py-2 pr-3 font-semibold w-12">Code</th>
                    <th class="text-left py-2 px-3 font-semibold">Rubrique</th>
                    <th class="text-right py-2 px-3 font-semibold">Net (XAF)</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            @foreach ($data['bilan_passif'] as $row)
                <tr>
                    <td class="py-2 pr-3 mono font-semibold text-slate-700">{{ $row['code'] }}</td>
                    <td class="py-2 px-3 text-slate-800">{{ $row['label'] }}</td>
                    <td class="py-2 px-3 text-right mono text-slate-900">{{ number_format($row['amount'], 0) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-stone-300 font-bold">
                    <td colspan="2" class="py-2 pr-3 text-slate-900">TOTAL PASSIF</td>
                    <td class="py-2 px-3 text-right mono text-rose-700">{{ number_format($data['bilan_passif_total'], 0) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<div class="panel p-5">
    <h2 class="text-sm font-semibold text-slate-900 mb-1">COMPTE DE RÉSULTAT</h2>
    <p class="text-[10px] text-slate-500 mb-3 uppercase tracking-wider">Income statement · last 365 days</p>
    <table class="w-full text-[13px]">
        @foreach ($data['cr_lines'] as $line)
            @php
                $isSub = !empty($line['subtotal']);
                $cls = $isSub
                    ? ($line['amount'] >= 0 ? 'font-bold text-emerald-700 border-t border-stone-300' : 'font-bold text-rose-700 border-t border-stone-300')
                    : ($line['amount'] >= 0 ? 'text-slate-900' : 'text-rose-700');
            @endphp
            <tr class="{{ $isSub ? 'border-t border-stone-300' : 'border-b border-stone-100' }}">
                <td class="py-2 pr-3 mono w-12 font-semibold text-slate-700">{{ $line['code'] }}</td>
                <td class="py-2 px-3 {{ $isSub ? 'font-bold text-slate-900' : 'text-slate-600' }}">{{ $line['label'] }}</td>
                <td class="py-2 px-3 text-right mono {{ $cls }}">{{ number_format($line['amount'], 0) }}</td>
            </tr>
        @endforeach
    </table>
    <p class="text-[10px] text-slate-500 mt-3 mono">All amounts in XAF</p>
</div>
@endsection
