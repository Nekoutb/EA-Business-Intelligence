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
<form method="GET" class="panel p-3 mb-4 flex gap-2">
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
                <th class="px-4 py-3 text-right">Created</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($tenants as $t)
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3 font-mono text-xs text-slate-800">{{ $t->slug }}</td>
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
                    <td class="px-4 py-3 text-right text-[11px] text-slate-500">{{ $t->created_at?->format('Y-m-d') }}</td>
                    <td class="px-4 py-3 text-right">
                        <a href="/admin/tenants/{{ $t->id }}" class="text-xs font-medium text-indigo-600 hover:text-indigo-800">Manage →</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-10 text-center text-slate-400 italic">No tenants match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $tenants->links() }}</div>
@endsection
