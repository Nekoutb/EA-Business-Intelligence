@extends('layouts.app')
@section('title', 'Clearance · ' . $title)
@section('page-title', 'Clearance Intelligence')
@section('content')
@include('clearance-intel._shell')
@if ($data)
    @php
        $T = $data['trends']; $B = $data['breakdowns'];
        $card = 'bg-white rounded-xl border border-[#e8e4de] p-4 shadow-sm';
        $h3 = 'text-[13px] font-semibold text-[#1e3d44]';
        $note = 'text-[11px] text-[#7c7466] mb-2';
        $th = 'px-2 py-2 text-[10px] font-semibold uppercase tracking-wide text-[#8a8578]';
        $td = 'px-2 py-1.5 text-[12px] text-[#2d3748]';
    @endphp
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-2">
        @foreach (['files_per_employee', 'files_per_employee_month', 'revenue_per_employee', 'files_per_responsible', 'billing_per_day', 'disbursements_paid', 'keyed_error_pct', 'reassignments'] as $key)
            @include('clearance-intel._kpi', ['key' => $key])
        @endforeach
    </div>
    <p class="text-[11px] text-[#7c7466] mb-5">Headcount from {{ $data['headcount_source'] }}. Files counted = files billed in the period (a file counts once, when its work turns into an invoice).</p>

    <div class="grid lg:grid-cols-2 gap-4 mb-4">
        <div class="{{ $card }}">
            <h3 class="{{ $h3 }}">Files billed per employee per month — 12 months</h3>
            <p class="{{ $note }}">Rising: the team handles more files each. Falling: more people for the same work, or files are stuck.</p>
            @include('clearance-intel._chart', ['cfg' => ['type' => 'line', 'fmt' => 'int', 'labels' => array_column($T, 'label'), 'datasets' => [
                ['label' => 'Files per employee', 'data' => array_column($T, 'files_per_employee_month'), 'color' => '#1e3d44'],
            ]]])
        </div>
        <div class="{{ $card }}">
            <h3 class="{{ $h3 }}">Fee revenue per employee — 12 months</h3>
            <p class="{{ $note }}">Fees HT earned per head each month.</p>
            @include('clearance-intel._chart', ['cfg' => ['type' => 'bar', 'fmt' => 'xaf', 'labels' => array_column($T, 'label'), 'datasets' => [
                ['label' => 'Fees per employee', 'data' => array_column($T, 'revenue_per_employee'), 'color' => '#b07f3b'],
            ]]])
        </div>
    </div>

    <div class="{{ $card }} mb-4">
        <h3 class="{{ $h3 }}">Files per department</h3>
        <p class="{{ $note }}">Distinct files each department acted on in the period: files it owned (opened or billed) and disbursements it keyed, approved or paid. Department = the user's clearance role.</p>
        <div class="grid lg:grid-cols-2 gap-4 items-start">
            <table class="w-full">
                <thead class="bg-[#faf9f6]"><tr>
                    <th class="{{ $th }} text-left">Department</th><th class="{{ $th }} text-right">Members</th>
                    <th class="{{ $th }} text-right">Files</th><th class="{{ $th }} text-right">Files per member</th>
                </tr></thead>
                <tbody>
                @foreach ($B['departments'] as $d)
                    <tr class="border-t border-[#f1ede5]">
                        <td class="{{ $td }} font-medium">{{ $d['dept'] }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ $d['members'] }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ $d['files'] }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ $d['per_member'] ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @include('clearance-intel._chart', ['cfg' => ['type' => 'bar', 'fmt' => 'int', 'horizontal' => true, 'labels' => array_column($B['departments'], 'dept'), 'datasets' => [
                ['label' => 'Files', 'data' => array_column($B['departments'], 'files'), 'color' => '#1e3d44'],
            ]]])
        </div>
    </div>

    @php
        $S = $B['users'];
        $dd = fn ($v) => $v === null ? '—' : number_format((float) $v, 1);
        $roleStaff = array_values(array_filter($S, fn ($u) => $u['role']));
        $chartStaff = array_slice($roleStaff, 0, 20);
    @endphp
    <div class="{{ $card }} mb-4">
        <h3 class="{{ $h3 }}">Files each staff member intervened on</h3>
        <p class="{{ $note }}">Distinct files a person acted on in the period, among staff holding a clearance role.</p>
        @include('clearance-intel._chart', ['h' => 'h-' . (count($chartStaff) > 10 ? '96' : '64'), 'cfg' => ['type' => 'bar', 'fmt' => 'int', 'horizontal' => true,
            'labels' => array_column($chartStaff, 'user'), 'datasets' => [['label' => 'Files', 'data' => array_column($chartStaff, 'files'), 'color' => '#1e3d44']]]])
    </div>

    <div class="{{ $card }}">
        <h3 class="{{ $h3 }}">Productivity per staff member</h3>
        <p class="{{ $note }}">Every user holding a clearance role (and anyone else who acted in the period). Volume: files intervened on, files opened and billed as the responsible, disbursements keyed, approved, settlements prepared and paid.
            Delays: average days the person took for the approvals and payments they made, and the opening-to-invoice time of the files they own.
            Quality: disbursements they keyed that were later cancelled as an error or reassigned to another file, cancellations they requested or executed, and late workflow steps on the files they own. Waiting: actions sitting in their queue now.</p>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-[#faf9f6]">
                    <tr>
                        <th class="{{ $th }}" colspan="2"></th>
                        <th class="{{ $th }} text-center border-l border-[#e8e4de]" colspan="7">Volume</th>
                        <th class="{{ $th }} text-center border-l border-[#e8e4de]" colspan="4">Delays (days)</th>
                        <th class="{{ $th }} text-center border-l border-[#e8e4de]" colspan="5">Quality</th>
                        <th class="{{ $th }} text-center border-l border-[#e8e4de]" colspan="2">Waiting now</th>
                    </tr>
                    <tr>
                        <th class="{{ $th }} text-left">Staff</th><th class="{{ $th }} text-left">Department</th>
                        <th class="{{ $th }} text-right border-l border-[#e8e4de]">Files</th><th class="{{ $th }} text-right">Opened</th><th class="{{ $th }} text-right">Billed</th>
                        <th class="{{ $th }} text-right">Keyed</th><th class="{{ $th }} text-right">Approved</th><th class="{{ $th }} text-right">Settl. prep.</th><th class="{{ $th }} text-right">Paid</th>
                        <th class="{{ $th }} text-right border-l border-[#e8e4de]" title="Submitted → approved, for approvals they made">Approve</th>
                        <th class="{{ $th }} text-right" title="Approved → settlement prepared, for settlements they prepared">Prepare</th>
                        <th class="{{ $th }} text-right" title="Settlement approved → paid, for payments they made">Pay</th>
                        <th class="{{ $th }} text-right" title="Opening → first invoice, for files they own billed in the period">Own files</th>
                        <th class="{{ $th }} text-right border-l border-[#e8e4de]" title="Disbursements they keyed, later cancelled as an error">Errors</th>
                        <th class="{{ $th }} text-right" title="Errors ÷ disbursements keyed">Error %</th>
                        <th class="{{ $th }} text-right" title="Disbursements they keyed that had to be moved to another file">Reassigned</th>
                        <th class="{{ $th }} text-right" title="Cancellations they requested / executed">Cancels req./exec.</th>
                        <th class="{{ $th }} text-right" title="Late steps ÷ steps completed on files they own">Late steps</th>
                        <th class="{{ $th }} text-right border-l border-[#e8e4de]">Actions</th><th class="{{ $th }} text-right">Oldest (d)</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($S as $u)
                    @php $hot = fn ($v, $warn) => $v !== null && $v > $warn ? 'text-rose-700 font-semibold' : ''; @endphp
                    <tr class="border-t border-[#f1ede5] {{ $u['role'] ? '' : 'text-[#8a8578]' }}">
                        <td class="{{ $td }} font-medium whitespace-nowrap">{{ $u['user'] }}</td>
                        <td class="{{ $td }} text-[#7c7466]">{{ $u['role'] ? $u['dept'] : 'no clearance role' }}</td>
                        <td class="{{ $td }} text-right tabular-nums font-semibold border-l border-[#f1ede5]">{{ $u['files'] }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ $u['opened'] }}</td><td class="{{ $td }} text-right tabular-nums">{{ $u['billed'] }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ $u['keyed'] }}</td><td class="{{ $td }} text-right tabular-nums">{{ $u['approved'] }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ $u['settle_prepared'] }}</td><td class="{{ $td }} text-right tabular-nums">{{ $u['paid'] }}</td>
                        <td class="{{ $td }} text-right tabular-nums border-l border-[#f1ede5] {{ $hot($u['approve_days'], 1) }}">{{ $dd($u['approve_days']) }}</td>
                        <td class="{{ $td }} text-right tabular-nums {{ $hot($u['prep_days'], 1) }}">{{ $dd($u['prep_days']) }}</td>
                        <td class="{{ $td }} text-right tabular-nums {{ $hot($u['pay_days'], 1) }}">{{ $dd($u['pay_days']) }}</td>
                        <td class="{{ $td }} text-right tabular-nums {{ $hot($u['own_open_to_bill'], 20) }}">{{ $dd($u['own_open_to_bill']) }}</td>
                        <td class="{{ $td }} text-right tabular-nums border-l border-[#f1ede5]">{{ $u['keyed_errors'] }}</td>
                        <td class="{{ $td }} text-right tabular-nums {{ $hot($u['error_pct'], 5) }}">{{ $u['error_pct'] === null ? '—' : $u['error_pct'] . ' %' }}</td>
                        <td class="{{ $td }} text-right tabular-nums {{ $u['reassignments'] ? 'text-amber-700' : '' }}">{{ $u['reassignments'] }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ $u['cancel_requested'] }} / {{ $u['cancel_executed'] }}</td>
                        <td class="{{ $td }} text-right tabular-nums {{ $hot($u['late_pct'], 25) }}">{{ $u['late_pct'] === null ? '—' : $u['late_pct'] . ' %' }}</td>
                        <td class="{{ $td }} text-right tabular-nums border-l border-[#f1ede5]">{{ $u['waiting'] }}</td>
                        <td class="{{ $td }} text-right tabular-nums {{ $hot($u['waiting_oldest'], 7) }}">{{ $u['waiting_oldest'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="20" class="{{ $td }} text-[#8a8578] py-4">No staff with a clearance role, and no activity in this period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <p class="text-[11px] text-[#7c7466] mt-2">Red: approvals, preparations or payments taking more than 1 day on average, own files taking more than 20 days from opening to invoice, error rate above 5 %, more than a quarter of steps late, or an action waiting more than 7 days.</p>
    </div>
@endif
@endsection
