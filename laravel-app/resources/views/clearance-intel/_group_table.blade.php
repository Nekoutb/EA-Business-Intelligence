{{-- Expects $rows (grouped rows: label, files, ...), $cols [key => [label, unit]], $first label --}}
@php
    $th = 'px-2 py-2 text-[10px] font-semibold uppercase tracking-wide text-[#8a8578]';
    $td = 'px-2 py-1.5 text-[12px] text-[#2d3748]';
    $tot = max(1, array_sum(array_column($rows, 'files')));
@endphp
<div class="overflow-x-auto">
    <table class="w-full">
        <thead class="bg-[#faf9f6]"><tr>
            <th class="{{ $th }} text-left">{{ $first ?? '' }}</th>
            <th class="{{ $th }} text-right">Files</th><th class="{{ $th }} text-right">Share</th>
            @foreach (($cols ?? []) as $ck => [$cl, $cu])<th class="{{ $th }} text-right">{{ $cl }}</th>@endforeach
        </tr></thead>
        <tbody>
        @forelse ($rows as $r)
            <tr class="border-t border-[#f1ede5]">
                <td class="{{ $td }} font-medium">{{ $r['label'] }}</td>
                <td class="{{ $td }} text-right tabular-nums">{{ number_format($r['files']) }}</td>
                <td class="{{ $td }} text-right tabular-nums text-[#7c7466]">{{ number_format(100 * $r['files'] / $tot, 0) }} %</td>
                @foreach (($cols ?? []) as $ck => [$cl, $cu])<td class="{{ $td }} text-right tabular-nums">{{ \App\Services\ClearanceIntel\Fmt::v($r[$ck] ?? null, $cu) }}</td>@endforeach
            </tr>
        @empty
            <tr><td colspan="{{ 3 + count($cols ?? []) }}" class="{{ $td }} text-[#8a8578] py-4">Nothing in this period.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
