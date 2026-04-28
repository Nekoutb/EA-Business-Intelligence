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
            'description' => 'Volume, frequency, and per-unit revenue / discount KPIs.',
        ],
        'margin_structure' => [
            'label'       => 'Margin Structure',
            'description' => 'Profitability layers from gross profit through net.',
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
            'description' => 'YTD revenue projected to a full year: (YTD ÷ days elapsed) × 365. Real-time forecast benchmark.',
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
    ],
];
