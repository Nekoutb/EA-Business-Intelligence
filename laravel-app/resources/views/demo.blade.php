<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Demo · BMS — Elite Advisors</title>
    <meta name="description" content="Six demonstrations of the Elite Advisors Business Management Suite. Revenue intelligence, treasury, receivables, GL quality, income tax, monthly declaration — every figure fictional and illustrative.">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    {{-- Same design system as the login page: Hanken Grotesk (UI), Newsreader (italic serif accents), JetBrains Mono (numerals). --}}
    <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@300;400;500;600;700&family=Newsreader:ital,opsz,wght@0,6..72,300;0,6..72,400;0,6..72,500;0,6..72,600;1,6..72,300;1,6..72,400;1,6..72,500&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    <style>
        /* ============================================================
           Demo page — Elite Advisors design tokens
           ============================================================ */
        :root {
            --font-sans:  'Hanken Grotesk', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --font-serif: 'Newsreader', 'Times New Roman', Georgia, serif;
            --font-mono:  'JetBrains Mono', ui-monospace, monospace;

            --fs-display-l:  clamp(48px, 6.5vw, 80px);
            --fs-h1:    40px;
            --fs-h2:    32px;
            --fs-h3:    24px;
            --fs-body-l:  18px;
            --fs-body:    16px;
            --fs-body-s:  14px;
            --fs-caption: 13px;
            --fs-micro:   11px;

            --fw-light: 300; --fw-regular: 400; --fw-medium: 500; --fw-semi: 600; --fw-bold: 700;
            --tracking-tight:   -0.01em;
            --tracking-display: -0.02em;
            --tracking-eyebrow: 0.18em;

            --space-3:12px; --space-4:16px; --space-6:24px; --space-8:32px;
            --space-10:40px; --space-12:48px; --space-16:64px; --space-20:80px;

            --radius-sm:2px; --radius-md:4px; --radius-lg:8px; --radius-pill:999px;

            --shadow-sm: 0 1px 2px rgba(31,27,23,0.06);
            --shadow-md: 0 4px 12px rgba(31,27,23,0.06);
            --shadow-lg: 0 12px 32px rgba(31,27,23,0.08);

            --ease-emphasis: cubic-bezier(0.2, 0.8, 0.2, 1);
            --dur-quick: 160ms; --dur-base: 240ms;

            --container-wide: 1440px;

            /* WARM palette — matches login default */
            --bg-page:      #FBF8F1;
            --bg-surface:   #F5F0E4;
            --bg-surface-2: #EDE5D2;
            --fg-primary:   #14110F;
            --fg-secondary: #4A4239;
            --fg-tertiary:  #6B6157;
            --ink-100:      #ECE6DA;
            --ink-200:      #D8D0C4;
            --ink-300:      #B8AFA3;
            --ink-700:      #2E2823;
            --ink-900:      #14110F;
            --parchment-50: #FBF8F1;
            --parchment-100:#F5F0E4;
            --bronze-400:   #C99454;
            --bronze-500:   #B07F3B;
            --accent:       #B07F3B;
            --accent-hover: #8E6228;
            --accent-soft:  #F8EFE2;
            --accent-fg:    #4F3614;
            --positive:     #2D6A4F;
            --warning:      #B07F3B;
            --negative:     #C0392B;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body {
            font-family: var(--font-sans);
            color: var(--fg-primary);
            background: var(--bg-page);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            line-height: 1.55;
        }
        a { color: inherit; text-decoration: none; }
        button { font: inherit; cursor: pointer; }

        .container {
            width: 100%; max-width: var(--container-wide);
            margin: 0 auto; padding: 0 24px;
        }
        @media (min-width: 768px) { .container { padding: 0 40px; } }

        /* ----------- Header (matches login) ----------- */
        .site-header {
            position: sticky; top: 0; z-index: 50;
            background: rgba(251, 248, 241, 0.92);
            backdrop-filter: saturate(180%) blur(10px);
            border-bottom: 1px solid var(--ink-200);
        }
        .site-header__inner {
            display: flex; align-items: center; justify-content: space-between;
            padding: 18px 0; gap: 24px;
        }
        .logo-mono {
            display: flex; align-items: center; gap: 14px;
            color: var(--fg-primary);
        }
        .logo-mono .logo-bars { color: var(--fg-primary); }
        .logo-mono .logo-words {
            font-family: var(--font-sans); font-size: 16px;
            font-weight: var(--fw-semi); letter-spacing: var(--tracking-tight);
            line-height: 1.1;
        }
        .logo-mono .logo-sub {
            font-family: var(--font-mono); font-size: var(--fs-micro);
            color: var(--bronze-500); letter-spacing: var(--tracking-eyebrow);
            text-transform: uppercase; margin-top: 4px;
        }
        .site-nav {
            display: none; gap: 28px;
            font-size: var(--fs-body-s);
        }
        @media (min-width: 900px) { .site-nav { display: flex; } }
        .site-nav a {
            color: var(--fg-secondary);
            transition: color var(--dur-quick);
        }
        .site-nav a:hover, .site-nav a.is-active { color: var(--fg-primary); }
        .site-actions { display: flex; gap: 12px; align-items: center; }
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 18px;
            font-family: var(--font-sans); font-size: var(--fs-body-s);
            font-weight: var(--fw-medium); letter-spacing: var(--tracking-tight);
            border-radius: var(--radius-md); border: 1px solid transparent;
            transition: background var(--dur-quick), color var(--dur-quick), border-color var(--dur-quick);
            white-space: nowrap;
        }
        .btn-ghost { color: var(--fg-secondary); border-color: var(--ink-200); }
        .btn-ghost:hover { color: var(--fg-primary); border-color: var(--ink-300); }
        .btn-primary { background: var(--ink-900); color: var(--parchment-50); }
        .btn-primary:hover { background: var(--ink-700); }

        /* ----------- Hero ----------- */
        .hero {
            padding: 80px 0 56px;
            border-bottom: 1px solid var(--ink-200);
        }
        @media (min-width: 768px) { .hero { padding: 120px 0 80px; } }
        .eyebrow {
            font-family: var(--font-mono); font-size: var(--fs-micro);
            letter-spacing: var(--tracking-eyebrow); text-transform: uppercase;
            color: var(--bronze-500); font-weight: var(--fw-regular);
            margin-bottom: 18px;
        }
        .hero h1 {
            font-family: var(--font-serif);
            font-size: var(--fs-display-l);
            font-weight: var(--fw-regular);
            line-height: 1.05;
            letter-spacing: var(--tracking-display);
            color: var(--fg-primary);
            max-width: 18ch;
            margin-bottom: 28px;
        }
        .hero h1 em { font-style: italic; color: var(--bronze-500); }
        .hero p {
            font-size: var(--fs-body-l);
            color: var(--fg-secondary);
            line-height: 1.55;
            max-width: 64ch;
            margin-bottom: 32px;
        }
        .hero-disclaimer {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 8px 14px;
            background: var(--accent-soft);
            border: 1px solid var(--ink-200);
            border-radius: var(--radius-pill);
            font-family: var(--font-mono); font-size: var(--fs-micro);
            letter-spacing: 0.06em; text-transform: uppercase;
            color: var(--bronze-500);
        }
        .hero-disclaimer::before {
            content: '●'; color: var(--bronze-400); font-size: 9px;
        }

        /* ----------- Capability index (cards linking to anchors) ----------- */
        .index-section { padding: 48px 0 16px; }
        .capability-index {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 1px;
            background: var(--ink-200);
            border: 1px solid var(--ink-200);
            border-radius: var(--radius-md); overflow: hidden;
        }
        @media (max-width: 960px) { .capability-index { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 600px) { .capability-index { grid-template-columns: 1fr; } }
        .capability-index a {
            background: var(--bg-page);
            padding: 22px 24px;
            display: flex; flex-direction: column; gap: 8px;
            transition: background var(--dur-quick);
        }
        .capability-index a:hover { background: var(--parchment-100); }
        .capability-index .num {
            font-family: var(--font-mono); font-size: var(--fs-micro);
            letter-spacing: 0.10em; text-transform: uppercase;
            color: var(--bronze-500);
        }
        .capability-index .title {
            font-family: var(--font-serif); font-size: 20px;
            font-weight: var(--fw-regular); color: var(--fg-primary);
            letter-spacing: var(--tracking-tight);
            line-height: 1.25;
        }
        .capability-index .title em { font-style: italic; color: var(--bronze-500); }
        .capability-index .meta {
            margin-top: 6px;
            font-family: var(--font-mono); font-size: var(--fs-micro);
            color: var(--fg-tertiary);
        }
        .capability-index .jump {
            margin-top: auto; padding-top: 8px;
            font-family: var(--font-sans); font-size: var(--fs-micro);
            letter-spacing: var(--tracking-eyebrow); text-transform: uppercase;
            font-weight: var(--fw-medium); color: var(--fg-primary);
        }
        .capability-index .jump::after { content: ' ↓'; }

        /* ----------- Demo sections (stacked) ----------- */
        .demo-section {
            padding: 64px 0;
            border-bottom: 1px solid var(--ink-200);
        }
        @media (min-width: 768px) { .demo-section { padding: 96px 0; } }
        .demo-section.is-alt { background: var(--bg-surface); }
        .demo-section__head { margin-bottom: 32px; }
        .demo-section__num {
            font-family: var(--font-mono); font-size: var(--fs-micro);
            letter-spacing: 0.10em; text-transform: uppercase;
            color: var(--bronze-500); font-weight: var(--fw-regular);
            margin-bottom: 12px; display: block;
        }
        .demo-section__title {
            font-family: var(--font-serif);
            font-size: clamp(32px, 4vw, 48px);
            font-weight: var(--fw-regular);
            line-height: 1.15;
            letter-spacing: var(--tracking-display);
            color: var(--fg-primary);
            margin-bottom: 18px;
            max-width: 22ch;
        }
        .demo-section__title em { font-style: italic; color: var(--bronze-500); }
        .demo-section__lede {
            font-size: var(--fs-body-l);
            color: var(--fg-secondary);
            line-height: 1.6;
            max-width: 56ch;
        }

        /* ----------- KPI strip (4-up) ----------- */
        .kpi-strip {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 1px;
            background: var(--ink-200);
            border: 1px solid var(--ink-200);
            margin-top: 32px;
        }
        @media (max-width: 760px) { .kpi-strip { grid-template-columns: repeat(2, 1fr); } }
        .kpi {
            background: var(--bg-page); padding: 24px 22px;
            display: flex; flex-direction: column; gap: 6px;
        }
        .demo-section.is-alt .kpi { background: var(--bg-surface); }
        .kpi__l {
            font-family: var(--font-sans); font-size: var(--fs-micro);
            letter-spacing: var(--tracking-eyebrow); text-transform: uppercase;
            color: var(--fg-tertiary); font-weight: var(--fw-medium);
        }
        .kpi__v {
            font-family: var(--font-mono); font-size: 28px;
            font-weight: var(--fw-medium); color: var(--fg-primary);
            line-height: 1.05; letter-spacing: -0.01em;
        }
        .kpi__v .u {
            font-size: 13px; color: var(--fg-tertiary); margin-left: 4px;
            font-weight: var(--fw-regular);
        }
        .kpi__v.is-pos  { color: var(--positive); }
        .kpi__v.is-warn { color: var(--warning); }
        .kpi__v.is-neg  { color: var(--negative); }
        .kpi__m {
            font-family: var(--font-sans); font-size: var(--fs-caption);
            color: var(--fg-tertiary);
        }

        /* ----------- Data table ----------- */
        .demo-table-wrap { margin-top: 32px; overflow-x: auto; }
        .demo-table {
            width: 100%; border-collapse: collapse;
            font-size: var(--fs-body-s);
            background: var(--bg-page);
            border: 1px solid var(--ink-200);
        }
        .demo-section.is-alt .demo-table { background: var(--bg-surface); }
        .demo-table thead th {
            font-family: var(--font-sans); font-size: var(--fs-micro);
            letter-spacing: var(--tracking-eyebrow); text-transform: uppercase;
            color: var(--fg-tertiary); font-weight: var(--fw-medium);
            padding: 14px 16px; text-align: left;
            border-bottom: 1px solid var(--ink-200);
            background: var(--parchment-100);
        }
        .demo-section.is-alt .demo-table thead th { background: var(--bg-surface-2); }
        .demo-table thead th:not(:first-child) { text-align: right; }
        .demo-table tbody td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--ink-100);
            font-family: var(--font-sans);
            color: var(--fg-primary);
        }
        .demo-table tbody td:not(:first-child) {
            text-align: right; font-family: var(--font-mono);
            font-variant-numeric: tabular-nums;
            color: var(--fg-secondary);
        }
        .demo-table tbody tr.is-total td {
            font-weight: var(--fw-semi); color: var(--fg-primary);
            border-top: 1px solid var(--ink-300);
            background: var(--accent-soft);
        }
        .demo-table tbody tr.is-section td {
            font-family: var(--font-sans); font-size: var(--fs-micro);
            letter-spacing: var(--tracking-eyebrow); text-transform: uppercase;
            color: var(--bronze-500); font-weight: var(--fw-medium);
            background: var(--parchment-100);
            padding-top: 18px;
        }
        .demo-section.is-alt .demo-table tbody tr.is-section td { background: var(--bg-surface-2); }

        /* ----------- Methodology note + jump-back ----------- */
        .demo-footnote {
            margin-top: 24px;
            display: flex; justify-content: space-between; align-items: flex-start;
            gap: 24px; flex-wrap: wrap;
            padding-top: 20px;
            border-top: 1px solid var(--ink-200);
        }
        .demo-footnote p {
            font-size: var(--fs-caption); color: var(--fg-tertiary);
            font-style: italic; max-width: 56ch; line-height: 1.55;
        }
        .demo-footnote code {
            font-family: var(--font-mono); font-size: var(--fs-micro);
            background: var(--bg-surface); padding: 1px 6px;
            border-radius: var(--radius-sm); color: var(--fg-secondary);
            font-style: normal;
        }
        .demo-footnote .top-link {
            font-family: var(--font-sans); font-size: var(--fs-micro);
            letter-spacing: var(--tracking-eyebrow); text-transform: uppercase;
            color: var(--fg-tertiary); font-weight: var(--fw-medium);
            white-space: nowrap;
            transition: color var(--dur-quick);
        }
        .demo-footnote .top-link:hover { color: var(--fg-primary); }

        /* ----------- Final CTA ----------- */
        .final-cta {
            padding: 96px 0; background: var(--ink-900);
            color: var(--parchment-50); text-align: center;
        }
        .final-cta h2 {
            font-family: var(--font-serif); font-weight: var(--fw-regular);
            font-size: clamp(32px, 4vw, 48px);
            line-height: 1.15; letter-spacing: var(--tracking-display);
            margin: 12px auto 18px; max-width: 22ch;
        }
        .final-cta h2 em { font-style: italic; color: var(--bronze-400); }
        .final-cta p {
            color: rgba(251, 248, 241, 0.7);
            max-width: 56ch; margin: 0 auto 32px;
            font-size: var(--fs-body-l); line-height: 1.6;
        }
        .final-cta .eyebrow { color: var(--bronze-400); margin-bottom: 0; }
        .final-cta .btn-primary {
            background: var(--accent); color: var(--accent-fg);
        }
        .final-cta .btn-primary:hover { background: var(--bronze-400); }
        .final-cta .btn-ghost {
            border-color: rgba(251, 248, 241, 0.2);
            color: rgba(251, 248, 241, 0.85);
        }
        .final-cta .btn-ghost:hover {
            border-color: var(--parchment-50); color: var(--parchment-50);
        }
        .final-cta .cta-buttons {
            display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;
        }

        /* ----------- Footer ----------- */
        .site-footer {
            background: var(--ink-900); color: var(--parchment-50);
            padding: 56px 0 32px;
            border-top: 1px solid var(--ink-700);
        }
        .footer-bottom {
            display: flex; justify-content: space-between; align-items: center;
            gap: 12px; flex-wrap: wrap;
            font-size: var(--fs-micro); letter-spacing: var(--tracking-eyebrow);
            text-transform: uppercase; color: rgba(251, 248, 241, 0.45);
        }
        .footer-bottom a {
            color: rgba(251, 248, 241, 0.65);
            transition: color var(--dur-quick);
        }
        .footer-bottom a:hover { color: var(--parchment-50); }

        /* ----------- Reveal animation ----------- */
        @keyframes rise { 0% { opacity:0; transform: translateY(18px); } 100% { opacity:1; transform: translateY(0); } }
        .scroll-reveal {
            opacity: 0; transform: translateY(24px);
            transition: opacity 0.8s var(--ease-emphasis), transform 0.8s var(--ease-emphasis);
        }
        .scroll-reveal.is-visible { opacity: 1; transform: translateY(0); }
    </style>
