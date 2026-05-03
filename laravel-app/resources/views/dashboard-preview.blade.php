<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Dashboard preview · Elite Advisors design system</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@300;400;500;600;700&family=Newsreader:ital,opsz,wght@0,6..72,300;0,6..72,400;0,6..72,500;0,6..72,600;1,6..72,300;1,6..72,400&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    <style>
        /* ──────────────────────────────────────────────────────────────
           Elite Advisors design system (v2) — embedded inline.
           Source: ui_kits/marketing/{palettes,marketing}.css
           Default palette: warm. TweaksPanel at bottom-right swaps live.
           ──────────────────────────────────────────────────────────── */

        :root {
            --font-sans:  'Hanken Grotesk', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --font-serif: 'Newsreader', 'Times New Roman', Georgia, serif;
            --font-mono:  'JetBrains Mono', ui-monospace, monospace;

            --fs-display-l: clamp(48px, 6.5vw, 80px);
            --fs-display-m: clamp(40px, 5vw, 56px);
            --fs-h1: 32px; --fs-h2: 24px; --fs-h3: 20px; --fs-h4: 18px;
            --fs-body-l: 18px; --fs-body: 16px; --fs-body-s: 14px;
            --fs-caption: 13px; --fs-micro: 11px;

            --lh-display: 1.05; --lh-tight: 1.18; --lh-snug: 1.32;
            --lh-body: 1.55; --lh-loose: 1.7;

            --tracking-display: -0.025em; --tracking-eyebrow: 0.18em;

            --radius-sm: 2px; --radius-md: 4px; --radius-lg: 8px; --radius-xl: 12px; --radius-pill: 999px;

            --shadow-xs: 0 1px 2px rgba(31,27,23,0.04);
            --shadow-sm: 0 1px 2px rgba(31,27,23,0.06), 0 1px 1px rgba(31,27,23,0.04);
            --shadow-md: 0 4px 12px rgba(31,27,23,0.06), 0 2px 4px rgba(31,27,23,0.04);
            --shadow-lg: 0 12px 32px rgba(31,27,23,0.08), 0 4px 8px rgba(31,27,23,0.04);

            --dur-quick: 160ms; --dur-base: 240ms;
            --ease-standard: cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* WARM (default) */
        [data-palette="warm"] {
            --bg-page:#FBF8F1; --bg-surface:#F5F0E4; --bg-surface-2:#EDE5D2; --bg-sunken:#E2D6BD;
            --fg-primary:#14110F; --fg-secondary:#4A4239; --fg-tertiary:#6B6157; --fg-muted:#948A7E;
            --ink-100:#ECE6DA; --ink-200:#D8D0C4; --ink-300:#B8AFA3; --ink-500:#6B6157; --ink-700:#2E2823; --ink-900:#14110F;
            --parchment-50:#FBF8F1; --parchment-100:#F5F0E4; --parchment-200:#EDE5D2; --parchment-300:#E2D6BD;
            --accent:#B07F3B; --accent-hover:#8E6228; --accent-soft:#F8EFE2; --accent-fg:#4F3614;
            --bronze-400:#B07F3B; --bronze-500:#8E6228;
            --forest-500:#3E5A44; --forest-700:#25382A;
            --oxblood-500:#9C3A2D; --oxblood-300:#C56754;
        }

        /* MONO */
        [data-palette="mono"] {
            --bg-page:#FFFFFF; --bg-surface:#FAFAFA; --bg-surface-2:#F2F2F2;
            --fg-primary:#000; --fg-secondary:#2A2A2A; --fg-tertiary:#5C5C5C; --fg-muted:#8A8A8A;
            --ink-200:#E5E5E5; --ink-300:#C9C9C9; --ink-700:#1F1F1F; --ink-900:#000;
            --parchment-100:#FAFAFA; --parchment-200:#F2F2F2;
            --accent:#000; --accent-soft:#F2F2F2; --accent-hover:#2A2A2A;
            --bronze-500:#2A2A2A; --forest-500:#2A2A2A; --oxblood-500:#5C5C5C;
        }

        /* NEWSPRINT */
        [data-palette="newsprint"] {
            --bg-page:#FBFAF7; --bg-surface:#FFFFFF; --bg-surface-2:#F2F1ED;
            --fg-primary:#0E0E0C; --fg-secondary:#2E2E2B; --fg-tertiary:#5F5F5A; --fg-muted:#8C8C86;
            --ink-200:#E6E5E0; --ink-300:#C9C8C2; --ink-700:#1F1F1C; --ink-900:#0E0E0C;
            --parchment-100:#FFFFFF; --parchment-200:#F2F1ED;
            --accent:#0E0E0C; --accent-soft:#F2F1ED; --accent-hover:#2E2E2B;
            --bronze-500:#2E2E2B; --forest-500:#2E2E2B; --oxblood-500:#5F5F5A;
        }

        /* GRAPHITE */
        [data-palette="graphite"] {
            --bg-page:#F4F4F4; --bg-surface:#FFFFFF; --bg-surface-2:#EBEBEB;
            --fg-primary:#16161A; --fg-secondary:#3B3B3F; --fg-tertiary:#6B6B6F; --fg-muted:#95959A;
            --ink-200:#DEDEDE; --ink-300:#C2C2C2; --ink-700:#25252A; --ink-900:#16161A;
            --parchment-100:#FFFFFF; --parchment-200:#EBEBEB;
            --accent:#16161A; --accent-soft:#EBEBEB; --accent-hover:#3B3B3F;
            --bronze-500:#3B3B3F; --forest-500:#3B3B3F; --oxblood-500:#6B6B6F;
        }

        /* INVERSE */
        [data-palette="inverse"] {
            --bg-page:#0A0A0A; --bg-surface:#141414; --bg-surface-2:#1F1F1F;
            --fg-primary:#FFFFFF; --fg-secondary:#D6D6D6; --fg-tertiary:#9A9A9A; --fg-muted:#6B6B6B;
            --ink-200:#2A2A2A; --ink-300:#3D3D3D; --ink-700:#D6D6D6; --ink-900:#FFFFFF;
            --parchment-100:#141414; --parchment-200:#1F1F1F;
            --accent:#FFFFFF; --accent-soft:#1F1F1F; --accent-hover:#D6D6D6;
            --bronze-500:#D6D6D6; --forest-500:#D6D6D6; --oxblood-500:#9A9A9A;
        }

        /* MIDNIGHT */
        [data-palette="midnight"] {
            --bg-page:#0B1220; --bg-surface:#131C2E; --bg-surface-2:#1B2638;
            --fg-primary:#F4EFE3; --fg-secondary:#C9C2B2; --fg-tertiary:#8C8576; --fg-muted:#5F5A4F;
            --ink-200:#243047; --ink-300:#3A4660; --ink-700:#C9C2B2; --ink-900:#F4EFE3;
            --parchment-100:#131C2E; --parchment-200:#1B2638;
            --accent:#D08A4A; --accent-soft:#1B2638; --accent-hover:#E5A567;
            --bronze-500:#B07238; --forest-500:#7AAE7C; --oxblood-500:#D87567;
        }

        /* SAGE */
        [data-palette="sage"] {
            --bg-page:#F4F1EA; --bg-surface:#FBF9F2; --bg-surface-2:#E9E4D6;
            --fg-primary:#1F2A26; --fg-secondary:#3E4A45; --fg-tertiary:#5F6B65; --fg-muted:#8C9087;
            --ink-200:#D6D1C2; --ink-300:#B8B2A1; --ink-700:#2D3631; --ink-900:#1F2A26;
            --parchment-100:#FBF9F2; --parchment-200:#E9E4D6;
            --accent:#5C7E5E; --accent-soft:#DDE5DC; --accent-hover:#3E5A44;
            --bronze-500:#8E6228; --forest-500:#5C7E5E; --oxblood-500:#9C3A2D;
        }

        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            background: var(--bg-page); color: var(--fg-primary);
            font-family: var(--font-sans); font-size: var(--fs-body); line-height: var(--lh-body);
            -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale;
        }
        a { color: inherit; text-decoration: none; }

        /* ── Top bar ── */
        .topbar {
            position: sticky; top: 0; z-index: 50;
            background: color-mix(in oklab, var(--bg-page) 88%, transparent);
            backdrop-filter: saturate(180%) blur(14px);
            border-bottom: 1px solid var(--ink-200);
        }
        .topbar .inner {
            max-width: 1400px; margin: 0 auto;
            padding: 0 32px; height: 64px;
            display: flex; align-items: center; gap: 24px;
        }
        .brand { display: flex; align-items: center; gap: 10px; line-height: 1; }
        .brand .bars { display: flex; flex-direction: column; gap: 3px; }
        .brand .bars span { display: block; width: 22px; height: 3px; background: var(--ink-900); }
        .brand .bars span:nth-child(2) { width: 14px; }
        .brand .name {
            font-size: 11px; letter-spacing: 0.22em; text-transform: uppercase; font-weight: 500;
            color: var(--fg-primary);
        }
        .brand .sub { font-size: 9px; letter-spacing: 0.30em; text-transform: uppercase; color: var(--fg-tertiary); margin-top: 2px; }
        .topbar .nav { display: flex; gap: 22px; flex: 1; margin-left: 16px; }
        .topbar .nav a { font-size: 13.5px; color: var(--fg-secondary); padding: 4px 0; }
        .topbar .nav a.active { color: var(--fg-primary); font-weight: 500; }
        .topbar .as-of { font-family: var(--font-mono); font-size: 11px; color: var(--fg-tertiary); }

        /* ── Page ── */
        .page { max-width: 1400px; margin: 0 auto; padding: 32px; }

        .eyebrow {
            display: inline-block; font-size: var(--fs-micro); font-weight: 500;
            letter-spacing: var(--tracking-eyebrow); text-transform: uppercase;
            color: var(--fg-tertiary); margin: 0 0 6px;
        }
        h1.page-title {
            font-family: var(--font-serif); font-weight: 400;
            font-size: var(--fs-h1); line-height: var(--lh-tight); letter-spacing: -0.02em;
            margin: 0 0 4px; color: var(--fg-primary);
        }
        .page-lede {
            font-family: var(--font-serif); font-style: italic; font-size: 17px;
            color: var(--fg-tertiary); margin: 0 0 24px; max-width: 64ch;
        }

        /* ── Period filter pill row ── */
        .filter-row {
            display: flex; flex-wrap: wrap; gap: 14px; align-items: center;
            padding: 14px 18px; margin-bottom: 24px;
            background: var(--parchment-100); border: 1px solid var(--ink-200); border-radius: var(--radius-lg);
        }
        .filter-row .label { font-size: 10px; letter-spacing: 0.18em; text-transform: uppercase; color: var(--fg-tertiary); margin-right: 8px; }
        .pill-group { display: inline-flex; border: 1px solid var(--ink-300); border-radius: var(--radius-md); overflow: hidden; }
        .pill-group a { padding: 6px 14px; font-size: 13px; font-family: var(--font-sans); color: var(--fg-secondary); transition: all var(--dur-quick); }
        .pill-group a.on { background: var(--ink-900); color: var(--bg-page); }
        .pill-group a:not(.on):hover { background: var(--parchment-200); color: var(--fg-primary); }
        select.kit { font-family: var(--font-sans); font-size: 13px; padding: 6px 10px; border: 1px solid var(--ink-300); border-radius: var(--radius-md); background: var(--bg-page); color: var(--fg-primary); }

        /* ── Theme header ── */
        .theme-header {
            display: flex; align-items: baseline; justify-content: space-between;
            margin: 32px 0 12px; padding-bottom: 8px; border-bottom: 1px solid var(--ink-200);
        }
        .theme-header h2 {
            font-family: var(--font-serif); font-weight: 400;
            font-size: 22px; letter-spacing: -0.01em; margin: 0; color: var(--fg-primary);
        }
        .theme-header .count { font-family: var(--font-mono); font-size: 11px; color: var(--fg-tertiary); }

        /* ── Card grid ── */
        .grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
        @media (max-width: 1100px) { .grid-4 { grid-template-columns: repeat(2, 1fr); } .grid-3 { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 640px)  { .grid-4, .grid-3 { grid-template-columns: 1fr; } }

        /* ── Card (ChartFrame style) ── */
        .card {
            background: var(--parchment-100);
            border: 1px solid var(--ink-200); border-radius: var(--radius-lg);
            padding: 18px 20px; box-shadow: var(--shadow-sm);
            display: block; text-decoration: none; color: inherit;
            transition: transform var(--dur-quick), box-shadow var(--dur-quick), border-color var(--dur-quick);
        }
        .card:hover { box-shadow: var(--shadow-md); border-color: var(--ink-300); transform: translateY(-1px); }
        .card .eyebrow { color: var(--fg-tertiary); }
        .card .number {
            font-family: var(--font-serif); font-weight: 400;
            font-size: 30px; line-height: 1; letter-spacing: -0.015em;
            color: var(--fg-primary); margin-top: 8px;
            display: flex; align-items: baseline; gap: 8px;
        }
        .card .number .unit { font-family: var(--font-mono); font-size: 12px; color: var(--fg-tertiary); font-weight: 400; }
        .card .chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px; }
        .chip {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 8px; border: 1px solid; border-radius: var(--radius-pill);
            font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.04em;
        }
        .chip.good   { color: var(--forest-500);  border-color: var(--forest-500);  background: color-mix(in srgb, var(--forest-500) 8%, transparent); }
        .chip.bad    { color: var(--oxblood-500); border-color: var(--oxblood-500); background: color-mix(in srgb, var(--oxblood-500) 8%, transparent); }
        .chip.warn   { color: var(--bronze-500);  border-color: var(--bronze-500);  background: color-mix(in srgb, var(--bronze-500) 8%, transparent); }
        .chip.muted  { color: var(--fg-tertiary); border-color: var(--ink-300);     background: var(--parchment-200); }
        .card .sub { font-family: var(--font-mono); font-size: 10.5px; color: var(--fg-tertiary); margin-top: 12px; line-height: 1.5; }

        /* Wide card variants */
        .recon-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 8px; }
        .recon-grid .label { font-size: 9px; letter-spacing: 0.16em; text-transform: uppercase; color: var(--fg-tertiary); }
        .recon-grid .v { font-family: var(--font-mono); font-size: 14px; color: var(--fg-primary); margin-top: 4px; }

        .tax-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 6px; }
        .tax-grid .label { font-size: 9px; letter-spacing: 0.16em; text-transform: uppercase; color: var(--fg-tertiary); }
        .tax-grid .v { font-family: var(--font-serif); font-size: 26px; color: var(--fg-primary); margin-top: 4px; line-height: 1; }
        .tax-grid .v.alarm { color: var(--oxblood-500); }

        /* Methodology */
        .meth {
            margin-top: 24px; padding: 16px 20px;
            background: var(--parchment-100); border: 1px solid var(--ink-200); border-radius: var(--radius-lg);
            font-size: 12px; line-height: 1.6; color: var(--fg-secondary);
        }
        .meth code { font-family: var(--font-mono); font-size: 11px; background: var(--parchment-200); padding: 1px 5px; border-radius: 3px; color: var(--fg-primary); }

        /* TweaksPanel */
        .tweaks-toggle {
            position: fixed; bottom: 24px; right: 24px; z-index: 100;
            width: 44px; height: 44px; border-radius: var(--radius-pill);
            background: var(--ink-900); color: var(--bg-page);
            border: 0; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            box-shadow: var(--shadow-lg);
        }
        .tweaks-panel {
            position: fixed; bottom: 80px; right: 24px; z-index: 100;
            width: 320px; max-height: calc(100vh - 120px); overflow-y: auto;
            background: var(--bg-surface); border: 1px solid var(--ink-200); border-radius: var(--radius-lg);
            padding: 18px; box-shadow: var(--shadow-lg);
            transform: translateY(8px) scale(0.96); opacity: 0; pointer-events: none;
            transition: transform var(--dur-base), opacity var(--dur-base);
        }
        .tweaks-panel.open { transform: translateY(0) scale(1); opacity: 1; pointer-events: auto; }
        .tweaks-panel h5 {
            font-size: var(--fs-micro); letter-spacing: var(--tracking-eyebrow); text-transform: uppercase;
            color: var(--fg-tertiary); margin: 0 0 10px; font-weight: 500;
        }
        .palette-list { display: flex; flex-direction: column; gap: 6px; }
        .palette-card {
            display: flex; align-items: center; gap: 10px; padding: 8px 10px;
            background: var(--parchment-100); border: 1px solid var(--ink-200); border-radius: var(--radius-md);
            cursor: pointer; text-align: left; width: 100%;
            transition: all var(--dur-quick);
        }
        .palette-card.active { background: var(--accent-soft); border-color: var(--accent); }
        .palette-card .swatches { display: flex; border: 1px solid var(--ink-200); border-radius: 3px; overflow: hidden; flex-shrink: 0; }
        .palette-card .swatches span { display: block; width: 14px; height: 26px; }
        .palette-card .meta .label { font-size: 12px; font-weight: 500; color: var(--fg-primary); }
        .palette-card .meta .sub { font-size: 10px; color: var(--fg-tertiary); margin-top: 1px; }

        .preview-banner {
            background: var(--accent-soft); border-bottom: 1px solid var(--accent);
            color: var(--accent-fg, var(--fg-primary));
            padding: 8px 32px; font-size: 12px; text-align: center;
        }
        .preview-banner a { text-decoration: underline; }
    </style>
