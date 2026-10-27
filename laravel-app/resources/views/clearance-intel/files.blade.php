@extends('layouts.app')
@section('title', 'Clearance · ' . $title)
@section('page-title', 'Clearance Intelligence')
@section('content')
@include('clearance-intel._shell')
@if ($data)
    @php
        $q = request();
        $trig = (array) config('clearance_intel.triggers');
        $all = $data['files'];
        $opt = fn (string $k) => array_values(array_unique(array_filter(array_column($all, $k)))) ;
        $clients = $opt('client'); sort($clients);
        $services = $opt('service'); sort($services);
        $owners = $opt('owner'); sort($owners);
        $states = array_unique(array_column($all, 'state_label'));
        $rows = array_filter($all, function ($f) use ($q, $data) {
            if (($s = trim((string) $q->query('q', ''))) !== '' && stripos($f['name'] . ' ' . $f['client'], $s) === false) return false;
            foreach (['client', 'service', 'owner', 'state_label' => 'state'] as $field => $param) {
                if (is_int($field)) $field = $param;
                if (($v = (string) $q->query($param, '')) !== '' && $f[$field] !== $v) return false;
            }
            if (($fl = (string) $q->query('flag', '')) !== '' && !in_array($fl, $f['flags'], true)) return false;
            if ($q->query('scope', $q->query('flag') ? 'all' : 'period') === 'period') {
                $in = fn ($d) => $d && $d >= $data['from'] && $d <= $data['to'];
                if (!$in($f['opened']) && !$in($f['billed']) && !in_array($f['state'], ['draft', 'in_progress', 'ops_closed'], true)) return false;
            }
            return true;
        });
        $sort = (string) $q->query('sort', 'opened');
        $sorts = ['opened' => 'Opened (newest)', 'fees' => 'Fees', 'net' => 'Net margin (lowest)', 'unbilled_debours' => 'Débours to recharge', 'age' => 'Age (oldest)', 'flags' => 'Most flags'];
        usort($rows, match ($sort) {
            'fees' => fn ($a, $b) => $b['fees'] <=> $a['fees'],
            'net' => fn ($a, $b) => ($a['net'] ?? PHP_INT_MAX) <=> ($b['net'] ?? PHP_INT_MAX),
            'unbilled_debours' => fn ($a, $b) => $b['unbilled_debours'] <=> $a['unbilled_debours'],
            'age' => fn ($a, $b) => ($b['age'] ?? 0) <=> ($a['age'] ?? 0),
            'flags' => fn ($a, $b) => count($b['flags']) <=> count($a['flags']),
            default => fn ($a, $b) => strcmp((string) $b['opened'], (string) $a['opened']),
        });
        $inp = 'text-[12px] px-2 py-1.5 border border-[#e8e4de] rounded-md bg-white';
        $sel = fn ($name, $list, $all = 'All') => '<select name="' . $name . '" class="' . $inp . '"><option value="">' . e($all) . '</option>'
            . implode('', array_map(fn ($v, $k) => '<option value="' . e(is_int($k) ? $v : $k) . '"' . ((string) $q->query($name) === (string) (is_int($k) ? $v : $k) ? ' selected' : '') . '>' . e($v) . '</option>', $list, array_keys($list))) . '</select>';
        $total = count($rows);
        $rows = array_slice($rows, 0, 300);
    @endphp
    <form method="GET" class="bg-white rounded-xl border border-[#e8e4de] p-3 shadow-sm mb-4 flex flex-wrap gap-2 items-end">
        @foreach ($filter as $fk => $fv)<input type="hidden" name="{{ $fk }}" value="{{ $fv }}">@endforeach
        <input name="q" value="{{ $q->query('q') }}" placeholder="File number or client" class="{{ $inp }} w-48">
        {!! $sel('client', $clients, 'All clients') !!}
        {!! $sel('service', $services, 'All services') !!}
        {!! $sel('owner', $owners, 'All owners') !!}
        {!! $sel('state', array_values($states), 'All states') !!}
        {!! $sel('flag', array_map(fn ($t) => $t[0], $trig), 'Any flag') !!}
        {!! $sel('scope', ['period' => 'Period + live files', 'all' => 'All files'], 'Period + live files') !!}
        {!! $sel('sort', $sorts, 'Sort') !!}
        <button class="text-[12px] font-semibold px-3 py-1.5 rounded-md bg-brand-600 text-white">Filter</button>
        <a href="{{ tenant_url('/clearance/files') }}{{ $filter ? '?' . http_build_query($filter) : '' }}" class="text-[12px] text-[#7c7466] hover:underline">reset</a>
    </form>
    <div class="text-[12px] text-[#5b5547] mb-2">{{ number_format($total) }} file{{ $total === 1 ? '' : 's' }}{{ $total > 300 ? ' — first 300 shown, narrow the filter' : '' }}. Click a file for its full review.</div>
    <div class="bg-white rounded-xl border border-[#e8e4de] p-2 shadow-sm">
        @include('clearance-intel._files_table', ['rows' => $rows, 'cols' => ['age' => ['Age (d)', 'int'], 'fees' => ['Fees', 'xaf'], 'contribution' => ['Contribution', 'xaf'], 'net' => ['Net', 'xaf'], 'unbilled_debours' => ['Débours to recharge', 'xaf'], 'open_to_bill' => ['Open → bill (d)', 'int']]])
    </div>
@endif
@endsection