</head>
<body>

{{-- ═══════════════════════════════════════════════════════════════════════════
   HEADER — mirrors the login page nav. "Demo" is marked active.
═══════════════════════════════════════════════════════════════════════════ --}}
<header class="site-header">
    <div class="container site-header__inner">
        <a href="/" class="logo-mono">
            <svg class="logo-bars" width="44" height="20" viewBox="0 0 56 26" fill="currentColor" aria-hidden="true">
                <rect x="0" y="2"  width="56" height="6"/>
                <rect x="0" y="11" width="36" height="6"/>
                <rect x="0" y="20" width="56" height="6"/>
            </svg>
            <div>
                <div class="logo-words">Elite Advisors</div>
                <div class="logo-sub">Business Management Suite · BMS</div>
            </div>
        </a>
        <nav class="site-nav">
            <a href="/#capabilities">Capabilities</a>
            <a href="/#integrations">Integrations</a>
            <a href="/#security">Security</a>
            <a href="/#newsroom">Newsroom</a>
            <a href="/demo" class="is-active">Demo</a>
        </nav>
        <div class="site-actions">
            <a href="/" class="btn btn-ghost">← Back to BMS</a>
            <a href="/#signin" class="btn btn-primary">Sign in</a>
        </div>
    </div>
</header>