</head>
<body data-palette="warm">

<div class="preview-banner">
    <strong>Design system preview</strong> · this is a re-skin proposal of the Dashboard against the Elite Advisors kit.
    Compare with the live page at <a href="{{ tenant_url('/') }}">{{ tenant_url('/') }}</a>.
    Try the palette switcher (bottom-right).
</div>

<header class="topbar">
    <div class="inner">
        <a href="{{ tenant_url('/') }}" class="brand">
            <span class="bars"><span></span><span></span><span></span></span>
            <span><span class="name">Elite Advisors</span><br><span class="sub">BMS</span></span>
        </a>
        <nav class="nav">
            <a class="active" href="#">Dashboard</a>
            <a href="{{ tenant_url('/operations') }}">Operations</a>
            <a href="{{ tenant_url('/billing') }}">Billing</a>
            <a href="{{ tenant_url('/receivables') }}">Receivables</a>
            <a href="{{ tenant_url('/monthly-tax-return') }}">Tax</a>
            <a href="{{ tenant_url('/settings') }}">Settings</a>
        </nav>
        @if (!empty($analysis['as_of']))
            <span class="as-of">as of {{ $analysis['as_of'] }}</span>
        @endif
    </div>
</header>

<main class="page">
    @if ($error)
        <div class="card" style="border-color:var(--oxblood-500); background: color-mix(in srgb, var(--oxblood-500) 5%, var(--parchment-100));">
            <p class="eyebrow" style="color:var(--oxblood-500);">Could not load data</p>
            <div class="number" style="font-size:18px;">{{ $error }}</div>
            <p class="sub">Go to <a href="{{ tenant_url('/settings') }}" style="color:var(--accent);">Settings</a> to verify your Odoo connection.</p>
        </div>
    @elseif ($analysis)
        @php
            $cur   = $analysis['currency'] ?? 'XAF';
            $kpis  = $analysis['kpis']     ?? [];
            $type  = $period['type']  ?? 'ytd';
            $year  = $period['year']  ?? (int) date('Y');
            $month = $period['month'] ?? (int) date('m');

            $fmtN   = fn ($n) => number_format((float) $n, 0, '.', ' ');
            $fmtPct = function ($p) {
                if ($p === null) return '—';
                $n = (float) $p * 100.0;
                $sign = $n > 0 ? '+' : '';
                return $sign . number_format($n, 1) . ' %';
            };
            $chip = function ($p) {
                if ($p === null) return 'muted';
                if ($p >= 0)     return 'good';
                if ($p >= -0.05) return 'warn';
                return 'bad';
            };
            $arrow = fn ($p) => $p === null ? '·' : ($p >= 0 ? '↑' : '↓');
        @endphp

        <p class="eyebrow">Top-management pulse</p>
        <h1 class="page-title">Dashboard</h1>
        <p class="page-lede">Eleven KPIs across financial performance, operational efficiency and compliance — all sourced from the same trial balance the modules read.</p>

        {{-- Period filter --}}
        <div class="filter-row">
            <span class="label">Period</span>
            <div class="pill-group">
                @foreach (['ytd' => 'YTD', 'mtd' => 'MTD', 'month' => 'Month'] as $v => $l)
                    <a href="{{ tenant_url('/preview/dashboard') }}?period={{ $v }}&year={{ $year }}&month={{ $month }}" class="{{ $type === $v ? 'on' : '' }}">{{ $l }}</a>
                @endforeach
            </div>
            <span class="label">Year</span>
            <form method="GET" action="{{ tenant_url('/preview/dashboard') }}" style="display:inline;">
                <input type="hidden" name="period" value="{{ $type }}">
                <select name="year" class="kit" onchange="this.form.submit()">
                    @foreach (range((int) date('Y'), (int) date('Y') - 4) as $y)
                        <option value="{{ $y }}" {{ $year === $y ? 'selected' : '' }}>{{ $y }}</option>
                    @endforeach
                </select>
                @if ($type === 'month')
                    <select name="month" class="kit" onchange="this.form.submit()" style="margin-left:8px;">
                        @foreach (range(1, 12) as $m)
                            <option value="{{ $m }}" {{ $month === $m ? 'selected' : '' }}>{{ \DateTime::createFromFormat('!m', (string) $m)->format('M') }}</option>
                        @endforeach
                    </select>
                @endif
            </form>
            <span style="flex:1; text-align:right; font-family: var(--font-mono); font-size: 11px; color: var(--fg-tertiary);">{{ $periodLabel ?? '' }}</span>
        </div>

        {{-- Theme A · Financial --}}
        <div class="theme-header"><h2>Financial Performance</h2><span class="count">4 KPIs · Theme A</span></div>
        <div class="grid-4">
            {{-- F1 --}}
            @php $k = $kpis['F1'] ?? []; @endphp
            <a href="{{ $k['drill'] ?? '#' }}" class="card">
                <p class="eyebrow">{{ $k['label'] ?? 'F1' }}</p>
                <div class="number">{{ $k['value_fmt'] ?? '—' }} <span class="unit">{{ $k['unit'] ?? '' }}</span></div>
                <div class="chips">
                    @if (array_key_exists('vs_budget', $k) && $k['vs_budget'] !== null)
                        <span class="chip {{ $chip($k['vs_budget']) }}">{{ $arrow($k['vs_budget']) }} {{ $fmtPct($k['vs_budget']) }} Budget</span>
                    @endif
                    @if (array_key_exists('vs_py', $k) && $k['vs_py'] !== null)
                        <span class="chip {{ $chip($k['vs_py']) }}">{{ $arrow($k['vs_py']) }} {{ $fmtPct($k['vs_py']) }} PY</span>
                    @endif
                </div>
                <p class="sub">{{ $k['sub'] ?? '' }}</p>
            </a>

            {{-- F2 wide_recon --}}
            @php $k = $kpis['F2'] ?? []; @endphp
            <a href="{{ $k['drill'] ?? '#' }}" class="card">
                <p class="eyebrow">{{ $k['label'] ?? 'F2' }}</p>
                <div class="recon-grid">
                    <div><div class="label">Scanned</div><div class="v">{{ $fmtN($k['expected'] ?? 0) }}</div></div>
                    <div><div class="label">Billed</div><div class="v">{{ $fmtN($k['billed'] ?? 0) }}</div></div>
                    <div><div class="label">Collected</div><div class="v">{{ $fmtN($k['collected'] ?? 0) }}</div></div>
                </div>
                @php
                    $br = $k['billing_realisation_pct'] ?? null;
                    $cr = $k['collection_realisation_pct'] ?? null;
                    $brC = $br === null ? 'muted' : ($br >= 95 ? 'good' : ($br >= 85 ? 'warn' : 'bad'));
                    $crC = $cr === null ? 'muted' : ($cr >= 90 ? 'good' : ($cr >= 75 ? 'warn' : 'bad'));
                @endphp
                <div class="chips">
                    <span class="chip {{ $brC }}">B/S {{ $br === null ? '—' : number_format((float) $br, 1) . ' %' }}</span>
                    <span class="chip {{ $crC }}">C/B {{ $cr === null ? '—' : number_format((float) $cr, 1) . ' %' }}</span>
                </div>
                <p class="sub">{{ $k['sub'] ?? '' }} · YTD</p>
            </a>

            {{-- F3 --}}
            @php $k = $kpis['F3'] ?? []; @endphp
            <a href="{{ $k['drill'] ?? '#' }}" class="card">
                <p class="eyebrow">{{ $k['label'] ?? 'F3' }}</p>
                <div class="number">{{ $k['value_fmt'] ?? '—' }} <span class="unit">{{ $k['unit'] ?? '' }}</span></div>
                <div class="chips">
                    @if (array_key_exists('vs_budget', $k) && $k['vs_budget'] !== null)
                        <span class="chip {{ $chip($k['vs_budget']) }}">{{ $arrow($k['vs_budget']) }} {{ $fmtPct($k['vs_budget']) }} Budget</span>
                    @endif
                </div>
                <p class="sub">{{ $k['sub'] ?? '' }}</p>
            </a>

            {{-- F4 wide_aging --}}
            @php $k = $kpis['F4'] ?? []; @endphp
            <a href="{{ $k['drill'] ?? '#' }}" class="card">
                <p class="eyebrow">{{ $k['label'] ?? 'F4' }}</p>
                <div class="number">{{ $fmtN($k['total_ar'] ?? 0) }} <span class="unit">{{ $cur }}</span></div>
                @php
                    $p30 = $k['pct_over_30'] ?? 0;
                    $p60 = $k['pct_over_60'] ?? 0;
                    $c30 = $p30 > 25 ? 'bad' : ($p30 > 10 ? 'warn' : 'good');
                    $c60 = $p60 > 15 ? 'bad' : ($p60 > 5  ? 'warn' : 'good');
                @endphp
                <div class="chips">
                    <span class="chip {{ $c30 }}">&gt; 30 d · {{ number_format($p30, 1) }} %</span>
                    <span class="chip {{ $c60 }}">&gt; 60 d · {{ number_format($p60, 1) }} %</span>
                </div>
                <p class="sub">{{ $k['sub'] ?? '' }}</p>
            </a>
        </div>

        {{-- Theme B · Operational --}}
        <div class="theme-header"><h2>Operational Efficiency</h2><span class="count">4 KPIs · Theme B</span></div>
        <div class="grid-4">
            @foreach (['O1', 'O2', 'O3', 'O4'] as $code)
                @php $k = $kpis[$code] ?? []; @endphp
                <a href="{{ $k['drill'] ?? '#' }}" class="card">
                    <p class="eyebrow">{{ $k['label'] ?? $code }}</p>
                    <div class="number">
                        {{ $k['value_fmt'] ?? '—' }}
                        <span class="unit">{{ ($k['unit'] ?? '') === 'count' ? '' : (($k['unit'] ?? '') === 'pct' ? '%' : ($k['unit'] ?? '')) }}</span>
                    </div>
                    <div class="chips">
                        @if (array_key_exists('vs_py', $k) && $k['vs_py'] !== null)
                            <span class="chip {{ $chip($k['vs_py']) }}">{{ $arrow($k['vs_py']) }} {{ $fmtPct($k['vs_py']) }} PY</span>
                        @endif
                        @if (!empty($k['pending']))
                            <span class="chip muted">pending</span>
                        @endif
                    </div>
                    <p class="sub">{{ $k['sub'] ?? '' }}</p>
                </a>
            @endforeach
        </div>

        {{-- Theme C · Compliance --}}
        <div class="theme-header"><h2>Compliance &amp; Risk</h2><span class="count">3 KPIs · Theme C</span></div>
        <div class="grid-3">
            @php $k = $kpis['C1'] ?? []; @endphp
            <a href="{{ $k['drill'] ?? '#' }}" class="card">
                <p class="eyebrow">{{ $k['label'] ?? 'C1' }}</p>
                <div class="number">{{ $k['value_fmt'] ?? '—' }} <span class="unit">%</span></div>
                <div class="chips">
                    @if (array_key_exists('vs_py', $k) && $k['vs_py'] !== null)
                        <span class="chip {{ $chip($k['vs_py']) }}">{{ $arrow($k['vs_py']) }} {{ $fmtPct($k['vs_py']) }} PY</span>
                    @endif
                </div>
                <p class="sub">{{ $k['sub'] ?? '' }}</p>
            </a>

            @php $k = $kpis['C2'] ?? []; @endphp
            <a href="{{ $k['drill'] ?? '#' }}" class="card">
                <p class="eyebrow">{{ $k['label'] ?? 'C2' }}</p>
                <div class="number">{{ $k['value_fmt'] ?? '—' }}</div>
                <p class="sub">{{ $k['sub'] ?? '' }}</p>
            </a>

            @php $k = $kpis['C3'] ?? []; @endphp
            <a href="{{ $k['drill'] ?? '#' }}" class="card">
                <p class="eyebrow">{{ $k['label'] ?? 'C3' }}</p>
                <div class="tax-grid">
                    <div>
                        <div class="label">Issues</div>
                        <div class="v {{ ($k['issues_count'] ?? 0) > 0 ? 'alarm' : '' }}">{{ $k['issues_count'] ?? 0 }}</div>
                    </div>
                    <div>
                        <div class="label">Exposure ({{ $cur }})</div>
                        <div class="v">{{ $k['exposure_fmt'] ?? '—' }}</div>
                    </div>
                </div>
                <p class="sub">{{ $k['sub'] ?? '' }}</p>
            </a>
        </div>

        <div class="meth">
            <strong>11 KPIs · 3 themes</strong> — all sourced via <code>DashboardKpiService</code>. Click any card to drill into the source module. The palette switcher (bottom-right) re-skins the entire page; the choice persists in <code>localStorage</code> so you stay in your preferred palette across visits.
        </div>
    @endif
