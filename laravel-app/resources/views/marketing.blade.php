<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>BMS Business Intelligence · Elite Advisors</title>
    <meta name="description" content="Elite Advisors BMS turns your accounting ledger into weekly decisions: revenue, margins, receivables, cash and unit economics, in English and French.">
    {{-- Draft under review: keep out of search engines until approved. --}}
    <meta name="robots" content="noindex, nofollow">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@300;400;500;600;700&family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;1,6..72,400&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --sans: 'Hanken Grotesk', -apple-system, 'Segoe UI', sans-serif;
            --serif: 'Newsreader', Georgia, serif;
            --mono: 'JetBrains Mono', ui-monospace, monospace;
            --page: #FBF8F1; --surface: #F5F0E4; --surface-2: #EDE5D2;
            --ink: #14110F; --ink-2: #4A4239; --ink-3: #6B6157;
            --line: #D8D0C4; --line-soft: #ECE6DA;
            --accent: #B07F3B; --accent-dark: #8E6228; --accent-soft: #F8EFE2;
            --dark: #1F1B17; --dark-2: #2E2823; --on-dark: #F5F0E4; --on-dark-2: #C9BFB0;
            --pos: #2D6A4F; --neg: #B23A2B;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body { font-family: var(--sans); color: var(--ink); background: var(--page); line-height: 1.55; -webkit-font-smoothing: antialiased; }
        a { color: inherit; text-decoration: none; }
        .wrap { max-width: 1200px; margin: 0 auto; padding: 0 16px; }
        @media (min-width: 768px) { .wrap { padding: 0 40px; } }
        .eyebrow { font-family: var(--mono); font-size: 12px; letter-spacing: .18em; text-transform: uppercase; color: var(--accent); }
        h1, h2 { font-family: var(--serif); font-weight: 500; letter-spacing: -.02em; line-height: 1.08; }
        h1 { font-size: clamp(40px, 6vw, 72px); }
        h2 { font-size: clamp(30px, 4vw, 44px); }
        h3 { font-size: 20px; font-weight: 600; letter-spacing: -.01em; }
        .lead { font-size: 19px; color: var(--ink-2); max-width: 640px; }
        section { padding: 88px 0; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 13px 22px; border-radius: 4px; font-weight: 600; font-size: 15px; transition: background .16s; }
        .btn-primary { background: var(--ink); color: var(--page); }
        .btn-primary:hover { background: var(--dark-2); }
        .btn-ghost { border: 1px solid var(--line); }
        .btn-ghost:hover { background: var(--surface); }

        /* header */
        header { position: sticky; top: 0; z-index: 20; background: rgba(251,248,241,.93); backdrop-filter: blur(10px); border-bottom: 1px solid var(--line); }
        .bar { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px 0; }
        .logo { display: flex; align-items: center; gap: 12px; }
        .logo b { display: block; font-size: 15px; font-weight: 600; line-height: 1.1; }
        .logo small { display: block; font-family: var(--mono); font-size: 10px; color: var(--accent); letter-spacing: .16em; text-transform: uppercase; margin-top: 3px; }
        nav.links { display: none; gap: 26px; font-size: 14px; color: var(--ink-2); }
        nav.links a:hover { color: var(--ink); }
        @media (min-width: 960px) { nav.links { display: flex; } }
        .lang { display: inline-flex; border: 1px solid var(--line); border-radius: 999px; overflow: hidden; font-family: var(--mono); font-size: 12px; }
        .lang button { border: 0; background: transparent; padding: 7px 12px; cursor: pointer; color: var(--ink-3); font: inherit; }
        .lang button[aria-pressed="true"] { background: var(--ink); color: var(--page); }

        /* hero */
        .hero { padding: 80px 0 64px; }
        .hero-grid { display: grid; gap: 48px; align-items: center; }
        @media (min-width: 980px) { .hero-grid { grid-template-columns: 1.05fr .95fr; } }
        .hero h1 em { font-style: italic; color: var(--accent-dark); }
        .hero .lead { margin: 22px 0 32px; }
        .cta { display: flex; flex-wrap: wrap; gap: 12px; }
        .kpi-board { background: var(--dark); color: var(--on-dark); border-radius: 10px; padding: 22px; box-shadow: 0 18px 40px rgba(31,27,23,.18); }
        .kpi-board .top { display: flex; justify-content: space-between; font-family: var(--mono); font-size: 11px; color: var(--on-dark-2); letter-spacing: .1em; text-transform: uppercase; margin-bottom: 16px; }
        .kpis { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 14px; }
        .kpi { background: var(--dark-2); border-radius: 6px; padding: 12px 14px; }
        .kpi span { font-size: 12px; color: var(--on-dark-2); }
        .kpi strong { display: block; font-family: var(--mono); font-size: 22px; font-weight: 500; margin-top: 2px; }
        .kpi i { font-style: normal; font-size: 12px; }
        .up { color: #7FC8A0; } .down { color: #F08A7A; }
        .chart-dark { height: 170px; }
        .proof { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1px; background: var(--line); border: 1px solid var(--line); border-radius: 8px; overflow: hidden; margin-top: 56px; }
        @media (min-width: 820px) { .proof { grid-template-columns: repeat(4, 1fr); } }
        .proof div { background: var(--page); padding: 20px; }
        .proof strong { font-family: var(--serif); font-size: 26px; font-weight: 500; display: block; }
        .proof span { font-size: 14px; color: var(--ink-3); }

        /* how it works */
        .steps { display: grid; gap: 20px; margin-top: 40px; }
        @media (min-width: 860px) { .steps { grid-template-columns: repeat(3, 1fr); } }
        .step { border-top: 2px solid var(--ink); padding-top: 18px; }
        .step .n { font-family: var(--mono); font-size: 12px; color: var(--accent); }
        .step h3 { margin: 6px 0 8px; }
        .step p { color: var(--ink-2); font-size: 15px; }

        /* showcase */
        .alt { background: var(--surface); border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
        .head { display: grid; gap: 14px; margin-bottom: 44px; }
        .demo-note { font-family: var(--mono); font-size: 11px; color: var(--ink-3); letter-spacing: .08em; text-transform: uppercase; }
        .cards { display: grid; gap: 22px; }
        @media (min-width: 900px) { .cards { grid-template-columns: repeat(2, 1fr); } }
        .card { background: var(--page); border: 1px solid var(--line); border-radius: 10px; padding: 26px; display: flex; flex-direction: column; gap: 14px; }
        .card .tag { font-family: var(--mono); font-size: 11px; color: var(--accent); letter-spacing: .14em; text-transform: uppercase; }
        .card .hook { font-family: var(--serif); font-size: 24px; line-height: 1.2; }
        .card .chart { height: 240px; position: relative; }
        .decide { background: var(--accent-soft); border-left: 3px solid var(--accent); padding: 12px 14px; font-size: 14px; color: #4F3614; border-radius: 0 4px 4px 0; }
        .decide b { font-weight: 600; }

        /* signal → decision */
        .table { width: 100%; border-collapse: collapse; margin-top: 32px; font-size: 15px; }
        .table th { text-align: left; font-family: var(--mono); font-size: 11px; letter-spacing: .14em; text-transform: uppercase; color: var(--ink-3); font-weight: 500; padding: 0 12px 12px 0; border-bottom: 1px solid var(--ink); }
        .table td { padding: 16px 12px 16px 0; border-bottom: 1px solid var(--line); vertical-align: top; }
        .table td:first-child { font-weight: 600; width: 34%; }
        .pill { display: inline-block; font-family: var(--mono); font-size: 11px; padding: 2px 8px; border-radius: 999px; margin-left: 6px; vertical-align: 2px; }
        .pill.red { background: #F4D9D4; color: #8A2A1C; } .pill.amber { background: #F6E7C8; color: #7A4F0A; }
        @media (max-width: 640px) { .table, .table tbody, .table tr, .table td { display: block; width: 100% !important; } .table thead { display: none; } .table td { border: 0; padding: 4px 0; } .table tr { border-bottom: 1px solid var(--line); padding: 12px 0; } }

        /* security */
        .dark { background: var(--dark); color: var(--on-dark); }
        .dark .lead { color: var(--on-dark-2); }
        .dark .eyebrow { color: #D9A866; }
        .sec { display: grid; gap: 1px; background: #3A332C; border: 1px solid #3A332C; border-radius: 10px; overflow: hidden; margin-top: 40px; }
        @media (min-width: 720px) { .sec { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 1040px) { .sec { grid-template-columns: repeat(4, 1fr); } }
        .sec div { background: var(--dark); padding: 24px; }
        .sec svg { color: #D9A866; margin-bottom: 12px; }
        .sec h3 { font-size: 17px; margin-bottom: 6px; }
        .sec p { font-size: 14px; color: var(--on-dark-2); }

        /* close */
        .close { text-align: center; }
        .close .lead { margin: 18px auto 30px; }
        .close .cta { justify-content: center; }
        footer { border-top: 1px solid var(--line); padding: 28px 0; font-size: 13px; color: var(--ink-3); }
        footer .wrap { display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-between; }
    </style>
</head>
<body>

<header>
    <div class="wrap bar">
        <a href="#top" class="logo">
            <svg width="40" height="18" viewBox="0 0 56 26" fill="currentColor" aria-hidden="true"><rect x="0" y="2" width="56" height="6"/><rect x="0" y="11" width="36" height="6"/><rect x="0" y="20" width="56" height="6"/></svg>
            <span><b>Elite Advisors</b><small>Business Management Suite · BMS</small></span>
        </a>
        <nav class="links">
            <a href="#how" data-i18n="nav_how">How it works</a>
            <a href="#intelligence" data-i18n="nav_bi">Business Intelligence</a>
            <a href="#decisions" data-i18n="nav_dec">Decisions</a>
            <a href="#security" data-i18n="nav_sec">Security</a>
        </nav>
        <div class="lang" role="group" aria-label="Language / Langue">
            <button type="button" data-lang="en" aria-pressed="true">EN</button>
            <button type="button" data-lang="fr" aria-pressed="false">FR</button>
        </div>
    </div>
</header>

<main id="top">
    {{-- HERO --}}
    <section class="hero">
        <div class="wrap">
            <div class="hero-grid">
                <div>
                    <p class="eyebrow" data-i18n="hero_eye">Business Intelligence · OHADA-native</p>
                    <h1 data-i18n="hero_h1" style="margin-top:14px">Your ledger already knows. <em>Now you will too.</em></h1>
                    <p class="lead" data-i18n="hero_lead">BMS connects to your accounting system, reads every posted entry and turns it into the handful of numbers that decide your week: revenue, margin, cash, collections and cost per unit of work.</p>
                    <div class="cta">
                        <a class="btn btn-primary" href="mailto:contact@cm-ea.com?subject=BMS%20demo" data-i18n="cta_demo">Book a 30-minute demo</a>
                        <a class="btn btn-ghost" href="#intelligence" data-i18n="cta_see">See it in action ↓</a>
                    </div>
                </div>
                <div class="kpi-board" aria-label="Sample dashboard">
                    <div class="top"><span data-i18n="board_title">Dashboard · year to date</span><span data-i18n="board_demo">Sample data</span></div>
                    <div class="kpis">
                        <div class="kpi"><span data-i18n="k_rev">Revenue</span><strong>1.24 bn</strong><i class="up" data-i18n="k_rev_d">▲ 12% vs last year</i></div>
                        <div class="kpi"><span data-i18n="k_ebitda">EBITDA margin</span><strong>29.0%</strong><i class="up" data-i18n="k_ebitda_d">▲ 3.1 pts</i></div>
                        <div class="kpi"><span data-i18n="k_dso">Days sales outstanding</span><strong>44 d</strong><i class="up" data-i18n="k_dso_d">▼ 24 days since Jan</i></div>
                        <div class="kpi"><span data-i18n="k_cash">Cash at bank</span><strong>182 M</strong><i class="down" data-i18n="k_cash_d">Low point in week 7</i></div>
                    </div>
                    <div class="chart-dark"><canvas id="c-hero" aria-label="Monthly revenue trend"></canvas></div>
                </div>
            </div>
            <div class="proof">
                <div><strong data-i18n="p1">Read-only</strong><span data-i18n="p1s">Connection to Odoo or Sage: BMS never writes to your books</span></div>
                <div><strong data-i18n="p2">Minutes</strong><span data-i18n="p2s">Figures refresh automatically throughout the day</span></div>
                <div><strong data-i18n="p3">OHADA</strong><span data-i18n="p3s">Built on the SYSCOHADA chart of accounts, classes 1 to 7</span></div>
                <div><strong>EN · FR</strong><span data-i18n="p4s">Every page, report and email in both languages</span></div>
            </div>
        </div>
    </section>

    {{-- HOW IT WORKS --}}
    <section id="how" class="alt">
        <div class="wrap">
            <p class="eyebrow" data-i18n="how_eye">How it works</p>
            <h2 data-i18n="how_h2" style="margin-top:12px">Connect once. Decide every week.</h2>
            <div class="steps">
                <div class="step"><span class="n">01</span><h3 data-i18n="s1">Connect</h3><p data-i18n="s1p">We link BMS to your accounting system with a read-only user. No data entry, no spreadsheets, no change to how your team works.</p></div>
                <div class="step"><span class="n">02</span><h3 data-i18n="s2">Compute</h3><p data-i18n="s2p">BMS applies accounting logic to your ledger: margins by layer, receivables ageing, cash runway, per-file and per-unit economics.</p></div>
                <div class="step"><span class="n">03</span><h3 data-i18n="s3">Decide</h3><p data-i18n="s3p">Management sees one dashboard, drills into any figure and receives a daily summary by email, with the signals that need action highlighted.</p></div>
            </div>
        </div>
    </section>

    {{-- BI SHOWCASE --}}
    <section id="intelligence">
        <div class="wrap">
            <div class="head">
                <p class="eyebrow" data-i18n="bi_eye">Business Intelligence</p>
                <h2 data-i18n="bi_h2">Six views that run the business</h2>
                <p class="lead" data-i18n="bi_lead">Each view answers one management question, then tells you what to do about it.</p>
                <p class="demo-note" data-i18n="demo_note">Charts show illustrative sample data, in millions of XAF</p>
            </div>
            <div class="cards">
                <article class="card">
                    <span class="tag" data-i18n="c1_tag">Profit &amp; Loss · Margin waterfall</span>
                    <p class="hook" data-i18n="c1_hook">Where does every franc of revenue go?</p>
                    <div class="chart"><canvas id="c-waterfall" aria-label="Margin waterfall chart"></canvas></div>
                    <p class="decide" data-i18n="c1_dec"><b>Decision:</b> staff costs take 23% of revenue. Benchmark them before approving new hires.</p>
                </article>
                <article class="card">
                    <span class="tag" data-i18n="c2_tag">Revenue · Actual vs budget vs last year</span>
                    <p class="hook" data-i18n="c2_hook">See the month slipping before it closes.</p>
                    <div class="chart"><canvas id="c-revenue" aria-label="Revenue versus budget chart"></canvas></div>
                    <p class="decide" data-i18n="c2_dec"><b>Decision:</b> July and August fell below budget. Bring the commercial review forward rather than wait for the year end.</p>
                </article>
                <article class="card">
                    <span class="tag" data-i18n="c3_tag">Receivables · Ageing &amp; DSO</span>
                    <p class="hook" data-i18n="c3_hook">Your cash, still sitting in your clients’ bank.</p>
                    <div class="chart"><canvas id="c-ageing" aria-label="Receivables ageing chart"></canvas></div>
                    <p class="decide" data-i18n="c3_dec"><b>Decision:</b> 83 M is over 60 days old. Call those five clients this week, and review their credit terms.</p>
                </article>
                <article class="card">
                    <span class="tag" data-i18n="c4_tag">Treasury · 13-week cash forecast</span>
                    <p class="hook" data-i18n="c4_hook">Know the tight week before it arrives.</p>
                    <div class="chart"><canvas id="c-cash" aria-label="Cash forecast chart"></canvas></div>
                    <p class="decide" data-i18n="c4_dec"><b>Decision:</b> cash dips under the 75 M floor in week 7. Reschedule two supplier payments now.</p>
                </article>
                <article class="card">
                    <span class="tag" data-i18n="c5_tag">Profitability · Unit economics</span>
                    <p class="hook" data-i18n="c5_hook">Is each file, order or job still worth it?</p>
                    <div class="chart"><canvas id="c-unit" aria-label="Revenue and cost per unit chart"></canvas></div>
                    <p class="decide" data-i18n="c5_dec"><b>Decision:</b> cost per file is closing on revenue per file. Revisit pricing before the margin disappears.</p>
                </article>
                <article class="card">
                    <span class="tag" data-i18n="c6_tag">Fixed assets · Value &amp; renewal</span>
                    <p class="hook" data-i18n="c6_hook">Plan the next investment, not the next breakdown.</p>
                    <div class="chart"><canvas id="c-assets" aria-label="Fixed assets by category chart"></canvas></div>
                    <p class="decide" data-i18n="c6_dec"><b>Decision:</b> vehicles are 82% depreciated. Budget their replacement in next year’s capex.</p>
                </article>
            </div>
        </div>
    </section>

    {{-- SIGNAL → DECISION --}}
    <section id="decisions" class="alt">
        <div class="wrap">
            <p class="eyebrow" data-i18n="d_eye">From number to action</p>
            <h2 data-i18n="d_h2" style="margin-top:12px">BMS flags it. You decide.</h2>
            <p class="lead" data-i18n="d_lead" style="margin-top:14px">Thresholds turn each indicator green, amber or red, so meetings start with what needs a decision.</p>
            <table class="table">
                <thead><tr><th data-i18n="t_sig">Signal BMS raises</th><th data-i18n="t_act">The decision it prompts</th></tr></thead>
                <tbody>
                    <tr><td><span data-i18n="r1s">Collection period above 60 days</span><span class="pill red" data-i18n="red">Red</span></td><td data-i18n="r1a">Prioritise collection calls, tighten credit limits, ask for deposits from slow payers.</td></tr>
                    <tr><td><span data-i18n="r2s">Gross margin down 3 points on last year</span><span class="pill amber" data-i18n="amber">Amber</span></td><td data-i18n="r2a">Open the margin waterfall, find the cost layer that moved, renegotiate or reprice.</td></tr>
                    <tr><td><span data-i18n="r3s">Cash forecast below the safety floor</span><span class="pill red" data-i18n="red">Red</span></td><td data-i18n="r3a">Reschedule supplier payments, chase the largest receivables, arrange a facility early.</td></tr>
                    <tr><td><span data-i18n="r4s">Top 5 clients above 50% of receivables</span><span class="pill amber" data-i18n="amber">Amber</span></td><td data-i18n="r4a">Reduce concentration risk: diversify the portfolio and secure guarantees.</td></tr>
                    <tr><td><span data-i18n="r5s">Revenue below budget two months running</span><span class="pill amber" data-i18n="amber">Amber</span></td><td data-i18n="r5a">Review the pipeline and pricing, and adjust the year-end forecast now.</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    {{-- SECURITY --}}
    <section id="security" class="dark">
        <div class="wrap">
            <p class="eyebrow" data-i18n="sec_eye">Security</p>
            <h2 data-i18n="sec_h2" style="margin-top:12px">Your figures stay yours.</h2>
            <p class="lead" data-i18n="sec_lead" style="margin-top:14px">Financial data is the most sensitive data a company holds. BMS is built so that it is only ever read, only by the right people, and never mixed with anyone else’s.</p>
            <div class="sec">
                <div>
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                    <h3 data-i18n="x1">Row-level isolation</h3><p data-i18n="x1p">Every record, report and cached result carries your workspace’s identity. A user signed in to one workspace cannot open another, even by changing the address.</p>
                </div>
                <div>
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                    <h3 data-i18n="x2">Read-only connection</h3><p data-i18n="x2p">BMS reads your accounting system through a dedicated read-only user. It cannot create, change or delete an entry in your books.</p>
                </div>
                <div>
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                    <h3 data-i18n="x3">Encrypted credentials</h3><p data-i18n="x3p">Connection keys and passwords are encrypted at rest and never shown in the interface. All traffic travels over HTTPS.</p>
                </div>
                <div>
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5"/><path d="M17 4.5a3.5 3.5 0 0 1 0 7M19 14.8c1.6.8 2.6 2.6 3 5.2"/></svg>
                    <h3 data-i18n="x4">Role-based access</h3><p data-i18n="x4p">Owner, administrator, manager, analyst and viewer roles, plus module-by-module access. Each person sees only the reports they need.</p>
                </div>
                <div>
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 3l8 3v6c0 4.5-3.4 8.2-8 9-4.6-.8-8-4.5-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/></svg>
                    <h3 data-i18n="x5">Protected sign-in</h3><p data-i18n="x5p">Limits on repeated login attempts, one-time password reset links, a forced password change for new accounts, and suspended users are signed out immediately.</p>
                </div>
                <div>
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M6 3h9l4 4v14H6z"/><path d="M9 11h7M9 15h7M9 7h3"/></svg>
                    <h3 data-i18n="x6">Audit trail</h3><p data-i18n="x6p">Administrative actions, such as sign-ins, settings changes and access grants, are logged with who did what and when.</p>
                </div>
                <div>
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    <h3 data-i18n="x7">Session safety</h3><p data-i18n="x7p">Sessions expire after inactivity and are tied to one workspace. Cookies are protected from scripts.</p>
                </div>
                <div>
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="4" width="18" height="6" rx="1"/><rect x="3" y="14" width="18" height="6" rx="1"/><path d="M7 7h.01M7 17h.01"/></svg>
                    <h3 data-i18n="x8">Dedicated hosting</h3><p data-i18n="x8p">BMS runs on a dedicated server behind a protective network layer, with backups taken before every major change.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- CLOSE --}}
    <section class="close">
        <div class="wrap">
            <h2 data-i18n="end_h2">See your own numbers in BMS.</h2>
            <p class="lead" data-i18n="end_lead">In a 30-minute session we connect to a copy of your data and show you your dashboard, not a demo company.</p>
            <div class="cta">
                <a class="btn btn-primary" href="mailto:contact@cm-ea.com?subject=BMS%20demo" data-i18n="cta_demo">Book a 30-minute demo</a>
            </div>
        </div>
    </section>
</main>

<footer>
    <div class="wrap">
        <span>© {{ date('Y') }} Elite Advisors · Business Management Suite</span>
        <span data-i18n="foot">Charts on this page use illustrative data.</span>
    </div>
</footer>

@verbatim
<script>
(function () {
    var FR = {
        nav_how: 'Fonctionnement', nav_bi: 'Business Intelligence', nav_dec: 'Décisions', nav_sec: 'Sécurité',
        hero_eye: 'Business Intelligence · conçu pour l’OHADA',
        hero_h1: 'Votre comptabilité sait déjà tout. <em>Désormais, vous aussi.</em>',
        hero_lead: 'BMS se connecte à votre logiciel comptable, lit chaque écriture validée et la transforme en quelques chiffres qui décident de votre semaine : chiffre d’affaires, marge, trésorerie, recouvrement et coût par unité de travail.',
        cta_demo: 'Réserver une démo de 30 minutes', cta_see: 'Voir en action ↓',
        board_title: 'Tableau de bord · cumul annuel', board_demo: 'Données fictives',
        k_rev: 'Chiffre d’affaires', k_rev_d: '▲ 12 % sur un an', k_ebitda: 'Marge d’EBE', k_ebitda_d: '▲ 3,1 pts',
        k_dso: 'Délai moyen de paiement clients', k_dso_d: '▼ 24 jours depuis janvier', k_cash: 'Trésorerie', k_cash_d: 'Point bas en semaine 7',
        p1: 'Lecture seule', p1s: 'Connexion à Odoo ou Sage : BMS n’écrit jamais dans vos livres',
        p2: 'Minutes', p2s: 'Les chiffres se mettent à jour automatiquement tout au long de la journée',
        p3: 'OHADA', p3s: 'Bâti sur le plan comptable SYSCOHADA, classes 1 à 7',
        p4s: 'Chaque page, rapport et e-mail dans les deux langues',
        how_eye: 'Fonctionnement', how_h2: 'Une connexion. Des décisions chaque semaine.',
        s1: 'Connecter', s1p: 'Nous relions BMS à votre logiciel comptable avec un utilisateur en lecture seule. Aucune saisie, aucun tableur, aucun changement pour vos équipes.',
        s2: 'Calculer', s2p: 'BMS applique la logique comptable à votre grand livre : marges par niveau, balance âgée clients, autonomie de trésorerie, rentabilité par dossier et par unité.',
        s3: 'Décider', s3p: 'La direction consulte un seul tableau de bord, explore chaque chiffre et reçoit un résumé quotidien par e-mail, avec les signaux à traiter mis en évidence.',
        bi_eye: 'Business Intelligence', bi_h2: 'Six vues pour piloter l’entreprise',
        bi_lead: 'Chaque vue répond à une question de direction, puis indique quoi faire.',
        demo_note: 'Graphiques à données fictives, en millions de XAF',
        c1_tag: 'Compte de résultat · Cascade des marges', c1_hook: 'Où part chaque franc de chiffre d’affaires ?',
        c1_dec: '<b>Décision :</b> les charges de personnel absorbent 23 % du chiffre d’affaires. Comparez-les au marché avant de valider de nouveaux recrutements.',
        c2_tag: 'Chiffre d’affaires · Réel, budget et année précédente', c2_hook: 'Voyez le mois décrocher avant sa clôture.',
        c2_dec: '<b>Décision :</b> juillet et août sont sous le budget. Avancez la revue commerciale au lieu d’attendre la fin d’année.',
        c3_tag: 'Créances clients · Balance âgée et DSO', c3_hook: 'Votre trésorerie, encore dans la banque de vos clients.',
        c3_dec: '<b>Décision :</b> 83 M ont plus de 60 jours. Appelez ces cinq clients cette semaine et revoyez leurs conditions de crédit.',
        c4_tag: 'Trésorerie · Prévision sur 13 semaines', c4_hook: 'Anticipez la semaine tendue avant qu’elle n’arrive.',
        c4_dec: '<b>Décision :</b> la trésorerie passe sous le seuil de 75 M en semaine 7. Décalez dès maintenant deux paiements fournisseurs.',
        c5_tag: 'Rentabilité · Économie unitaire', c5_hook: 'Chaque dossier, commande ou mission est-il encore rentable ?',
        c5_dec: '<b>Décision :</b> le coût par dossier rattrape le revenu par dossier. Revoyez vos tarifs avant que la marge ne disparaisse.',
        c6_tag: 'Immobilisations · Valeur et renouvellement', c6_hook: 'Planifiez le prochain investissement, pas la prochaine panne.',
        c6_dec: '<b>Décision :</b> les véhicules sont amortis à 82 %. Prévoyez leur remplacement au budget d’investissement de l’an prochain.',
        d_eye: 'Du chiffre à l’action', d_h2: 'BMS alerte. Vous décidez.',
        d_lead: 'Des seuils colorent chaque indicateur en vert, orange ou rouge : les réunions commencent par ce qui demande une décision.',
        t_sig: 'Signal remonté par BMS', t_act: 'La décision qu’il déclenche', red: 'Rouge', amber: 'Orange',
        r1s: 'Délai de recouvrement supérieur à 60 jours', r1a: 'Prioriser les relances, resserrer les plafonds de crédit, exiger des acomptes des mauvais payeurs.',
        r2s: 'Marge brute en baisse de 3 points sur un an', r2a: 'Ouvrir la cascade des marges, identifier le poste de coût qui a bougé, renégocier ou réviser les prix.',
        r3s: 'Prévision de trésorerie sous le seuil de sécurité', r3a: 'Décaler des paiements fournisseurs, relancer les plus grosses créances, négocier une facilité à temps.',
        r4s: 'Cinq premiers clients au-delà de 50 % des créances', r4a: 'Réduire le risque de concentration : diversifier le portefeuille et obtenir des garanties.',
        r5s: 'Chiffre d’affaires sous le budget deux mois de suite', r5a: 'Revoir le pipeline et les prix, et ajuster dès maintenant la prévision de fin d’année.',
        sec_eye: 'Sécurité', sec_h2: 'Vos chiffres restent les vôtres.',
        sec_lead: 'Les données financières sont les plus sensibles d’une entreprise. BMS est conçu pour qu’elles soient uniquement lues, seulement par les bonnes personnes, et jamais mélangées à celles d’un autre client.',
        x1: 'Isolation au niveau des lignes', x1p: 'Chaque enregistrement, rapport et résultat en cache porte l’identité de votre espace. Un utilisateur connecté à un espace ne peut pas en ouvrir un autre, même en modifiant l’adresse.',
        x2: 'Connexion en lecture seule', x2p: 'BMS lit votre logiciel comptable via un utilisateur dédié en lecture seule. Il ne peut ni créer, ni modifier, ni supprimer une écriture.',
        x3: 'Identifiants chiffrés', x3p: 'Les clés de connexion et mots de passe sont chiffrés au repos et jamais affichés à l’écran. Tous les échanges passent en HTTPS.',
        x4: 'Accès par rôle', x4p: 'Rôles propriétaire, administrateur, manager, analyste et lecteur, plus un accès module par module. Chacun ne voit que les rapports dont il a besoin.',
        x5: 'Connexion protégée', x5p: 'Limitation des tentatives de connexion, liens de réinitialisation à usage unique, changement de mot de passe imposé aux nouveaux comptes, déconnexion immédiate des utilisateurs suspendus.',
        x6: 'Piste d’audit', x6p: 'Les actions d’administration, comme les connexions, changements de paramètres et attributions d’accès, sont journalisées : qui a fait quoi, et quand.',
        x7: 'Sessions sécurisées', x7p: 'Les sessions expirent après inactivité et sont liées à un seul espace. Les cookies sont protégés contre les scripts.',
        x8: 'Hébergement dédié', x8p: 'BMS tourne sur un serveur dédié derrière une couche réseau protectrice, avec des sauvegardes avant chaque changement majeur.',
        end_h2: 'Découvrez vos propres chiffres dans BMS.',
        end_lead: 'En 30 minutes, nous nous connectons à une copie de vos données et vous montrons votre tableau de bord, pas une société fictive.',
        foot: 'Les graphiques de cette page utilisent des données fictives.'
    };
    var L = {
        en: { months: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct'], actual: 'Actual', budget: 'Budget', prior: 'Last year',
              wf: ['Revenue','Direct costs','Staff','Other opex','EBITDA','D&A','Operating profit'],
              age: ['Not due','1–30 d','31–60 d','61–90 d','90+ d'], dso: 'DSO (days)', ar: 'Receivables (M XAF)',
              wk: 'W', cash: 'Forecast cash', floor: 'Safety floor', rpf: 'Revenue per file (K XAF)', cpf: 'Cost per file (K XAF)',
              assets: ['Buildings','Vehicles','IT equipment','Furniture'], nbv: 'Net book value', dep: 'Depreciated' },
        fr: { months: ['Janv','Févr','Mars','Avr','Mai','Juin','Juil','Août','Sept','Oct'], actual: 'Réel', budget: 'Budget', prior: 'Année précédente',
              wf: ['Chiffre d’affaires','Coûts directs','Personnel','Autres charges','EBE','Dotations','Résultat d’exploitation'],
              age: ['Non échu','1–30 j','31–60 j','61–90 j','+90 j'], dso: 'DSO (jours)', ar: 'Créances (M XAF)',
              wk: 'S', cash: 'Trésorerie prévue', floor: 'Seuil de sécurité', rpf: 'Revenu par dossier (K XAF)', cpf: 'Coût par dossier (K XAF)',
              assets: ['Bâtiments','Véhicules','Informatique','Mobilier'], nbv: 'Valeur nette', dep: 'Amorti' }
    };

    var EN = {};
    document.querySelectorAll('[data-i18n]').forEach(function (el) {
        var k = el.getAttribute('data-i18n');
        if (!(k in EN)) EN[k] = el.innerHTML;
    });

    var C = { ink: '#2E2823', accent: '#B07F3B', soft: '#D9C4A0', muted: '#B8AFA3', pos: '#2D6A4F', neg: '#B23A2B', grid: '#ECE6DA', text: '#6B6157' };
    Chart.defaults.font.family = "'Hanken Grotesk', sans-serif";
    Chart.defaults.font.size = 12;
    Chart.defaults.color = C.text;
    Chart.defaults.plugins.legend.labels.boxWidth = 10;
    Chart.defaults.plugins.legend.labels.boxHeight = 10;
    Chart.defaults.maintainAspectRatio = false;
    var charts = [];
    var gridY = { grid: { color: C.grid }, border: { display: false } };
    var noGridX = { grid: { display: false }, border: { color: C.muted } };

    function build(lang) {
        charts.forEach(function (c) { c.destroy(); });
        charts = [];
        var t = L[lang];

        charts.push(new Chart(document.getElementById('c-hero'), {
            type: 'line',
            data: { labels: t.months, datasets: [{ data: [101,107,121,115,129,134,122,125,141,145], borderColor: '#D9A866', backgroundColor: 'rgba(217,168,102,.15)', fill: true, tension: .35, pointRadius: 0, borderWidth: 2 }] },
            options: { plugins: { legend: { display: false }, tooltip: { enabled: true } }, scales: { x: { ticks: { color: '#C9BFB0' }, grid: { display: false }, border: { display: false } }, y: { display: false } } }
        }));

        var wf = [1240, -410, -290, -180, 360, -70, 290], bars = [], run = 0;
        wf.forEach(function (v, i) {
            if (i === 4 || i === 6) { bars.push([0, v]); run = v; }
            else if (i === 0) { bars.push([0, v]); run = v; }
            else { bars.push([run + v, run]); run += v; }
        });
        charts.push(new Chart(document.getElementById('c-waterfall'), {
            type: 'bar',
            data: { labels: t.wf, datasets: [{ data: bars, backgroundColor: wf.map(function (v, i) { return (i === 0 || i === 4 || i === 6) ? C.ink : C.soft; }), borderRadius: 2, barPercentage: .7 }] },
            options: { plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return ' ' + Math.abs(wf[c.dataIndex]) + ' M'; } } } },
                       scales: { x: Object.assign({ ticks: { autoSkip: false, maxRotation: 40, minRotation: 0 } }, noGridX), y: gridY } }
        }));

        charts.push(new Chart(document.getElementById('c-revenue'), {
            data: { labels: t.months, datasets: [
                { type: 'bar', label: t.actual, data: [101,107,121,115,129,134,122,125,141,145], backgroundColor: C.ink, borderRadius: 2, order: 2 },
                { type: 'line', label: t.budget, data: [100,105,112,115,120,125,128,130,132,136], borderColor: C.accent, borderWidth: 2, pointRadius: 0, tension: .3, order: 1 },
                { type: 'line', label: t.prior, data: [92,96,105,103,114,118,112,115,124,128], borderColor: C.muted, borderDash: [5, 4], borderWidth: 2, pointRadius: 0, tension: .3, order: 1 }
            ] },
            options: { plugins: { legend: { position: 'bottom' } }, scales: { x: noGridX, y: gridY } }
        }));

        charts.push(new Chart(document.getElementById('c-ageing'), {
            data: { labels: t.age, datasets: [
                { type: 'bar', label: t.ar, data: [210, 95, 60, 35, 48], backgroundColor: [C.pos, C.soft, C.accent, C.neg, C.neg], borderRadius: 2, yAxisID: 'y' }
            ] },
            options: { plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return ' ' + c.parsed.y + ' M XAF'; } } } }, scales: { x: noGridX, y: gridY } }
        }));

        var weeks = [], cash = [182,165,150,131,118,96,62,88,104,121,138,150,163], floor = [];
        for (var w = 1; w <= 13; w++) { weeks.push(t.wk + w); floor.push(75); }
        charts.push(new Chart(document.getElementById('c-cash'), {
            type: 'line',
            data: { labels: weeks, datasets: [
                { label: t.cash, data: cash, borderColor: C.ink, borderWidth: 2, tension: .3,
                  pointRadius: cash.map(function (v) { return v < 75 ? 5 : 0; }), pointBackgroundColor: C.neg, pointBorderColor: C.neg },
                { label: t.floor, data: floor, borderColor: C.neg, borderDash: [6, 5], borderWidth: 1.5, pointRadius: 0 }
            ] },
            options: { plugins: { legend: { position: 'bottom' } }, scales: { x: noGridX, y: gridY } }
        }));

        charts.push(new Chart(document.getElementById('c-unit'), {
            type: 'line',
            data: { labels: t.months, datasets: [
                { label: t.rpf, data: [380,384,379,376,371,368,362,359,355,352], borderColor: C.ink, borderWidth: 2, tension: .3, pointRadius: 0 },
                { label: t.cpf, data: [262,266,271,279,284,292,299,305,313,321], borderColor: C.accent, borderWidth: 2, tension: .3, pointRadius: 0,
                  fill: '-1', backgroundColor: 'rgba(176,127,59,.10)' }
            ] },
            options: { plugins: { legend: { position: 'bottom' } }, scales: { x: noGridX, y: gridY } }
        }));

        charts.push(new Chart(document.getElementById('c-assets'), {
            type: 'bar',
            data: { labels: t.assets, datasets: [
                { label: t.nbv, data: [48, 9, 6, 4], backgroundColor: C.ink, borderRadius: 2 },
                { label: t.dep, data: [22, 41, 14, 9], backgroundColor: C.soft, borderRadius: 2 }
            ] },
            options: { indexAxis: 'y', plugins: { legend: { position: 'bottom' } },
                       scales: { x: Object.assign({ stacked: true }, gridY), y: { stacked: true, grid: { display: false }, border: { display: false } } } }
        }));
    }

    function setLang(lang) {
        if (lang !== 'fr') lang = 'en';
        document.documentElement.lang = lang;
        document.querySelectorAll('[data-i18n]').forEach(function (el) {
            var k = el.getAttribute('data-i18n');
            var v = lang === 'fr' ? FR[k] : EN[k];
            if (v !== undefined) el.innerHTML = v;
        });
        document.querySelectorAll('[data-lang]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-lang') === lang ? 'true' : 'false'); });
        document.title = lang === 'fr' ? 'BMS Business Intelligence · Elite Advisors' : 'BMS Business Intelligence · Elite Advisors';
        try { localStorage.setItem('bms_mkt_lang', lang); } catch (e) {}
        if (window.Chart) build(lang);
    }

    document.querySelectorAll('[data-lang]').forEach(function (b) {
        b.addEventListener('click', function () { setLang(b.getAttribute('data-lang')); });
    });
    var q = new URLSearchParams(location.search).get('lang'), saved = null;
    try { saved = localStorage.getItem('bms_mkt_lang'); } catch (e) {}
    setLang(q || saved || ((navigator.language || '').toLowerCase().indexOf('fr') === 0 ? 'fr' : 'en'));
})();
</script>
@endverbatim
</body>
</html>
