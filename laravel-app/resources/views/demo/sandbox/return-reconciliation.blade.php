@extends('demo.sandbox.layout')
@section('title', 'Return Reconciliation')

@section('header')
    <div class="flex items-end justify-between gap-4">
        <div>
            <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Return Reconciliation</div>
            <h1 class="text-2xl font-bold text-slate-900">Filed return vs GL ground truth — {{ $data['period_label'] }}</h1>
            <p class="text-[12px] text-slate-500 mt-1">Reconciles §2 CA Global HT declared on the monthly return against the sum of Class 70 movements + invoice CA HT in the GL.</p>
        </div>
        <form method="GET" class="flex items-center gap-2">
            <label class="text-[11px] uppercase tracking-widest text-slate-500 font-semibold">Month:</label>
            <input name="month" type="month" value="{{ $data['month'] }}" onchange="this.form.submit()" class="border border-stone-300 rounded-lg px-3 py-1.5 text-[12px] bg-white mono">
        </form>
    </div>
@endsection

@section('content')
@include('demo.sandbox._kpi-strip', ['kpis' => $kpis])

<div class="panel p-5">
    <h2 class="text-sm font-semibold text-slate-900 mb-3">Three-way reconciliation</h2>
    <table class="w-full text-[13px]">
        <tbody class="divide-y divide-stone-100">
            <tr><td class="py-2 pr-3 text-slate-600">A · Declared on filed return (§2 CA Global HT)</td><td class="py-2 text-right mono text-slate-900">{{ number_format($data['declared_on_return'], 0) }}</td></tr>
            <tr><td class="py-2 pr-3 text-slate-600">B · GL Class 70 movements (credit)</td><td class="py-2 text-right mono text-slate-900">{{ number_format($data['gl_class_70'], 0) }}</td></tr>
            <tr><td class="py-2 pr-3 text-slate-600">C · Implicit from invoices (HT)</td><td class="py-2 text-right mono text-slate-900">{{ number_format($data['invoice_ht'], 0) }}</td></tr>
            <tr class="border-t-2 border-stone-300">
                <td class="py-2 pr-3 font-bold text-slate-900">GL ground truth (B + C)</td>
                <td class="py-2 text-right mono font-bold text-slate-900">{{ number_format($data['gl_total'], 0) }}</td>
            </tr>
            <tr class="{{ $data['reconciled'] ? 'bg-emerald-50/40' : 'bg-rose-50/40' }}">
                <td class="py-2 pr-3 font-bold {{ $data['reconciled'] ? 'text-emerald-900' : 'text-rose-900' }}">Variance (GL − Declared)</td>
                <td class="py-2 text-right mono font-bold {{ $data['reconciled'] ? 'text-emerald-700' : 'text-rose-700' }}">{{ ($data['variance'] >= 0 ? '+' : '') . number_format($data['variance'], 0) }}</td>
            </tr>
        </tbody>
    </table>
</div>
@endsection
