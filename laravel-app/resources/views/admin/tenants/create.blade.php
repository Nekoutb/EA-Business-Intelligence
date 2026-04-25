@extends('admin.layout')
@section('title', 'New tenant')

@section('header')
    <div>
        <h1 class="text-2xl font-bold text-slate-900">Create Tenant</h1>
        <p class="text-xs text-slate-500 mt-1">Provision a workspace and bootstrap an owner user.</p>
    </div>
@endsection

@section('content')
<form method="POST" action="/admin/tenants" class="grid lg:grid-cols-3 gap-4 max-w-5xl">
    @csrf

    <div class="panel p-5 lg:col-span-2 space-y-4">
        <h2 class="text-sm font-semibold text-slate-900">Workspace</h2>
        @if ($errors->any())
            <div class="text-xs text-rose-700 bg-rose-50 border border-rose-200 rounded-lg p-3">
                @foreach ($errors->all() as $err) <div>{{ $err }}</div> @endforeach
            </div>
        @endif

        <div class="grid grid-cols-2 gap-4">
            <label class="block">
                <span class="text-xs font-medium text-slate-600">Slug (URL)</span>
                <input name="slug" value="{{ old('slug') }}" required pattern="[A-Za-z0-9_-]+"
                       class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono"
                       placeholder="acme">
                <p class="text-[10px] text-slate-400 mt-1">Tenant URL: /t/{slug}</p>
            </label>
            <label class="block">
                <span class="text-xs font-medium text-slate-600">Display name</span>
                <input name="name" value="{{ old('name') }}" required
                       class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
            </label>
            <label class="block">
                <span class="text-xs font-medium text-slate-600">Brand (optional)</span>
                <input name="brand" value="{{ old('brand') }}"
                       class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
            </label>
            <label class="block">
                <span class="text-xs font-medium text-slate-600">Plan</span>
                <input name="plan" value="{{ old('plan', 'standard') }}"
                       class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
            </label>
        </div>

        <div class="border-t border-slate-100 pt-4">
            <h3 class="text-sm font-semibold text-slate-900 mb-2">Owner user</h3>
            <div class="grid grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-xs font-medium text-slate-600">Name</span>
                    <input name="owner_name" value="{{ old('owner_name') }}" required
                           class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-slate-600">Email</span>
                    <input name="owner_email" type="email" value="{{ old('owner_email') }}" required
                           class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
                </label>
                <label class="block col-span-2">
                    <span class="text-xs font-medium text-slate-600">Initial password</span>
                    <input name="owner_password" type="text" required minlength="8"
                           class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono">
                </label>
            </div>
        </div>
    </div>

    <div class="panel p-5 space-y-3">
        <h2 class="text-sm font-semibold text-slate-900">Modules</h2>
        <p class="text-xs text-slate-500">Tick which modules are enabled at provisioning. Core modules are always on.</p>
        <div class="space-y-1.5">
            @foreach ($modules as $m)
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="modules[]" value="{{ $m['key'] }}"
                           @checked(!empty($m['default']))
                           @disabled(!empty($m['core']))
                           class="rounded border-slate-300">
                    <span class="text-slate-700">{{ $m['label'] }}</span>
                    @if (!empty($m['core'])) <span class="text-[9px] text-amber-600 font-semibold">CORE</span> @endif
                </label>
            @endforeach
        </div>

        <div class="pt-3 border-t border-slate-100">
            <button class="w-full bg-gradient-to-r from-rose-600 to-orange-600 hover:from-rose-700 hover:to-orange-700 text-white font-semibold py-2.5 rounded-lg text-sm shadow transition">
                Create tenant
            </button>
            <a href="/admin/tenants" class="block text-center text-xs text-slate-500 mt-2 hover:underline">Cancel</a>
        </div>
    </div>
</form>
@endsection