{{-- ═══════════════════════════════════════════════════════════════════════════
   HERO
═══════════════════════════════════════════════════════════════════════════ --}}
<section class="hero">
    <div class="container">
        <p class="eyebrow scroll-reveal">Demo · live capabilities</p>
        <h1 class="scroll-reveal">Six demonstrations, <em>one page.</em></h1>
        <p class="scroll-reveal">
            Each section below is a complete walk-through of one BMS capability,
            rendered with figures from a typical OHADA engagement. Numbers reconcile
            within each demo (KPI strip ↔ table totals) and across demos (the same
            240M monthly CA HT drives the VAT and tax sections).
        </p>
        <div class="scroll-reveal">
            <span class="hero-disclaimer">Fictional illustrative figures · No client data</span>
        </div>
        <div class="scroll-reveal" style="margin-top: var(--space-8); display: flex; flex-direction: column; align-items: center; gap: var(--space-3);">
            <a href="/demo/enter" style="display: inline-flex; align-items: center; gap: 10px; padding: 14px 28px; background: linear-gradient(135deg, var(--bronze-500), var(--accent-hover)); color: #fff; font-weight: 600; font-size: 15px; border-radius: var(--radius-pill); text-decoration: none; box-shadow: var(--shadow-md); transition: transform var(--dur-quick) var(--ease-emphasis);"
               onmouseover="this.style.transform='translateY(-1px)'"
               onmouseout="this.style.transform='translateY(0)'">
                Open my private sandbox
                <span style="display: inline-block; font-size: 18px;">→</span>
            </a>
            <span style="font-size: var(--fs-caption); color: var(--fg-tertiary);">
                Email-gated · 2-hour session · Edit data + watch KPIs recompute live
            </span>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════════════════════
   CAPABILITY INDEX — quick anchor jumps to each demo
