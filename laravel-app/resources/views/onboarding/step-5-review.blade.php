@extends('layouts.app')
@section('title', 'Onboarding: Review & Complete')

@section('content')
<div class="min-h-screen bg-slate-50 py-12">
    <div class="max-w-2xl mx-auto px-4">
        @include('onboarding.stepper', ['step' => 5])

        <div class="bg-white rounded-lg shadow-sm p-8">
            <h2 class="text-2xl font-bold text-slate-900 mb-2">Ready to Get Started!</h2>
            <p class="text-slate-600 mb-8">Here's a summary of your workspace configuration.</p>

            <div class="space-y-6 mb-8">
                {{-- Company Information --}}
                @if (!empty($company))
                    <div class="border-b border-slate-200 pb-5">
                        <h3 class="font-semibold text-slate-900 text-sm mb-3">Company Information</h3>
                        <dl class="space-y-2 text-sm">
                            @if ($company['company_name'] ?? null)
                                <div class="flex justify-between">
                                    <dt class="text-slate-600">Company:</dt>
                                    <dd class="text-slate-900">{{ $company['company_name'] }}</dd>
                                </div>
                            @endif
                            @if ($company['industry'] ?? null)
                                <div class="flex justify-between">
                                    <dt class="text-slate-600">Industry:</dt>
                                    <dd class="text-slate-900">{{ $company['industry'] }}</dd>
                                </div>
                            @endif
                            @if ($company['tax_id'] ?? null)
                                <div class="flex justify-between">
                                    <dt class="text-slate-600">Tax ID:</dt>
                                    <dd class="font-mono text-slate-900">{{ $company['tax_id'] }}</dd>
                                </div>
                            @endif
                            @if ($company['country'] ?? null)
                                <div class="flex justify-between">
                                    <dt class="text-slate-600">Country:</dt>
                                    <dd class="text-slate-900">{{ $company['country'] }}</dd>
                                </div>
                            @endif
                        </dl>
                    </div>
                @endif

                {{-- Services --}}
                @if (!empty($enabledServices))
                    <div class="border-b border-slate-200 pb-5">
                        <h3 class="font-semibold text-slate-900 text-sm mb-3">Enabled Services</h3>
                        <div class="space-y-2">
                            @foreach ($enabledServices as $serviceKey)
                                @php $svc = collect($allServices)->firstWhere('key', $serviceKey); @endphp
                                @if ($svc)
                                    <div class="flex items-center gap-2">
                                        <span class="w-2 h-2 bg-indigo-500 rounded-full"></span>
                                        <span class="text-sm text-slate-900">{{ $svc['label'] }}</span>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-4">
                    <p class="text-sm text-slate-700">
                        Your workspace is now set up and ready to use! You can access all your enabled modules from the main navigation. You can update your settings anytime.
                    </p>
                </div>
            </div>

            <form method="POST" action="/t/{{ $tenant->slug }}/onboarding/complete" class="space-y-5">
                @csrf

                <label class="flex items-start gap-3 p-4 border-2 border-amber-200 bg-amber-50 rounded-lg">
                    <input type="checkbox" name="confirm" required
                           class="mt-1 w-4 h-4 rounded border-amber-300 text-amber-600 focus:ring-amber-500">
                    <span class="text-sm text-amber-900">
                        I confirm that the information above is correct and I'm ready to start using {{ config('bi.brand') }}.
                    </span>
                </label>

                <div class="flex gap-3 pt-4 border-t border-slate-200">
                    <button type="submit" name="action" value="prev" class="px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 rounded-lg transition"
                            formaction="/t/{{ $tenant->slug }}/onboarding/step" formmethod="POST">
                        ← Back
                    </button>
                    <button type="submit" class="ml-auto px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg text-sm transition">
                        Complete Setup ✓
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
