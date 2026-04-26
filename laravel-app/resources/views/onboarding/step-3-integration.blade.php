@extends('layouts.app')
@section('title', 'Onboarding: Odoo Integration')

@section('content')
<div class="min-h-screen bg-slate-50 py-12">
    <div class="max-w-2xl mx-auto px-4">
        @include('onboarding.stepper', ['step' => 3])

        <div class="bg-white rounded-lg shadow-sm p-8">
            @if ($needsOdoo)
                <h2 class="text-2xl font-bold text-slate-900 mb-2">Connect Your Odoo Instance</h2>
                <p class="text-slate-600 mb-8">Your enabled services require Odoo integration. Provide your Odoo instance credentials below.</p>

                @if ($errors->any())
                    <div class="text-sm text-rose-700 bg-rose-50 border border-rose-200 rounded-lg p-4 mb-6">
                        <div class="font-semibold mb-2">Please fix the errors:</div>
                        @foreach ($errors->all() as $err) <div>{{ $err }}</div> @endforeach
                    </div>
                @endif

                <form method="POST" action="/t/{{ $tenant->slug }}/onboarding/step" class="space-y-5">
                    @csrf
                    <input type="hidden" name="step" value="3">
                    <input type="hidden" name="action" value="next">

                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">Odoo URL <span class="text-red-500">*</span></span>
                        <input type="url" name="ODOO_URL"
                               value="{{ old('ODOO_URL', $data['ODOO_URL'] ?? $currentOdoo['ODOO_URL'] ?? '') }}"
                               required
                               class="mt-2 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                               placeholder="https://odoo.example.com">
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">Database Name <span class="text-red-500">*</span></span>
                        <input type="text" name="ODOO_DB"
                               value="{{ old('ODOO_DB', $data['ODOO_DB'] ?? $currentOdoo['ODOO_DB'] ?? '') }}"
                               required
                               class="mt-2 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                               placeholder="production">
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">Username <span class="text-red-500">*</span></span>
                        <input type="text" name="ODOO_USERNAME"
                               value="{{ old('ODOO_USERNAME', $data['ODOO_USERNAME'] ?? $currentOdoo['ODOO_USERNAME'] ?? '') }}"
                               required
                               class="mt-2 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                               placeholder="api@example.com">
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">API Key <span class="text-red-500">*</span></span>
                        <input type="password" name="ODOO_API_KEY"
                               value="{{ old('ODOO_API_KEY', $data['ODOO_API_KEY'] ?? '') }}"
                               required
                               class="mt-2 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                               placeholder="••••••••••">
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">Protocol <span class="text-red-500">*</span></span>
                        <select name="ODOO_PROTOCOL" required
                                class="mt-2 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                            <option value="json2" {{ old('ODOO_PROTOCOL', $data['ODOO_PROTOCOL'] ?? $currentOdoo['ODOO_PROTOCOL'] ?? 'json2') === 'json2' ? 'selected' : '' }}>JSON-RPC v2</option>
                            <option value="jsonrpc" {{ old('ODOO_PROTOCOL', $data['ODOO_PROTOCOL'] ?? '') === 'jsonrpc' ? 'selected' : '' }}>JSON-RPC</option>
                            <option value="xmlrpc" {{ old('ODOO_PROTOCOL', $data['ODOO_PROTOCOL'] ?? '') === 'xmlrpc' ? 'selected' : '' }}>XML-RPC</option>
                        </select>
                    </label>

                    <div class="flex gap-3 pt-6 border-t border-slate-200">
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
                <h2 class="text-2xl font-bold text-slate-900 mb-2">Odoo Integration</h2>
                <p class="text-slate-600 mb-8">Your enabled services do not require Odoo integration at this time. You can configure it later in settings if needed.</p>

                <form method="POST" action="/t/{{ $tenant->slug }}/onboarding/step" class="space-y-5">
                    @csrf
                    <input type="hidden" name="step" value="3">
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
</div>
@endsection
