<?php
/**
 * Registry of every KPI card shown on the Business Intelligence Dashboard.
 *
 * Single source of truth for:
 *   - the admin toggle UI (/admin/tenants/{id} → Dashboard KPIs panel)
 *   - the tenant Dashboard view (decides whether to render each card)
 *   - the seeder/backfill that pre-populates tenant_dashboard_kpis rows
 *
 * Adding a new KPI:
 *   1. Append an entry below with a unique `key` slug.
 *   2. Wrap its <x-kpi> card in dashboard.blade.php with
 *        @if ($dashboardKpiEnabled('your-key')) ... @endif
 *   3. (No migration needed — the helper falls back to `default` if no
 *      tenant_dashboard_kpis row exists.)
 *
 * Fields:
 *   - key          : stable slug stored in tenant_dashboard_kpis.kpi_key
 *   - label        : human-readable name shown in the admin toggle UI
 *   - description  : short blurb describing what the KPI measures
 *   - section      : groups KPIs in the admin UI (matches dashboard sections)
 *   - default      : whether NEW tenants get the KPI enabled by default
 */
return [
    'sections' => [
        'revenue_profitability' => [
            'label'       => 'Revenue & Profitability',
            'description' => 'Volume, frequency, and per-unit revenue / discount KPIs. These toggles affect both the Dashboard and the Revenue Analysis page — the same KPI shown on both pages obeys a single switch.',
        ],
        'margin_structure' => [
            'label'       => 'Margin Structure',
            'description' => 'Profitability layers from gross profit through net.',
        ],
        'revenue_tables' => [
            'label'       => 'Revenue · Tables',
            'description' => 'Optional product-level tables on the Revenue Analysis page. Hide for tenants where product breakdowns aren\'t relevant.',
        ],
        'cash_position' => [
            'label'       => 'Cash & AR',
            'description' => 'Headline cash & receivables cells that appear in the dashboard strip. Toggle off for tenants where cash position isn\'t the primary lens.',
        ],
        'dashboard_sections' => [
            'label'       => 'Dashboard sections',
            'description' => 'Whole panels of the Dashboard. Switch off the ones that do not apply to a tenant\'s business.',
        ],
        'efficiency' => [
            'label'       => 'Efficiency KPI strip',
            'description' => 'Unit-economics cells in the dashboard KPI strip and KPI Targets & Trends panel. Per-scan for scanning operations; per-file for clearing / forwarding (files = distinct invoice references on class 70, field set by the KPI_FILE_REF_FIELD tenant setting, default move_name).',
        ],
        'logistics' => [
            'label'       => 'Logistics · Dashboard',
            'description' => 'Summary cards from the Logistics Operations pages, shown at the top of the Dashboard. Off by default; switched on for logistics-sector tenants.',
        ],
    ],

    'kpis' => [
        // ── Revenue & Profitability ──────────────────────────────────────
        [
            'key'         => 'revenue_ytd',
            'label'       => 'Revenue YTD',
            'description' => 'Total revenue year-to-date with YoY growth and vs-plan variance.',
            'section'     => 'revenue_profitability',
            'default'     => true,
        ],
        [
            'key'         => 'rpd',
            'label'       => 'Revenue per Day (RpD)',
            'description' => 'Revenue ÷ working days in the period.',
            'section'     => 'revenue_profitability',
            'default'     => true,
        ],
        [
            'key'         => 'rpo',
            'label'       => 'Revenue per Outfit (RpO)',
            'description' => 'Revenue ÷ outfits sold.',
            'section'     => 'revenue_profitability',
            'default'     => true,
        ],
        [
            'key'         => 'opd',
            'label'       => 'Outfits per Day (OpD)',
            'description' => 'Outfits ÷ working days.',
            'section'     => 'revenue_profitability',
            'default'     => true,
        ],
        [
            'key'         => 'rpc',
            'label'       => 'Revenue per Customer (RpC)',
            'description' => 'Revenue ÷ unique customers in the period.',
            'section'     => 'revenue_profitability',
            'default'     => true,
        ],
        [
            'key'         => 'dpot',
            'label'       => 'Discount per Outfit (DpOT)',
            'description' => 'Discounts granted ÷ outfits sold.',
            'section'     => 'revenue_profitability',
            'default'     => true,
        ],
        [
            'key'         => 'dpc',
            'label'       => 'Discount per Customer (DpC)',
            'description' => 'Discounts granted ÷ customers.',
            'section'     => 'revenue_profitability',
            'default'     => true,
        ],
        [
            'key'         => 'cpo',
            'label'       => 'Cost per Outfit (CpO)',
            'description' => 'Cost of goods sold ÷ outfits.',
            'section'     => 'revenue_profitability',
            'default'     => true,
        ],
        [
            'key'         => 'run_rate',
            'label'       => 'Annualised Run-Rate',
            'description' => 'YTD revenue projected to a full year on a working-day basis: (YTD ÷ working days YTD) × annual working days. Same denominator as RpD.',
            'section'     => 'revenue_profitability',
            'default'     => true,
        ],

        // ── Margin Structure ──────────────────────────────────────────────
        [
            'key'         => 'gross_profit',
            'label'       => 'Gross Profit',
            'description' => 'Revenue − COGS, with gross margin %.',
            'section'     => 'margin_structure',
            'default'     => true,
        ],
        [
            'key'         => 'ebitda',
            'label'       => 'EBITDA',
            'description' => 'Earnings before interest, tax, D&A, with EBITDA margin %.',
            'section'     => 'margin_structure',
            'default'     => true,
        ],
        [
            'key'         => 'net_profit',
            'label'       => 'Net Profit',
            'description' => 'Bottom-line profit with net margin %.',
            'section'     => 'margin_structure',
            'default'     => true,
        ],
        [
            'key'         => 'opex',
            'label'       => 'OpEx',
            'description' => 'Operating expenses (class 6 except direct costs and D&A).',
            'section'     => 'margin_structure',
            'default'     => true,
        ],

        // ── Revenue · Tables ─────────────────────────────────────────────
        [
            'key'         => 'top_profitable_products',
            'label'       => 'Top 5 Profitable Products',
            'description' => 'Side-by-side tables showing the most and least profitable products by margin (sales price vs standard cost). Disable for tenants whose business model isn\'t product-driven (e.g. pure services).',
            'section'     => 'revenue_tables',
            'default'     => true,
        ],

        // ── Cash & AR ─────────────────────────────────────────────────────
        [
            'key'         => 'cash_collected',
            'label'       => 'Cash Collected',
            'description' => 'Cash collected to date with % of billings collected. Lives in the dashboard headline strip alongside Revenue/Profit cells.',
            'section'     => 'cash_position',
            'default'     => true,
        ],
        [
            'key'         => 'ar_outstanding',
            'label'       => 'AR Outstanding',
            'description' => 'Accounts receivable balance with current DSO. Lives in the dashboard headline strip.',
            'section'     => 'cash_position',
            'default'     => true,
        ],

        [
            'key'         => 'revenue_cost_ratios',
            'label'       => 'Cost & Discount Ratios (Revenue page)',
            'description' => 'CpO / RpO, DpOT / RpO, DpC / RpC vs target on the Revenue Analysis page. Off for businesses without a per-unit cost/discount model.',
            'section'     => 'revenue_tables',
            'default'     => true,
        ],
        [
            'key'         => 'revenue_streams',
            'label'       => 'Revenue streams by nature (Revenue page)',
            'description' => 'One stream per class-7 account: trend since the start of the year, stream per file, and the Revenue Composition donut by account instead of by product. Files = distinct invoice references on class 70 (KPI_FILE_REF_FIELD, default move_name).',
            'section'     => 'revenue_tables',
            'default'     => false,
        ],

        // ── Dashboard sections ──────────────────────────────────────────
        [
            'key'         => 'cost_ratio_card',
            'label'       => 'Cost Ratio & Discount card',
            'description' => 'The CPO · DPOT · DPC vs target card (also needs at least one of those KPIs on). Off for businesses without a per-unit cost/discount model.',
            'section'     => 'dashboard_sections',
            'default'     => true,
        ],
        [
            'key'         => 'kpi_targets_trends',
            'label'       => 'KPI Targets & Trends panel',
            'description' => 'Budgeted targets per efficiency KPI with the monthly actual plotted against them. Off for tenants without a live Odoo P&L feed.',
            'section'     => 'dashboard_sections',
            'default'     => true,
        ],

        // ── Efficiency KPI strip ────────────────────────────────────────
        [
            'key'         => 'kpi_rev_per_scan',
            'label'       => 'Revenue / scan',
            'description' => 'Revenue ÷ scans (containers + vehicles + wood), monthly.',
            'section'     => 'efficiency',
            'default'     => true,
        ],
        [
            'key'         => 'kpi_cost_per_scan',
            'label'       => 'Cost / scan',
            'description' => 'Operating cost (revenue − EBIT) ÷ scans, monthly.',
            'section'     => 'efficiency',
            'default'     => true,
        ],
        [
            'key'         => 'kpi_rev_per_file',
            'label'       => 'Revenue / file',
            'description' => 'Class-7 revenue ÷ files, cumulative year to date.',
            'section'     => 'efficiency',
            'default'     => false,
        ],
        [
            'key'         => 'kpi_cost_per_file',
            'label'       => 'Cost / file',
            'description' => 'Class-6 cost ÷ files, cumulative year to date.',
            'section'     => 'efficiency',
            'default'     => false,
        ],

        // ── Logistics · Dashboard ───────────────────────────────────────
        [
            'key'         => 'logi_file_summary',
            'label'       => 'Logistics file summary',
            'description' => 'The four headline tiles of the Files page: open files as of period end, opened, closed, median backlog age.',
            'section'     => 'logistics',
            'default'     => false,
        ],
        [
            'key'         => 'logi_closure_rate',
            'label'       => 'Closure rate',
            'description' => 'Closed ÷ (open at start + opened), closed vs opened, closures per working day, median days to close.',
            'section'     => 'logistics',
            'default'     => false,
        ],
        [
            'key'         => 'logi_oop_summary',
            'label'       => 'OOP compliance summary',
            'description' => 'Débours incurred vs recharged to clients in the period, the gap, and how many files over-, under- or exactly recharged.',
            'section'     => 'logistics',
            'default'     => false,
        ],
        [
            'key'         => 'logi_unbilled_debours',
            'label'       => 'Unbilled débours',
            'description' => 'Débours disbursed in the period with no matching débours line on the file\'s invoices: value, number of transactions, files and clients.',
            'section'     => 'logistics',
            'default'     => false,
        ],
        [
            'key'         => 'logi_volume_trend',
            'label'       => 'Logistics volume trend',
            'description' => 'Line graphs of tonnage, containers and packages across the period (per month for yearly views, per day for monthly ones).',
            'section'     => 'logistics',
            'default'     => false,
        ],    ],
];