</main>

{{-- TweaksPanel --}}
<button class="tweaks-toggle" id="tweaks-toggle" aria-label="Open palette switcher">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <circle cx="12" cy="12" r="9"/>
        <path d="M12 3a9 9 0 0 0 0 18M3 12h18"/>
    </svg>
</button>
<div class="tweaks-panel" id="tweaks-panel" role="dialog" aria-label="Palette switcher">
    <h5>Palette</h5>
    <div class="palette-list">
        @php
            $palettes = [
                ['warm',      'Warm',       'Parchment + bronze',                ['#FBF8F1','#F5F0E4','#B07F3B','#14110F']],
                ['mono',      'Mono',       'Pure black on white',               ['#FFFFFF','#F2F2F2','#5C5C5C','#000000']],
                ['newsprint', 'Newsprint',  'Soft warm white, near-black ink',   ['#FBFAF7','#F2F1ED','#5F5F5A','#0E0E0C']],
                ['graphite',  'Graphite',   'Light grey, charcoal ink',          ['#F4F4F4','#EBEBEB','#6B6B6F','#16161A']],
                ['inverse',   'Inverse',    'Black page, white ink',             ['#0A0A0A','#1F1F1F','#9A9A9A','#FFFFFF']],
                ['midnight',  'Midnight',   'Deep navy + copper accent',         ['#0B1220','#131C2E','#D08A4A','#F4EFE3']],
                ['sage',      'Sage',       'Cool green editorial',              ['#F4F1EA','#E9E4D6','#5C7E5E','#1F2A26']],
            ];
        @endphp
        @foreach ($palettes as [$key, $label, $sub, $sw])
            <button type="button" class="palette-card{{ $key === 'warm' ? ' active' : '' }}" data-palette-key="{{ $key }}">
                <div class="swatches">
                    @foreach ($sw as $color)
                        <span style="background:{{ $color }};"></span>
                    @endforeach
                </div>
                <div class="meta">
                    <div class="label">{{ $label }}</div>
                    <div class="sub">{{ $sub }}</div>
                </div>
            </button>
        @endforeach
    </div>
</div>

<script>
    (function () {
        const toggle = document.getElementById('tweaks-toggle');
        const panel  = document.getElementById('tweaks-panel');
        const cards  = panel.querySelectorAll('.palette-card');
        const STORAGE_KEY = 'bms.preview.palette';
        const valid = ['warm','mono','newsprint','graphite','inverse','midnight','sage'];

        try {
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved && valid.includes(saved)) {
                document.body.setAttribute('data-palette', saved);
                cards.forEach(c => c.classList.toggle('active', c.dataset.paletteKey === saved));
            }
        } catch (_) {}

        toggle.addEventListener('click', () => panel.classList.toggle('open'));
        document.addEventListener('click', e => {
            if (!panel.contains(e.target) && !toggle.contains(e.target)) panel.classList.remove('open');
        });
        cards.forEach(card => card.addEventListener('click', () => {
            const k = card.dataset.paletteKey;
            document.body.setAttribute('data-palette', k);
            cards.forEach(c => c.classList.toggle('active', c === card));
            try { localStorage.setItem(STORAGE_KEY, k); } catch (_) {}
        }));
    })();
</script>

</body>
</html>
