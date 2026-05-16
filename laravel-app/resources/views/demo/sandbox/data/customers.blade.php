@extends('demo.sandbox.layout')
@section('title', 'Data · Customers')

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Sandbox data</div>
        <h1 class="text-2xl font-bold text-slate-900">Customers</h1>
        <p class="text-[12px] text-slate-500 mt-1">Master data for the customers your demo BMS reports against.</p>
    </div>
@endsection

@section('content')
@include('demo.sandbox.data._tabs', ['tab' => 'customers'])

<details class="panel mb-4 p-0 overflow-hidden">
    <summary class="px-4 py-3 cursor-pointer flex items-center gap-2 bg-amber-50 border-b border-amber-200">
        <svg class="w-3.5 h-3.5 text-amber-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
        <span class="text-[12px] font-semibold text-amber-900">Add a customer</span>
    </summary>
    @if ($errors->any())
        <div class="mx-4 mt-3 px-3 py-2 rounded bg-rose-50 border border-rose-200 text-rose-800 text-[12px]">
            @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
        </div>
    @endif
    <form method="POST" action="/demo/sandbox/data/customers/add" class="p-4 grid grid-cols-1 md:grid-cols-3 gap-3">
        @csrf
        <div>
            <label class="block text-[10px] uppercase tracking-widest text-slate-600 font-semibold mb-1">Name *</label>
            <input name="name" type="text" required class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-[10px] uppercase tracking-widest text-slate-600 font-semibold mb-1">Category</label>
            <select name="category" class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm bg-white">
                <option value="">— None —</option>
                <option>Hotel & Restaurant</option>
                <option>Retail</option>
                <option>Wholesale</option>
                <option>Corporate</option>
            </select>
        </div>
        <div>
            <label class="block text-[10px] uppercase tracking-widest text-slate-600 font-semibold mb-1">Payment terms (days) *</label>
            <input name="payment_terms_days" type="number" required value="30" min="0" max="365" class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm mono">
        </div>
        <div>
            <label class="block text-[10px] uppercase tracking-widest text-slate-600 font-semibold mb-1">Country</label>
            <input name="country" type="text" value="CM" class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm mono">
        </div>
        <div>
            <label class="block text-[10px] uppercase tracking-widest text-slate-600 font-semibold mb-1">Contact email</label>
            <input name="contact_email" type="email" class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div class="flex items-end">
            <button type="submit" class="bg-stone-900 hover:bg-stone-800 text-white text-[12px] font-semibold rounded-lg px-4 py-2">Add customer</button>
        </div>
    </form>
</details>

<div class="panel overflow-hidden">
    <table class="w-full text-[12px]">
        <thead class="bg-stone-50 text-[10px] uppercase tracking-widest text-slate-500">
            <tr>
                <th class="text-left py-2 px-3 font-semibold">Name</th>
                <th class="text-left py-2 px-3 font-semibold">Category</th>
                <th class="text-right py-2 px-3 font-semibold">Terms</th>
                <th class="text-left py-2 px-3 font-semibold">Country</th>
                <th class="text-left py-2 px-3 font-semibold">Contact</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
        @forelse ($customers as $c)
            <tr class="hover:bg-stone-50">
                <td class="py-2 px-3 font-medium text-slate-900">{{ $c->name }}</td>
                <td class="py-2 px-3 text-slate-600">{{ $c->category ?? '—' }}</td>
                <td class="py-2 px-3 text-right mono text-slate-700">{{ $c->payment_terms_days }}d</td>
                <td class="py-2 px-3 mono text-slate-500">{{ $c->country ?? '—' }}</td>
                <td class="py-2 px-3 mono text-slate-500">{{ $c->contact_email ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-6 text-center text-slate-500 italic">No customers.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
