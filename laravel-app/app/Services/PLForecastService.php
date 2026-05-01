<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * P&L Forecast — full-year monthly P&L with actuals on closed months
 * and a forecast on remaining months. Each subtotal line is drillable
 * to its constituent accounts.
 *
 * Classification (SYSCOHADA, code-prefix based — works on Odoo 14..19+
 * the same way ProfitLossService does):
 *
 *   Revenue              -Σ class 7 (excl. 79 reversals & 77 financial revenue)
 *   Direct op costs       Σ class 60 (goods) OR class 61+62 (service business)
 *   Operating expenses    Σ remaining class 6 (excl. direct, 67, 68, 69)
 *   D&A                   Σ class 68
 *   Net financial result  -Σ class 77   −   Σ class 67    (revenue minus expense)
 *   Income tax            Σ class 89    (impôts sur le résultat)
 *
 *   Gross Profit  = Revenue − Direct op costs
 *   EBITDA        = Gross Profit − OpEx
 *   EBIT          = EBITDA − D&A
 *   PBT           = EBIT + Net financial result
 *   Net Result    = PBT − Income tax
 *
 * Business-type detection: same inventory-balance heuristic as
 * WorkingCapitalService — if inventory ≤ 1,000 XAF, treat as service
 * and use 61+62 as the COGS basis instead of 60.
 *
 * Forecasting (for future months in the selected year):
 *   - Primary: SMLY (same month last year). Captures seasonality.
 *   - Fallback: trailing 3-month mean of current-year actuals.
 *   - The forecast is computed line-by-line at the *account* level,
 *     so drill-down detail is forecast too.
 *
 * Sign convention: revenue and revenue-like lines (Gross Profit, EBITDA,
 * EBIT, PBT, Net Result) are positive when profitable. Cost lines
 * (Direct op costs, OpEx, D&A, Income tax) are stored as negative
 * numbers in the result so the row totals add up directly without
 * needing to subtract.
 */
final class PLForecastService
{
    /** Class 7 except 79 (reversals) and 77 (financial revenue, broken out separately). */
    private const REVENUE_INCLUDE = ['7'];
    private const REVENUE_EXCLUDE = ['77', '79'];

    /** Goods-business direct cost (Achats). */
    private const DIRECT_GOODS = ['60'];

    /** Service-business direct cost (sub-contracting + transport — the
     *  "cost of services rendered"). 60 is also included for any small
     *  consumables purchases that show up. */
    private const DIRECT_SERVICE = ['60', '61', '62'];

    /** Class 6 except direct costs, financials (67), D&A (68), HAO (69). */
    private const OPEX_INCLUDE = ['6'];
    private const OPEX_EXCLUDE_FOR_GOODS   = ['60', '67', '68', '69'];
    private const OPEX_EXCLUDE_FOR_SERVICE = ['60', '61', '62', '67', '68', '69'];

    private const DA_INCLUDE        = ['68'];
    private const FIN_EXPENSE       = ['67'];
    private const FIN_REVENUE       = ['77'];
    private const INCOME_TAX        = ['89'];

    /** Inventory threshold for service-business detection. */
    private const INVENTORY_ZERO_THRESHOLD = 1_000.0;

    private OdooClient $odoo;

    public function __construct(OdooClient $odoo)
    {
        $this->odoo = $odoo;
    }