═══════════════════════════════════════════════════════════════════════════ --}}
<section class="index-section">
    <div class="container">
        @php
            $index = [
                ['key' => 'revenue',  'num' => '01', 'eyebrow' => 'Revenue Intelligence',  'title' => 'Variance, <em>decomposed.</em>',          'meta' => 'YTD 1,200M · Plan 1,250M · Gap −50M'],
                ['key' => 'treasury', 'num' => '02', 'eyebrow' => 'Liquidity Posture',     'title' => 'Three ratios, <em>one screen.</em>',      'meta' => 'Current 1.40x · Quick 0.95x · CCC 30d'],
                ['key' => 'ar',       'num' => '03', 'eyebrow' => 'Receivables Discipline','title' => 'The customers <em>behind your DSO.</em>', 'meta' => 'AR 280M · DSO 45d · 90+ 50M'],
                ['key' => 'gl',       'num' => '04', 'eyebrow' => 'Ledger Hygiene',        'title' => 'Findings <em>before the audit.</em>',     'meta' => 'Empty 150 · Manual 90 · After close 15'],
                ['key' => 'tax',      'num' => '05', 'eyebrow' => 'CGI Compliance',        'title' => 'Add-backs <em>at posting.</em>',          'meta' => '40 lines · Add-back 10.5M · @33% 3.5M'],
                ['key' => 'decl',     'num' => '06', 'eyebrow' => 'DGI Filings',           'title' => 'The déclaration <em>writes itself.</em>', 'meta' => 'CA HT 240M · Reverser 26.2M · Total 35.85M'],
            ];
        @endphp
        <div class="capability-index scroll-reveal">
            @foreach ($index as $row)
                <a href="#demo-{{ $row['key'] }}">
                    <span class="num">{{ $row['num'] }} · {{ $row['eyebrow'] }}</span>
                    <span class="title">{!! $row['title'] !!}</span>
                    <span class="meta">{{ $row['meta'] }}</span>
                    <span class="jump">Jump to demo</span>
                </a>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════════════════════
   01 · REVENUE — variance decomposed
