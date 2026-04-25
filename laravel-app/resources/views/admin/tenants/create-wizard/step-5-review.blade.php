@extends('admin.layout')
@section('title', 'Create Tenant - Step 5')

@section('header')
    <div>
        <h1 class="text-2xl font-bold text-slate-900">Create Tenant</h1>
        <p class="text-xs text-slate-500 mt-1">Step 5 of 5 · Review & Create</p>
    </div>
@endsection

@section('content')
<div class="max-w-2xl">
    @include('admin.tenants.create-wizard.stepper', ['step' => 5])

    <div class="panel p-6">
        <h2 class="text-lg font-bold text-slate-900 mb-6">Review Workspace Configuration</h2>

        <div class="space-y-6">
            {{-- Workspace Section --}}
            <div class="border-b border-slate-200 pb-5">
                <h3 class="font-semibold text-slate-900 text-sm mb-3">Workspace</h3>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-slate-600">Slug:</dt>
                        <dd class="font-mono text-slate-900">/t/{{ $workspace['slug'] ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-600">Display Name:</dt>
                        <dd class="text-slate-900">{{ $workspace['name'] ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-600">Brand:</dt>
                        <dd class="text-slate-900">{{ $workspace['brand'] ?? $workspace['name'] ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-600">Plan:</dt>
                        <dd class="text-slate-900">{{ $workspace['plan'] ?? 'standard' }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Owner Section --}}
            <div class="border-b border-slate-200 pb-5">
                <h3 class="font-semibold text-slate-900 text-sm mb-3">Owner Account</h3>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-slate-600">Name:</dt>
                        <dd class="text-slate-900">{{ $owner['owner_name'] ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-600">Email:</dt>
                        <dd class="text-slate-900">{{ $owner['owner_email'] ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-600">Password:</dt>
                        <dd class="text-slate-600 italic">Set ({{ strlen($owner['owner_password'] ?? '') > 0 ? '✓' : '—' }})</dd>
                    </div>
                </dl>
            </div>

            {{-- Services Section --}}
            <div class="border-b border-slate-200 pb-5">
                <h3 class="font-semibold text-slate-900 text-sm mb-3">Services</h3>
                @if (empty($services))
                    <p class="text-sm text-slate-600 italic">No services selected</p>
                @else
                    <div class="space-y-2">
                        @foreach ($services as $serviceKey)
                            @php $svc = collect($allServices)->firstWhere('key', $serviceKey); @endphp
                            @if ($svc)
                                <div class="flex items-center gap-2">
                                    <span class="w-1.5 h-1.5 bg-indigo-500 rounded-full"></span>
                                    <span class="text-sm text-slate-900">{{ $svc['label'] }}</span>
                                </div>
                                <ul class="ml-4 text-xs text-slate-600 space-y-1">
                                    @foreach (collect($allModules)->filter(fn ($m) => ($m['service'] ?? null) === $serviceKey)->take(5) as $mod)
                                        <li>• {{ $mod['label'] }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Integration Section --}}
            @if (!empty($integration) && !($integration['skip_integration'] ?? false))
                <div class="border-b border-slate-200 pb-5">
                    <h3 class="font-semibold text-slate-900 text-sm mb-3">Odoo Integration</h3>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-slate-600">URL:</dt>
                            <dd class="font-mono text-slate-900 text-xs break-all">{{ $integration['ODOO_URL'] ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-600">Database:</dt>
                            <dd class="text-slate-900">{{ $integration['ODOO_DB'] ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-600">Username:</dt>
                            <dd class="text-slate-900">{{ $integration['ODOO_USERNAME'] ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-600">Protocol:</dt>
                            <dd class="text-slate-900 font-mono">{{ $integration['ODOO_PROTOCOL'] ?? 'json2' }}</dd>
                        </div>
                    </dl>
                </div>
            @endif
        </div>

        {{-- Confirmation --}}
        <form method="POST" action="/admin/tenants" class="mt-6">
            @csrf

            <label class="flex items-center gap-3 mb-5 p-4 border-2 border-amber-200 bg-amber-50 rounded-lg">
                <input type="checkbox" name="confirm" required
                       class="w-4 h-4 rounded border-amber-300 text-amber-600 focus:ring-amber-500">
                <span class="text-sm text-amber-900">
                    I confirm that the workspace configuration above is correct. This action will create the tenant and cannot be undone.
                </span>
            </label>

            <div class="flex gap-3 pt-4 border-t border-slate-200">
                <button type="submit" name="action" value="prev" class="px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 rounded-lg transition"
                        formaction="/admin/tenants/wizard-step" formmethod="POST">
                    ← Back
                </button>
                <button type="submit" class="ml-auto px-6 py-2 bg-gradient-to-r from-rose-600 to-orange-600 hover:from-rose-700 hover:to-orange-700 text-white font-medium rounded-lg text-sm transition shadow">
                    Create Tenant ✓
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
