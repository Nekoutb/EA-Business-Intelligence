@extends('demo.sandbox.layout')
@section('title', 'Trial Balance')

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Trial Balance</div>
        <h1 class="text-2xl font-bold text-slate-900">Trial balance as of {{ $data['as_of'] }}</h1>
        <p class="text-[12px] text-slate-500 mt-1">Computed live from sandbox journal entries + invoice/payment activity. Every row reflects opening balance + period movement.</p>
    </div>
@endsection

@section('content')
<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
    <div class="panel p-4">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Total debits</div>
        <div class="text-xl font-bold mono text-slate-900 mt-1">{{ number_format($data['total_debit'], 0) }} <span class="text-[11px] text-slate-500">XAF</span></div>
    </div>
    <div class="panel p-4">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Total credits</div>
        <div class="text-xl font-bold mono text-slate-900 mt-1">{{ number_format($data['total_credit'], 0) }} <span class="text-[11px] text-slate-500">XAF</span></div>
    </div>
    <div class="panel p-4 border-l-4 {{ $data['balanced'] ? 'border-emerald-400' : 'border-rose-400' }}">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Balance check</div>
        <div class="text-xl font-bold mono mt-1 {{ $data['balanced'] ? 'text-emerald-700' : 'text-rose-700' }}">{{ $data['balanced'] ? 'Balanced ✓' : 'Out of balance' }}</div>
        <div class="text-[10px] text-slate-500 mt-0.5 mono">Δ {{ number_format($data['total_debit'] - $data['total_credit'], 0) }}</div>
    </div>
</div>

<div class="panel overflow-hidden">
    <table class="w-full text-[12px]">
        <thead class="bg-stone-50 text-[10px] uppercase tracking-widest text-slate-500">
            <tr>
                <th class="text-left py-2 px-3 font-semibold w-24">Class</th>
                <th class="text-left py-2 px-3 font-semibold w-24">Code</th>
                <th class="text-left py-2 px-3 font-semibold">Account</th>
                <th class="text-right py-2 px-3 font-semibold">Opening</th>
                <th class="text-right py-2 px-3 font-semibold">Debit</th>
                <th class="text-right py-2 px-3 font-semibold">Credit</th>
                <th class="text-right py-2 px-3 font-semibold">Balance</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
        @forelse ($data['rows'] as $row)
            <tr class="hover:bg-stone-50">
                <td class="py-2 px-3 mono text-slate-500 text-[10px]">Class {{ $row['class'] }}</td>
                <td class="py-2 px-3 mono font-semibold text-slate-700">{{ $row['code'] }}</td>
                <td class="py-2 px-3 text-slate-800">{{ $row['name'] }}</td>
                <td class="py-2 px-3 text-right mono text-slate-500">{{ number_format($row['opening'], 0) }}</td>
                <td class="py-2 px-3 text-right mono text-slate-900">{{ $row['debit'] > 0 ? number_format($row['debit'], 0) : '—' }}</td>
                <td class="py-2 px-3 text-right mono text-slate-900">{{ $row['credit'] > 0 ? number_format($row['credit'], 0) : '—' }}</td>
                <td class="py-2 px-3 text-right mono font-semibold {{ $row['balance'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ number_format($row['balance'], 0) }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="py-6 text-center text-slate-500 italic">No activity yet.</td></tr>
        @endforelse
        </tbody>
        <tfoot class="bg-stone-100 text-[12px] font-bold">
            <tr>
                <td colspan="4" class="py-2 px-3 text-right text-slate-900">TOTAL</td>
                <td class="py-2 px-3 text-right mono text-slate-900">{{ number_format($data['total_debit'], 0) }}</td>
                <td class="py-2 px-3 text-right mono text-slate-900">{{ number_format($data['total_credit'], 0) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>
@endsection