═══════════════════════════════════════════════════════════════════════════ --}}
<section id="demo-revenue" class="demo-section">
    <div class="container">
        <div class="demo-section__head scroll-reveal">
            <span class="demo-section__num">01 · Revenue Intelligence</span>
            <h2 class="demo-section__title">Variance, <em>decomposed correctly.</em></h2>
            <p class="demo-section__lede">
                YTD revenue ran at 240M XAF / month for five months. Budget pace is
                250M / month. The gap reads as −50M (−4.0%), with volume effect and
                price effect split cleanly on the variance table so the narrative
                writes itself.
            </p>
        </div>

        <div class="kpi-strip scroll-reveal">
            <div class="kpi"><div class="kpi__l">Revenue YTD</div><div class="kpi__v">1,200<span class="u">M XAF</span></div><div class="kpi__m">HT basis · 5 mo</div></div>
            <div class="kpi"><div class="kpi__l">Budget YTD</div><div class="kpi__v">1,250<span class="u">M XAF</span></div><div class="kpi__m">linear pace</div></div>
            <div class="kpi"><div class="kpi__l">Net gap</div><div class="kpi__v is-neg">−50<span class="u">M XAF</span></div><div class="kpi__m">−4.0% vs plan</div></div>
            <div class="kpi"><div class="kpi__l">vs Prior year</div><div class="kpi__v is-pos">+10.0<span class="u">%</span></div><div class="kpi__m">trending up</div></div>
        </div>

        <div class="demo-table-wrap scroll-reveal">
            <table class="demo-table">
                <thead><tr><th>Top 5 product lines</th><th>Budget</th><th>Actual</th><th>Δ</th></tr></thead>
                <tbody>
                    <tr><td>Product A</td><td>280M</td><td>300M</td><td>+20M</td></tr>
                    <tr><td>Product B</td><td>220M</td><td>230M</td><td>+10M</td></tr>
                    <tr><td>Product C</td><td>150M</td><td>160M</td><td>+10M</td></tr>
                    <tr><td>Product D</td><td>120M</td><td>110M</td><td>−10M</td></tr>
                    <tr><td>Product E</td><td>80M</td><td>75M</td><td>−5M</td></tr>
                    <tr class="is-total"><td>Top 5 total</td><td>850M</td><td>875M</td><td>+25M</td></tr>
                </tbody>
            </table>
        </div>

        <div class="demo-footnote scroll-reveal">
            <p>
                Volume effect <code>(act_qty − bud_qty) × bud_price</code> and
                price effect <code>(act_price − bud_price) × act_qty</code>
                reconcile to the GL class 70 movement for the period. Revenue is
                always sourced from <code>amount_untaxed_signed</code> (HT) on
                customer invoices — never TTC.
            </p>
            <a href="#top" class="top-link">↑ Back to index</a>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════════════════════
   02 · TREASURY — liquidity ratios + CCC
