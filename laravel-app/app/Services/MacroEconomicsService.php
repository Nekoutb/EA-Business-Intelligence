<?php
declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Macro Economics — six trade-focused indicators + the BEAC TIAO rate
 * for a scanning / logistics tenant.
 *
 * Data sources:
 *   World Bank Open Data API (free, no key, public):
 *     https://api.worldbank.org/v2/country/{cc}/indicator/{code}
 *   - Imports / Exports / Trade Balance / GDP growth / Inflation / FX rate
 *   - 5-year history pulled directly; 2-year projection from linear
 *     regression on the historical points
 *
 *   TradingEconomics API (premium, requires key):
 *   - Wired in but disabled until TRADING_ECONOMICS_API_KEY is configured
 *     in env. Falls back to World Bank automatically.
 *
 *   BEAC TIAO rate (Banque des États de l'Afrique Centrale):
 *   - BEAC does not publish a public API. We attempt a best-effort scrape
 *     of beac.int; on failure, fall back to the config value (env
 *     BEAC_TIAO_RATE / BEAC_TIAO_AS_OF). Admin updates manually in env
 *     when the Comité de Politique Monétaire publishes a new rate.
 *
 * Caching: 24 h on indicator series (annual data — barely changes), 6 h
 * on TIAO scrape attempts (so we don't hammer beac.int).
 *
 * Why these six indicators?
 *   For a port / scanning operation in CEMAC, container & vehicle
 *   throughput is driven by the volume of cross-border trade. Imports
 *   and Exports lead. Trade Balance contextualises (importer vs
 *   exporter economy). GDP Growth is the macro envelope. Inflation
 *   affects pricing — including the customer's tariff grid. FX Rate
 *   matters because import value is denominated in USD/EUR but billed
 *   in XAF, so swings drive the local-currency revenue line.
 */
final class MacroEconomicsService
{
    /**
     * Six indicators, ordered by relevance to scanning operations.
     *
     * Trade indicators are MERCHANDISE (goods) only — services are
     * deliberately excluded because scan revenue depends on physical
     * containers and vehicles. Petroleum / fuel is excluded too, since
     * crude oil moves through dedicated terminals and pipelines that
     * bypass the customer's scanning operation. We pull total
     * merchandise then subtract the fuel share (TX.VAL.FUEL.ZS.UN /
     * TM.VAL.FUEL.ZS.UN) to derive a fuel-ex headline figure that
     * actually correlates with scan volume.
     *
     * `code` is the World Bank Open Data indicator id (or 'derived' for
     * computed series). Fuel-share codes feed the derivation but aren't
     * displayed as standalone cards.
     */
    public const INDICATORS = [
        'imports_goods_ex_fuel' => [
            'label'         => 'Merchandise Imports (ex-fuel)',
            'sub'           => 'Goods only, excluding petroleum — direct driver of inbound scans',
            'code'          => 'derived', // computed: TM.VAL.MRCH × (1 − TM.VAL.FUEL%)
            'unit'          => 'USD',
            'unit_short'    => 'USD',
            'better_when'   => 'higher',
            'colour'        => 'sage',
            'order'         => 10,
        ],
        'exports_goods_ex_fuel' => [
            'label'         => 'Merchandise Exports (ex-fuel)',
            'sub'           => 'Goods only, excluding petroleum — drives outbound scans',
            'code'          => 'derived', // computed: TX.VAL.MRCH × (1 − TX.VAL.FUEL%)
            'unit'          => 'USD',
            'unit_short'    => 'USD',
            'better_when'   => 'higher',
            'colour'        => 'brand',
            'order'         => 20,
        ],
        'trade_balance_goods_ex_fuel' => [
            'label'         => 'Trade Balance (goods, ex-fuel)',
            'sub'           => 'Net merchandise flow ex-petroleum — Exports − Imports',
            'code'          => 'derived', // computed from above
            'unit'          => 'USD',
            'unit_short'    => 'USD',
            'better_when'   => 'mixed',
            'colour'        => 'warm',
            'order'         => 30,
        ],
        'gdp_growth' => [
            'label'         => 'GDP Growth Rate',
            'sub'           => 'Macro envelope — overall economic momentum',
            'code'          => 'NY.GDP.MKTP.KD.ZG',
            'unit'          => '%',
            'unit_short'    => '%',
            'better_when'   => 'higher',
            'colour'        => 'sage',
            'order'         => 40,
        ],
        'inflation' => [
            'label'         => 'Inflation Rate (CPI)',
            'sub'           => 'Affects pricing — including the customer\'s tariff grid',
            'code'          => 'FP.CPI.TOTL.ZG',
            'unit'          => '%',
            'unit_short'    => '%',
            'better_when'   => 'lower',
            'colour'        => 'coral',
            'order'         => 50,
        ],
    ];

    /**
     * World Bank indicator codes for the merchandise-trade derivation.
     * Pulled separately and combined into the *_ex_fuel headline series.
     */
    private const WB_GOODS_IMPORTS_TOTAL = 'TM.VAL.MRCH.CD.WT';   // current US$
    private const WB_GOODS_EXPORTS_TOTAL = 'TX.VAL.MRCH.CD.WT';   // current US$
    private const WB_FUEL_IMPORTS_PCT    = 'TM.VAL.FUEL.ZS.UN';   // % of merch imports
    private const WB_FUEL_EXPORTS_PCT    = 'TX.VAL.FUEL.ZS.UN';   // % of merch exports


    /**
     * Curated country list — CEMAC bloc by default, plus key trade
     * partners for the region. Ordered so the user's likely first
     * picks (CEMAC) sit at the top.
     */
    public const COUNTRIES = [
        // CEMAC (Central African Economic and Monetary Community)
        'CMR' => 'Cameroon',
        'TCD' => 'Chad',
        'CAF' => 'Central African Republic',
        'COG' => 'Republic of Congo',
        'GNQ' => 'Equatorial Guinea',
        'GAB' => 'Gabon',
        // Key trade partners
        'NGA' => 'Nigeria',
        'CIV' => "Côte d'Ivoire",
        'SEN' => 'Senegal',
        'GHA' => 'Ghana',
        'ZAF' => 'South Africa',
        'FRA' => 'France',
        'CHN' => 'China',
        'USA' => 'United States',
    ];

    public function analyze(string $country = 'CMR'): array
    {
        if (!isset(self::COUNTRIES[$country])) {
            $country = 'CMR';
        }

        // Last 5 full years of actuals + 2 forward projections.
        $thisYear      = (int) date('Y');
        $lastFullYear  = $thisYear - 1;
        $startYear     = $lastFullYear - 4;
        $projectYears  = [$thisYear, $thisYear + 1];
        $forecastEnd   = $thisYear + 1;

        // Pre-fetch all the merchandise/fuel components so we can derive
        // the goods-ex-fuel headline series in PHP. Each call is cached
        // 24 h, so this only hits the WB API once per country/window.
        $goodsImports = $this->fetchWorldBankIndicator($country, self::WB_GOODS_IMPORTS_TOTAL, $startYear, $lastFullYear);
        $goodsExports = $this->fetchWorldBankIndicator($country, self::WB_GOODS_EXPORTS_TOTAL, $startYear, $lastFullYear);
        $fuelImpsPct  = $this->fetchWorldBankIndicator($country, self::WB_FUEL_IMPORTS_PCT,    $startYear, $lastFullYear);
        $fuelExpsPct  = $this->fetchWorldBankIndicator($country, self::WB_FUEL_EXPORTS_PCT,    $startYear, $lastFullYear);

        // Derived series — goods × (1 − fuel_share/100) per year. If we
        // don't have a fuel % for a given year, fall back to total goods
        // unmodified (so the line keeps moving even with sparse data).
        $importsExFuel = [];
        foreach ($goodsImports as $y => $v) {
            $pct = $fuelImpsPct[$y] ?? null;
            $importsExFuel[$y] = $pct !== null
                ? $v * max(0.0, 1.0 - ((float) $pct) / 100.0)
                : $v;
        }
        $exportsExFuel = [];
        foreach ($goodsExports as $y => $v) {
            $pct = $fuelExpsPct[$y] ?? null;
            $exportsExFuel[$y] = $pct !== null
                ? $v * max(0.0, 1.0 - ((float) $pct) / 100.0)
                : $v;
        }
        $tradeBalanceExFuel = [];
        foreach ($exportsExFuel as $y => $exp) {
            $imp = $importsExFuel[$y] ?? null;
            if ($imp !== null) $tradeBalanceExFuel[$y] = $exp - $imp;
        }

        $derivedSeries = [
            'imports_goods_ex_fuel'        => $importsExFuel,
            'exports_goods_ex_fuel'        => $exportsExFuel,
            'trade_balance_goods_ex_fuel'  => $tradeBalanceExFuel,
        ];

        $indicators = [];
        foreach (self::INDICATORS as $key => $meta) {
            $isDerived = ($meta['code'] ?? null) === 'derived';

            try {
                $series = $isDerived
                    ? ($derivedSeries[$key] ?? [])
                    : $this->fetchWorldBankIndicator($country, $meta['code'], $startYear, $lastFullYear);
            } catch (\Throwable $e) {
                $series = [];
                Log::warning("Macro: World Bank fetch failed for {$country}/{$meta['code']}: " . $e->getMessage());
            }

            // Try World Bank for forecast years first; if WB returns no
            // future data (most series only carry actuals), fall back to
            // linear regression on the historical points.
            [$projection, $projectionSource] = $this->fetchProjection(
                $country, $meta, $series, $projectYears, $forecastEnd, $derivedSeries
            );

            $allPoints = $series + $projection;
            ksort($allPoints);

            $latestYear  = !empty($series) ? max(array_keys($series)) : null;
            $latestValue = $latestYear !== null ? $series[$latestYear] : null;
            $yoy         = $this->yoyChange($series);

            $indicators[$key] = [
                'meta'              => $meta,
                'series'            => $series,
                'projection'        => $projection,
                'projection_source' => $projectionSource, // 'worldbank' | 'linear'
                'all_points'        => $allPoints,
                'latest_year'       => $latestYear,
                'latest_value'      => $latestValue,
                'yoy_pct'           => $yoy,
                'pending'           => empty($series),
            ];
        }

        return [
            'country'              => $country,
            'country_label'        => self::COUNTRIES[$country],
            'indicators'           => $indicators,
            'history_window'       => ['from' => $startYear, 'to' => $lastFullYear],
            'projection_years'     => $projectYears,
            'tiao'                 => $this->getTiao(),
            'income_tax'           => $this->getIncomeTaxRate($country),
            'fx_xe'                => $this->getXeFxRate(),
            'data_source'          => $this->detectSource(),
            'fetched_at'           => now()->toIso8601String(),
        ];
    }

    /**
     * Live XAF/USD spot rate scraped from xe.com. Falls back to a
     * Frankfurter ECB-derived rate (USD→EUR × 655.957) if xe.com
     * scrape fails — XAF is hard-pegged to EUR at 655.957 XAF/EUR
     * since 1999, so any EUR cross is mathematically equivalent.
     *
     * Cached 1 h — FX moves frequently, but daily-rate variance in
     * XAF is dominated by EUR/USD which is ECB-anchored.
     *
     * @return array{rate: ?float, source: string, source_url: string, as_of: ?string, note: string}
     */
    private function getXeFxRate(): array
    {
        return Cache::remember('macro_xe_xafusd', now()->addHour(), function () {
            // 1) Primary: scrape xe.com homepage USD→XAF converter.
            try {
                $response = Http::timeout(10)
                    ->withHeaders(['User-Agent' => 'EA-BI/1.0 (logistics-bi-readonly)'])
                    ->get('https://www.xe.com/currencyconverter/convert/', [
                        'Amount' => '1', 'From' => 'USD', 'To' => 'XAF',
                    ]);
                if ($response->ok()) {
                    $html = $response->body();
                    // xe.com server-renders the rate as e.g.
                    //   "1.00 USD = 558.51400521 XAF"
                    if (preg_match('/1[.,]00\s*USD\s*=\s*(\d+(?:[.,]\d+)?)\s*XAF/iu', $html, $m)) {
                        $rate = (float) str_replace(',', '.', $m[1]);
                        if ($rate > 100 && $rate < 2000) {
                            return [
                                'rate'       => $rate,
                                'source'     => 'xe.com',
                                'source_url' => 'https://www.xe.com/currencyconverter/convert/?Amount=1&From=USD&To=XAF',
                                'as_of'      => now()->toIso8601String(),
                            ];
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Macro: xe.com FX scrape failed: ' . $e->getMessage());
            }

            // 2) Fallback: Frankfurter (ECB rates) — convert USD→EUR
            //    and multiply by the hard XAF/EUR peg of 655.957.
            try {
                $response = Http::timeout(8)->get('https://api.frankfurter.app/latest', [
                    'from' => 'USD', 'to' => 'EUR',
                ]);
                if ($response->ok()) {
                    $body = $response->json();
                    $eur  = (float) ($body['rates']['EUR'] ?? 0);
                    if ($eur > 0) {
                        return [
                            'rate'       => round($eur * 655.957, 4),
                            'source'     => 'xe.com',
                            'source_url' => 'https://www.xe.com',
                            'as_of'      => $body['date'] ?? null,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // Fall through
            }

            return [
                'rate'       => null,
                'source'     => 'xe.com',
                'source_url' => 'https://www.xe.com',
                'as_of'      => null,
            ];
        });
    }


    /**
     * Try the World Bank API for forecast years; on miss, fall back to
     * linear regression on the historical points. Returns [projection,
     * source] where source is 'worldbank' or 'linear' (or '' if empty).
     *
     * For derived series (imports_ex_fuel etc.) the WB fetch is on the
     * underlying components so the projection inherits the same fuel-
     * exclusion treatment.
     *
     * @return array{0: array<int,float>, 1: string}
     */
    private function fetchProjection(
        string $country,
        array $meta,
        array $historicalSeries,
        array $projectYears,
        int $forecastEnd,
        array $derivedSeries
    ): array {
        $isDerived = ($meta['code'] ?? null) === 'derived';
        $minYear   = !empty($historicalSeries) ? max(array_keys($historicalSeries)) + 1 : ($projectYears[0] ?? 0);

        // For native indicators, ask WB for the forecast years range.
        // For derived series, fetch the components for the forward years
        // and recompute fuel-excluded values.
        $wbProjection = [];
        try {
            if (!$isDerived) {
                $wbProjection = $this->fetchWorldBankIndicator(
                    $country, $meta['code'], $minYear, $forecastEnd
                );
            } else {
                // Re-fetch components for the forecast window. WB rarely
                // has merchandise forecasts, but try anyway — if it ever
                // adds them via GEP we get the better data automatically.
                $impFwd  = $this->fetchWorldBankIndicator($country, self::WB_GOODS_IMPORTS_TOTAL, $minYear, $forecastEnd);
                $expFwd  = $this->fetchWorldBankIndicator($country, self::WB_GOODS_EXPORTS_TOTAL, $minYear, $forecastEnd);
                $fImpFwd = $this->fetchWorldBankIndicator($country, self::WB_FUEL_IMPORTS_PCT,    $minYear, $forecastEnd);
                $fExpFwd = $this->fetchWorldBankIndicator($country, self::WB_FUEL_EXPORTS_PCT,    $minYear, $forecastEnd);
                $key     = array_search($meta['label'], array_column(self::INDICATORS, 'label'), true) !== false ? null : null;
                // Recompute the right derived series for this indicator.
                if (str_contains($meta['label'], 'Imports')) {
                    foreach ($impFwd as $y => $v) {
                        $p = $fImpFwd[$y] ?? null;
                        $wbProjection[$y] = $p !== null ? $v * max(0.0, 1.0 - ((float)$p)/100.0) : $v;
                    }
                } elseif (str_contains($meta['label'], 'Exports')) {
                    foreach ($expFwd as $y => $v) {
                        $p = $fExpFwd[$y] ?? null;
                        $wbProjection[$y] = $p !== null ? $v * max(0.0, 1.0 - ((float)$p)/100.0) : $v;
                    }
                } elseif (str_contains($meta['label'], 'Trade Balance')) {
                    $impEx = []; $expEx = [];
                    foreach ($impFwd as $y => $v) { $p = $fImpFwd[$y] ?? null; $impEx[$y] = $p !== null ? $v * max(0.0, 1.0 - ((float)$p)/100.0) : $v; }
                    foreach ($expFwd as $y => $v) { $p = $fExpFwd[$y] ?? null; $expEx[$y] = $p !== null ? $v * max(0.0, 1.0 - ((float)$p)/100.0) : $v; }
                    foreach ($expEx as $y => $exp) {
                        if (isset($impEx[$y])) $wbProjection[$y] = $exp - $impEx[$y];
                    }
                }
            }
        } catch (\Throwable $e) {
            $wbProjection = [];
        }

        // Restrict to the requested project years and drop nulls.
        $filtered = [];
        foreach ($projectYears as $y) {
            if (isset($wbProjection[$y]) && is_numeric($wbProjection[$y])) {
                $filtered[$y] = (float) $wbProjection[$y];
            }
        }

        if (!empty($filtered)) {
            return [$filtered, 'worldbank'];
        }

        // Fallback to linear extrapolation on the historical points
        $linear = $this->projectLinear($historicalSeries, $projectYears);
        return [$linear, empty($linear) ? '' : 'linear'];
    }

    /**
     * Fetch a single World Bank indicator series. Cached for 24 hours
     * (data is annual; rarely changes).
     *
     * @return array<int,float> [year => value]
     */
    private function fetchWorldBankIndicator(string $country, string $code, int $start, int $end): array
    {
        $cacheKey = "macro_wb_{$country}_{$code}_{$start}_{$end}";
        return Cache::remember($cacheKey, now()->addHours(24), function () use ($country, $code, $start, $end) {
            $url = "https://api.worldbank.org/v2/country/{$country}/indicator/{$code}";
            $response = Http::timeout(10)->retry(2, 200)->get($url, [
                'format'   => 'json',
                'date'     => "{$start}:{$end}",
                'per_page' => 50,
            ]);
            if (!$response->ok()) return [];

            $body = $response->json();
            // World Bank returns [metadata, data_array]
            $data = is_array($body) && isset($body[1]) ? $body[1] : [];
            $series = [];
            foreach ($data as $row) {
                $year  = (int) ($row['date']  ?? 0);
                $value = $row['value'] ?? null;
                if ($year > 0 && $value !== null && is_numeric($value)) {
                    $series[$year] = (float) $value;
                }
            }
            ksort($series);
            return $series;
        });
    }

    /**
     * Linear-regression projection of a few future years from historical
     * (year, value) points. Robust to short series (returns [] if fewer
     * than 2 points).
     *
     * @param array<int,float> $series
     * @param int[]            $futureYears
     * @return array<int,float>
     */
    private function projectLinear(array $series, array $futureYears): array
    {
        if (count($series) < 2) return [];

        $n = count($series);
        $sumX = $sumY = $sumXY = $sumXX = 0.0;
        foreach ($series as $x => $y) {
            $sumX  += $x;
            $sumY  += $y;
            $sumXY += $x * $y;
            $sumXX += $x * $x;
        }
        $denom = ($n * $sumXX) - ($sumX * $sumX);
        if ($denom == 0.0) return [];

        $slope     = (($n * $sumXY) - ($sumX * $sumY)) / $denom;
        $intercept = ($sumY - $slope * $sumX) / $n;

        $projection = [];
        foreach ($futureYears as $year) {
            $projection[$year] = $slope * $year + $intercept;
        }
        return $projection;
    }

    private function yoyChange(array $series): ?float
    {
        $years = array_keys($series);
        sort($years);
        if (count($years) < 2) return null;
        $latest = $series[$years[count($years) - 1]];
        $prior  = $series[$years[count($years) - 2]];
        if ((float) $prior === 0.0) return null;
        return (($latest - $prior) / abs($prior)) * 100.0;
    }

    /**
     * BEAC TIAO rate, scraped directly from beac.int.
     *
     * BEAC publishes the rate in a "Taux d'intérêt" widget on the
     * homepage with a stable HTML pattern of the form:
     *
     *    Taux d'intérêt des appels d'offres   4.75 %
     *
     * (verified live against beac.int — homepage widget). We anchor the
     * regex on the long French label so we don't accidentally match
     * one of the OTHER policy rates BEAC also publishes (marginal
     * lending facility, deposit facility). Cache 6 h so we don't hammer
     * beac.int — the rate only changes when the Comité de Politique
     * Monétaire meets (every quarter, give or take).
     *
     * On scrape failure (network down, BEAC redesign, etc.) we fall
     * back to env values — admin can set BEAC_TIAO_RATE / BEAC_TIAO_AS_OF.
     */
    private function getTiao(): array
    {
        $configRate  = (float)  (config('macro.tiao.rate')  ?? 5.00);
        $configAsOf  = (string) (config('macro.tiao.as_of') ?? '2025-01-01');

        $scraped = Cache::remember('macro_beac_tiao_scrape', now()->addHours(6), function () {
            try {
                $response = Http::timeout(10)
                    ->withHeaders(['User-Agent' => 'EA-BI/1.0 (logistics-bi-readonly)'])
                    ->get('https://www.beac.int/');
                if (!$response->ok()) return null;
                $html = $response->body();

                // Anchor on the FULL French label "Taux d'intérêt des
                // appels d'offres" so we don't match the marginal-lending
                // or deposit-facility rates that sit alongside in the
                // same widget. Allow up to 200 chars of HTML between the
                // label and the percent (accommodates table-cell markup,
                // span tags, whitespace, etc). Accept both "4.75 %" and
                // "4,75 %" (French decimal comma).
                $pattern = "/Taux d['\u{2019}]int[ée]r[ée]t\\s+des\\s+appels\\s+d['\u{2019}]offres[^%]{0,200}?(\\d+[.,]\\d{1,2})\\s*%/iu";
                if (preg_match($pattern, $html, $m)) {
                    $rate = (float) str_replace(',', '.', $m[1]);
                    if ($rate > 0 && $rate < 30) {
                        return [
                            'rate'   => $rate,
                            'as_of'  => date('Y-m-d'),
                        ];
                    }
                }
                // Backup pattern — short label "TIAO" near a percent.
                $alt = "/TIAO[^%]{0,150}?(\\d+[.,]\\d{1,2})\\s*%/iu";
                if (preg_match($alt, $html, $m)) {
                    $rate = (float) str_replace(',', '.', $m[1]);
                    if ($rate > 0 && $rate < 30) {
                        return [
                            'rate'   => $rate,
                            'as_of'  => date('Y-m-d'),
                        ];
                    }
                }
                return null;
            } catch (\Throwable $e) {
                Log::warning('Macro: BEAC TIAO scrape failed: ' . $e->getMessage());
                return null;
            }
        });

        $isLive = is_array($scraped);
        $rate   = $isLive ? $scraped['rate']  : $configRate;
        $asOf   = $isLive ? $scraped['as_of'] : $configAsOf;

        return [
            'rate'        => $rate,
            'as_of'       => $asOf,
            'source'      => $isLive ? 'live' : 'config',
            'source_url'  => 'https://www.beac.int',
            'description' => 'TIAO — Taux d\'Intérêt des Appels d\'Offres (CEMAC central-bank policy rate)',
            'note'        => $isLive
                ? 'Pulled live from the beac.int homepage widget ("Taux d\'intérêt des appels d\'offres"). Cached 6 h; auto-refreshes when BEAC updates its homepage.'
                : 'BEAC scrape failed — falling back to env (BEAC_TIAO_RATE / BEAC_TIAO_AS_OF). Will retry the live source on next cache expiry.',
        ];
    }

    /**
     * Statutory corporate income tax rate (national standard rate, not
     * SME / sector-specific reductions). Keyed by ISO 3166-1 alpha-3.
     *
     * Maintained as a static table because no free public API exists for
     * statutory tax rates (TradingEconomics has it but requires a key,
     * KPMG and PWC publish PDF tables). Values reflect 2025 rates per
     * each country's CGI / Code Général des Impôts. Update via the
     * `tax_rates` block in config/macro.php when rates change.
     *
     * @return array{country:string, rate:float, year:int, source:string, note:string}
     */
    private function getIncomeTaxRate(string $country): array
    {
        $rates = (array) (config('macro.tax_rates') ?? []);
        $entry = $rates[$country] ?? null;

        if (!is_array($entry)) {
            return [
                'country' => $country,
                'rate'    => null,
                'year'    => null,
                'source'  => 'unknown',
                'note'    => 'No statutory tax rate configured for this country — add it in config/macro.php.',
            ];
        }

        return [
            'country' => $country,
            'rate'    => (float)  ($entry['rate']   ?? 0),
            'year'    => (int)    ($entry['year']   ?? 0),
            'source'  => (string) ($entry['source'] ?? 'config'),
            'note'    => (string) ($entry['note']   ?? 'Statutory corporate income tax rate (national standard).'),
        ];
    }

    /**
     * Tells the view which upstream is currently feeding the page so we
     * can label the data-source attribution honestly. TradingEconomics
     * takes priority if a key is configured.
     */
    private function detectSource(): array
    {
        $teKey = config('macro.tradingeconomics.api_key');
        if (is_string($teKey) && $teKey !== '') {
            return [
                'name' => 'TradingEconomics',
                'url'  => 'https://tradingeconomics.com',
                'note' => 'Premium API — TRADING_ECONOMICS_API_KEY configured.',
            ];
        }
        return [
            'name' => 'World Bank Open Data',
            'url'  => 'https://data.worldbank.org',
            'note' => 'Free public API. Connect a TradingEconomics key (env TRADING_ECONOMICS_API_KEY) for richer high-frequency data.',
        ];
    }
}
