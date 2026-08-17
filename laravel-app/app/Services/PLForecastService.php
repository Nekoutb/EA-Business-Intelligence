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
 *
 * View modes:
 *   - 'full_year'  (default) — 12-month grid + year total + budget total
 *   - 'mtd'        — single month: PY | CY | Forecast | Budget + variances
 *   - 'ytd'        — Jan..closed-month: PY | CY | Forecast (full year) |
 *                                       Budget (full year) + variances
 *
 * Budget definition: in V1 we don't pull from Odoo's `account.budget`
 * (which the customer may or may not maintain). Instead we use a
 * configurable `pl_budget_growth_factor` applied to prior-year actuals.
 * Defaults to 1.0 (Budget = PY) — the most conservative neutral baseline
 * for "did we hold the line?" reviews.
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
    public function analyze(?int $year = null, ?int $thruMonth = null, ?array $assumptions = null): array
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

        // 5b) Assumption-driven forecast. Rescales the SMLY base in the
        //     FUTURE months so each P&L line lands on a target derived
        //     from the saved/forecast assumptions, while preserving each
        //     account's seasonal shape + sign (drill-down still ties out).
        //     FAIL-SAFE: any error leaves the SMLY base untouched, so the
        //     page renders exactly as before instead of erroring.
        $effectiveAssumptions = null;
        $assumptionDefaults   = null;
        try {
            [$effectiveAssumptions, $assumptionDefaults] = $this->applyAssumptionForecast(
                $accountVectors, $monthlyPy, $bucketByAccount, $thruMonth, $assumptions
            );
        } catch (\Throwable $e) {
            // Keep the statistical (SMLY) forecast on any failure.
        }

        // 6) Aggregate accounts into the P&L lines, then compute the
        //    derived (subtotal / margin) lines.
        $linesData = $this->buildLines($accountVectors, $isService, $thruMonth);

        return [
            'currency'             => 'XAF',
            'year'                 => $year,
            'thru_month'           => $thruMonth,
            'is_service'           => $isService,
            'cogs_basis'           => $isService ? 'Class 60+61+62' : 'Class 60',
            'months'               => $this->buildMonthsHeader($year, $thruMonth),
            'lines'                => $linesData,
            'assumptions'          => $effectiveAssumptions,
            'assumption_defaults'  => $assumptionDefaults,
            'assumptions_driven'   => $effectiveAssumptions !== null,
            'fetched_at'           => now()->toIso8601String(),
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

    /**
     * Warehouse GL usable for this tenant, or null to use Odoo.
     * Shares bi.warehouse_gl with GLAccountService and WorkingCapitalService:
     * all three read wh_fact_gl_line, gated together at 0.00.
     */
    private function whTenantId(): ?int
    {
        if (!config('bi.warehouse_gl', false)) return null;
        try {
            $id = (int) (optional(tenant())->id ?? 0);
            if ($id <= 0) return null;
            return \Illuminate\Support\Facades\DB::table('wh_fact_gl_line')
                ->where('tenant_id', $id)->limit(1)->exists() ? $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function fetchAccountsInScope(): array
    {
        $whT = $this->whTenantId();
        if ($whT !== null) {
            try {
                $rows = \Illuminate\Support\Facades\DB::table('wh_dim_account')
                    ->where('tenant_id', $whT)
                    ->where(function ($q) {
                        foreach (['6', '7', '89'] as $p) $q->orWhere('code', 'like', $p . '%');
                    })
                    ->orderBy('code')
                    ->get(['odoo_id', 'code', 'name'])
                    ->map(fn ($r) => [
                        'id'   => (int) $r->odoo_id,
                        'code' => (string) $r->code,
                        'name' => (string) ($r->name ?? ''),
                    ])->all();
                if ($rows !== []) return $rows;
            } catch (\Throwable $e) { /* fall through to Odoo */ }
        }

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

        // P3 — same aggregation locally. This returns the finished
        // account => [1..12] map, so the warehouse branch builds that directly
        // instead of emitting read_group rows. That also sidesteps the month
        // LABEL parsing the Odoo path needs: Odoo returns 'date:month' as
        // "January 2026", localised, and a French-locale server would return
        // "janvier 2026" for the same data. MONTH() returns an integer.
        $whT = $this->whTenantId();
        if ($whT !== null) {
            try {
                $agg = \Illuminate\Support\Facades\DB::table('wh_fact_gl_line')
                    ->where('tenant_id', $whT)
                    ->where('parent_state', 'posted')
                    ->whereNotNull('gl_date')
                    ->whereBetween('gl_date', [$year . '-01-01', $year . '-12-31'])
                    ->whereIn('account_odoo_id', $accountIds)
                    ->selectRaw('account_odoo_id AS aid, MONTH(gl_date) AS m,
                                 SUM(debit) - SUM(credit) AS bal')
                    ->groupBy('account_odoo_id', \Illuminate\Support\Facades\DB::raw('MONTH(gl_date)'))
                    ->get();
                if ($agg->isNotEmpty()) {
                    $byAccount = [];
                    foreach ($agg as $r) {
                        $m = (int) $r->m;
                        if ($m < 1 || $m > 12) continue;
                        $aid = (int) $r->aid;
                        $byAccount[$aid] ??= array_fill(1, 12, 0.0);
                        $byAccount[$aid][$m] += (float) $r->bal;
                    }
                    return $byAccount;
                }
            } catch (\Throwable $e) { /* fall through to Odoo */ }
        }

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

    /* ── assumption-driven forecast ───────────────────────────────── */

    /**
     * Rescale the FUTURE months (thru+1..12) of the SMLY base vectors so
     * each P&L line hits a target derived from the forecast assumptions.
     * Scaling (not overwriting) keeps each account's shape + sign and the
     * drill-down reconciling to the line total.
     *
     * Cascade (line level, then scaled down to accounts):
     *   Revenue   → annual-target spread by PY seasonality | YoY growth | run-rate
     *   Direct    → −Revenue × (1 − gross-margin%)
     *   OpEx      → −fixed monthly (× inflation) | −Revenue × %
     *   D&A       → −fixed monthly
     *   Net fin.  → fixed monthly (signed)
     *   Tax       → −rate% × max(0, PBT) | −fixed monthly | 0
     *
     * @param array<int,array> $accountVectors  mutated in place
     * @param array<int,array> $monthlyPy        account_id → [m => balance] last year
     * @param array<int,array> $bucketByAccount  account_id → bucket meta
     * @return array{0:array,1:array}  [effectiveAssumptions, defaults]
     */
    private function applyAssumptionForecast(array &$accountVectors, array $monthlyPy, array $bucketByAccount, int $thruMonth, ?array $saved): array
    {
        $netFinLines = ['fin_revenue', 'fin_expense'];
        $groupOf = fn (string $line) => in_array($line, $netFinLines, true) ? 'net_financial' : $line;

        // Forecast month list — guard against PHP range() auto-reversing
        // when thru >= 12 (range(13,12) would yield [13,12], not []).
        $fc = $thruMonth >= 12 ? [] : range($thruMonth + 1, 12);

        // ── Line actuals (Jan..thru), signed, from the base vectors ──
        $lineActual = ['revenue'=>0.0,'direct_costs'=>0.0,'opex'=>0.0,'depreciation'=>0.0,'net_financial'=>0.0,'income_tax'=>0.0];
        // Interest income (77x) and interest expense (67x) are ALSO tracked
        // separately. They net into one figure on the statement, but the user
        // budgets them as two distinct decisions - what deposits should earn,
        // and what the debt will cost - so the settings expose both and the
        // defaults have to be seeded from each side independently.
        $finActual = ['fin_revenue' => 0.0, 'fin_expense' => 0.0];
        foreach ($accountVectors as $av) {
            $lineName = (string) ($av['line'] ?? '');
            if (array_key_exists($lineName, $finActual)) {
                for ($m = 1; $m <= $thruMonth; $m++) {
                    $finActual[$lineName] += (float) ($av['monthly'][$m] ?? 0);
                }
            }
            $grp = $groupOf($av['line']);
            if (!array_key_exists($grp, $lineActual)) continue;
            for ($m = 1; $m <= $thruMonth; $m++) {
                $lineActual[$grp] += (float) ($av['monthly'][$m] ?? 0);
            }
        }

        // ── PY revenue per month (positive) for seasonality + growth ──
        $pyRev = array_fill(1, 12, 0.0);
        $pyAnnualRev = 0.0;
        foreach ($bucketByAccount as $aid => $b) {
            if (($b['line'] ?? '') !== 'revenue') continue;
            $pm = $monthlyPy[$aid] ?? [];
            for ($m = 1; $m <= 12; $m++) {
                $v = ((float) ($pm[$m] ?? 0)) * (float) $b['sign'];   // sign=-1 → positive
                $pyRev[$m]   += $v;
                $pyAnnualRev += $v;
            }
        }

        // ── Defaults derived from actuals (fallbacks when no actuals) ──
        $revRun = $thruMonth >= 1 ? $lineActual['revenue'] / $thruMonth : ($pyAnnualRev / 12);
        $gmDef  = $lineActual['revenue'] > 0
            ? 100.0 * ($lineActual['revenue'] + $lineActual['direct_costs']) / $lineActual['revenue']
            : 40.0;
        $opexMonthlyDef   = abs($thruMonth >= 1 ? $lineActual['opex'] / $thruMonth : 0.0);
        $opexPctDef       = $lineActual['revenue'] > 0 ? 100.0 * abs($lineActual['opex']) / $lineActual['revenue'] : 0.0;
        $daMonthlyDef     = abs($thruMonth >= 1 ? $lineActual['depreciation'] / $thruMonth : 0.0);
        $netFinMonthlyDef = $thruMonth >= 1 ? $lineActual['net_financial'] / $thruMonth : 0.0;

        $defaults = [
            // revenue.lower / .upper (per-month XAF bounds) add deterministic
            // variation so the spread isn't flat. 0/0 = flat (even split).
            'revenue'               => ['method' => 'annual_target', 'annual_target' => round($revRun * 12, 0), 'growth_pct' => 0.0, 'lower' => 0.0, 'upper' => 0.0],
            'gross_margin_pct'      => round($gmDef, 1),
            // gross_margin_lower_pct / _upper_pct: when both set (upper>lower>0)
            // each forecast month's GM% is a deterministic value in that band.
            'gross_margin_lower_pct'=> 0.0,
            'gross_margin_upper_pct'=> 0.0,
            'opex'                  => ['method' => 'fixed', 'monthly' => round($opexMonthlyDef, 0), 'inflation_pct' => 0.0, 'pct_of_revenue' => round($opexPctDef, 1)],
            'da_monthly'            => round($daMonthlyDef, 0),
            'net_financial_monthly' => round($netFinMonthlyDef, 0),
            // Both entered as POSITIVE magnitudes and netted below. Requiring
            // the user to remember that one of them must be typed negative is
            // exactly the sign trap that produces silently wrong forecasts.
            // fin_revenue is stored sign-flipped (credit-normal) so it is
            // already positive; fin_expense is stored negative, so abs() it.
            'interest_income_monthly'  => $thruMonth >= 1 ? round(max(0.0, $finActual['fin_revenue']) / $thruMonth, 0) : 0.0,
            'interest_expense_monthly' => $thruMonth >= 1 ? round(abs(min(0.0, $finActual['fin_expense'])) / $thruMonth, 0) : 0.0,
            'tax'                   => ['method' => 'rate', 'rate_pct' => 33.0, 'monthly' => 0.0],
        ];

        $A = $this->mergeAssumptions($defaults, is_array($saved) ? $saved : []);

        // Nothing to forecast (all months closed) — still return for the editor.
        if (empty($fc)) return [$A, $defaults];

        // ── Revenue targets per forecast month (positive) ──
        $tRev = [];
        switch ($A['revenue']['method']) {
            case 'growth_pct':
                $g = (float) $A['revenue']['growth_pct'] / 100.0;
                foreach ($fc as $m) $tRev[$m] = $pyRev[$m] > 0 ? $pyRev[$m] * (1 + $g) : $revRun;
                break;
            case 'run_rate':
                foreach ($fc as $m) $tRev[$m] = $revRun;
                break;
            case 'annual_target':
            default:
                // Split the target across the forecast months. The total always
                // equals the target. If a per-month band [lower, upper] is given
                // (upper>lower>0), distribute with deterministic variation inside
                // that band (normalised back to the target) so the line isn't a
                // flat figure; otherwise split evenly.
                $annual = max(0.0, (float) $A['revenue']['annual_target']);
                $n      = count($fc);
                $lo     = (float) ($A['revenue']['lower'] ?? 0);
                $hi     = (float) ($A['revenue']['upper'] ?? 0);
                if ($n > 0 && $hi > $lo && $lo > 0) {
                    $raw = [];
                    foreach ($fc as $m) $raw[$m] = $lo + $this->seededRand("rev-$m") * ($hi - $lo);
                    $sumRaw = array_sum($raw);
                    foreach ($fc as $m) $tRev[$m] = $sumRaw > 0 ? $annual * ($raw[$m] / $sumRaw) : $annual / $n;
                } else {
                    $perMonth = $n > 0 ? $annual / $n : 0.0;
                    foreach ($fc as $m) $tRev[$m] = $perMonth;
                }
                break;
        }

        // ── Cascade the remaining lines off revenue + the cost drivers ──
        $gm        = (float) $A['gross_margin_pct'] / 100.0;
        $gmLo      = (float) ($A['gross_margin_lower_pct'] ?? 0);
        $gmHi      = (float) ($A['gross_margin_upper_pct'] ?? 0);
        $gmRange   = ($gmHi > $gmLo && $gmLo > 0);   // valid band → vary per month
        $opexFixed = (float) $A['opex']['monthly'] * (1 + (float) $A['opex']['inflation_pct'] / 100.0);
        $opexPct   = (float) $A['opex']['pct_of_revenue'] / 100.0;
        $daM       = (float) $A['da_monthly'];
        // Net financial result = interest income - interest expense, both
        // entered positive. A tenant who saved settings before these two
        // fields existed falls back to the single combined figure, so their
        // forecast does not silently change under them on deploy.
        $hasSplit  = array_key_exists('interest_income_monthly', $A)
                  || array_key_exists('interest_expense_monthly', $A);
        $nfM       = $hasSplit
            ? (float) ($A['interest_income_monthly'] ?? 0) - (float) ($A['interest_expense_monthly'] ?? 0)
            : (float) $A['net_financial_monthly'];
        // Per-side magnitudes for the separate targets. With only a combined
        // figure saved (pre-split settings), put it on whichever side its sign
        // implies so the total still reconciles.
        $finIncomeM  = $hasSplit ? (float) ($A['interest_income_monthly']  ?? 0) : max(0.0, $nfM);
        $finExpenseM = $hasSplit ? (float) ($A['interest_expense_monthly'] ?? 0) : abs(min(0.0, $nfM));

        $target = ['revenue'=>[], 'direct_costs'=>[], 'opex'=>[], 'depreciation'=>[], 'fin_revenue'=>[], 'fin_expense'=>[], 'income_tax'=>[]];
        foreach ($fc as $m) {
            $rev  = (float) $tRev[$m];
            // Per-month gross margin: a deterministic value inside the band when
            // one is set, else the flat single GM%.
            $gmM  = $gmRange ? (($gmLo + $this->seededRand("gm-$m") * ($gmHi - $gmLo)) / 100.0) : $gm;
            $dir  = -1.0 * $rev * (1 - $gmM);
            $opx = $A['opex']['method'] === 'pct_of_revenue' ? (-1.0 * $rev * $opexPct) : (-1.0 * $opexFixed);
            $da  = -1.0 * $daM;
            $nfn = $nfM;
            $pbt = $rev + $dir + $opx + $da + $nfn;
            $tax = match ($A['tax']['method']) {
                'fixed' => -1.0 * (float) $A['tax']['monthly'],
                'none'  => 0.0,
                default => -1.0 * max(0.0, $pbt) * ((float) $A['tax']['rate_pct'] / 100.0),
            };
            $target['revenue'][$m]       = $rev;
            $target['direct_costs'][$m]  = $dir;
            $target['opex'][$m]          = $opx;
            $target['depreciation'][$m]  = $da;
            // Targeted SEPARATELY, which is what lets a financial assumption
            // actually land. As one combined 'net_financial' group it mixed
            // signs (77 income positive, 67 expense negative), so the scaling
            // step below refused to touch it whenever the historical base was
            // ~0 - and a user entering an interest expense saw nothing change.
            // Split, each group is single-sign and safe to write.
            $target['fin_revenue'][$m]   = $finIncomeM;
            $target['fin_expense'][$m]   = -$finExpenseM;
            $target['income_tax'][$m]    = $tax;
        }

        // ── Group account ids, then scale base forecast months to target ──
        $groupAccts = ['revenue'=>[], 'direct_costs'=>[], 'opex'=>[], 'depreciation'=>[], 'fin_revenue'=>[], 'fin_expense'=>[], 'income_tax'=>[]];
        foreach ($accountVectors as $aid => $av) {
            $grp = (string) ($av['line'] ?? '');
            if (isset($groupAccts[$grp])) $groupAccts[$grp][] = $aid;
        }

        // Every group needs somewhere for its target to land. A line with no
        // accounts in the GL - no class-89, no 67x, no 77x - has nothing for
        // the scaling step below to write into, so that assumption would stay
        // flat at zero no matter what the user typed. A synthetic holder with
        // zero actuals gives it a home without touching real account detail.
        $synthMeta = [
            'revenue'      => ['7',  'Revenue (computed)'],
            'direct_costs' => ['60', 'Direct costs (computed)'],
            'opex'         => ['6',  'Operating expenses (computed)'],
            'depreciation' => ['68', 'Depreciation & amortisation (computed)'],
            'fin_revenue'  => ['77', 'Financial income (computed)'],
            'fin_expense'  => ['67', 'Financial expense (computed)'],
            'income_tax'   => ['89', 'Income tax (computed)'],
        ];
        foreach ($synthMeta as $grp => [$code, $label]) {
            if (!empty($groupAccts[$grp])) continue;
            $key = '__synth_' . $grp;
            $accountVectors[$key] = [
                'line'    => $grp,
                'code'    => $code,
                'name'    => $label,
                'monthly' => array_fill(1, 12, 0.0),
            ];
            $groupAccts[$grp][] = $key;
        }

        foreach ($target as $grp => $monthsTarget) {
            $aids = $groupAccts[$grp];
            foreach ($fc as $m) {
                $tv   = (float) ($monthsTarget[$m] ?? 0);
                $base = 0.0;
                foreach ($aids as $aid) $base += (float) ($accountVectors[$aid]['monthly'][$m] ?? 0);
                if (abs($base) > 1.0) {
                    $scale = $tv / $base;
                    foreach ($aids as $aid) $accountVectors[$aid]['monthly'][$m] *= $scale;
                } elseif (count($aids) > 0) {
                    // Base ~0 — split the target equally so it still shows.
                    // Safe for every group now that financial income and
                    // expense are separate: each carries one sign, so writing
                    // the target cannot flip an account the wrong way.
                    // net_financial used to be excluded here for exactly that
                    // reason, which is why an entered interest expense silently
                    // did nothing when there was no historical base.
                    $each = $tv / count($aids);
                    foreach ($aids as $aid) $accountVectors[$aid]['monthly'][$m] = $each;
                }
            }
        }

        return [$A, $defaults];
    }

    /** Merge saved assumption overrides onto the actuals-derived defaults. */
    private function mergeAssumptions(array $defaults, array $saved): array
    {
        $out = $defaults;
        foreach (['revenue', 'opex', 'tax'] as $k) {
            if (isset($saved[$k]) && is_array($saved[$k])) {
                $out[$k] = array_merge($defaults[$k], $saved[$k]);
            }
        }
        foreach (['gross_margin_pct', 'gross_margin_lower_pct', 'gross_margin_upper_pct', 'da_monthly',
                  'net_financial_monthly', 'interest_income_monthly', 'interest_expense_monthly'] as $k) {
            if (array_key_exists($k, $saved) && is_numeric($saved[$k])) {
                $out[$k] = (float) $saved[$k];
            }
        }
        return $out;
    }

    /**
     * Deterministic pseudo-random value in [0,1) from a string seed. Stable
     * across recomputes (NOT rand()) so the forecast doesn't jump around on
     * every reload — it just isn't a flat line.
     */
    private function seededRand(string $seed): float
    {
        return (abs(crc32($seed)) % 100000) / 100000.0;
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
     *
     * Drill-down hygiene rules (per user spec):
     *   1. Filter out accounts whose annual total rounds to zero in
     *      millions XAF (|total| < 500,000 — i.e. would render as "0"
     *      anyway given the no-decimals millions formatting). Truly
     *      empty / dormant accounts disappear so the operator can
     *      focus on real contributors.
     *   2. Sort by absolute total descending so the biggest
     *      contributors sit at the top — the operator sees what
     *      moves the line first, then can scan further down for
     *      detail.
     */
    private function row(string $key, string $label, array $monthly, string $kind, string $colour, array $accounts = []): array
    {
        $total = array_sum($monthly);
        // Threshold matches the millions-with-no-decimals display: an
        // account contributing < 0.5M for the year rounds to "0" and
        // adds noise without information.
        $minDisplayable = 500_000.0;
        $accountRows = [];
        foreach ($accounts as $a) {
            $acctTotal = array_sum($a['monthly']);
            if (abs($acctTotal) < $minDisplayable) continue;
            $accountRows[] = [
                'code'    => $a['code'],
                'name'    => $a['name'],
                'monthly' => $a['monthly'],
                'total'   => $acctTotal,
            ];
        }
        // Biggest contributors first.
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

    /* ───────────────── Comparison-mode views (MTD, YTD) ─────────────── */

    /**
     * MTD — single calendar month, four columns:
     *   PY (same month last year actual)
     *   CY (current year, this month actual to date — partial if mid-month)
     *   Forecast (full-month forecast: SMLY w/ trailing-3 fallback)
     *   Budget (uploaded budget if present, else PY × growth factor)
     * Plus variances: CY vs PY, Forecast vs Budget.
     *
     * @param int   $year   Selected year (defaults to current)
     * @param int   $month  Month 1-12 (defaults to current month)
     * @param float $budgetGrowth Multiplier applied to PY for Budget. Default 1.0.
     * @param ?array $uploadedBudget Optional uploaded budget. Shape:
     *                                ['revenue' => [1=>x, 2=>y, …], …]
     *                                When present, overrides PY×growth for the
     *                                Budget column.
     */
    public function analyzeMtd(?int $year = null, ?int $month = null, float $budgetGrowth = 1.0, ?array $uploadedBudget = null): array
    {
        $today = new \DateTimeImmutable('today');
        $year  = $year  ?? (int) $today->format('Y');
        $month = $month ?? (int) $today->format('n');
        $month = max(1, min(12, $month));

        $isService       = $this->isServiceBusiness($year);
        $accounts        = $this->fetchAccountsInScope();
        if (empty($accounts)) return $this->emptyComparison($year, $month, 'mtd', $isService);
        $bucketByAccount = $this->bucketAccounts($accounts, $isService);
        if (empty($bucketByAccount)) return $this->emptyComparison($year, $month, 'mtd', $isService);

        $accountIds = array_keys($bucketByAccount);
        $monthly    = $this->fetchMonthlyBalances($accountIds, $year);
        $monthlyPy  = $this->fetchMonthlyBalances($accountIds, $year - 1);

        // For MTD we need 4 single-figure series per line (PY, CY, Fcst, Bdg).
        // Compute at the account level, then aggregate.
        $rowsByLine = [
            'revenue' => [], 'direct_costs' => [], 'opex' => [],
            'depreciation' => [], 'fin_revenue' => [], 'fin_expense' => [], 'income_tax' => [],
        ];
        $accountsByLine = [
            'revenue' => [], 'direct_costs' => [], 'opex' => [],
            'depreciation' => [], 'fin_revenue' => [], 'fin_expense' => [], 'income_tax' => [],
        ];

        // For forecast we need the trailing-3 mean from CY (or last year if
        // we're projecting into the current month and CY itself is empty).
        $thruMonth = $year < (int) $today->format('Y')
            ? 12
            : ($year > (int) $today->format('Y') ? 0 : max(0, ((int) $today->format('n')) - 1));

        foreach ($accountIds as $aid) {
            $b      = $bucketByAccount[$aid];
            $sign   = $b['sign'];
            $cy     = $monthly[$aid]   ?? array_fill(1, 12, 0.0);
            $py     = $monthlyPy[$aid] ?? array_fill(1, 12, 0.0);

            $pyVal  = ((float) ($py[$month] ?? 0)) * $sign;
            $cyVal  = ((float) ($cy[$month] ?? 0)) * $sign;
            // Forecast: if month is closed (≤ thruMonth) the forecast IS
            // the actual; if not, derive via SMLY → trailing-3 fallback.
            if ($month <= $thruMonth) {
                $fcVal = $cyVal;
            } else {
                $smly = (float) ($py[$month] ?? 0);
                if ($smly === 0.0) {
                    // trailing-3 mean of CY (so far), to scale up
                    $tail = [];
                    for ($m = max(1, $thruMonth - 2); $m <= $thruMonth; $m++) {
                        $tail[] = (float) ($cy[$m] ?? 0);
                    }
                    $smly = empty($tail) ? 0.0 : array_sum($tail) / count($tail);
                }
                $fcVal = $smly * $sign;
            }
            $bdgVal = $pyVal * $budgetGrowth;

            $accountsByLine[$b['line']][] = [
                'code' => $b['code'], 'name' => $b['name'],
                'py' => $pyVal, 'cy' => $cyVal, 'forecast' => $fcVal, 'budget' => $bdgVal,
            ];
        }

        // Aggregate per line
        $sumLine = function (array $accts, string $field): float {
            $s = 0.0;
            foreach ($accts as $a) $s += (float) ($a[$field] ?? 0);
            return $s;
        };

        $vec = function (string $line) use (&$accountsByLine, $sumLine): array {
            return [
                'py'       => $sumLine($accountsByLine[$line], 'py'),
                'cy'       => $sumLine($accountsByLine[$line], 'cy'),
                'forecast' => $sumLine($accountsByLine[$line], 'forecast'),
                'budget'   => $sumLine($accountsByLine[$line], 'budget'),
            ];
        };

        $rev   = $vec('revenue');
        $dc    = $vec('direct_costs');
        $opex  = $vec('opex');
        $da    = $vec('depreciation');
        $finR  = $vec('fin_revenue');
        $finE  = $vec('fin_expense');
        $tax   = $vec('income_tax');

        // Apply uploaded budget (line-level override). This replaces the
        // per-account PY×growth budget with the user-provided figure for
        // each top-level line. fin_revenue + fin_expense aggregate into
        // 'net_financial' on the upload — set finR['budget'] directly,
        // leave finE['budget'] at zero so the sum still works.
        if (is_array($uploadedBudget)) {
            $applyMonth = function (string $key) use ($uploadedBudget, $month): ?float {
                $vec = $uploadedBudget[$key] ?? null;
                if (!is_array($vec)) return null;
                return isset($vec[$month]) ? (float) $vec[$month] : null;
            };
            if (($v = $applyMonth('revenue'))      !== null) $rev['budget']  = $v;
            if (($v = $applyMonth('direct_costs')) !== null) $dc['budget']   = $v;
            if (($v = $applyMonth('opex'))         !== null) $opex['budget'] = $v;
            if (($v = $applyMonth('depreciation')) !== null) $da['budget']   = $v;
            if (($v = $applyMonth('income_tax'))   !== null) $tax['budget']  = $v;
            if (($v = $applyMonth('net_financial')) !== null) {
                $finR['budget'] = $v;
                $finE['budget'] = 0.0;
            }
        }

        $addV = fn (array $a, array $b) => [
            'py'       => $a['py']       + $b['py'],
            'cy'       => $a['cy']       + $b['cy'],
            'forecast' => $a['forecast'] + $b['forecast'],
            'budget'   => $a['budget']   + $b['budget'],
        ];
        $gp     = $addV($rev, $dc);
        $ebitda = $addV($gp, $opex);
        $ebit   = $addV($ebitda, $da);
        $netFin = $addV($finR, $finE);
        $pbt    = $addV($ebit, $netFin);
        $net    = $addV($pbt, $tax);

        $marginV = function (array $num, array $den): array {
            return [
                'py'       => $den['py']       != 0 ? 100.0 * $num['py']       / $den['py']       : null,
                'cy'       => $den['cy']       != 0 ? 100.0 * $num['cy']       / $den['cy']       : null,
                'forecast' => $den['forecast'] != 0 ? 100.0 * $num['forecast'] / $den['forecast'] : null,
                'budget'   => $den['budget']   != 0 ? 100.0 * $num['budget']   / $den['budget']   : null,
            ];
        };

        // Build the row list with optional drill-down (filtered + sorted).
        $rows = [];
        $rows[] = $this->compRow('revenue',       'Revenue',                     $rev,    'leaf',     'sage',  $accountsByLine['revenue']);
        $rows[] = $this->compRow('direct_costs',  'Direct operating costs',      $dc,     'leaf',     'coral', $accountsByLine['direct_costs']);
        $rows[] = $this->compRow('gross_profit',  'Gross Profit',                $gp,     'subtotal', 'sage');
        $rows[] = $this->compRow('gross_margin',  'Gross Margin %',              $marginV($gp, $rev),     'margin', 'slate');
        $rows[] = $this->compRow('opex',          'Operating expenses',          $opex,   'leaf',     'coral', $accountsByLine['opex']);
        $rows[] = $this->compRow('ebitda',        'EBITDA',                      $ebitda, 'subtotal', 'brand');
        $rows[] = $this->compRow('ebitda_margin', 'EBITDA Margin %',             $marginV($ebitda, $rev), 'margin', 'slate');
        $rows[] = $this->compRow('depreciation',  'Depreciation & amortisation', $da,     'leaf',     'coral', $accountsByLine['depreciation']);
        $rows[] = $this->compRow('ebit',          'EBIT',                        $ebit,   'subtotal', 'brand');
        $rows[] = $this->compRow('ebit_margin',   'EBIT Margin %',               $marginV($ebit, $rev),   'margin', 'slate');
        $rows[] = $this->compRow('net_financial', 'Net financial result',        $netFin, 'leaf',     'warm',  array_merge($accountsByLine['fin_revenue'], $accountsByLine['fin_expense']));
        $rows[] = $this->compRow('pbt',           'Profit Before Tax',           $pbt,    'subtotal', 'brand');
        $rows[] = $this->compRow('income_tax',    'Income tax',                  $tax,    'leaf',     'coral', $accountsByLine['income_tax']);
        $rows[] = $this->compRow('net_result',    'Net Result',                  $net,    'subtotal', 'sage');
        $rows[] = $this->compRow('net_margin',    'Net Margin %',                $marginV($net, $rev),    'margin', 'slate');

        return [
            'view'             => 'mtd',
            'currency'         => 'XAF',
            'year'             => $year,
            'month'            => $month,
            'month_label'      => $this->monthName($month),
            'is_service'       => $isService,
            'cogs_basis'       => $isService ? 'Class 60+61+62' : 'Class 60',
            'budget_growth'    => $budgetGrowth,
            'budget_uploaded'  => is_array($uploadedBudget),
            'lines'            => $rows,
            'fetched_at'       => now()->toIso8601String(),
        ];
    }

    /**
     * YTD — Jan..(current/closed month), four columns.
     *   PY YTD          = sum of same months last year
     *   CY YTD          = sum of months this year up to thruMonth
     *   Forecast (full year) = full-year forecast (CY actual + future SMLY)
     *   Budget (full year)   = uploaded budget if present, else PY × growth
     */
    public function analyzeYtd(?int $year = null, float $budgetGrowth = 1.0, ?array $uploadedBudget = null): array
    {
        $today      = new \DateTimeImmutable('today');
        $year       = $year ?? (int) $today->format('Y');
        $isService  = $this->isServiceBusiness($year);

        $accounts = $this->fetchAccountsInScope();
        if (empty($accounts)) return $this->emptyComparison($year, 0, 'ytd', $isService);
        $bucketByAccount = $this->bucketAccounts($accounts, $isService);
        if (empty($bucketByAccount)) return $this->emptyComparison($year, 0, 'ytd', $isService);

        $accountIds = array_keys($bucketByAccount);
        $monthly    = $this->fetchMonthlyBalances($accountIds, $year);
        $monthlyPy  = $this->fetchMonthlyBalances($accountIds, $year - 1);

        $thruMonth = $year < (int) $today->format('Y')
            ? 12
            : ($year > (int) $today->format('Y') ? 0 : max(0, ((int) $today->format('n')) - 1));

        $accountsByLine = [
            'revenue' => [], 'direct_costs' => [], 'opex' => [],
            'depreciation' => [], 'fin_revenue' => [], 'fin_expense' => [], 'income_tax' => [],
        ];

        foreach ($accountIds as $aid) {
            $b    = $bucketByAccount[$aid];
            $sign = $b['sign'];
            $cy   = $monthly[$aid]   ?? array_fill(1, 12, 0.0);
            $py   = $monthlyPy[$aid] ?? array_fill(1, 12, 0.0);

            // PY YTD = same Jan..thruMonth last year (full closed period)
            $pyYtd = 0.0;
            for ($m = 1; $m <= max(1, $thruMonth); $m++) {
                $pyYtd += (float) ($py[$m] ?? 0);
            }
            $pyYtd *= $sign;

            // CY YTD = Jan..thruMonth this year
            $cyYtd = 0.0;
            for ($m = 1; $m <= $thruMonth; $m++) {
                $cyYtd += (float) ($cy[$m] ?? 0);
            }
            $cyYtd *= $sign;

            // Forecast full year: actuals + SMLY for future months
            $vec = $this->fillForecast($cy, $py, $thruMonth, $sign);
            $forecastYear = array_sum($vec);

            // Budget full year = PY full year × growth
            $pyYear = 0.0;
            for ($m = 1; $m <= 12; $m++) $pyYear += (float) ($py[$m] ?? 0);
            $budgetYear = $pyYear * $sign * $budgetGrowth;

            $accountsByLine[$b['line']][] = [
                'code' => $b['code'], 'name' => $b['name'],
                'py' => $pyYtd, 'cy' => $cyYtd, 'forecast' => $forecastYear, 'budget' => $budgetYear,
            ];
        }

        $sumLine = function (array $accts, string $field): float {
            $s = 0.0;
            foreach ($accts as $a) $s += (float) ($a[$field] ?? 0);
            return $s;
        };
        $vec = function (string $line) use (&$accountsByLine, $sumLine): array {
            return [
                'py'       => $sumLine($accountsByLine[$line], 'py'),
                'cy'       => $sumLine($accountsByLine[$line], 'cy'),
                'forecast' => $sumLine($accountsByLine[$line], 'forecast'),
                'budget'   => $sumLine($accountsByLine[$line], 'budget'),
            ];
        };

        $rev   = $vec('revenue');
        $dc    = $vec('direct_costs');
        $opex  = $vec('opex');
        $da    = $vec('depreciation');
        $finR  = $vec('fin_revenue');
        $finE  = $vec('fin_expense');
        $tax   = $vec('income_tax');

        // Apply uploaded budget — for YTD the Budget column is "Full Year",
        // so sum 12 months from the upload per line.
        if (is_array($uploadedBudget)) {
            $sumYear = function (string $key) use ($uploadedBudget): ?float {
                $v = $uploadedBudget[$key] ?? null;
                if (!is_array($v)) return null;
                return array_sum($v);
            };
            if (($v = $sumYear('revenue'))      !== null) $rev['budget']  = $v;
            if (($v = $sumYear('direct_costs')) !== null) $dc['budget']   = $v;
            if (($v = $sumYear('opex'))         !== null) $opex['budget'] = $v;
            if (($v = $sumYear('depreciation')) !== null) $da['budget']   = $v;
            if (($v = $sumYear('income_tax'))   !== null) $tax['budget']  = $v;
            if (($v = $sumYear('net_financial')) !== null) {
                $finR['budget'] = $v;
                $finE['budget'] = 0.0;
            }
        }

        $addV = fn (array $a, array $b) => [
            'py'       => $a['py']       + $b['py'],
            'cy'       => $a['cy']       + $b['cy'],
            'forecast' => $a['forecast'] + $b['forecast'],
            'budget'   => $a['budget']   + $b['budget'],
        ];
        $gp     = $addV($rev, $dc);
        $ebitda = $addV($gp, $opex);
        $ebit   = $addV($ebitda, $da);
        $netFin = $addV($finR, $finE);
        $pbt    = $addV($ebit, $netFin);
        $net    = $addV($pbt, $tax);

        $marginV = function (array $num, array $den): array {
            return [
                'py'       => $den['py']       != 0 ? 100.0 * $num['py']       / $den['py']       : null,
                'cy'       => $den['cy']       != 0 ? 100.0 * $num['cy']       / $den['cy']       : null,
                'forecast' => $den['forecast'] != 0 ? 100.0 * $num['forecast'] / $den['forecast'] : null,
                'budget'   => $den['budget']   != 0 ? 100.0 * $num['budget']   / $den['budget']   : null,
            ];
        };

        $rows = [];
        $rows[] = $this->compRow('revenue',       'Revenue',                     $rev,    'leaf',     'sage',  $accountsByLine['revenue']);
        $rows[] = $this->compRow('direct_costs',  'Direct operating costs',      $dc,     'leaf',     'coral', $accountsByLine['direct_costs']);
        $rows[] = $this->compRow('gross_profit',  'Gross Profit',                $gp,     'subtotal', 'sage');
        $rows[] = $this->compRow('gross_margin',  'Gross Margin %',              $marginV($gp, $rev),     'margin', 'slate');
        $rows[] = $this->compRow('opex',          'Operating expenses',          $opex,   'leaf',     'coral', $accountsByLine['opex']);
        $rows[] = $this->compRow('ebitda',        'EBITDA',                      $ebitda, 'subtotal', 'brand');
        $rows[] = $this->compRow('ebitda_margin', 'EBITDA Margin %',             $marginV($ebitda, $rev), 'margin', 'slate');
        $rows[] = $this->compRow('depreciation',  'Depreciation & amortisation', $da,     'leaf',     'coral', $accountsByLine['depreciation']);
        $rows[] = $this->compRow('ebit',          'EBIT',                        $ebit,   'subtotal', 'brand');
        $rows[] = $this->compRow('ebit_margin',   'EBIT Margin %',               $marginV($ebit, $rev),   'margin', 'slate');
        $rows[] = $this->compRow('net_financial', 'Net financial result',        $netFin, 'leaf',     'warm',  array_merge($accountsByLine['fin_revenue'], $accountsByLine['fin_expense']));
        $rows[] = $this->compRow('pbt',           'Profit Before Tax',           $pbt,    'subtotal', 'brand');
        $rows[] = $this->compRow('income_tax',    'Income tax',                  $tax,    'leaf',     'coral', $accountsByLine['income_tax']);
        $rows[] = $this->compRow('net_result',    'Net Result',                  $net,    'subtotal', 'sage');
        $rows[] = $this->compRow('net_margin',    'Net Margin %',                $marginV($net, $rev),    'margin', 'slate');

        return [
            'view'             => 'ytd',
            'currency'         => 'XAF',
            'year'             => $year,
            'thru_month'       => $thruMonth,
            'is_service'       => $isService,
            'cogs_basis'       => $isService ? 'Class 60+61+62' : 'Class 60',
            'budget_growth'    => $budgetGrowth,
            'budget_uploaded'  => is_array($uploadedBudget),
            'lines'            => $rows,
            'fetched_at'       => now()->toIso8601String(),
        ];
    }

    /**
     * Pack a comparison-mode row (PY, CY, Forecast, Budget + variances).
     * Filters out empty accounts and sorts drill-down by absolute CY desc.
     */
    private function compRow(string $key, string $label, array $values, string $kind, string $colour, array $accounts = []): array
    {
        // Variance helpers
        $variances = [
            'cy_vs_py'      => $this->varianceAbsPct($values['cy'] ?? 0, $values['py'] ?? 0),
            'fcst_vs_bdg'   => $this->varianceAbsPct($values['forecast'] ?? 0, $values['budget'] ?? 0),
            'cy_vs_bdg'     => $this->varianceAbsPct($values['cy'] ?? 0, $values['budget'] ?? 0),
        ];

        $minDisp = 500_000.0;
        $accountRows = [];
        foreach ($accounts as $a) {
            $maxAbs = max(abs($a['py'] ?? 0), abs($a['cy'] ?? 0), abs($a['forecast'] ?? 0), abs($a['budget'] ?? 0));
            if ($maxAbs < $minDisp) continue;
            $accountRows[] = [
                'code'      => $a['code'],
                'name'      => $a['name'],
                'py'        => $a['py'],
                'cy'        => $a['cy'],
                'forecast'  => $a['forecast'],
                'budget'    => $a['budget'],
                'cy_vs_py'  => $this->varianceAbsPct($a['cy'], $a['py']),
            ];
        }
        usort($accountRows, fn ($x, $y) => abs($y['cy']) <=> abs($x['cy']));

        return [
            'key'       => $key,
            'label'     => $label,
            'values'    => $values,
            'variances' => $variances,
            'kind'      => $kind,
            'colour'    => $colour,
            'accounts'  => $accountRows,
        ];
    }

    /** @return array{abs:float, pct:?float} */
    private function varianceAbsPct(?float $a, ?float $b): array
    {
        $a = (float) ($a ?? 0);
        $b = (float) ($b ?? 0);
        $abs = $a - $b;
        $pct = $b != 0.0 ? 100.0 * $abs / abs($b) : null;
        return ['abs' => $abs, 'pct' => $pct];
    }

    private function monthName(int $m): string
    {
        $names = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return $names[$m - 1] ?? '';
    }

    private function emptyComparison(int $year, int $month, string $view, bool $isService): array
    {
        return [
            'view'       => $view,
            'currency'   => 'XAF',
            'year'       => $year,
            'month'      => $month,
            'is_service' => $isService,
            'cogs_basis' => $isService ? 'Class 60+61+62' : 'Class 60',
            'lines'      => [],
            'fetched_at' => now()->toIso8601String(),
            'error'      => 'No accounts in scope.',
        ];
    }
}
