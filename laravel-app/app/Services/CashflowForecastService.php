<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Cashflow Forecast — DIRECT method.
 *
 * Built from actual cash movements (Class 5 — banques + caisse) by
 * inspecting the OTHER side of each cash move and bucketing the cash
 * impact according to what the cash was for:
 *
 *   Operating CF
 *     + Cash receipts from customers      (counter-account class 41)
 *     − Cash paid to suppliers            (class 40)
 *     − Cash paid to employees / payroll  (class 64, 42)
 *     − Cash paid for taxes (excl. CIT)   (class 43, 44)
 *     − Cash paid for income tax           (class 89, 695)
 *     ± Cash from / for other operating   (other class 6 / 7)
 *
 *   Investing CF
 *     − Capex (class 2)                   counter-account class 2
 *     ± Disposals / proceeds              also class 2
 *
 *   Financing CF
 *     ± Debt drawdown / repayment         class 16-19
 *     ± Equity injection / dividends      class 10
 *
 *   Net Change in Cash = Operating + Investing + Financing
 *   Ending Cash trajectory = cumulative from year-start opening balance
 *
 * Algorithm:
 *   1. Pull all account.move.line on Class 5 accounts in the year
 *   2. For each, find the move's other (non-cash) lines
 *   3. Bucket the cash impact (signed: debit = inflow, credit = outflow)
 *      by the dominant counter-account class
 *
 * Forecast for future months: SMLY (same month last year) for each
 * bucket independently, with trailing-3 fallback when prior year is
 * empty.
 *
 * V1 simplification: when a move has multiple non-cash lines (e.g. a
 * payment that splits across customers + a fee account), we attribute
 * 100% of the cash impact to the LARGEST-magnitude counter-line. For
 * the vast majority of cash movements this is exactly right; multi-
 * customer payments are the rare case where the categorisation is
 * approximate.
 */
final class CashflowForecastService
{
    /**
     * Counter-account class prefixes → bucket key. Order matters
     * (longest match wins) so 60..69 don't all collapse into "6".
     */
    private const BUCKETS = [
        'customers'       => ['41'],
        'suppliers'       => ['40'],
        'employees'       => ['42', '64'],
        'taxes_other'     => ['43', '44'],
        'income_tax'      => ['89'],
        'other_operating' => ['60', '61', '62', '63', '65', '66', '67', '68', '70', '71', '72', '73', '74', '75', '76', '77', '78'],
        'capex'           => ['2'],
        'financing'       => ['1'],
    ];

    /** Mapping of bucket → display row spec. */
    private const BUCKET_DISPLAY = [
        'customers'       => ['label' => '+ Cash receipts from customers',       'colour' => 'sage',  'group' => 'operating'],
        'other_operating' => ['label' => '± Other operating cash flows',         'colour' => 'sage',  'group' => 'operating'],
        'suppliers'       => ['label' => '− Cash paid to suppliers',             'colour' => 'coral', 'group' => 'operating'],
        'employees'       => ['label' => '− Cash paid to employees',             'colour' => 'coral', 'group' => 'operating'],
        'taxes_other'     => ['label' => '− Cash paid for taxes (excl. CIT)',    'colour' => 'coral', 'group' => 'operating'],
        'income_tax'      => ['label' => '− Cash paid for income tax',           'colour' => 'coral', 'group' => 'operating'],
        'capex'           => ['label' => '± Investing — Capex / disposals',      'colour' => 'warm',  'group' => 'investing'],
        'financing'       => ['label' => '± Financing — debt / equity changes',  'colour' => 'warm',  'group' => 'financing'],
    ];

    private OdooClient $odoo;

    public function __construct(OdooClient $odoo)
    {
        $this->odoo = $odoo;
    }

