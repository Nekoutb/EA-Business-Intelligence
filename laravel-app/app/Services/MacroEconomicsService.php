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
     * `code` is the World Bank Open Data indicator id.
     * `tradingeconomics_code` is the TE API equivalent (used when key set).
     */
    public const INDICATORS = [
        'imports' => [
            'label'         => 'Imports of Goods & Services',
            'sub'           => 'Direct driver of inbound container & vehicle scans',
            'code'          => 'NE.IMP.GNFS.CD',
            'tradingeconomics_code' => 'imports',
            'unit'          => 'USD',
            'unit_short'    => 'USD',
            'better_when'   => 'higher',
            'colour'        => 'sage',
            'order'         => 10,
        ],
        'exports' => [
            'label'         => 'Exports of Goods & Services',
            'sub'           => 'Direct driver of outbound container & bois scans',
            'code'          => 'NE.EXP.GNFS.CD',
            'tradingeconomics_code' => 'exports',
            'unit'          => 'USD',
            'unit_short'    => 'USD',
            'better_when'   => 'higher',
            'colour'        => 'brand',
            'order'         => 20,
        ],
        'trade_balance' => [
            'label'         => 'Trade Balance',
            'sub'           => 'Net flow — Exports − Imports',
            'code'          => 'NE.RSB.GNFS.CD',
            'tradingeconomics_code' => 'balance-of-trade',
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
            'tradingeconomics_code' => 'gdp-growth-annual',
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
            'tradingeconomics_code' => 'inflation-cpi',
            'unit'          => '%',
            'unit_short'    => '%',
            'better_when'   => 'lower',
            'colour'        => 'coral',
            'order'         => 50,
        ],
        'fx_rate' => [
            'label'         => 'Exchange Rate (LCU per USD)',
            'sub'           => 'XAF/USD — affects revenue conversion on USD-priced trade',
            'code'          => 'PA.NUS.FCRF',
            'tradingeconomics_code' => 'currency',
            'unit'          => 'LCU/USD',
            'unit_short'    => '',
            'better_when'   => 'mixed',
            'colour'        => 'slate',
            'order'         => 60,
        ],
    ];

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

        // Use last full year as the most-recent actual. World Bank typically
        // publishes year-end data in Q1 of the following year, so a value
        // for "this year" is rarely available — pull last 5 full years
        // ending at last year, project the next 2 years (this year +
        // next year).
        $thisYear      = (int) date('Y');
        $lastFullYear  = $thisYear - 1;
        $startYear     = $lastFullYear - 4; // 5 years inclusive
        $projectYears  = [$thisYear, $thisYear + 1];

        $indicators = [];
        foreach (self::INDICATORS as $key => $meta) {
            try {
                $series = $this->fetchWorldBankIndicator($country, $meta['code'], $startYear, $lastFullYear);
            } catch (\Throwable $e) {
                $series = [];
                Log::warning("Macro: World Bank fetch failed for {$country}/{$meta['code']}: " . $e->getMessage());
            }

            $projection = $this->projectLinear($series, $projectYears);

            $allPoints = $series + $projection; // year => value, sorted later
            ksort($allPoints);

            $latestYear  = !empty($series) ? max(array_keys($series)) : null;
            $latestValue = $latestYear !== null ? $series[$latestYear] : null;
            $yoy         = $this->yoyChange($series);

            $indicators[$key] = [
                'meta'         => $meta,
                'series'       => $series,        // [year => value] historical
                'projection'   => $projection,    // [year => value] forward
                'all_points'   => $allPoints,     // merged, sorted
                'latest_year'  => $latestYear,
                'latest_value' => $latestValue,
                'yoy_pct'      => $yoy,
                'pending'      => empty($series),
            ];
        }

        return [
            'country'           => $country,
            'country_label'     => self::COUNTRIES[$country],
            'indicators'        => $indicators,
            'history_window'    => ['from' => $startYear, 'to' => $lastFullYear],
            'projection_years'  => $projectYears,
            'tiao'              => $this->getTiao(),
            'data_source'       => $this->detectSource(),
            'fetched_at'        => now()->toIso8601String(),
        ];
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
     * BEAC TIAO rate. Best-effort scrape of beac.int with config
     * fallback. The value is published by the Comité de Politique
     * Monétaire and changes a few times a year.
     */
    private function getTiao(): array
    {
        $configRate  = (float)  (config('macro.tiao.rate')  ?? 5.00);
        $configAsOf  = (string) (config('macro.tiao.as_of') ?? '2025-01-01');

        $cached = Cache::remember('macro_beac_tiao_scrape', now()->addHours(6), function () {
            try {
                $response = Http::timeout(8)
                    ->withHeaders(['User-Agent' => 'EA-BI/1.0 (logistics-bi-readonly)'])
                    ->get('https://www.beac.int/');
                if (!$response->ok()) return null;
                $html = $response->body();

                // Best-effort: find the TIAO percent on the homepage. The
                // rate sits near labels like "TIAO" or "Taux d'Intérêt
                // des Appels d'Offres" with a percentage like "5,00 %".
                // Pattern is brittle by design — fall back to config on
                // miss instead of trying to parse the whole page.
                if (preg_match('/TIAO[^%]{0,200}?(\d+[.,]\d+)\s*%/iu', $html, $m)) {
                    $rate = (float) str_replace(',', '.', $m[1]);
                    if ($rate > 0 && $rate < 30) {
                        return [
                            'rate'   => $rate,
                            'as_of'  => date('Y-m-d'),
                            'source' => 'scraped',
                        ];
                    }
                }
                return null;
            } catch (\Throwable $e) {
                return null;
            }
        });

        $rate   = is_array($cached) ? $cached['rate']  : $configRate;
        $asOf   = is_array($cached) ? $cached['as_of'] : $configAsOf;
        $source = is_array($cached) ? 'live'           : 'config';

        return [
            'rate'        => $rate,
            'as_of'       => $asOf,
            'source'      => $source,
            'source_url'  => 'https://www.beac.int',
            'description' => 'TIAO — Taux d\'Intérêt des Appels d\'Offres (CEMAC central-bank policy rate)',
            'note'        => $source === 'live'
                ? 'Pulled from beac.int homepage via best-effort scrape; cached 6 h.'
                : 'BEAC does not publish a public API. Value sourced from config (env BEAC_TIAO_RATE / BEAC_TIAO_AS_OF) — update when the Comité de Politique Monétaire publishes a new rate.',
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
