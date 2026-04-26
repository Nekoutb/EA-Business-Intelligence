@extends('admin.layout')
@section('title', $tenant->name . ' settings')

@section('header')
    <div>
        <div class="flex items-center gap-2 mb-1">
            <a href="/admin/tenants/{{ $tenant->id }}" class="text-xs text-slate-500 hover:text-slate-800">← {{ $tenant->name }}</a>
        </div>
        <h1 class="text-2xl font-bold text-slate-900">Settings</h1>
        <p class="text-xs text-slate-500 mt-1">Per-tenant configuration. Secrets are encrypted at rest. Leave secret fields blank to keep the existing value.</p>
    </div>
@endsection

@section('content')
<form method="POST" action="/admin/tenants/{{ $tenant->id }}/settings" class="space-y-4 max-w-3xl">
    @csrf

    @php
        // AI provider config is platform-global (managed at /admin/settings/ai),
        // so it's intentionally not exposed on a per-tenant page.
        $groups = [
            'Odoo connection'    => ['ODOO_URL','ODOO_DB','ODOO_USERNAME','ODOO_API_KEY','ODOO_PROTOCOL','REVENUE_SOURCE'],
            'Branding'           => ['APP_BRAND'],
            'Budget benchmarks'  => ['BUDGET_MONTHLY_REVENUE','BUDGET_MONTHLY_OUTFITS','BUDGET_MONTHLY_COGS','BUDGET_MONTHLY_DISCOUNTS','BUDGET_MONTHLY_CUSTOMERS'],
        ];
    @endphp

    @foreach ($groups as $heading => $keys)
        <div class="panel p-5">
            <h2 class="text-sm font-semibold text-slate-900 mb-3">{{ $heading }}</h2>
            <div class="grid md:grid-cols-2 gap-4">
                @foreach ($keys as $k)
                    @php $isSecret = preg_match('/(KEY|SECRET|PASSWORD|TOKEN)/i', $k); @endphp
                    <label class="block">
                        <span class="text-[11px] font-medium text-slate-600 font-mono">{{ $k }}</span>
                        <input name="{{ $k }}"
                               type="{{ $isSecret ? 'password' : 'text' }}"
                               value="{{ $isSecret ? '' : $values[$k] }}"
                               placeholder="{{ $isSecret && !empty($values['__has_'.$k]) ? '•••• (already set, leave blank to keep)' : '' }}"
                               class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono">
                    </label>
                @endforeach
            </div>
        </div>
    @endforeach

    <div class="flex justify-end gap-2">
        <a href="/admin/tenants/{{ $tenant->id }}" class="text-sm text-slate-500 hover:text-slate-800 px-3 py-2">Cancel</a>
        <button class="bg-gradient-to-r from-rose-600 to-orange-600 hover:from-rose-700 hover:to-orange-700 text-white font-semibold px-4 py-2 rounded-lg text-sm shadow transition">
            Save settings
        </button>
    </div>
</form>
@endsection
