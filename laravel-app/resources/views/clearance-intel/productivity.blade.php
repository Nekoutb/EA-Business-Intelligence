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
    <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-2">
        @foreach (['files_per_employee', 'files_per_employee_month', 'revenue_per_employee', 'files_per_responsible', 'billing_per_day', 'disbursements_paid'] as $key)
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

    <div class="{{ $card }}">
        <h3 class="{{ $h3 }}">Activity per person</h3>
        <p class="{{ $note }}">What each user did in the period: files opened and billed as the responsible, disbursements keyed, approved and paid. "Files" = distinct files touched.</p>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-[#faf9f6]"><tr>
                    <th class="{{ $th }} text-left">User</th><th class="{{ $th }} text-left">Department</th>
                    <th class="{{ $th }} text-right">Files</th><th class="{{ $th }} text-right">Opened</th><th class="{{ $th }} text-right">Billed</th>
                    <th class="{{ $th }} text-right">Débours keyed</th><th class="{{ $th }} text-right">Approved</th><th class="{{ $th }} text-right">Paid</th>
                </tr></thead>
                <tbody>
                @forelse ($B['users'] as $u)
                    <tr class="border-t border-[#f1ede5]">
                        <td class="{{ $td }} font-medium">{{ $u['user'] }}</td><td class="{{ $td }} text-[#7c7466]">{{ $u['dept'] }}</td>
                        <td class="{{ $td }} text-right tabular-nums font-semibold">{{ $u['files'] }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ $u['opened'] }}</td><td class="{{ $td }} text-right tabular-nums">{{ $u['billed'] }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ $u['keyed'] }}</td><td class="{{ $td }} text-right tabular-nums">{{ $u['approved'] }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ $u['paid'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="{{ $td }} text-[#8a8578] py-4">No activity in this period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