    public function analyze(?int $year = null): array
    {
        $today = new \DateTimeImmutable('today');
        $year  = $year ?? (int) $today->format('Y');

        $thruMonth = $year < (int) $today->format('Y')
            ? 12
            : ($year > (int) $today->format('Y') ? 0 : max(0, ((int) $today->format('n')) - 1));

        // Build the bucket → monthly vector matrix for the year and PY.
        $cyBuckets = $this->fetchBucketedCashflow($year);
        $pyBuckets = $this->fetchBucketedCashflow($year - 1);

        // Forecast: for each bucket, fill future months with SMLY → trailing-3.
        $bucketForecasted = [];
        foreach (array_keys(self::BUCKETS) as $key) {
            $cy = $cyBuckets[$key] ?? array_fill(1, 12, 0.0);
            $py = $pyBuckets[$key] ?? array_fill(1, 12, 0.0);
            $bucketForecasted[$key] = $this->fillForecast($cy, $py, $thruMonth);
        }

        // Build the rendered rows: leaf buckets + subtotals + cumulative ending cash.
        $opCf  = array_fill(1, 12, 0.0);
        $invCf = array_fill(1, 12, 0.0);
        $finCf = array_fill(1, 12, 0.0);

        $rows = [];
        // Operating section (sub-rows + subtotal)
        foreach (['customers', 'suppliers', 'employees', 'taxes_other', 'income_tax', 'other_operating'] as $key) {
            $disp = self::BUCKET_DISPLAY[$key];
            $rows[] = [
                'key' => $key, 'label' => $disp['label'], 'kind' => 'leaf', 'colour' => $disp['colour'],
                'monthly' => $bucketForecasted[$key],
            ];
            for ($m = 1; $m <= 12; $m++) $opCf[$m] += $bucketForecasted[$key][$m] ?? 0.0;
        }
        $rows[] = ['key' => 'op_cf', 'label' => 'Operating Cash Flow', 'kind' => 'subtotal', 'colour' => 'sage', 'monthly' => $opCf];

        // Investing
        foreach (['capex'] as $key) {
            $disp = self::BUCKET_DISPLAY[$key];
            $rows[] = [
                'key' => $key, 'label' => $disp['label'], 'kind' => 'leaf', 'colour' => $disp['colour'],
                'monthly' => $bucketForecasted[$key],
            ];
            for ($m = 1; $m <= 12; $m++) $invCf[$m] += $bucketForecasted[$key][$m] ?? 0.0;
        }
        $rows[] = ['key' => 'inv_cf', 'label' => 'Investing Cash Flow', 'kind' => 'subtotal', 'colour' => 'brand', 'monthly' => $invCf];

        // Financing
        foreach (['financing'] as $key) {
            $disp = self::BUCKET_DISPLAY[$key];
            $rows[] = [
                'key' => $key, 'label' => $disp['label'], 'kind' => 'leaf', 'colour' => $disp['colour'],
                'monthly' => $bucketForecasted[$key],
            ];
            for ($m = 1; $m <= 12; $m++) $finCf[$m] += $bucketForecasted[$key][$m] ?? 0.0;
        }
        $rows[] = ['key' => 'fin_cf', 'label' => 'Financing Cash Flow', 'kind' => 'subtotal', 'colour' => 'warm', 'monthly' => $finCf];

        // Net Change & Ending Cash
        $netChange = array_fill(1, 12, 0.0);
        for ($m = 1; $m <= 12; $m++) $netChange[$m] = $opCf[$m] + $invCf[$m] + $finCf[$m];

        $openingCash = $this->fetchClass5BalanceAt(new \DateTimeImmutable($year . '-01-01'));
        $endingCash = array_fill(1, 12, 0.0);
        $running = $openingCash;
        for ($m = 1; $m <= 12; $m++) {
            $running += $netChange[$m];
            $endingCash[$m] = $running;
        }
        $rows[] = ['key' => 'net_change',  'label' => 'Net Change in Cash',   'kind' => 'subtotal', 'colour' => 'brand', 'monthly' => $netChange];
        $rows[] = ['key' => 'ending_cash', 'label' => 'Ending Cash Position', 'kind' => 'subtotal', 'colour' => 'sage',  'monthly' => $endingCash];

        return [
            'currency'     => 'XAF',
            'year'         => $year,
            'thru_month'   => $thruMonth,
            'months'       => $this->buildMonthsHeader($year, $thruMonth),
            'lines'        => $rows,
            'opening_cash' => $openingCash,
            'method'       => 'direct',
            'fetched_at'   => now()->toIso8601String(),
        ];
    }

    /**
     * For a given year, walk every cash-account (Class 5) move and
     * categorise its cash impact by the dominant counter-account class.
     * Returns bucket_key → [month1..12 => signed XAF amount].
     *
     * Sign: debit on Class 5 = cash inflow → positive
     *       credit on Class 5 = cash outflow → negative
     *
     * @return array<string, array<int, float>>
     */
    private function fetchBucketedCashflow(int $year): array
    {
        $bucketKeys = array_keys(self::BUCKETS);
        $out = [];
        foreach ($bucketKeys as $k) $out[$k] = array_fill(1, 12, 0.0);

        try {
            // 1. Load Class 5 (cash) account ids
            $cashAccts = $this->odoo->searchRead('account.account',
                [['code', '=like', '5%']], ['id', 'code'], []);
            if (empty($cashAccts)) return $out;
            $cashAccountIds = array_column($cashAccts, 'id');

            // 2. Pull every cash move-line in year
            $cashLines = $this->odoo->searchRead('account.move.line',
                [
                    ['parent_state', '=', 'posted'],
                    ['date', '>=', $year . '-01-01'],
                    ['date', '<=', $year . '-12-31'],
                    ['account_id', 'in', $cashAccountIds],
                ],
                ['id', 'move_id', 'date', 'debit', 'credit'],
                ['limit' => 100000]
            );
            if (empty($cashLines)) return $out;

            // 3. Collect distinct move ids, then bulk-fetch ALL lines for
            //    those moves so we can identify counter-accounts.
            $moveIds = array_values(array_unique(array_map(
                fn ($l) => is_array($l['move_id']) ? (int) $l['move_id'][0] : 0,
                $cashLines
            )));
            $moveIds = array_values(array_filter($moveIds, fn ($i) => $i > 0));

            $allMoveLines = $this->odoo->searchRead('account.move.line',
                [
                    ['move_id', 'in', $moveIds],
                    ['parent_state', '=', 'posted'],
                ],
                ['id', 'move_id', 'account_id', 'debit', 'credit'],
                ['limit' => 500000]
            );

            // 4. Build account.id → code map for bucket lookup
            $allAccountIds = array_values(array_unique(array_map(
                fn ($l) => is_array($l['account_id']) ? (int) $l['account_id'][0] : 0,
                $allMoveLines
            )));
            $accounts = $this->odoo->searchRead('account.account',
                [['id', 'in', $allAccountIds]], ['id', 'code'], []);
            $codeByAcct = [];
            foreach ($accounts as $a) $codeByAcct[(int) $a['id']] = (string) ($a['code'] ?? '');

            // 5. Group all move lines by move_id
            $linesByMove = [];
            foreach ($allMoveLines as $l) {
                $mid = is_array($l['move_id']) ? (int) $l['move_id'][0] : 0;
                if ($mid === 0) continue;
                $linesByMove[$mid][] = $l;
            }

            // 6. For each cash line, find the dominant counter-account
            //    bucket and accumulate its (debit - credit) into that bucket.
            foreach ($cashLines as $cashLine) {
                $mid = is_array($cashLine['move_id']) ? (int) $cashLine['move_id'][0] : 0;
                if ($mid === 0) continue;
                $cashImpact = (float) ($cashLine['debit'] ?? 0) - (float) ($cashLine['credit'] ?? 0);
                if ($cashImpact === 0.0) continue;

                $month = $this->parseDateMonth((string) ($cashLine['date'] ?? ''));
                if ($month < 1 || $month > 12) continue;

                // Find dominant counter-line: largest-magnitude non-cash line
                $counterBucket = $this->dominantCounterBucket(
                    $linesByMove[$mid] ?? [], $cashAccountIds, $codeByAcct
                );
                if ($counterBucket === null) continue;

                $out[$counterBucket][$month] += $cashImpact;
            }
        } catch (\Throwable $e) {
            // GL not reachable — return zeros
        }

        return $out;
    }

