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

];
