@extends('layouts.app')
@section('title', 'Clearance · ' . $title)
@section('page-title', 'Clearance Intelligence')
@section('content')
@include('clearance-intel._shell')
@if ($rec && $rec['file'])
    @php
        $f = $rec['file']; $L = $rec['labels']; $x = $fact ?? [];
        $card = 'bg-white rounded-xl border border-[#e8e4de] p-4 shadow-sm';
        $h3 = 'text-[13px] font-semibold text-[#1e3d44] mb-2';
        $th = 'px-2 py-2 text-[10px] font-semibold uppercase tracking-wide text-[#8a8578]';
        $td = 'px-2 py-1.5 text-[12px] text-[#2d3748] align-top';
        $trig = (array) config('clearance_intel.triggers');
        $dt = fn ($v) => $v ? \App\Services\ClearanceIntel\Fmt::d(\App\Services\ClearanceIntel\Engine::d($v)) : '—';
        $kv = function ($label, $value) { return '<div class="flex justify-between gap-3 py-1 border-t border-[#f1ede5] text-[12px]"><span class="text-[#7c7466]">' . e($label) . '</span><span class="text-right font-medium text-[#2d3748]">' . e($value) . '</span></div>'; };
        $milestones = [
            'Opened' => $f['date_opened'], 'Commercial invoice received' => $f['commercial_invoice_received'], 'All documents received' => $f['documents_received_on'],
            'Work started' => $f['date_started'], 'Manifesting' => $f['manifesting_date'], 'Transit start' => $f['transit_start'], 'Transit end' => $f['transit_end'],
            'Delivered' => $f['delivery_date'], 'Closed for operations' => $f['date_ops_closed'], 'Invoiced' => $f['date_billed'], 'Closed' => $f['date_closed'],
        ];
        $ciQs = $filter ? '?' . http_build_query($filter) : '';
    @endphp
    <a href="{{ tenant_url('/clearance/files') }}{{ $ciQs }}" class="text-[12px] text-brand-700 hover:underline">← all files</a>
    <div class="flex flex-wrap items-baseline gap-3 mt-1 mb-3">
        <h2 class="text-lg font-semibold text-[#1e3d44]">{{ $f['name'] }}</h2>
        <span class="text-[12px] px-2 py-0.5 rounded bg-[#f5f2ec] text-[#5b5547]">{{ $L['file_state'][$f['state']] ?? $f['state'] }}</span>
        <span class="text-[12px] text-[#5b5547]">{{ \App\Services\Clearance\ClearanceData::m2oName($f['partner_id']) }} · {{ \App\Services\Clearance\ClearanceData::m2oName($f['service_type_id']) }} · owner {{ \App\Services\Clearance\ClearanceData::m2oName($f['user_id']) }}</span>
        @if ($f['stage_owner'])<span class="text-[12px] text-[#7c7466]">sitting with {{ $f['stage_owner'] }}{{ $f['stage_detail'] ? ' — ' . $f['stage_detail'] : '' }}</span>@endif
    </div>

    @if (!empty($x['flags']))
        <div class="bg-rose-50 border border-rose-200 rounded-xl p-3 mb-4">
            <div class="text-[12px] font-semibold text-rose-900 mb-1">{{ count($x['flags']) }} trigger{{ count($x['flags']) > 1 ? 's' : '' }} on this file</div>
            @foreach ($x['flags'] as $fl)
                <div class="text-[12px] text-rose-900"><b>{{ $trig[$fl][0] ?? $fl }}</b> — {{ str_replace('{days}', \App\Services\ClearanceIntel\Targets::triggerDays($fl), $trig[$fl][1] ?? '') }}</div>
            @endforeach
        </div>
    @endif

    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 mb-4">
        @foreach ([['Fees HT', \App\Services\ClearanceIntel\Fmt::xaf($x['fees'] ?? null)], ['Contribution', \App\Services\ClearanceIntel\Fmt::xaf($x['contribution'] ?? null)],
                   ['Operating cost share', \App\Services\ClearanceIntel\Fmt::xaf($x['overhead'] ?? null)], ['Net margin', \App\Services\ClearanceIntel\Fmt::xaf($x['net'] ?? null)],
                   ['Débours incurred / billed', \App\Services\ClearanceIntel\Fmt::xaf((float) $f['debours_incurred']) . ' / ' . \App\Services\ClearanceIntel\Fmt::xaf($x['debours_billed'] ?? null)],
                   ['Opening → invoice', isset($x['open_to_bill']) && $x['open_to_bill'] !== null ? $x['open_to_bill'] . ' days' : '—']] as [$l, $v])
            <div class="bg-white rounded-xl border border-[#e8e4de] p-3 shadow-sm">
                <div class="text-[11px] text-[#7c7466]">{{ $l }}</div>
                <div class="text-[17px] font-semibold text-[#1e3d44] tabular-nums">{{ $v }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid lg:grid-cols-3 gap-4 mb-4">
        <div class="{{ $card }}">
            <h3 class="{{ $h3 }}">Shipment</h3>
            {!! $kv('Customs regime', $f['customs_regime'] ?: '—') !!}
            {!! $kv('Port', \App\Services\Clearance\ClearanceData::m2oName($f['port_id'])) !!}
            {!! $kv('Shipment type', $f['shipment_type'] ?: '—') !!}
            {!! $kv('Containers (equivalent)', (int) $f['container_count'] . ' (' . (int) $f['equivalent_container_count'] . ')') !!}
            {!! $kv('Packages / weight', (int) $f['package_count'] . ' / ' . number_format((float) $f['weight_kg'], 0, '.', ' ') . ' kg') !!}
            {!! $kv('Cargo value', number_format((float) $f['cargo_value'], 0, '.', ' ')) !!}
            {!! $kv('Documents complete', $f['documents_complete'] ? 'yes' : ('no — ' . (int) $f['missing_mandatory_count'] . ' missing')) !!}
        </div>
        <div class="{{ $card }}">
            <h3 class="{{ $h3 }}">Billing</h3>
            {!! $kv('HAD', \App\Services\ClearanceIntel\Fmt::full($x['fee_had'] ?? null)) !!}
            {!! $kv('Commission', \App\Services\ClearanceIntel\Fmt::full($x['fee_commission'] ?? null) . ' (rate ' . ((float) $f['billing_commission_rate'] ?: (float) $f['commission_rate']) . ' %' . ($f['billing_commission_skipped'] ? ', skipped' : '') . ')') !!}
            {!! $kv('Frais de dossier', \App\Services\ClearanceIntel\Fmt::full($x['fee_file'] ?? null)) !!}
            {!! $kv('Other services', \App\Services\ClearanceIntel\Fmt::full($x['fee_other'] ?? null)) !!}
            {!! $kv('Débours absorbed', \App\Services\ClearanceIntel\Fmt::full($x['undercharge'] ?? null)) !!}
            {!! $kv('Recharge adjustment', $f['recharge_state'] && $f['recharge_state'] !== 'none' ? \App\Services\ClearanceIntel\Fmt::full((float) $f['recharge_variance']) . ' (' . $f['recharge_state'] . ')' : 'at cost') !!}
            {!! $kv('Balance due', \App\Services\ClearanceIntel\Fmt::full((float) $f['invoice_balance_due'])) !!}
            {!! $kv('Unjustified advances', \App\Services\ClearanceIntel\Fmt::full((float) $f['unjustified_advance_total'])) !!}
        </div>
        <div class="{{ $card }}">
            <h3 class="{{ $h3 }}">Milestones</h3>
            @foreach ($milestones as $ml => $mv){!! $kv($ml, $dt($mv)) !!}@endforeach
            {!! $kv('Overall turnaround', $f['delivery_date'] ? number_format((float) $f['overall_turnaround_days'], 1) . ' days' : '—') !!}
            {!! $kv('Transit time', $f['transit_end'] ? number_format((float) $f['transit_days'], 1) . ' days' : '—') !!}
        </div>
    </div>

    <div class="{{ $card }} mb-4">
        <h3 class="{{ $h3 }}">Workflow steps against target</h3>
        <table class="w-full">
            <thead class="bg-[#faf9f6]"><tr><th class="{{ $th }} text-left">Step</th><th class="{{ $th }} text-left">Started</th><th class="{{ $th }} text-left">Done</th>
                <th class="{{ $th }} text-right">Days</th><th class="{{ $th }} text-right">Target (d)</th><th class="{{ $th }} text-left">Status</th></tr></thead>
            <tbody>
            @forelse ($rec['steps'] as $s)
                <tr class="border-t border-[#f1ede5]">
                    <td class="{{ $td }}">{{ $L['step'][$s['step']] ?? $s['step'] }}</td>
                    <td class="{{ $td }}">{{ $dt($s['started']) }}</td><td class="{{ $td }}">{{ $dt($s['completed']) }}</td>
                    <td class="{{ $td }} text-right tabular-nums">{{ number_format((float) $s['days_taken'], 1) }}</td>
                    <td class="{{ $td }} text-right tabular-nums">{{ $s['target_hours'] !== false ? number_format((float) $s['target_hours'] / 24, 1) : '—' }}</td>
                    <td class="{{ $td }}">
                        @if ($s['is_late'])<span class="text-[11px] px-1.5 py-0.5 rounded bg-rose-50 text-rose-800">over target</span>
                        @elseif ($s['is_done'])<span class="text-[11px] px-1.5 py-0.5 rounded bg-emerald-50 text-emerald-800">on time</span>
                        @else<span class="text-[11px] px-1.5 py-0.5 rounded bg-slate-50 text-slate-600">running</span>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="{{ $td }} text-[#8a8578]">No measured steps.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="{{ $card }} mb-4">
        <h3 class="{{ $h3 }}">Disbursements ({{ count($rec['expenses']) }})</h3>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-[#faf9f6]"><tr><th class="{{ $th }} text-left">Ref</th><th class="{{ $th }} text-left">Category</th><th class="{{ $th }} text-left">Third party</th>
                    <th class="{{ $th }} text-right">Amount</th><th class="{{ $th }} text-left">Mode</th><th class="{{ $th }} text-left">State</th>
                    <th class="{{ $th }} text-left">Requested</th><th class="{{ $th }} text-left">Paid</th><th class="{{ $th }} text-right">Req → paid (d)</th>
                    <th class="{{ $th }} text-left">Justified</th><th class="{{ $th }} text-left">Billed</th></tr></thead>
                <tbody>
                @forelse ($rec['expenses'] as $e)
                    <tr class="border-t border-[#f1ede5] {{ $e['state'] === 'cancel' ? 'opacity-50' : '' }}">
                        <td class="{{ $td }} whitespace-nowrap">{{ $e['name'] }}</td>
                        <td class="{{ $td }}">{{ \App\Services\Clearance\ClearanceData::m2oName($e['category_id']) }}{{ $e['category_is_urgent'] ? ' (urgent)' : '' }}</td>
                        <td class="{{ $td }}">{{ \App\Services\Clearance\ClearanceData::m2oName($e['vendor_id']) }}{{ $e['employee_id'] ? ' · ' . \App\Services\Clearance\ClearanceData::m2oName($e['employee_id']) : '' }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ \App\Services\ClearanceIntel\Fmt::full((float) $e['amount']) }}</td>
                        <td class="{{ $td }}">{{ $L['payment_mode'][$e['payment_mode']] ?? '—' }}</td>
                        <td class="{{ $td }}">{{ $L['expense_state'][$e['state']] ?? $e['state'] }}</td>
                        <td class="{{ $td }}">{{ $dt($e['date_requested']) }}</td><td class="{{ $td }}">{{ $dt($e['date_settled']) }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ \App\Services\ClearanceIntel\Engine::days($e['date_requested'], $e['date_settled']) ?? '—' }}</td>
                        <td class="{{ $td }}">{{ $dt($e['date_justified']) }}</td>
                        <td class="{{ $td }}">{{ $e['is_billed'] ? 'yes' : 'no' }}{{ $e['recharge_amount'] ? ' (recharged ' . \App\Services\ClearanceIntel\Fmt::full((float) $e['recharge_amount']) . ')' : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="{{ $td }} text-[#8a8578]">No disbursement.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-4 mb-4">
        <div class="{{ $card }}">
            <h3 class="{{ $h3 }}">Invoices and credit notes</h3>
            <table class="w-full">
                <thead class="bg-[#faf9f6]"><tr><th class="{{ $th }} text-left">Number</th><th class="{{ $th }} text-left">Date</th><th class="{{ $th }} text-right">HT</th>
                    <th class="{{ $th }} text-right">TTC</th><th class="{{ $th }} text-right">Due</th><th class="{{ $th }} text-left">Status</th></tr></thead>
                <tbody>
                @forelse ($rec['moves'] as $m)
                    <tr class="border-t border-[#f1ede5]">
                        <td class="{{ $td }}">{{ $m['name'] }}{{ $m['move_type'] === 'out_refund' ? ' (credit note)' : '' }}</td>
                        <td class="{{ $td }}">{{ $dt($m['invoice_date']) }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ \App\Services\ClearanceIntel\Fmt::full((float) $m['amount_untaxed']) }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ \App\Services\ClearanceIntel\Fmt::full((float) $m['amount_total']) }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ \App\Services\ClearanceIntel\Fmt::full((float) $m['amount_residual']) }}</td>
                        <td class="{{ $td }}">{{ $m['clearance_voided'] ? 'cancelled' : ($m['state'] . ' · ' . ($m['payment_state'] ?: '')) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="{{ $td }} text-[#8a8578]">Not invoiced.</td></tr>
                @endforelse
                </tbody>
            </table>
            @if ($rec['lines'])
                <div class="text-[11px] font-semibold text-[#5b5547] mt-3 mb-1">Invoice lines by nature</div>
                <table class="w-full">
                    @foreach ($rec['lines'] as $l)
                        <tr class="border-t border-[#f1ede5]">
                            <td class="{{ $td }}">{{ $L['category'][$l['clearance_category']] ?? $l['clearance_category'] }}{{ $l['clearance_service_kind'] ? ' · ' . ($L['service_kind'][$l['clearance_service_kind']] ?? '') : '' }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ \App\Services\ClearanceIntel\Fmt::full((float) $l['price_subtotal']) }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>
        <div class="{{ $card }}">
            <h3 class="{{ $h3 }}">Documents, re-openings and waiting actions</h3>
            @foreach ($rec['documents'] as $doc)
                {!! $kv(\App\Services\Clearance\ClearanceData::m2oName($doc['document_type_id']) . ($doc['is_mandatory'] ? ' *' : ''), $doc['received'] ? 'received ' . $dt($doc['date_received']) : 'missing') !!}
            @endforeach
            @foreach ($rec['reopenings'] as $r)
                {!! $kv('Re-opened ' . $dt($r['date']), \App\Services\Clearance\ClearanceData::m2oName($r['reason_id']) . ' → ' . ($r['target_state'] ?: '')) !!}
            @endforeach
            @foreach ($rec['tasks'] as $t)
                {!! $kv('Waiting: ' . ($L['kind'][$t['kind']] ?? $t['kind']), 'since ' . $dt($t['date_landed'])) !!}
            @endforeach
            @if (!$rec['documents'] && !$rec['reopenings'] && !$rec['tasks'])<div class="text-[12px] text-[#8a8578]">Nothing recorded.</div>@endif
        </div>
    </div>
@endif
@endsection
