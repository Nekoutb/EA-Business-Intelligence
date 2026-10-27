@php
    $kk = $data['kpis'][$key] ?? null;
@endphp
@if ($kk)
    @php
        $rs = \App\Services\ClearanceIntel\Fmt::ragStyle($kk['rag']);
        $dl = \App\Services\ClearanceIntel\Fmt::delta($kk['value'], $kk['prev'], $kk['unit'], $kk['better']);
        $tgt = $kk['target'] === null ? null
            : ($kk['better'] === 'down' ? '≤ ' : '≥ ') . \App\Services\ClearanceIntel\Fmt::v($kk['target'], $kk['unit']);
    @endphp
    <div class="relative bg-white rounded-xl border border-[#e8e4de] shadow-sm p-3 pl-4 overflow-hidden" title="{{ $kk['definition'] }}">
        <span class="absolute left-0 top-0 bottom-0 w-1 {{ $rs['bar'] }}" aria-hidden="true"></span>
        <div class="flex items-start justify-between gap-2">
            <div class="text-[11px] font-semibold text-[#5b5547] leading-tight">{{ $kk['label'] }}</div>
            @if ($rs['word'])<span class="text-[10px] font-semibold px-1.5 py-0.5 rounded {{ $rs['chip'] }}">{{ $rs['word'] }}</span>@endif
        </div>
        <div class="text-[22px] font-semibold text-[#1e3d44] tabular-nums mt-1">{{ \App\Services\ClearanceIntel\Fmt::v($kk['value'], $kk['unit']) }}</div>
        <div class="flex flex-wrap gap-x-2 text-[11px] mt-0.5">
            @if ($dl['text'])<span class="{{ $dl['class'] }}">{{ $dl['text'] }}</span>@endif
            @if ($kk['prev'] !== null)<span class="text-[#8a8578]">prev {{ \App\Services\ClearanceIntel\Fmt::v($kk['prev'], $kk['unit']) }}</span>@endif
            @if ($tgt)<span class="text-[#8a8578]">target {{ $tgt }}</span>@endif
        </div>
    </div>
@endif
