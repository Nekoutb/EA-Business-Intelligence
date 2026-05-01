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

];
