<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ $meta['company'] }} — Clearance management pack — {{ $periodLabel }}</title>
<style>
    body { margin:0; background:#FBF8F1; font-family:-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif; }
    .page { max-width:1180px; margin:0 auto; padding:28px 24px; }
    .cover { border-bottom:2px solid #3E5A44; padding-bottom:16px; margin-bottom:22px; }
    .cover h1 { font-size:26px; margin:6px 0; }
    .toc { columns:2; font-size:13px; margin:12px 0 0; padding-left:18px; }
    .report { page-break-before:always; break-before:page; padding-top:18px; margin-top:26px; border-top:1px solid #E5DED2; }
    .report:first-of-type { page-break-before:auto; break-before:auto; }
    @media print { body { background:#fff; } .page { padding:0; } }
</style>
@include('clearance._style')
</head>
<body>
<div class="page clr">
    <div class="cover">
        <div class="clr-eyebrow">Management reporting pack · Customs clearance</div>
        <h1>{{ $meta['company'] }}</h1>
        <div class="clr-period">{{ $periodLabel }} ({{ \App\Services\Clearance\Rules::fdate($fromIso) }} – {{ \App\Services\Clearance\Rules::fdate($toIso) }})</div>
        <ol class="toc">
            @foreach ($reports as $r)
                <li><a href="#{{ strtolower($r['code']) }}">{{ $r['code'] }} — {{ $r['title'] }}</a></li>
            @endforeach
        </ol>
    </div>

    @if ($error)
        <div class="clr-warn">The pack could not be produced: {{ $error }}</div>
    @else
        @include('clearance._validation')
        @foreach ($reports as $report)
            <section class="report" id="{{ strtolower($report['code']) }}">
                <div class="clr-head">
                    <div>
                        <div class="clr-eyebrow">{{ $report['code'] }} · {{ $report['cadence'] }}</div>
                        <h2 class="clr-title">{{ $report['title'] }}</h2>
                        <div class="clr-period">{{ $report['period'] }}</div>
                    </div>
                </div>
                @include('clearance._body', ['report' => $report])
            </section>
        @endforeach
    @endif
</div>
</body>
</html>
