@extends('admin.layout')
@section('title', 'Edit Industry Modules')

@section('header')
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">{{ $industry->name }} - Module Bundle</h1>
            <p class="text-xs text-slate-500 mt-1">Configure which modules are enabled by default for {{ $industry->name }} tenants</p>
        </div>
        <a href="/admin/industries" class="px-4 py-2 text-slate-600 hover:text-slate-900 text-sm font-medium">
            ← Back to Industries
        </a>
    </div>
@endsection

@section('content')
<div class="max-w-5xl">
    @if ($flash = session('flash'))
        <div class="mb-4 p-4 rounded-lg {{ $flash['type'] === 'success' ? 'bg-emerald-50 border border-emerald-200 text-emerald-700' : 'bg-rose-50 border border-rose-200 text-rose-700' }}">
            {{ $flash['msg'] }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 p-4 bg-rose-50 border border-rose-200 rounded-lg">
            <div class="font-semibold text-rose-700 mb-2">Please fix the following errors:</div>
            <ul class="list-disc list-inside text-sm text-rose-700 space-y-1">
                @foreach ($errors->all() as $err) <li>{{ $err }}</li> @endforeach
            </ul>
        </div>
    @endif

    <div class="panel p-6">
        <form method="POST" action="/admin/industries/{{ $industry->id }}/edit-modules" class="space-y-6">
            @csrf
            @method('PATCH')

            <div class="space-y-3">
                @foreach ($modules->groupBy(function ($m) { return collect(config('modules.modules', []))->firstWhere('key', $m['key'])['service'] ?? 'other'; }) as $service => $serviceModules)
                    <div class="border border-slate-200 rounded-lg overflow-hidden">
                        <div class="bg-slate-50 px-4 py-3 border-b border-slate-200">
                            <h3 class="font-semibold text-slate-700 text-sm">
                                {{ collect(config('modules.services', []))->firstWhere('key', $service)['label'] ?? ucfirst(str_replace('_', ' ', $service)) }}
                            </h3>
                        </div>
                        <div class="divide-y divide-slate-200">
                            @foreach ($serviceModules as $idx => $module)
                                <div class="px-4 py-3 hover:bg-slate-50 flex items-start gap-3">
                                    <input type="hidden" name="modules[{{ $idx }}][key]" value="{{ $module['key'] }}">

                                    <div class="flex-1">
                                        <p class="text-sm font-medium text-slate-900">{{ $module['label'] }}</p>
                                        @php
                                            $moduleConfig = collect(config('modules.modules', []))->firstWhere('key', $module['key']);
                                            if ($moduleConfig['core'] ?? false) {
                                                echo '<p class="text-[10px] text-slate-400 mt-0.5">Core module (always required)</p>';
                                            }
                                        @endphp
                                    </div>

                                    <div class="flex items-center gap-4">
                                        <label class="flex items-center gap-2">
                                            <input type="checkbox" name="modules[{{ $idx }}][default]" value="1"
                                                   {{ $module['enabled_by_default'] ? 'checked' : '' }}
                                                   class="w-4 h-4 border border-slate-300 rounded focus:ring-2 focus:ring-indigo-500">
                                            <span class="text-xs text-slate-600">Enabled by default</span>
                                        </label>
                                        <label class="flex items-center gap-2">
                                            <input type="checkbox" name="modules[{{ $idx }}][required]" value="1"
                                                   {{ $module['required'] ? 'checked' : '' }}
                                                   class="w-4 h-4 border border-slate-300 rounded focus:ring-2 focus:ring-indigo-500">
                                            <span class="text-xs text-slate-600">Required</span>
                                        </label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="flex gap-3 pt-4 border-t border-slate-200">
                <a href="/admin/industries" class="px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 rounded-lg transition">
                    Cancel
                </a>
                <button type="submit" class="ml-auto px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg text-sm transition">
                    Save Module Bundle
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
