@extends('layouts.app')
@section('title', 'Payables')

@section('page-title', 'Accounts Payable')
@section('content')
    @if ($error)
        <div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-xl p-4 text-sm">{{ $error }}</div>
    @elseif ($data)
        @php $ccy = $data['currency'] ?? 'USD'; @endphp
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 mb-6">
            <x-kpi title="Total AP"  :value="ea_money_short((float)($data['total_ap'] ?? 0), $ccy)" color="indigo"/>
            <x-kpi title="Overdue AP" :value="ea_money_short((float)($data['overdue_ap'] ?? 0), $ccy)" color="rose"/>
            <x-kpi title="DPO"        :value="number_format((float)($data['dpo'] ?? 0), 0).' d'" color="amber"/>
            <x-kpi title="Top Vendor %" :value="number_format((float)($data['top5_pct'] ?? 0), 1).'%'" color="slate"/>
        </div>

        @if (!empty($data['top_partners']))
          <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
              <h2 class="text-sm font-semibold text-slate-900 mb-3">Top vendors</h2>
              <div class="overflow-x-auto">
                  <table class="w-full text-sm">
                      <thead><tr class="text-left text-xs text-slate-500 uppercase tracking-wide border-b border-slate-100">
                          <th class="pb-2">Vendor</th><th class="pb-2 text-right">AP</th></tr></thead>
                      <tbody class="divide-y divide-slate-50">
                          @foreach (array_slice($data['top_partners'], 0, 10) as $p)
                            <tr class="hover:bg-slate-50">
                                <td class="py-2 text-slate-700">{{ $p['name'] ?? '—' }}</td>
                                <td class="py-2 text-right tabular-nums">{{ ea_money((float)($p['ap'] ?? 0), $ccy) }}</td>
                            </tr>
                          @endforeach
                      </tbody>
                  </table>
              </div>
          </div>
        @endif
    @endif
@endsection
