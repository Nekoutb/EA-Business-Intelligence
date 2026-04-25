@extends('admin.layout')
@section('title', 'Create Tenant - Step 2')

@section('header')
    <div>
        <h1 class="text-2xl font-bold text-slate-900">Create Tenant</h1>
        <p class="text-xs text-slate-500 mt-1">Step 2 of 5 · Owner Account Setup</p>
    </div>
@endsection

@section('content')
<div class="max-w-2xl">
    @include('admin.tenants.create-wizard.stepper', ['step' => 2])

    <div class="panel p-6">
        <h2 class="text-lg font-bold text-slate-900 mb-5">Owner Account Setup</h2>

        @if ($errors->any())
            <div class="text-sm text-rose-700 bg-rose-50 border border-rose-200 rounded-lg p-4 mb-4">
                <div class="font-semibold mb-1">Please fix the following errors:</div>
                @foreach ($errors->all() as $err) <div>{{ $err }}</div> @endforeach
            </div>
        @endif

        <form method="POST" action="/admin/tenants/wizard-step" class="space-y-4">
            @csrf
            <input type="hidden" name="step" value="2">
            <input type="hidden" name="action" value="next">

            <p class="text-sm text-slate-600 mb-4">Create the workspace owner account that will be used for initial login and workspace management.</p>

            <div class="grid grid-cols-2 gap-4">
                <label class="block col-span-2">
                    <span class="text-xs font-medium text-slate-600">Full Name <span class="text-red-500">*</span></span>
                    <input type="text" name="owner_name" value="{{ old('owner_name', $data['owner_name'] ?? '') }}" required maxlength="191"
                           class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                           placeholder="John Doe">
                </label>
                <label class="block col-span-2">
                    <span class="text-xs font-medium text-slate-600">Email Address <span class="text-red-500">*</span></span>
                    <input type="email" name="owner_email" value="{{ old('owner_email', $data['owner_email'] ?? '') }}" required
                           class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                           placeholder="john@acme.com">
                </label>
                <label class="block col-span-2">
                    <span class="text-xs font-medium text-slate-600">Password <span class="text-red-500">*</span></span>
                    <input type="text" name="owner_password" value="{{ old('owner_password') }}" required minlength="8" maxlength="191"
                           class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                           placeholder="At least 8 characters">
                    <p class="text-[10px] text-slate-400 mt-1">Minimum 8 characters. Store securely and share with the workspace owner.</p>
                </label>
                <label class="block col-span-2">
                    <span class="text-xs font-medium text-slate-600">Confirm Password <span class="text-red-500">*</span></span>
                    <input type="text" name="owner_password_confirm" value="{{ old('owner_password_confirm') }}" required minlength="8" maxlength="191"
                           class="mt-1.5 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                           placeholder="Repeat the password">
                </label>
            </div>

            <div class="flex gap-3 pt-4 border-t border-slate-200">
                <button type="submit" name="action" value="prev" class="px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 rounded-lg transition">
                    ← Back
                </button>
                <button type="submit" name="action" value="next" class="ml-auto px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg text-sm transition">
                    Continue →
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
