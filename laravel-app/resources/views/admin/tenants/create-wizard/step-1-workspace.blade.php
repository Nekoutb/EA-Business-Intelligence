@extends('admin.layout')
@section('title', 'Create Tenant - Step 1')

@section('header')
    <div>
        <h1 class="text-2xl font-bold text-slate-900">Create Tenant</h1>
        <p class="text-xs text-slate-500 mt-1">Step 1 of 5 · Workspace Configuration</p>
    </div>
@endsection

@section('content')
<div class="max-w-2xl">
    @include('admin.tenants.create-wizard.stepper', ['step' => 1])

    <div class="panel p-6">
        <h2 class="text-lg font-bold text-slate-900 mb-5">Workspace Configuration</h2>

        @if ($errors->any())
            <div class="text-sm text-rose-700 bg-rose-50 border border-rose-200 rounded-lg p-4 mb-4">
                <div class="font-semibold mb-1">Please fix the following errors:</div>
                @foreach ($errors->all() as $err) <div>{{ $err }}</div> @endforeach
            </div>
        @endif

        <form method="POST" action="/admin/tenants/wizard-step" class="space-y-4">
            @csrf
            <input type="hidden" name="step" value="1">
            <input type="hidden" name="action" value="next">

            <div class="grid grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-xs font-medium text-slate-600">Slug (URL) <span class="text-red-500">*</span></span>
                    <input type="text" name="slug" value="{{ old('slug', $data['slug'] ?? '') }}" required
                           pattern="[A-Za-z0-9_-]+" maxlength="64"
                           class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                           placeholder="acme-corp">
                    <p class="text-[10px] text-slate-400 mt-1">Tenant URL will be: /t/{slug}</p>
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-slate-600">Display Name <span class="text-red-500">*</span></span>
                    <input type="text" name="name" value="{{ old('name', $data['name'] ?? '') }}" required maxlength="191"
                           class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                           placeholder="Acme Corporation">
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-slate-600">Brand Name <span class="text-slate-400">(optional)</span></span>
                    <input type="text" name="brand" value="{{ old('brand', $data['brand'] ?? '') }}" maxlength="191"
                           class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                           placeholder="Acme">
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-slate-600">Plan</span>
                    <input type="text" name="plan" value="{{ old('plan', $data['plan'] ?? 'standard') }}" maxlength="32"
                           class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                           placeholder="standard">
                </label>
            </div>

            <div class="flex gap-3 pt-4 border-t border-slate-200">
                <a href="/admin/tenants" class="px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 rounded-lg transition">
                    Cancel
                </a>
                <button type="submit" name="action" value="next" class="ml-auto px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg text-sm transition">
                    Continue →
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
