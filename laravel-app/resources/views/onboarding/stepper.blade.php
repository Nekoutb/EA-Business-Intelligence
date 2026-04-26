{{-- Onboarding stepper header --}}
<div class="mb-6">
    <div class="flex items-center justify-between mb-4">
        @foreach([1, 2, 3, 4, 5] as $s)
            <div class="flex flex-col items-center flex-1">
                <div class="flex items-center w-full">
                    @if ($s > 1)
                        <div class="flex-1 h-1 mx-2 {{ $s <= $step ? 'bg-indigo-500' : 'bg-slate-200' }}"></div>
                    @endif
                    <div class="w-10 h-10 rounded-full flex items-center justify-center font-semibold text-sm transition-colors
                                {{ $s < $step ? 'bg-indigo-500 text-white' : ($s === $step ? 'bg-indigo-600 text-white ring-4 ring-indigo-100' : 'bg-slate-200 text-slate-600') }}">
                        @if ($s < $step)
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        @else
                            {{ $s }}
                        @endif
                    </div>
                    @if ($s < 5)
                        <div class="flex-1 h-1 mx-2 {{ $s < $step ? 'bg-indigo-500' : 'bg-slate-200' }}"></div>
                    @endif
                </div>
                <div class="text-xs font-medium text-slate-600 mt-2 text-center px-1 line-clamp-2">
                    @switch($s)
                        @case(1) Company Info @break
                        @case(2) Services @break
                        @case(3) Integration @break
                        @case(4) Configuration @break
                        @case(5) Review @break
                    @endswitch
                </div>
            </div>
        @endforeach
    </div>
</div>
