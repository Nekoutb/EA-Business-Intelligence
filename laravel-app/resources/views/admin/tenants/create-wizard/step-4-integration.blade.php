@extends('admin.layout')
@section('title', 'Create Tenant - Step 4')

@section('header')
    <div>
        <h1 class="text-2xl font-bold text-slate-900">Create Tenant</h1>
        <p class="text-xs text-slate-500 mt-1">Step 4 of 5 · Odoo Integration Setup</p>
    </div>
@endsection

@section('content')
<div class="max-w-2xl">
    @include('admin.tenants.create-wizard.stepper', ['step' => 4])

    <div class="panel p-6">
        @if ($needsOdoo)
            <h2 class="text-lg font-bold text-slate-900 mb-2">Odoo Integration Setup</h2>
            <p class="text-sm text-slate-600 mb-6">The selected services require Odoo connection. Provide your Odoo instance credentials below.</p>

            @if ($errors->any())
                <div class="text-sm text-rose-700 bg-rose-50 border border-rose-200 rounded-lg p-4 mb-4">
                    <div class="font-semibold mb-1">Please fix the following errors:</div>
                    @foreach ($errors->all() as $err) <div>{{ $err }}</div> @endforeach
                </div>
            @endif

            <form method="POST" action="/admin/tenants/wizard-step" class="space-y-4">
                @csrf
                <input type="hidden" name="step" value="4">
                <input type="hidden" name="action" value="next">

                <div class="space-y-4">
                    <label class="block">
                        <span class="text-xs font-medium text-slate-600">Odoo URL <span class="text-red-500">*</span></span>
                        <input type="url" name="ODOO_URL" value="{{ old('ODOO_URL', $data['ODOO_URL'] ?? '') }}" required
                               class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                               placeholder="https://odoo.example.com">
                    </label>

                    <label class="block">
                        <span class="text-xs font-medium text-slate-600">Database Name <span class="text-red-500">*</span></span>
                        <input type="text" name="ODOO_DB" value="{{ old('ODOO_DB', $data['ODOO_DB'] ?? '') }}" required
                               class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                               placeholder="production">
                    </label>

                    <label class="block">
                        <span class="text-xs font-medium text-slate-600">Username <span class="text-red-500">*</span></span>
                        <input type="text" name="ODOO_USERNAME" value="{{ old('ODOO_USERNAME', $data['ODOO_USERNAME'] ?? '') }}" required
                               class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                               placeholder="api@example.com">
                    </label>

                    <label class="block">
                        <span class="text-xs font-medium text-slate-600">API Key <span class="text-red-500">*</span></span>
                        <input type="password" name="ODOO_API_KEY" value="{{ old('ODOO_API_KEY', $data['ODOO_API_KEY'] ?? '') }}" required
                               class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                               placeholder="••••••••••">
                    </label>

                    <label class="block">
                        <span class="text-xs font-medium text-slate-600">Protocol <span class="text-red-500">*</span></span>
                        <select name="ODOO_PROTOCOL" required
                                class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                            <option value="json2" {{ old('ODOO_PROTOCOL', $data['ODOO_PROTOCOL'] ?? 'json2') === 'json2' ? 'selected' : '' }}>JSON-RPC v2</option>
                            <option value="jsonrpc" {{ old('ODOO_PROTOCOL', $data['ODOO_PROTOCOL'] ?? '') === 'jsonrpc' ? 'selected' : '' }}>JSON-RPC</option>
                            <option value="xmlrpc" {{ old('ODOO_PROTOCOL', $data['ODOO_PROTOCOL'] ?? '') === 'xmlrpc' ? 'selected' : '' }}>XML-RPC</option>
                        </select>
                    </label>
                </div>

                <div class="flex gap-3 pt-4 border-t border-slate-200">
                    <button type="submit" name="action" value="prev" class="px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 rounded-lg transition">
                        ← Back
                    </button>
                    <button type="submit" name="skip_integration" value="1" class="px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 rounded-lg transition">
                        Skip for now
                    </button>
                    <button type="submit" name="action" value="next" class="ml-auto px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg text-sm transition">
                        Continue →
                    </button>
                </div>
            </form>
        @else
            <h2 class="text-lg font-bold text-slate-900 mb-2">Odoo Integration Setup</h2>
            <p class="text-sm text-slate-600 mb-6">The selected services do not require Odoo integration at this time. You can configure it later in the settings.</p>

            <form method="POST" action="/admin/tenants/wizard-step" class="space-y-4">
                @csrf
                <input type="hidden" name="step" value="4">
                <input type="hidden" name="action" value="next">

                <div class="flex gap-3 pt-4 border-t border-slate-200">
                    <button type="submit" name="action" value="prev" class="px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 rounded-lg transition">
                        ← Back
                    </button>
                    <button type="submit" name="action" value="next" class="ml-auto px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg text-sm transition">
                        Continue →
                    </button>
                </div>
            </form>
        @endif
    </div>
</div>
@endsection