    /**
     * @param int      $year         Fiscal year (default: current calendar year)
     * @param ?int     $thruMonth    Last actual month (1-12). Default: today's month - 1.
     */
    public function analyze(?int $year = null, ?int $thruMonth = null): array
    {
        $today  = new \DateTimeImmutable('today');
        $year   = $year   ?? (int) $today->format('Y');
        // Last full month with actuals = previous month relative to today
        // when we're in the current year; full-year actuals for past years.
        if ($thruMonth === null) {
            if ($year < (int) $today->format('Y')) {
                $thruMonth = 12;
            } elseif ($year > (int) $today->format('Y')) {
                $thruMonth = 0;
            } else {
                // current year: previous month, clamp 0..12
                $thruMonth = max(0, ((int) $today->format('n')) - 1);
            }
        }
        $thruMonth = max(0, min(12, $thruMonth));

        // 1) Detect business type (service vs goods) from inventory balance
        $isService = $this->isServiceBusiness($year);

        // 2) Pull the chart of accounts in scope (class 6 + 7 + 89)
        $accounts = $this->fetchAccountsInScope();
        if (empty($accounts)) return $this->emptyResult($year, $thruMonth, $isService);

        // 3) Bucket each account into one of the leaf P&L lines
        $bucketByAccount = $this->bucketAccounts($accounts, $isService);
        if (empty($bucketByAccount)) return $this->emptyResult($year, $thruMonth, $isService);

        // 4) Fetch monthly balances for every account in scope, this year
        //    and last (last year is needed for SMLY forecasts)
        $accountIds = array_keys($bucketByAccount);
        $monthly    = $this->fetchMonthlyBalances($accountIds, $year);
        $monthlyPy  = $this->fetchMonthlyBalances($accountIds, $year - 1);

        // 5) Build per-account year vector (12 months); replace future
        //    months with forecast values
        $accountVectors = [];
        foreach ($accountIds as $aid) {
            $bucket   = $bucketByAccount[$aid];
            $cyMonths = $monthly[$aid]   ?? array_fill(1, 12, 0.0);
            $pyMonths = $monthlyPy[$aid] ?? array_fill(1, 12, 0.0);
            $vector   = $this->fillForecast($cyMonths, $pyMonths, $thruMonth, $bucket['sign']);
            $accountVectors[$aid] = [
                'code'    => $bucket['code'],
                'name'    => $bucket['name'],
                'line'    => $bucket['line'],
                'sign'    => $bucket['sign'],
                'monthly' => $vector,
            ];
        }

        // 6) Aggregate accounts into the P&L lines, then compute the
        //    derived (subtotal / margin) lines.
        $linesData = $this->buildLines($accountVectors, $isService, $thruMonth);

        return [
            'currency'      => 'XAF',
            'year'          => $year,
            'thru_month'    => $thruMonth,
            'is_service'    => $isService,
            'cogs_basis'    => $isService ? 'Class 60+61+62' : 'Class 60',
            'months'        => $this->buildMonthsHeader($year, $thruMonth),
            'lines'         => $linesData,
            'fetched_at'    => now()->toIso8601String(),
        ];
    }

    /* ── classification ───────────────────────────────────────────── */

    private function isServiceBusiness(int $year): bool
    {
        try {
            // Inventory balance as of end-of-year (or today, whichever is earlier).
            $today   = new \DateTimeImmutable('today');
            $asOf    = (int) $today->format('Y') === $year
                ? $today
                : new \DateTimeImmutable($year . '-12-31');
            $invAccts = $this->odoo->searchRead('account.account',
                [['code', '=like', '3%']], ['id', 'code'], []);
            if (empty($invAccts)) return true; // no inventory accounts at all → service
            $ids = array_column($invAccts, 'id');
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
            $bal = (float) ($rows[0]['balance'] ?? 0);
            return $bal <= self::INVENTORY_ZERO_THRESHOLD;
        } catch (\Throwable $e) {
            return true; // safer default for the customer at hand
        }
    }

