@extends('layouts.app')
@section('title', 'Onboarding: Review Services')

@section('content')
<div class="min-h-screen bg-slate-50 py-12">
    <div class="max-w-3xl mx-auto px-4">
        @include('onboarding.stepper', ['step' => 2])

        <div class="bg-white rounded-lg shadow-sm p-8">
            <h2 class="text-2xl font-bold text-slate-900 mb-2">Your Enabled Services</h2>
            <p class="text-slate-600 mb-8">These are the services currently enabled for your workspace. You can manage them later in settings.</p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
                @foreach ($enabledServices as $serviceKey)
                    @php $service = collect($services)->firstWhere('key', $serviceKey); @endphp
                    @if ($service)
                        <div class="border-2 border-indigo-200 bg-indigo-50 rounded-lg p-4">
                            <div class="font-semibold text-slate-900 text-sm mb-1">{{ $service['label'] }}</div>
                            <p class="text-xs text-slate-600 mb-3">{{ $service['description'] }}</p>

                            @php $serviceModules = collect($modules)->filter(fn ($m) => ($m['service'] ?? null) === $serviceKey); @endphp
                            @if ($serviceModules->count() > 0)
                                <div class="pt-3 border-t border-indigo-200">
                                    <p class="text-xs font-medium text-slate-700 mb-2">Modules included:</p>
                                    <ul class="text-xs text-slate-600 space-y-1">
                                        @foreach ($serviceModules->take(6) as $module)
                                            <li class="flex items-center gap-2">
                                                <span class="w-1 h-1 bg-indigo-500 rounded-full"></span>
                                                {{ $module['label'] }}
                                            </li>
                                        @endforeach
                                        @if ($serviceModules->count() > 6)
                                            <li class="text-indigo-600">… and {{ $serviceModules->count() - 6 }} more</li>
                                        @endif
                                    </ul>
                                </div>
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>

            <form method="POST" action="/t/{{ $tenant->slug }}/onboarding/step" class="space-y-5">
                @csrf
                <input type="hidden" name="step" value="2">
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
        </div>
    </div>
</div>
@endsection
