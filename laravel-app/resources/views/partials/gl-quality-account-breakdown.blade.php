{{-- Reusable trial-balance breakdown table for the GL Quality page.
     Used by both AR and Inventory reconciliation boxes. --}}
<table class="w-full text-xs">
    <thead class="bg-slate-50">
        <tr>
            <th class="text-left py-2 px-5 font-semibold text-slate-600 uppercase tracking-wide">Code</th>
            <th class="text-left py-2 px-3 font-semibold text-slate-600 uppercase tracking-wide">Account</th>
            <th class="text-right py-2 px-5 font-semibold text-slate-600 uppercase tracking-wide">Balance</th>
        </tr>
    </thead>
    <tbody>
        @if (empty($rows))
            <tr><td colspan="3" class="py-4 px-5 text-center text-slate-500">No matching accounts found</td></tr>
        @else
            @foreach ($rows as $row)
                <tr class="border-t border-slate-100 hover:bg-slate-50">
                    <td class="py-2 px-5 text-slate-700">{{ $row['code'] ?? '—' }}</td>
                    <td class="py-2 px-3 text-slate-900">{{ $row['name'] ?? 'Unknown' }}</td>
                    <td class="text-right py-2 px-5 text-slate-700">{{ ea_money_short($row['balance'] ?? 0, $currency) }}</td>
                </tr>
            @endforeach
            <tr class="border-t-2 border-slate-300 bg-slate-50 font-semibold">
                <td class="py-2 px-5"></td>
                <td class="py-2 px-3 text-slate-900">Total</td>
                <td class="text-right py-2 px-5 text-slate-900">{{ ea_money_short($total ?? 0, $currency) }}</td>
            </tr>
        @endif
    </tbody>
</table>