    /**
     * Returns id => ['code', 'name', 'line', 'sign']
     *   line ∈ {revenue, direct_costs, opex, depreciation, fin_revenue,
     *           fin_expense, income_tax}
     *   sign multiplier applied at storage so result vectors add up
     *   naturally.
     */
    private function bucketAccounts(array $accounts, bool $isService): array
    {
        $result = [];
        foreach ($accounts as $a) {
            $aid  = (int)    ($a['id']   ?? 0);
            $code = (string) ($a['code'] ?? '');
            $name = (string) ($a['name'] ?? '');
            if ($aid === 0 || $code === '') continue;

            // Revenue (class 7 except 77, 79) — credit-normal, flip sign
            if ($this->prefMatch($code, self::REVENUE_INCLUDE, self::REVENUE_EXCLUDE)) {
                $result[$aid] = ['code' => $code, 'name' => $name, 'line' => 'revenue',     'sign' => -1.0];
                continue;
            }
            // Financial revenue (77) — credit-normal, flip sign
            if ($this->prefMatch($code, self::FIN_REVENUE, [])) {
                $result[$aid] = ['code' => $code, 'name' => $name, 'line' => 'fin_revenue', 'sign' => -1.0];
                continue;
            }
            // Income tax (89) — debit-normal, store as NEGATIVE so it
            //                   subtracts from PBT to give Net Result.
            if ($this->prefMatch($code, self::INCOME_TAX, [])) {
                $result[$aid] = ['code' => $code, 'name' => $name, 'line' => 'income_tax', 'sign' => -1.0];
                continue;
            }
            // D&A (68) — debit-normal, negative for the P&L
            if ($this->prefMatch($code, self::DA_INCLUDE, [])) {
                $result[$aid] = ['code' => $code, 'name' => $name, 'line' => 'depreciation', 'sign' => -1.0];
                continue;
            }
            // Financial expense (67) — debit-normal, negative for the P&L
            if ($this->prefMatch($code, self::FIN_EXPENSE, [])) {
                $result[$aid] = ['code' => $code, 'name' => $name, 'line' => 'fin_expense', 'sign' => -1.0];
                continue;
            }
            // Direct operating costs (longest match wins)
            $directInclude = $isService ? self::DIRECT_SERVICE : self::DIRECT_GOODS;
            if ($this->prefMatch($code, $directInclude, [])) {
                $result[$aid] = ['code' => $code, 'name' => $name, 'line' => 'direct_costs', 'sign' => -1.0];
                continue;
            }
            // OpEx — class 6 minus the more specific buckets above
            $opexExclude = $isService ? self::OPEX_EXCLUDE_FOR_SERVICE : self::OPEX_EXCLUDE_FOR_GOODS;
            if ($this->prefMatch($code, self::OPEX_INCLUDE, $opexExclude)) {
                $result[$aid] = ['code' => $code, 'name' => $name, 'line' => 'opex', 'sign' => -1.0];
                continue;
            }
            // Anything else — unclassified, drop
        }
        return $result;
    }

    private function prefMatch(string $code, array $include, array $exclude): bool
    {
        foreach ($exclude as $p) if (str_starts_with($code, $p)) return false;
        foreach ($include as $p) if (str_starts_with($code, $p)) return true;
        return false;
    }

    /* ── data fetch ───────────────────────────────────────────────── */

    private function fetchAccountsInScope(): array
    {
        return $this->odoo->searchRead('account.account',
            [
                '|', '|',
                ['code', '=like', '6%'],
                ['code', '=like', '7%'],
                ['code', '=like', '89%'],
            ],
            ['id', 'code', 'name'],
            []
        );
    }