═══════════════════════════════════════════════════════════════════════════ --}}
<section id="demo-treasury" class="demo-section is-alt">
    <div class="container">
        <div class="demo-section__head scroll-reveal">
            <span class="demo-section__num">02 · Liquidity Posture</span>
            <h2 class="demo-section__title">Three ratios, <em>one screen.</em></h2>
            <p class="demo-section__lede">
                Current, Quick and Cash ratios trended over 13 months against the 1.0×
                threshold. The Cash Conversion Cycle walks alongside — DSO 45 + DIO 25
                − |DPO| 40 = 30 days reconciles at the end of the strip.
            </p>
        </div>

        <div class="kpi-strip scroll-reveal">
            <div class="kpi"><div class="kpi__l">Current ratio</div><div class="kpi__v is-pos">1.40<span class="u">x</span></div><div class="kpi__m">healthy · ≥1.2 target</div></div>
            <div class="kpi"><div class="kpi__l">Quick ratio</div><div class="kpi__v is-pos">0.95<span class="u">x</span></div><div class="kpi__m">near 1.0 · acceptable</div></div>
            <div class="kpi"><div class="kpi__l">Cash ratio</div><div class="kpi__v is-warn">0.55<span class="u">x</span></div><div class="kpi__m">improving trend</div></div>
            <div class="kpi"><div class="kpi__l">CCC</div><div class="kpi__v is-pos">30<span class="u">d</span></div><div class="kpi__m">↓ from 45d · 6 mo ago</div></div>
        </div>

        <div class="demo-table-wrap scroll-reveal">
            <table class="demo-table">
                <thead><tr><th>Cash conversion cycle</th><th>Q1 2026</th><th>Q2 2026</th><th>Δ</th></tr></thead>
                <tbody>
                    <tr><td>DSO · days sales outstanding</td><td>50d</td><td>45d</td><td>−5d</td></tr>
                    <tr><td>DIO · days inventory outstanding</td><td>30d</td><td>25d</td><td>−5d</td></tr>
                    <tr><td>DPO · days payable outstanding</td><td>−35d</td><td>−40d</td><td>−5d</td></tr>
                    <tr class="is-total"><td>CCC = DSO + DIO − |DPO|</td><td>45d</td><td>30d</td><td>−15d</td></tr>
                </tbody>
            </table>
        </div>

        <div class="demo-footnote scroll-reveal">
            <p>
                All three components computed on a trailing-twelve-month basis with
                partial-period annualisation for new tenants — no artificial spikes,
                no missing data points. The 13-month strip is rendered with the same
                ECharts engine as the production suite.
            </p>
            <a href="#top" class="top-link">↑ Back to index</a>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════════════════════
   03 · RECEIVABLES — aging buckets + top offenders
═══════════════════════════════════════════════════════════════════════════ --}}
<section id="demo-ar" class="demo-section">
    <div class="container">
        <div class="demo-section__head scroll-reveal">
            <span class="demo-section__num">03 · Receivables Discipline</span>
            <h2 class="demo-section__title">The customers <em>behind your DSO.</em></h2>
            <p class="demo-section__lede">
                Aging buckets sum to 280M total AR, fully reconciled to the class 41
                GL balance. The 50M over-90 figure is concentrated in five customers —
                provisioning decisions can be made on names, not bucket totals.
            </p>
        </div>

        <div class="kpi-strip scroll-reveal">
            <div class="kpi"><div class="kpi__l">Total AR</div><div class="kpi__v">280<span class="u">M XAF</span></div><div class="kpi__m">40 active accounts</div></div>
            <div class="kpi"><div class="kpi__l">DSO</div><div class="kpi__v is-warn">45<span class="u">d</span></div><div class="kpi__m">target ≤30d</div></div>
            <div class="kpi"><div class="kpi__l">Over 60 days</div><div class="kpi__v is-warn">70<span class="u">M XAF</span></div><div class="kpi__m">25.0% of total</div></div>
            <div class="kpi"><div class="kpi__l">Over 90 days</div><div class="kpi__v is-neg">50<span class="u">M XAF</span></div><div class="kpi__m">17.9% · provisioning?</div></div>
        </div>

        <div class="demo-table-wrap scroll-reveal">
            <table class="demo-table">
                <thead><tr><th>Aging bucket</th><th>Outstanding</th><th>% of AR</th></tr></thead>
                <tbody>
                    <tr><td>Current</td><td>130M</td><td>46.4%</td></tr>
                    <tr><td>1–30 days</td><td>55M</td><td>19.6%</td></tr>
                    <tr><td>31–60 days</td><td>25M</td><td>8.9%</td></tr>
                    <tr><td>61–90 days</td><td>20M</td><td>7.1%</td></tr>
                    <tr><td>90+ days</td><td>50M</td><td>17.9%</td></tr>
                    <tr class="is-total"><td>Total</td><td>280M</td><td>100.0%</td></tr>
                </tbody>
            </table>
        </div>

        <div class="demo-table-wrap scroll-reveal">
            <table class="demo-table">
                <thead><tr><th>Top 5 offenders · over 90 days</th><th>Days</th><th>Outstanding</th></tr></thead>
                <tbody>
                    <tr><td>Customer A</td><td>145d</td><td>15.0M</td></tr>
                    <tr><td>Customer B</td><td>130d</td><td>12.0M</td></tr>
                    <tr><td>Customer C</td><td>115d</td><td>10.0M</td></tr>
                    <tr><td>Customer D</td><td>100d</td><td>8.0M</td></tr>
                    <tr><td>Customer E</td><td>95d</td><td>5.0M</td></tr>
                    <tr class="is-total"><td>Sum · matches bucket total</td><td>—</td><td>50.0M</td></tr>
                </tbody>
            </table>
        </div>

        <div class="demo-footnote scroll-reveal">
            <p>
                Top 5 offenders in 61–90 days bucket (F 6M · G 5M · H 4M · I 3M · J 2M)
                also sum to the bucket total — surfaced ahead of the next escalation
                cycle.
            </p>
            <a href="#top" class="top-link">↑ Back to index</a>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════════════════════
   04 · GL QUALITY — automated ledger checks
