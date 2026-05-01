<?php

return [

    /*
    |---------------------------------------------------------------------
    | TIAO — Banque des États de l'Afrique Centrale (BEAC) policy rate
    |---------------------------------------------------------------------
    |
    | BEAC does not publish a public API. The Macro Economics module
    | does a best-effort scrape of beac.int on first hit (cached 6 h),
    | falling back to these config values when the scrape fails.
    |
    | Update via .env when the Comité de Politique Monétaire publishes a
    | new rate:
    |
    |   BEAC_TIAO_RATE=5.00         # percent
    |   BEAC_TIAO_AS_OF=2025-03-15  # YYYY-MM-DD of the announcement
    |
    */
    'tiao' => [
        'rate'  => env('BEAC_TIAO_RATE',  5.00),
        'as_of' => env('BEAC_TIAO_AS_OF', '2025-01-01'),
    ],

    /*
    |---------------------------------------------------------------------
    | TradingEconomics API
    |---------------------------------------------------------------------
    |
    | Optional. If set, the service prefers TradingEconomics over the
    | World Bank Open Data fallback. Get a key at:
    |
    |   https://tradingeconomics.com/api
    |
    */
    'tradingeconomics' => [
        'api_key' => env('TRADING_ECONOMICS_API_KEY', null),
    ],

    /*
    |---------------------------------------------------------------------
    | Statutory corporate income tax rates (national standard)
    |---------------------------------------------------------------------
    |
    | No free public API exists for statutory tax rates. KPMG and PWC
    | publish PDF tables; TradingEconomics requires a paid key. We keep
    | this table here so it's auditable and easy to update when rates
    | change.
    |
    | Each entry: ['rate' => %, 'year' => CGI ref year, 'source' => label,
    |              'note' => one-liner with article reference]
    |
    | Add new countries / update rates in this file; the Macro Economics
    | page reads them via $service->getIncomeTaxRate($country).
    |
    */
    'tax_rates' => [
        // CEMAC bloc
        'CMR' => ['rate' => 33.0, 'year' => 2025, 'source' => 'CGI Cameroun',
                  'note' => 'Cameroon — Article 17 CGI: standard 33% (incl. 10% surtax). SMEs 25%, very-small entities flat regimes apply.'],
        'TCD' => ['rate' => 35.0, 'year' => 2025, 'source' => 'CGI Tchad',
                  'note' => 'Chad — 35% standard. Petroleum sector taxed under separate regimes.'],
        'CAF' => ['rate' => 30.0, 'year' => 2025, 'source' => 'CGI RCA',
                  'note' => 'Central African Republic — 30% standard.'],
        'COG' => ['rate' => 28.0, 'year' => 2025, 'source' => 'CGI Congo',
                  'note' => 'Republic of Congo — 28% standard (recent reduction from 30%).'],
        'GNQ' => ['rate' => 35.0, 'year' => 2025, 'source' => 'CGI Guinée Équatoriale',
                  'note' => 'Equatorial Guinea — 35% standard.'],
        'GAB' => ['rate' => 30.0, 'year' => 2025, 'source' => 'CGI Gabon',
                  'note' => 'Gabon — 30% standard. Mining / petroleum specific regimes.'],
        // Key trade partners
        'NGA' => ['rate' => 30.0, 'year' => 2025, 'source' => 'CITA Nigeria',
                  'note' => 'Nigeria — 30% standard for large companies (>NGN 100m turnover); 20% medium, 0% small.'],
        'CIV' => ['rate' => 25.0, 'year' => 2025, 'source' => 'CGI Côte d\'Ivoire',
                  'note' => "Côte d'Ivoire — 25% standard (telecoms / banks 30%)."],
        'SEN' => ['rate' => 30.0, 'year' => 2025, 'source' => 'CGI Sénégal',
                  'note' => 'Senegal — 30% standard.'],
        'GHA' => ['rate' => 25.0, 'year' => 2025, 'source' => 'GRA Ghana',
                  'note' => 'Ghana — 25% standard (banks 30%, mining 35%).'],
        'ZAF' => ['rate' => 27.0, 'year' => 2025, 'source' => 'SARS South Africa',
                  'note' => 'South Africa — 27% standard (reduced from 28% in 2022).'],
        'FRA' => ['rate' => 25.0, 'year' => 2025, 'source' => 'CGI France',
                  'note' => 'France — 25% standard. SMEs 15% on first €42,500 of taxable profit.'],
        'CHN' => ['rate' => 25.0, 'year' => 2025, 'source' => 'EIT Law China',
                  'note' => 'China — 25% standard. High-tech enterprises 15%.'],
        'USA' => ['rate' => 21.0, 'year' => 2025, 'source' => 'IRC §11',
                  'note' => 'United States — 21% federal flat rate. State CIT adds 0–10% on top.'],
    ],

    /*
    |---------------------------------------------------------------------
    | Top trading partners (top 5 import sources / top 5 export destinations)
    |---------------------------------------------------------------------
    |
    | The free World Bank API doesn't expose bilateral trade flows
    | ergonomically — that lives in UN Comtrade / WITS, which requires
    | a heavier integration. For now this table is curated from each
    | country's published trade statistics (most recent full year).
    |
    | Country codes are ISO 3166-1 alpha-3 to match COUNTRIES in the
    | service. Update annually when each country publishes new
    | external-trade reports.
    |
    | Each partner row:
    |   country : ISO3 code of the partner
    |   name    : Display name
    |   pct     : % share of the host country's total imports / exports
    |   goods   : One-liner describing the dominant goods on this route —
    |             this is the "pertinent for the company" hook
    |
    */
    'top_partners' => [
        'CMR' => [
            'as_of_year' => 2023,
            'source'     => 'INS Cameroun + IMF DOTS approximations',
            'imports_from' => [
                ['country' => 'CHN', 'name' => 'China',     'pct' => 19.5, 'goods' => 'Machinery, electronics, vehicles, manufactured goods — drives container scan volume'],
                ['country' => 'FRA', 'name' => 'France',    'pct' => 9.2,  'goods' => 'Pharmaceuticals, vehicles, machinery, dairy — historic principal partner'],
                ['country' => 'NGA', 'name' => 'Nigeria',   'pct' => 6.8,  'goods' => 'Refined petroleum, plastics, food — overland and short-sea volumes'],
                ['country' => 'BEL', 'name' => 'Belgium',   'pct' => 4.7,  'goods' => 'Pharmaceuticals, machinery, chemicals — regional EU consolidation hub'],
                ['country' => 'IND', 'name' => 'India',     'pct' => 4.5,  'goods' => 'Pharmaceuticals, rice, vehicles, machinery — growing share'],
            ],
            'exports_to' => [
                ['country' => 'NLD', 'name' => 'Netherlands', 'pct' => 13.2, 'goods' => 'Cocoa, oilseeds, timber — Rotterdam re-export hub for EU'],
                ['country' => 'CHN', 'name' => 'China',       'pct' => 12.0, 'goods' => 'Logs & sawn wood, raw cotton, oilseeds'],
                ['country' => 'ITA', 'name' => 'Italy',       'pct' => 9.4,  'goods' => 'Cocoa, coffee, processed tropical products'],
                ['country' => 'ESP', 'name' => 'Spain',       'pct' => 7.8,  'goods' => 'Cocoa, bananas, fish — direct EU access'],
                ['country' => 'IND', 'name' => 'India',       'pct' => 7.5,  'goods' => 'Logs, cotton, oilseeds — manufacturing inputs'],
            ],
            'note' => 'Approximate shares; refresh annually from the INS / Customs annual report.',
        ],
        // Other CEMAC + partners — using high-level approximations until
        // each country's national trade statistics agencies are wired in.
        'TCD' => [
            'as_of_year' => 2023,
            'source'     => 'INSEED Tchad',
            'imports_from' => [
                ['country' => 'CHN', 'name' => 'China',     'pct' => 26.0, 'goods' => 'Machinery, vehicles, manufactures'],
                ['country' => 'FRA', 'name' => 'France',    'pct' => 12.0, 'goods' => 'Pharmaceuticals, vehicles, machinery'],
                ['country' => 'CMR', 'name' => 'Cameroon',  'pct' => 9.0,  'goods' => 'Refined petroleum, food (re-exports via Douala)'],
                ['country' => 'IND', 'name' => 'India',     'pct' => 6.0,  'goods' => 'Pharmaceuticals, machinery, rice'],
                ['country' => 'TUR', 'name' => 'Turkey',    'pct' => 5.0,  'goods' => 'Construction materials, processed food'],
            ],
            'exports_to' => [
                ['country' => 'CHN', 'name' => 'China',     'pct' => 35.0, 'goods' => 'Crude petroleum (out of port-scan scope)'],
                ['country' => 'USA', 'name' => 'United States', 'pct' => 18.0, 'goods' => 'Crude petroleum'],
                ['country' => 'ARE', 'name' => 'UAE',       'pct' => 9.0,  'goods' => 'Gum arabic, livestock'],
                ['country' => 'IND', 'name' => 'India',     'pct' => 7.0,  'goods' => 'Crude petroleum, cotton'],
                ['country' => 'CMR', 'name' => 'Cameroon',  'pct' => 5.0,  'goods' => 'Livestock, cotton, sesame'],
            ],
            'note' => 'Trade dominated by petroleum (out-of-scope for scanning); cotton & livestock are the scannable share.',
        ],
        'GAB' => [
            'as_of_year' => 2023,
            'source'     => 'DGS Gabon',
            'imports_from' => [
                ['country' => 'FRA', 'name' => 'France',    'pct' => 24.0, 'goods' => 'Machinery, vehicles, pharmaceuticals'],
                ['country' => 'CHN', 'name' => 'China',     'pct' => 14.0, 'goods' => 'Manufactures, machinery'],
                ['country' => 'BEL', 'name' => 'Belgium',   'pct' => 8.0,  'goods' => 'Pharmaceuticals, processed foods'],
                ['country' => 'USA', 'name' => 'United States', 'pct' => 6.0, 'goods' => 'Machinery, vehicles'],
                ['country' => 'CMR', 'name' => 'Cameroon',  'pct' => 5.0,  'goods' => 'Food, cement, building materials'],
            ],
            'exports_to' => [
                ['country' => 'CHN', 'name' => 'China',     'pct' => 47.0, 'goods' => 'Crude petroleum, manganese, timber'],
                ['country' => 'ITA', 'name' => 'Italy',     'pct' => 7.0,  'goods' => 'Crude petroleum, manganese'],
                ['country' => 'IDN', 'name' => 'Indonesia', 'pct' => 6.0,  'goods' => 'Manganese ore'],
                ['country' => 'USA', 'name' => 'United States', 'pct' => 5.0, 'goods' => 'Crude petroleum, timber'],
                ['country' => 'AUS', 'name' => 'Australia', 'pct' => 4.0,  'goods' => 'Manganese ore'],
            ],
            'note' => 'Crude-petroleum and manganese dominate; scannable trade is the smaller balance.',
        ],
        // For other countries (CAF, COG, GNQ, NGA, CIV, SEN, GHA, ZAF,
        // FRA, CHN, USA), the table is omitted — the page falls back to
        // showing "no partner data configured for this country" with a
        // pointer to extend config/macro.php. CMR is the primary tenant
        // anyway.
    ],

];
