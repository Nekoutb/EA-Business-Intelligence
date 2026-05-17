@extends('admin.layout')
@section('title', 'Tenants')

@section('header')
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Tenants</h1>
            <p class="text-xs text-slate-500 mt-1">Manage workspaces, modules and users.</p>
        </div>
        <a href="/admin/tenants/create"
           class="inline-flex items-center gap-1.5 text-[12px] font-semibold text-white bg-gradient-to-r from-rose-600 to-orange-600 hover:from-rose-700 hover:to-orange-700 rounded-lg px-3.5 py-2 shadow transition">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
            New tenant
        </a>
    </div>
@endsection

@section('content')

{{-- ───── Type tabs ─────
     BMS is the default tab so the operator never has to wade
     through demo sandbox tenants (which can pile up fast during
     prospecting) to find a real customer. Demo gets its own tab
     with expiry-ordered listing + visual amber tagging. --}}
@php
    $tabs = [
        'bms'  => ['BMS · production tenants', $bmsCount,  'border-rose-500 text-rose-700',    'text-slate-500 hover:text-slate-900'],
        'demo' => ['Demo sandboxes',           $demoCount, 'border-amber-500 text-amber-700',  'text-slate-500 hover:text-slate-900'],
        'all'  => ['All',                      $allCount,  'border-slate-700 text-slate-900',  'text-slate-500 hover:text-slate-900'],
    ];
@endphp
<div class="border-b border-slate-200 mb-4 -mt-1 flex items-center gap-1">
    @foreach ($tabs as $key => [$label, $count, $activeCls, $inactiveCls])
        @php
            $qs = http_build_query(array_filter([
                'type'   => $key,
                'q'      => $q,
                'status' => $status,
            ], fn ($v) => $v !== '' && $v !== null));
            $isActive = $type === $key;
        @endphp
        <a href="/admin/tenants?{{ $qs }}"
           class="px-4 py-2.5 text-[13px] font-semibold border-b-2 -mb-px transition {{ $isActive ? $activeCls : 'border-transparent ' . $inactiveCls }}">
            {{ $label }}
            <span class="ml-1.5 inline-flex items-center justify-center text-[10px] font-bold {{ $isActive ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600' }} rounded px-1.5 py-0.5 min-w-[1.5rem]">
                {{ $count }}
            </span>
        </a>
    @endforeach
</div>

{{-- Demo-specific context note --}}
@if ($type === 'demo')
    <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg px-3 py-2.5 mb-3 text-[12px] flex items-start gap-2">
        <svg class="w-4 h-4 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div>
            <strong>Visitor demo sandboxes.</strong> Each row is a per-visitor isolated copy of the BMS spawned via <code class="bg-white border border-amber-200 px-1 rounded text-[10.5px]">/demo/enter</code>. Auto-reaped after the 2-hour TTL expires. Lead info (name + email + phone + company + role) lives in the <code class="bg-white border border-amber-200 px-1 rounded text-[10.5px]">demo_sessions</code> table for sales follow-up.
        </div>
    </div>
@endif

<form method="GET" class="panel p-3 mb-4 flex gap-2">
    <input type="hidden" name="type" value="{{ $type }}">
    <input name="q" value="{{ $q }}" placeholder="Search slug or name..."
           class="flex-1 border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
    <select name="status" class="border border-slate-200 rounded-lg px-3 py-2 text-sm">
        <option value="">All statuses</option>
        @foreach (['active','trial','suspended'] as $s)
            <option value="{{ $s }}" @selected($status === $s)>{{ ucfirst($s) }}</option>
        @endforeach
    </select>
    <button class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2">Filter</button>
</form>

<div class="panel overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500">
            <tr>
                <th class="px-4 py-3 text-left">Slug</th>
                <th class="px-4 py-3 text-left">Name</th>
                <th class="px-4 py-3 text-left">Status</th>
                <th class="px-4 py-3 text-left">Plan</th>
                <th class="px-4 py-3 text-right">Modules</th>
                <th class="px-4 py-3 text-right">Users</th>
                @if ($type === 'demo')
                    <th class="px-4 py-3 text-right">Expires</th>
                @else
                    <th class="px-4 py-3 text-right">Created</th>
                @endif
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($tenants as $t)
                @php
                    $isDemo = (bool) $t->is_demo;
                    // Expired demos appear lightly dimmed so the operator
                    // can see at-a-glance which sandboxes are about to be
                    // garbage-collected.
                    $rowCls = $isDemo && $t->demo_expires_at && $t->demo_expires_at->isPast()
                        ? 'hover:bg-slate-50 opacity-60'
                        : 'hover:bg-slate-50';
                @endphp
                <tr class="{{ $rowCls }}">
                    <td class="px-4 py-3 font-mono text-xs text-slate-800">
                        {{ $t->slug }}
                        @if ($isDemo && $type !== 'demo')
                            <span class="ml-1.5 text-[9px] font-bold uppercase tracking-wider bg-amber-100 text-amber-700 border border-amber-200 rounded px-1 py-0.5">demo</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">{{ $t->name }}</td>
                    <td class="px-4 py-3">
                        @php
                            $tone = match($t->status) {
                                'active'    => 'bg-emerald-100 text-emerald-700',
                                'trial'     => 'bg-sky-100 text-sky-700',
                                'suspended' => 'bg-rose-100 text-rose-700',
                                default     => 'bg-slate-100 text-slate-700',
                            };
                        @endphp
                        <span class="text-[10px] uppercase tracking-wider rounded px-1.5 py-0.5 {{ $tone }}">{{ $t->status }}</span>
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-600">{{ $t->plan }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-700">{{ $t->modules_count ?? 0 }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-700">{{ $t->users_count ?? 0 }}</td>
                    @if ($type === 'demo')
                        <td class="px-4 py-3 text-right text-[11px]">
                            @if ($t->demo_expires_at)
                                @if ($t->demo_expires_at->isPast())
                                    <span class="text-rose-600 font-semibold">expired {{ $t->demo_expires_at->diffForHumans() }}</span>
                                @else
                                    <span class="text-amber-700">{{ $t->demo_expires_at->diffForHumans() }}</span>
                                @endif
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                    @else
                        <td class="px-4 py-3 text-right text-[11px] text-slate-500">{{ $t->created_at?->format('Y-m-d') }}</td>
                    @endif
                    <td class="px-4 py-3 text-right">
                        <a href="/admin/tenants/{{ $t->id }}" class="text-xs font-medium text-indigo-600 hover:text-indigo-800">Manage →</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-10 text-center text-slate-400 italic">
                    @if ($type === 'demo')
                        No demo sandboxes match the current filter. Visitors create demo tenants by submitting the form at <a href="/demo/enter" target="_blank" class="text-indigo-600 underline">/demo/enter</a>.
                    @else
                        No tenants match the current filter.
                    @endif
                </td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $tenants->links() }}</div>
@endsection