═══════════════════════════════════════════════════════════════════════════ --}}
<section id="demo-gl" class="demo-section is-alt">
    <div class="container">
        <div class="demo-section__head scroll-reveal">
            <span class="demo-section__num">04 · Ledger Hygiene</span>
            <h2 class="demo-section__title">Findings <em>before the audit, not during.</em></h2>
            <p class="demo-section__lede">
                Five of the 90+ automated checks shown here — empty descriptions,
                manual entries, round-number postings, lines posted after period
                close, and imbalanced moves (zero today). Findings link directly
                to the underlying journal lines for one-click drill-down.
            </p>
        </div>

        <div class="demo-table-wrap scroll-reveal">
            <table class="demo-table">
                <thead><tr><th>Automated check</th><th>Count</th><th>Status</th></tr></thead>
                <tbody>
                    <tr><td>Lines with empty / short descriptions (&lt; 5 chars)</td><td>150 lines</td><td>Undefined audit risk</td></tr>
                    <tr><td>Manual journal entries</td><td>90 entries</td><td>Within tolerance &lt; 100</td></tr>
                    <tr><td>Round-number postings (multiples of 100K / 1M)</td><td>25 entries</td><td>Review required</td></tr>
                    <tr><td>Lines posted after period close</td><td>15 entries</td><td>Period needs re-closing</td></tr>
                    <tr><td>Imbalanced moves (Σ Dr ≠ Σ Cr)</td><td>0 moves</td><td>All balanced ✓</td></tr>
                </tbody>
            </table>
        </div>

        <div class="demo-footnote scroll-reveal">
            <p>
                Each check is parametrised against the ledger and re-runs every
                period. New checks land via release notes — no client code to
                update. Custom thresholds per tenant.
            </p>
            <a href="#top" class="top-link">↑ Back to index</a>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════════════════════
   05 · INCOME TAX — CGI compliance & add-backs
═══════════════════════════════════════════════════════════════════════════ --}}
<section id="demo-tax" class="demo-section">
    <div class="container">
        <div class="demo-section__head scroll-reveal">
            <span class="demo-section__num">05 · CGI Compliance</span>
            <h2 class="demo-section__title">Add-backs identified <em>at posting.</em></h2>
            <p class="demo-section__lede">
                Forty lines flagged under CGI Article 7 — entertainment without
                business purpose, statutory fines and penalties, personal-benefit
                expenditure. Donations exceed the 1%-of-revenue ceiling by 3.0M.
                Combined: 10.5M of add-backs, 3.5M of tax exposure at the 33% rate.
            </p>
        </div>

        <div class="kpi-strip scroll-reveal">
            <div class="kpi"><div class="kpi__l">Non-deductible base</div><div class="kpi__v">7.5<span class="u">M XAF</span></div><div class="kpi__m">40 lines · CGI Art. 7</div></div>
            <div class="kpi"><div class="kpi__l">Donation excess</div><div class="kpi__v">3.0<span class="u">M XAF</span></div><div class="kpi__m">declared 15M · cap 12M</div></div>
            <div class="kpi"><div class="kpi__l">Add-back total</div><div class="kpi__v is-warn">10.5<span class="u">M XAF</span></div><div class="kpi__m">to taxable profit</div></div>
            <div class="kpi"><div class="kpi__l">Tax impact @ 33%</div><div class="kpi__v is-neg">3.5<span class="u">M XAF</span></div><div class="kpi__m">corporate IS rate</div></div>
        </div>

        <div class="demo-table-wrap scroll-reveal">
            <table class="demo-table">
                <thead><tr><th>Check</th><th>Trigger</th><th>Exposure</th></tr></thead>
                <tbody>
                    <tr><td>Non-deductible expenses · CGI Art. 7</td><td>40 lines flagged</td><td>7.5M XAF base</td></tr>
                    <tr><td>Donations exceeding 1% revenue cap</td><td>Declared 15M · cap 12M</td><td>3.0M XAF excess</td></tr>
                    <tr class="is-section"><td colspan="3">Combined corporate-tax exposure</td></tr>
                    <tr class="is-total"><td>Total add-back × 33% IS rate</td><td>10.5M add-back</td><td>3.5M XAF tax impact</td></tr>
                </tbody>
            </table>
        </div>

        <div class="demo-footnote scroll-reveal">
            <p>
                Each flagged line is drillable to the underlying
                <code>account.move.line</code>, with a per-line category override
                for accountant judgment. The full Income Tax Compliance module
                surfaces 12+ deductibility tests plus per-partner withholding
                reconciliation.
            </p>
            <a href="#top" class="top-link">↑ Back to index</a>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════════════════════
   06 · MONTHLY DECLARATION — DGI-ready filing extract