    private function dominantCounterBucket(array $moveLines, array $cashAccountIds, array $codeByAcct): ?string
    {
        $bestBucket = null;
        $bestAbs    = -1.0;
        foreach ($moveLines as $l) {
            $aid = is_array($l['account_id']) ? (int) $l['account_id'][0] : 0;
            if ($aid === 0 || in_array($aid, $cashAccountIds, true)) continue; // skip cash leg
            $code = $codeByAcct[$aid] ?? '';
            if ($code === '') continue;
            $bucket = $this->codeToBucket($code);
            if ($bucket === null) continue;
            $mag = abs((float) ($l['debit'] ?? 0)) + abs((float) ($l['credit'] ?? 0));
            if ($mag > $bestAbs) {
                $bestAbs = $mag;
                $bestBucket = $bucket;
            }
        }
        return $bestBucket;
    }

    private function codeToBucket(string $code): ?string
    {
        // Longest-prefix match wins to disambiguate e.g. 89 from generic 8/6.
        $best = null;
        $bestLen = 0;
        foreach (self::BUCKETS as $bucket => $prefixes) {
            foreach ($prefixes as $p) {
                if (str_starts_with($code, $p) && strlen($p) > $bestLen) {
                    $best = $bucket;
                    $bestLen = strlen($p);
                }
            }
        }
        return $best;
    }

    /** Cash + bank balance at a specific date (Class 5 sum). */
    private function fetchClass5BalanceAt(\DateTimeImmutable $asOf): float
    {
        try {
            $accts = $this->odoo->searchRead('account.account',
                [['code', '=like', '5%']], ['id'], []);
            if (empty($accts)) return 0.0;
            $ids = array_column($accts, 'id');
            $rows = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain' => [
                    ['parent_state', '=', 'posted'],
                    ['date', '<=', $asOf->format('Y-m-d')],
                    ['account_id', 'in', $ids],
                ],
                'fields'  => ['balance:sum'],
                'groupby' => [],
                'lazy'    => false,
            ]);
            return (float) ($rows[0]['balance'] ?? 0);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /**
     * SMLY forecast: future months take same-month-last-year value.
     * Trailing-3 fallback when last year is empty for that month.
     */
    private function fillForecast(array $cy, array $py, int $thruMonth): array
    {
        $out = $cy;
        $tail = [];
        for ($m = max(1, $thruMonth - 2); $m <= $thruMonth; $m++) $tail[] = (float) ($cy[$m] ?? 0);
        $tailMean = empty($tail) ? 0.0 : array_sum($tail) / count($tail);
        for ($m = $thruMonth + 1; $m <= 12; $m++) {
            $smly = (float) ($py[$m] ?? 0);
            $out[$m] = $smly !== 0.0 ? $smly : $tailMean;
        }
        return $out;
    }

    private function parseDateMonth(string $date): int
    {
        if ($date === '' || strlen($date) < 7) return 0;
        return (int) substr($date, 5, 2);
    }

    private function buildMonthsHeader(int $year, int $thruMonth): array
    {
        $names = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $out[$m] = ['num' => $m, 'label' => $names[$m - 1], 'is_forecast' => $m > $thruMonth];
        }
        return $out;
    }
}
