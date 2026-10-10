@extends('layouts.app')

@section('page-title', 'Clearance · ' . ($report['title'] ?? 'Report'))

@section('content')
@include('clearance._style')
<div class="clr">
    @php
        $qs = $periodParam !== '' ? '?period=' . $periodParam : '';
        $titles = collect(\App\Services\Clearance\ClearancePack::REPORTS)->map(fn ($r) => $r[2]);
    @endphp

    <nav class="clr-tabs" aria-label="Clearance reports">
        @foreach ($enabled as $c)
            <a href="{{ tenant_url('/clearance/' . $c) }}{{ $qs }}" class="{{ $c === $code ? 'on' : '' }}" title="{{ $titles[$c] }}">{{ strtoupper($c) }} · {{ $titles[$c] }}</a>
        @endforeach
    </nav>

    <div class="clr-head">
        <div>
            <div class="clr-eyebrow">{{ $meta['company'] }} · {{ strtoupper($code) }} · {{ \App\Services\Clearance\ClearancePack::REPORTS[$code][3] }}</div>
            <h1 class="clr-title">{{ $report['title'] ?? \App\Services\Clearance\ClearancePack::REPORTS[$code][2] }}</h1>
            <div class="clr-period">{{ $report['period'] ?? $periodLabel }}</div>
        </div>
        <div class="clr-actions">
            <form method="GET" style="display:flex; gap:6px; align-items:center;">
                <label for="clr-period" class="clr-period">Month</label>
                <input id="clr-period" type="month" name="period" value="{{ $periodParam }}" style="font-size:12px; padding:5px 6px; border:1px solid #E5DED2; border-radius:5px;">
                <button class="clr-btn" type="submit">Apply</button>
            </form>
            <a class="clr-btn" href="{{ tenant_url('/clearance/' . $code . '/export') }}{{ $qs }}">Excel</a>
            <a class="clr-btn" href="{{ tenant_url('/clearance/pack.html') }}{{ $qs }}">Full pack (HTML)</a>
            <a class="clr-btn" href="{{ tenant_url('/clearance/pack.xlsx') }}{{ $qs }}">Full pack (Excel)</a>
        </div>
    </div>

    @if ($error)
        <div class="clr-warn" role="alert">
            The report could not be produced: {{ $error }}
        </div>
        <div class="clr-note">The pack reads Odoo through read-only calls and checks every field it needs before computing anything — nothing was changed in Odoo.</div>
    @elseif ($report)
        @include('clearance._validation')
        @include('clearance._body')
    @endif
</div>
@endsection