═══════════════════════════════════════════════════════════════════════════ --}}
<section id="demo-decl" class="demo-section is-alt">
    <div class="container">
        <div class="demo-section__head scroll-reveal">
            <span class="demo-section__num">06 · DGI Filings</span>
            <h2 class="demo-section__title">The déclaration <em>writes itself.</em></h2>
            <p class="demo-section__lede">
                Auto-generated <em>Déclaration mensuelle</em> ready for DGI
                submission. Every line derived directly from the GL — period
                boundaries respected, VAT cross-checked against class 4431, IS
                advance booked at 1.1% of CA TTC, withholdings on prestations and
                loyers separately. Sample extract for Avril 2026 below.
            </p>
        </div>

        <div class="demo-table-wrap scroll-reveal">
            <table class="demo-table">
                <thead><tr><th>Déclaration mensuelle · Avril 2026</th><th>Base</th><th>Montant XAF</th></tr></thead>
                <tbody>
                    <tr class="is-section"><td colspan="3">TVA — Taxe sur la Valeur Ajoutée</td></tr>
                    <tr><td>Chiffre d'affaires HT</td><td>—</td><td>240,000,000</td></tr>
                    <tr><td>TVA collectée</td><td>19.25%</td><td>46,200,000</td></tr>
                    <tr><td>TVA déductible · achats</td><td>—</td><td>(20,000,000)</td></tr>
                    <tr><td>TVA déductible · investissements</td><td>—</td><td>(0)</td></tr>
                    <tr class="is-total"><td>TVA à reverser</td><td>—</td><td>26,200,000</td></tr>

                    <tr class="is-section"><td colspan="3">IS — Impôt sur les Sociétés</td></tr>
                    <tr><td>Acompte mensuel</td><td>1.1% CA TTC</td><td>3,150,000</td></tr>
                    <tr><td>Acomptes versés YTD</td><td>—</td><td>(12,600,000)</td></tr>

                    <tr class="is-section"><td colspan="3">Retenues à la source</td></tr>
                    <tr><td>RAS sur prestations</td><td>5.5%</td><td>4,500,000</td></tr>
                    <tr><td>RAS sur loyers</td><td>10%</td><td>2,000,000</td></tr>

                    <tr class="is-total"><td>Total à payer · échéance 15 mai 2026</td><td>—</td><td>35,850,000</td></tr>
                </tbody>
            </table>
        </div>

        <div class="demo-footnote scroll-reveal">
            <p>
                Exports to print-ready PDF and the DGI portal Excel template.
                Variance flag triggers when declared VAT doesn't reconcile to the
                class 4431 movement for the same period — caught before
                submission, not after.
            </p>
            <a href="#top" class="top-link">↑ Back to index</a>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════════════════════
   FINAL CTA
═══════════════════════════════════════════════════════════════════════════ --}}
<section class="final-cta">
    <div class="container">
        <p class="eyebrow">Run it against your own data</p>
        <h2>Same demos, <em>your numbers.</em></h2>
        <p>
            Book a 30-minute walk-through with the team. We'll wire the suite to
            a sandbox copy of your Odoo and show you the same six demonstrations
            with your actual chart of accounts. No slide deck.
        </p>
        <div class="cta-buttons">
            <a href="mailto:hello@cm-ea.com?subject=BMS%20demo%20against%20our%20Odoo" class="btn btn-primary">Book a walk-through</a>
            <a href="/" class="btn btn-ghost">← Back to BMS</a>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════════════════════
   FOOTER
═══════════════════════════════════════════════════════════════════════════ --}}
<footer class="site-footer">
    <div class="container">
        <div class="footer-bottom">
            <span>© 2026 Elite Advisors · Business Management Suite</span>
            <span><a href="/">Back to bms.cm-ea.com</a> · <a href="https://www.eliteadvisors.cm-ea.com" target="_blank" rel="noopener">eliteadvisors.cm-ea.com ↗</a></span>
        </div>
    </div>
</footer>

<script>
    // Scroll-reveal on intersection — same pattern as the login page so
    // the two feel consistent.
    (function () {
        const items = document.querySelectorAll('.scroll-reveal');
        if (!('IntersectionObserver' in window)) {
            items.forEach(el => el.classList.add('is-visible'));
            return;
        }
        const obs = new IntersectionObserver((entries) => {
            entries.forEach(e => {
                if (e.isIntersecting) {
                    e.target.classList.add('is-visible');
                    obs.unobserve(e.target);
                }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
        items.forEach(el => obs.observe(el));
    })();
</script>

</body>
</html>
