@extends('admin.layout')
@section('title', 'Create Tenant - Step 3')

@section('header')
    <div>
        <h1 class="text-2xl font-bold text-slate-900">Create Tenant</h1>
        <p class="text-xs text-slate-500 mt-1">Step 3 of 5 · Select Services</p>
    </div>
@endsection

@section('content')
<div class="max-w-4xl">
    @include('admin.tenants.create-wizard.stepper', ['step' => 3])

    <div class="panel p-6">
        <h2 class="text-lg font-bold text-slate-900 mb-2">Select Services</h2>
        <p class="text-sm text-slate-600 mb-6">Choose which services to enable for this workspace. All modules within a selected service will be automatically enabled.</p>

        @if ($errors->any())
            <div class="text-sm text-rose-700 bg-rose-50 border border-rose-200 rounded-lg p-4 mb-4">
                <div class="font-semibold mb-1">Please select at least one service:</div>
                @foreach ($errors->all() as $err) <div>{{ $err }}</div> @endforeach
            </div>
        @endif

        <form method="POST" action="/admin/tenants/wizard-step" class="space-y-4">
            @csrf
            <input type="hidden" name="step" value="3">
            <input type="hidden" name="action" value="next">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach ($services as $service)
                    @php
                        $checked = in_array($service['key'], $data);
                        $serviceModules = collect($modules)->filter(fn ($m) => ($m['service'] ?? null) === $service['key']);
                    @endphp
                    <label class="block border-2 rounded-lg p-4 cursor-pointer transition
                                  {{ $checked ? 'border-indigo-500 bg-indigo-50' : 'border-slate-200 hover:border-slate-300' }}">
                        <div class="flex items-start gap-3">
                            <input type="checkbox" name="services[]" value="{{ $service['key'] }}"
                                   {{ $checked ? 'checked' : '' }}
                                   class="mt-1 w-4 h-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold text-slate-900 text-sm">{{ $service['label'] }}</div>
                                <p class="text-xs text-slate-600 mt-1">{{ $service['description'] }}</p>

                                @if ($serviceModules->count() > 0)
                                    <div class="mt-3 pt-3 border-t border-slate-200">
                                        <p class="text-xs font-medium text-slate-700 mb-2">Includes {{ $serviceModules->count() }} module(s):</p>
                                        <ul class="text-xs text-slate-600 space-y-1">
                                            @foreach ($serviceModules->take(5) as $module)
                                                <li class="flex items-center gap-2">
                                                    <span class="w-1 h-1 bg-slate-400 rounded-full"></span>
                                                    {{ $module['label'] }}
                                                </li>
                                            @endforeach
                                            @if ($serviceModules->count() > 5)
                                                <li class="text-slate-500">… and {{ $serviceModules->count() - 5 }} more</li>
                                            @endif
                                        </ul>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </label>
                @endforeach
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
