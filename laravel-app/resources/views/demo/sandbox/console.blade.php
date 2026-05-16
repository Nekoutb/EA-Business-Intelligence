@extends('demo.sandbox.layout')
@section('title', 'NL console')

@section('header')
    <div>
        <div class="text-[10px] uppercase tracking-widest text-amber-700 font-semibold mb-1">Demo · Natural-language console</div>
        <h1 class="text-2xl font-bold text-slate-900">Type a command. Watch the BMS react.</h1>
        <p class="text-[12px] text-slate-500 mt-1">
            Claude parses your free-text instruction into a structured operation (add invoice, book journal,
            record payment, add customer) and applies it to your sandbox. Every successful command flows through
            to the dashboard + module pages on next refresh.
        </p>
    </div>
@endsection

@section('content')
<div class="panel p-5 mb-4 bg-gradient-to-r from-stone-50 to-amber-50/40 border border-amber-200">
    <form method="POST" action="/demo/sandbox/console" class="space-y-3">
        @csrf
        <label class="block text-[10px] uppercase tracking-widest text-slate-600 font-semibold">Your command</label>
        <textarea name="command" rows="3" required
                  placeholder="e.g. add an invoice for Mahima 850k XAF dated today, no PO&#10;e.g. book journal: Dr 622000 6,500,000 / Cr 521000 6,500,000 — Loyer mai&#10;e.g. record payment of 500k against INV-2026-00012, bank transfer&#10;e.g. add customer 'Total Cameroun' Wholesale 60 days"
                  class="w-full border border-stone-300 rounded-lg px-3 py-2.5 text-sm mono focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-100">{{ old('command') }}</textarea>
        <div class="flex items-center justify-between">
            <div class="text-[10px] text-slate-500">
                Tip: be specific about amounts + dates. Use account codes from the
                <a href="/demo/sandbox/data/journals" class="underline">Journals tab</a> for journal entries.
            </div>
            <button type="submit" class="bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-700 hover:to-orange-700 text-white text-[12px] font-semibold rounded-lg px-5 py-2 shadow">
                Run command →
            </button>
        </div>
    </form>

    <div class="mt-4 pt-4 border-t border-amber-200/60">
        <div class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-2">Try one of these</div>
        <div class="flex flex-wrap gap-1.5 text-[11px]">
            @foreach ([
                'add an invoice for Mahima 850000 XAF dated today, no PO',
                'add invoice for Hilton Yaoundé 1,250,000 XAF',
                'book journal: Dr 622000 6,500,000 / Cr 521000 6,500,000 — May rent',
                'record payment of 500k against most recent Mahima invoice via bank transfer',
                'add customer Distribution Bafia, Wholesale, 45 days',
                'book journal: Dr 631100 95000 / Cr 521000 95000 — bank fees',
            ] as $example)
                <button type="button" onclick="document.querySelector('textarea[name=command]').value = this.textContent.trim();"
                        class="bg-white border border-amber-200 rounded-full px-2.5 py-1 text-amber-900 hover:bg-amber-50 transition">{{ $example }}</button>
            @endforeach
        </div>
    </div>
</div>

<div class="panel overflow-hidden">
    <div class="px-4 py-3 border-b border-stone-200 flex items-center justify-between">
        <h2 class="text-sm font-semibold text-slate-900">Command history · last 50</h2>
        <span class="text-[10px] text-slate-500">audit trail · this session only</span>
    </div>
    <table class="w-full text-[12px]">
        <thead class="bg-stone-50 text-[10px] uppercase tracking-widest text-slate-500">
            <tr>
                <th class="text-left py-2 px-3 font-semibold w-44">Applied</th>
                <th class="text-left py-2 px-3 font-semibold w-24">Source</th>
                <th class="text-left py-2 px-3 font-semibold w-20">Action</th>
                <th class="text-center py-2 px-3 font-semibold w-20">Status</th>
                <th class="text-left py-2 px-3 font-semibold">Details</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
        @forelse ($audit as $a)
            @php
                $op = is_array($a->parsed_operations) ? $a->parsed_operations : [];
                $action = $op['action'] ?? '—';
            @endphp
            <tr class="hover:bg-stone-50 align-top">
                <td class="py-2 px-3 mono text-slate-600">{{ $a->applied_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                <td class="py-2 px-3 mono text-[11px] text-slate-500">{{ str_replace('_', ' ', $a->source) }}</td>
                <td class="py-2 px-3 mono text-[11px] font-semibold text-slate-700">{{ $action }}</td>
                <td class="py-2 px-3 text-center">
                    @if ($a->status === 'success')
                        <span class="inline-block px-2 py-0.5 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-700 text-[9px] font-semibold uppercase">success</span>
                    @else
                        <span class="inline-block px-2 py-0.5 rounded-full bg-rose-50 border border-rose-200 text-rose-700 text-[9px] font-semibold uppercase">failed</span>
                    @endif
                </td>
                <td class="py-2 px-3">
                    @if ($a->command_text)
                        <div class="text-slate-700 mono text-[11px] bg-stone-50 rounded px-2 py-1 mb-1">{{ \Illuminate\Support\Str::limit($a->command_text, 200) }}</div>
                    @endif
                    @if ($a->error_msg)
                        <div class="text-rose-700 text-[11px] italic">{{ $a->error_msg }}</div>
                    @endif
                    @if ($a->status === 'success' && !empty($op))
                        <div class="text-slate-500 text-[10px] mono">{{ \Illuminate\Support\Str::limit(json_encode($op, JSON_UNESCAPED_UNICODE), 200) }}</div>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-6 text-center text-slate-500 italic">No commands yet. Type one above to get started.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
