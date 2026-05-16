@extends('demo.sandbox.layout')
@section('title', 'Data · Journal entries')

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Sandbox data</div>
        <h1 class="text-2xl font-bold text-slate-900">Journal entries</h1>
        <p class="text-[12px] text-slate-500 mt-1">Book a double-entry move — debits must equal credits. The P&L recomputes from these on next page load.</p>
    </div>
@endsection

@section('content')
@include('demo.sandbox.data._tabs', ['tab' => 'journals'])

<details class="panel mb-4 p-0 overflow-hidden" open>
    <summary class="px-4 py-3 cursor-pointer flex items-center justify-between bg-amber-50 border-b border-amber-200">
        <div class="flex items-center gap-2">
            <svg class="w-3.5 h-3.5 text-amber-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
            <span class="text-[12px] font-semibold text-amber-900">Book a journal entry</span>
        </div>
        <span class="text-[10px] text-amber-700 italic">Example: rent payment → Dr 622000 6,500,000 / Cr 521000 6,500,000</span>
    </summary>
    @if ($errors->any())
        <div class="mx-4 mt-3 px-3 py-2 rounded bg-rose-50 border border-rose-200 text-rose-800 text-[12px]">
            @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
        </div>
    @endif

    <form method="POST" action="/demo/sandbox/data/journals/add" class="p-4 space-y-3">
        @csrf
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-slate-600 font-semibold mb-1">Entry date *</label>
                <input name="entry_date" type="date" required value="{{ now()->format('Y-m-d') }}" class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-slate-600 font-semibold mb-1">Journal code</label>
                <select name="journal_code" class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm bg-white">
                    <option value="OD">OD · Operations diverses</option>
                    <option value="VTE">VTE · Sales</option>
                    <option value="ACH">ACH · Purchases</option>
                    <option value="BNK">BNK · Bank</option>
                    <option value="CSH">CSH · Cash</option>
                </select>
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-slate-600 font-semibold mb-1">Reference</label>
                <input name="reference" type="text" placeholder="REF-001" class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm mono">
            </div>
            <div>
                <label class="block text-[10px] uppercase tracking-widest text-slate-600 font-semibold mb-1">Description *</label>
                <input name="description" type="text" required placeholder="Loyer mensuel" class="w-full border border-stone-300 rounded-lg px-3 py-2 text-sm">
            </div>
        </div>
        <div>
            <div class="text-[10px] uppercase tracking-widest text-slate-600 font-semibold mb-1">Lines (debits must equal credits)</div>
            <table class="w-full text-[12px] border border-stone-200 rounded">
                <thead class="bg-stone-50 text-[10px] uppercase tracking-widest text-slate-500">
                    <tr>
                        <th class="text-left py-2 px-3 font-semibold w-32">Account code</th>
                        <th class="text-left py-2 px-3 font-semibold">Account name (auto)</th>
                        <th class="text-right py-2 px-3 font-semibold w-36">Debit</th>
                        <th class="text-right py-2 px-3 font-semibold w-36">Credit</th>
                    </tr>
                </thead>
                <tbody id="lines-tbody" class="divide-y divide-stone-200">
                    @for ($i = 0; $i < 4; $i++)
                        <tr>
                            <td class="py-2 px-3">
                                <select name="lines[{{ $i }}][account_code]" class="w-full border border-stone-300 rounded px-2 py-1.5 text-sm bg-white mono">
                                    <option value="">— select —</option>
                                    @foreach ($accounts as $a)
                                        <option value="{{ $a->code }}">{{ $a->code }} · {{ $a->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="py-2 px-3 text-slate-500 text-[11px] italic">resolved on save</td>
                            <td class="py-2 px-3"><input name="lines[{{ $i }}][debit]"  type="number" step="1" min="0" placeholder="0" class="w-full border border-stone-300 rounded px-2 py-1.5 text-right mono"></td>
                            <td class="py-2 px-3"><input name="lines[{{ $i }}][credit]" type="number" step="1" min="0" placeholder="0" class="w-full border border-stone-300 rounded px-2 py-1.5 text-right mono"></td>
                        </tr>
                    @endfor
                </tbody>
            </table>
            <p class="text-[10px] text-slate-500 mt-2">Lines with both debit and credit empty are ignored. Submission rejected if totals don't balance.</p>
        </div>
        <div class="flex justify-end">
            <button type="submit" class="bg-stone-900 hover:bg-stone-800 text-white text-[12px] font-semibold rounded-lg px-4 py-2">Post entry</button>
        </div>
    </form>
</details>

<div class="panel overflow-hidden">
    <table class="w-full text-[12px]">
        <thead class="bg-stone-50 text-[10px] uppercase tracking-widest text-slate-500">
            <tr>
                <th class="text-left py-2 px-3 font-semibold">Date</th>
                <th class="text-left py-2 px-3 font-semibold">Journal</th>
                <th class="text-left py-2 px-3 font-semibold">Reference</th>
                <th class="text-left py-2 px-3 font-semibold">Description</th>
                <th class="text-right py-2 px-3 font-semibold">Total debit</th>
                <th class="text-right py-2 px-3 font-semibold">Total credit</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
        @forelse ($entries as $e)
            <tr class="hover:bg-stone-50">
                <td class="py-2 px-3 mono text-slate-600">{{ $e->entry_date->format('Y-m-d') }}</td>
                <td class="py-2 px-3 mono font-semibold text-slate-700">{{ $e->journal_code }}</td>
                <td class="py-2 px-3 mono text-slate-600">{{ $e->reference ?? '—' }}</td>
                <td class="py-2 px-3 text-slate-800">{{ $e->description }}</td>
                <td class="py-2 px-3 text-right mono font-semibold text-slate-900">{{ number_format($e->totalDebit(), 0) }}</td>
                <td class="py-2 px-3 text-right mono font-semibold text-slate-900">{{ number_format($e->totalCredit(), 0) }}</td>
            </tr>
            @foreach ($e->lines as $l)
                <tr class="bg-stone-50/40">
                    <td colspan="3"></td>
                    <td class="py-1 px-3 text-[11px] text-slate-600 mono">{{ $l->account_code }} · {{ $l->account_name }}</td>
                    <td class="py-1 px-3 text-right text-[11px] mono {{ $l->debit > 0 ? 'text-slate-700' : 'text-slate-400' }}">{{ $l->debit > 0 ? number_format($l->debit, 0) : '—' }}</td>
                    <td class="py-1 px-3 text-right text-[11px] mono {{ $l->credit > 0 ? 'text-slate-700' : 'text-slate-400' }}">{{ $l->credit > 0 ? number_format($l->credit, 0) : '—' }}</td>
                </tr>
            @endforeach
        @empty
            <tr><td colspan="6" class="py-6 text-center text-slate-500 italic">No journal entries. Use the form above to post one.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
