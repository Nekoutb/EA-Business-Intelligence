{{-- Expects $rows (file facts), optional $cols extra columns [key => [label, unit]] --}}
@php
    $th = 'px-2 py-2 text-[10px] font-semibold uppercase tracking-wide text-[#8a8578] text-left';
    $td = 'px-2 py-1.5 text-[12px] text-[#2d3748] align-top';
    $trig = (array) config('clearance_intel.triggers');
    $cols = $cols ?? ['fees' => ['Fees', 'xaf'], 'net' => ['Net margin', 'xaf']];
    $ciQs2 = $filter ? '?' . http_build_query($filter) : '';
@endphp
<div class="overflow-x-auto">
    <table class="w-full">
        <thead class="bg-[#faf9f6]"><tr>
            <th class="{{ $th }}">File</th><th class="{{ $th }}">Client</th><th class="{{ $th }}">Service</th>
            <th class="{{ $th }}">State</th><th class="{{ $th }}">Opened</th>
            @foreach ($cols as $ck => [$cl, $cu])<th class="{{ $th }} text-right">{{ $cl }}</th>@endforeach
            <th class="{{ $th }}">Flags</th>
        </tr></thead>
        <tbody>
        @forelse ($rows as $r)
            <tr class="border-t border-[#f1ede5] hover:bg-[#faf9f6]">
                <td class="{{ $td }} font-semibold"><a class="text-brand-700 hover:underline" href="{{ tenant_url('/clearance/files/' . $r['id']) }}{{ $ciQs2 }}">{{ $r['name'] }}</a></td>
                <td class="{{ $td }}">{{ $r['client'] }}</td>
                <td class="{{ $td }}">{{ $r['service'] }}</td>
                <td class="{{ $td }}">{{ $r['state_label'] }}</td>
                <td class="{{ $td }} whitespace-nowrap">{{ \App\Services\ClearanceIntel\Fmt::d($r['opened']) }}</td>
                @foreach ($cols as $ck => [$cl, $cu])<td class="{{ $td }} text-right tabular-nums whitespace-nowrap">{{ \App\Services\ClearanceIntel\Fmt::v($r[$ck] ?? null, $cu) }}</td>@endforeach
                <td class="{{ $td }}">
                    @foreach ($r['flags'] as $fl)
                        <span class="inline-block text-[10px] px-1.5 py-0.5 rounded bg-rose-50 text-rose-800 mr-1 mb-0.5" title="{{ str_replace('{days}', \App\Services\ClearanceIntel\Targets::triggerDays($fl), $trig[$fl][1] ?? '') }}">{{ $trig[$fl][0] ?? $fl }}</span>
                    @endforeach
                </td>
            </tr>
        @empty
            <tr><td colspan="{{ 6 + count($cols) }}" class="{{ $td }} text-[#8a8578] py-4">No files.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