    /**
     * Pull monthly balance sums per account for the given calendar year.
     * Single read_group with groupby = [account_id, date:month].
     *
     * @return array<int, array<int, float>> account_id → [month1..12 => balance]
     */
    private function fetchMonthlyBalances(array $accountIds, int $year): array
    {
        if (empty($accountIds)) return [];
        try {
            $rows = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain'  => [
                    ['parent_state', '=', 'posted'],
                    ['date', '>=', $year . '-01-01'],
                    ['date', '<=', $year . '-12-31'],
                    ['account_id', 'in', $accountIds],
                ],
                'fields'  => ['account_id', 'balance:sum'],
                'groupby' => ['account_id', 'date:month'],
                'lazy'    => false,
            ]);
        } catch (\Throwable $e) {
            return [];
        }

        $byAccount = [];
        foreach ($rows as $r) {
            if (!isset($r['account_id']) || !is_array($r['account_id'])) continue;
            $aid    = (int) $r['account_id'][0];
            $bal    = (float) ($r['balance'] ?? 0);
            // 'date:month' returns labels like "January 2026"; parse the month.
            $label  = (string) ($r['date:month'] ?? '');
            $month  = $this->parseMonthLabel($label, $year);
            if ($month < 1 || $month > 12) continue;
            $byAccount[$aid] ??= array_fill(1, 12, 0.0);
            $byAccount[$aid][$month] += $bal;
        }
        return $byAccount;
    }

    /**
     * Odoo's read_group returns date:month as e.g. "January 2026".
     * Convert to month number 1..12.
     */
    private function parseMonthLabel(string $label, int $year): int
    {
        if ($label === '') return 0;
        $months = [
            'January'=>1,'February'=>2,'March'=>3,'April'=>4,'May'=>5,'June'=>6,
            'July'=>7,'August'=>8,'September'=>9,'October'=>10,'November'=>11,'December'=>12,
            // French fallbacks just in case
            'janvier'=>1,'février'=>2,'mars'=>3,'avril'=>4,'mai'=>5,'juin'=>6,
            'juillet'=>7,'août'=>8,'septembre'=>9,'octobre'=>10,'novembre'=>11,'décembre'=>12,
        ];
        foreach ($months as $name => $m) {
            if (stripos($label, $name) !== false) return $m;
        }
        return 0;
    }

    /* ── forecasting ──────────────────────────────────────────────── */

    /**
     * Apply the sign multiplier to actual months and synthesize
     * forecast values for months > thruMonth.
     *
     * Forecast logic per future month:
     *   1) Same Month Last Year (SMLY) when prior-year data exists
     *   2) Trailing 3-month mean of this year's actuals
     *   3) Zero
     *
     * @return array<int, float>  month1..12 => signed value
     */
    private function fillForecast(array $cy, array $py, int $thruMonth, float $sign): array
    {
        $out = [];
        // Months 1..thruMonth = actuals
        for ($m = 1; $m <= $thruMonth; $m++) {
            $out[$m] = ((float) ($cy[$m] ?? 0)) * $sign;
        }
        // Compute trailing-3-mean fallback once from actuals
        $tail = [];
        for ($m = max(1, $thruMonth - 2); $m <= $thruMonth; $m++) {
            $tail[] = (float) ($cy[$m] ?? 0);
        }
        $tailMean = empty($tail) ? 0.0 : array_sum($tail) / count($tail);

        // Months thruMonth+1..12 = forecast
        for ($m = $thruMonth + 1; $m <= 12; $m++) {
            $smly = isset($py[$m]) ? (float) $py[$m] : null;
            $val  = ($smly !== null && $smly !== 0.0) ? $smly : $tailMean;
            $out[$m] = $val * $sign;
        }
        return $out;
    }

    /* ── line aggregation ────────────────────────────────────────── */

    private function buildLines(array $accountVectors, bool $isService, int $thruMonth): array
    {
        // 1) Aggregate per-line totals
        $byLine = [
            'revenue' => [], 'direct_costs' => [], 'opex' => [],
            'depreciation' => [], 'fin_revenue' => [], 'fin_expense' => [], 'income_tax' => [],
        ];
        foreach ($accountVectors as $av) {
            $byLine[$av['line']][] = $av;
        }

        $sumLine = function (array $accts): array {
            $sum = array_fill(1, 12, 0.0);
            foreach ($accts as $a) {
                foreach ($a['monthly'] as $m => $v) $sum[$m] += $v;
            }
            return $sum;
        };

        $rev   = $sumLine($byLine['revenue']);
        $dc    = $sumLine($byLine['direct_costs']);
        $opex  = $sumLine($byLine['opex']);
        $da    = $sumLine($byLine['depreciation']);
        $finR  = $sumLine($byLine['fin_revenue']);
        $finE  = $sumLine($byLine['fin_expense']);
        $tax   = $sumLine($byLine['income_tax']);

        $gp     = $this->vecAdd($rev, $dc);                 // direct_costs already negative
        $ebitda = $this->vecAdd($gp, $opex);
        $ebit   = $this->vecAdd($ebitda, $da);
        $netFin = $this->vecAdd($finR, $finE);              // fin_expense already negative
        $pbt    = $this->vecAdd($ebit, $netFin);
        $net    = $this->vecAdd($pbt, $tax);                // tax already negative

        $marginPct = function (array $num, array $den): array {
            $out = array_fill(1, 12, null);
            for ($m = 1; $m <= 12; $m++) {
                if (abs($den[$m]) > 0.001) $out[$m] = 100.0 * $num[$m] / $den[$m];
            }
            return $out;
        };

        // Build the ordered list of P&L rows the view will render.
        // 'kind' tells the view how to render (subtotal / margin /
        // expandable leaf line) and what colour to use.
        $rows = [];

        $rows[] = $this->row('revenue',       'Revenue',                   $rev,   'leaf',     'sage',  $byLine['revenue']);
        $rows[] = $this->row('direct_costs',  'Direct operating costs',    $dc,    'leaf',     'coral', $byLine['direct_costs']);
        $rows[] = $this->row('gross_profit',  'Gross Profit',              $gp,    'subtotal', 'sage');
        $rows[] = $this->row('gross_margin',  'Gross Margin %',            $marginPct($gp, $rev),     'margin', 'slate');

        $rows[] = $this->row('opex',          'Operating expenses',        $opex,  'leaf',     'coral', $byLine['opex']);
        $rows[] = $this->row('ebitda',        'EBITDA',                    $ebitda,'subtotal', 'brand');
        $rows[] = $this->row('ebitda_margin', 'EBITDA Margin %',           $marginPct($ebitda, $rev), 'margin', 'slate');

        $rows[] = $this->row('depreciation',  'Depreciation & amortisation', $da, 'leaf',     'coral', $byLine['depreciation']);
        $rows[] = $this->row('ebit',          'EBIT',                      $ebit,  'subtotal', 'brand');
        $rows[] = $this->row('ebit_margin',   'EBIT Margin %',             $marginPct($ebit, $rev),   'margin', 'slate');

        $rows[] = $this->row('net_financial', 'Net financial result',      $netFin,'leaf',     'warm',  array_merge($byLine['fin_revenue'], $byLine['fin_expense']));
        $rows[] = $this->row('pbt',           'Profit Before Tax',         $pbt,   'subtotal', 'brand');

        $rows[] = $this->row('income_tax',    'Income tax',                $tax,   'leaf',     'coral', $byLine['income_tax']);
        $rows[] = $this->row('net_result',    'Net Result',                $net,   'subtotal', 'sage');
        $rows[] = $this->row('net_margin',    'Net Margin %',              $marginPct($net, $rev),    'margin', 'slate');

        return $rows;
    }

    private function vecAdd(array $a, array $b): array
    {
        $out = array_fill(1, 12, 0.0);
        for ($m = 1; $m <= 12; $m++) $out[$m] = ($a[$m] ?? 0) + ($b[$m] ?? 0);
        return $out;
    }

    /**
     * Pack a row for the view. For 'leaf' rows, also flatten the
     * underlying account vectors for drill-down.
     */
    private function row(string $key, string $label, array $monthly, string $kind, string $colour, array $accounts = []): array
    {
        $total = array_sum($monthly);
        $accountRows = [];
        foreach ($accounts as $a) {
            $accountRows[] = [
                'code'    => $a['code'],
                'name'    => $a['name'],
                'monthly' => $a['monthly'],
                'total'   => array_sum($a['monthly']),
            ];
        }
        // Sort accounts by absolute total desc — biggest contributors first
        usort($accountRows, fn ($x, $y) => abs($y['total']) <=> abs($x['total']));

        return [
            'key'      => $key,
            'label'    => $label,
            'monthly'  => $monthly,
            'total'    => $total,
            'kind'     => $kind,    // leaf | subtotal | margin
            'colour'   => $colour,
            'accounts' => $accountRows,
        ];
    }

    private function buildMonthsHeader(int $year, int $thruMonth): array
    {
        $names = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $out[$m] = [
                'num'         => $m,
                'label'       => $names[$m - 1],
                'is_forecast' => $m > $thruMonth,
            ];
        }
        return $out;
    }

    private function emptyResult(int $year, int $thruMonth, bool $isService): array
    {
        return [
            'currency'   => 'XAF',
            'year'       => $year,
            'thru_month' => $thruMonth,
            'is_service' => $isService,
            'cogs_basis' => $isService ? 'Class 60+61+62' : 'Class 60',
            'months'     => $this->buildMonthsHeader($year, $thruMonth),
            'lines'      => [],
            'fetched_at' => now()->toIso8601String(),
            'error'      => 'No accounts in scope.',
        ];
    }
}
