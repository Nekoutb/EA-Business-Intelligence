<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Working Capital analytics — eight ratios computed directly from the
 * SYSCOHADA general ledger.
 *
 * Ratios produced:
 *   Current Ratio        = Current Assets ÷ Current Liabilities
 *   Quick Ratio          = (Current Assets − Inventory) ÷ Current Liabilities
 *   Cash Ratio           = Cash & Equivalents ÷ Current Liabilities
 *   Working Capital      = Current Assets − Current Liabilities      (XAF)
 *   DSO                  = (AR ÷ Revenue_TTM) × 365                  (days)
 *   DPO                  = (AP ÷ COGS_TTM)    × 365                  (days)
 *   DIO                  = (Inventory ÷ COGS_TTM) × 365              (days)
 *   Cash Conversion Cycle = DSO + DIO − DPO                          (days)
 *
 * Methodology — code-prefix based, version-agnostic across Odoo 14..19+
 * (matches ProfitLossService approach):
 *
 *   Class 3 (3*)         → Inventory (debit balances)
 *   Class 41 (41*)       → AR — debit balances are AR; credit balances
 *                          are customer advances and roll into current
 *                          liabilities
 *   Class 40 (40*)       → AP — credit balances are AP; debit balances
 *                          are supplier advances (current asset)
 *   Class 5 (5*)         → Cash & equivalents — debit balances are
 *                          cash/banks; credit balances are bank
 *                          overdrafts (current liability)
 *   Class 4 other (42-49)→ Split by sign — debit = current asset,
 *                          credit = current liability
 *
 *   Revenue_TTM          = -Σ class-7 balance, trailing 12 months
 *                          ending at as_of (excludes 79 — provisions)
 *   COGS_TTM             =  Σ class-60 balance, trailing 12 months
 *                          ending at as_of
 *
 * Sign convention reminder: in Odoo move_line.balance is debit-positive.
 * Revenue (class 7, credit-normal) shows as negative balance — flip the
 * sign for the magnitude. Same for AP (class 40 credit-normal).
 *
 * Why TTM (not period)?
 *   DSO/DPO/DIO are by definition annualised on a 365-day basis. Using
 *   the period's revenue/COGS would distort the days figures (e.g. an
 *   MTD denominator with a balance-sheet snapshot would scale the days
 *   by 30:365 ≈ 12×). TTM keeps the annualisation honest regardless of
 *   the as-of date selected.
 *
 * Business-type detection (goods vs service):
 *   The first thing analyze() does is check the inventory balance:
 *
 *     inventory > 0   → "goods"   business: COGS = Class 60 (Achats)
 *     inventory == 0  → "service" business: COGS = Class 60+61+62+63+64+65+66+67
 *                                         (every operating expense except
 *                                          68 D&A and 69 HAO/income-tax)
 *
 *   For service businesses the Quick Ratio (= Current Ratio when inventory=0,
 *   so adds no information) and DIO (= 0, meaningless without inventory)
 *   are returned as null — the view hides those cards entirely. CCC for a
 *   service business simplifies to DSO − DPO (no inventory term).
 */
final class WorkingCapitalService
{
    /** Inventory (SYSCOHADA Class 3). */
    private const INVENTORY_PREFIXES = ['3'];

    /** AR (Class 41 — clients). */
    private const AR_PREFIXES = ['41'];

    /** AP (Class 40 — fournisseurs). */
    private const AP_PREFIXES = ['40'];

    /** Cash & banks (Class 5 — comptes financiers). */
    private const CASH_PREFIXES = ['5'];

    /** All current-tiers accounts (Class 4 other than AR/AP). */
    private const CURRENT_TIERS_PREFIXES = ['42', '43', '44', '45', '46', '47', '48', '49'];

    /** Revenue (Class 7 except 79). */
    private const REVENUE_PREFIXES         = ['7'];
    private const REVENUE_EXCLUDE_PREFIXES = ['79'];

    /** Goods-business COGS — SYSCOHADA Class 60 (Achats). */
    private const COGS_GOODS_PREFIXES = ['60'];

    /**
     * Service-business "Cost of revenue" — entire operating-expense block
     * except depreciation (68) and HAO/income tax (69). Captures personnel,
     * sub-contracting, transport, external services, taxes, financial
     * charges and other operating costs that drive a service operation.
     */
    private const COGS_SERVICE_PREFIXES         = ['6'];
    private const COGS_SERVICE_EXCLUDE_PREFIXES = ['68', '69'];

    /**
     * Threshold (in XAF) below which inventory is treated as effectively
     * zero. Catches small office-supply blips on services companies'
     * balance sheets without flipping them to "goods business".
     */
    private const INVENTORY_ZERO_THRESHOLD = 1_000.0;

    private OdooClient $odoo;

    public function __construct(OdooClient $odoo)
    {
        $this->odoo = $odoo;
    }

    public function analyze(?\DateTimeImmutable $asOf = null): array
    {
        $asOf = $asOf ?? new \DateTimeImmutable('today');

        $balances = $this->fetchBalanceSheet($asOf);

        $ar        = $balances['ar'];
        $ap        = $balances['ap'];
        $cash      = $balances['cash'];
        $inventory = $balances['inventory'];
        $ca        = $balances['current_assets'];
        $cl        = $balances['current_liabilities'];

        // ── Business-type detection ──────────────────────────────────
        // Inventory == 0 (or near-zero) → service business. The COGS basis
        // and the Quick Ratio / DIO display behaviour both depend on this
        // flag. See class-doc for the full rationale.
        $isService    = $inventory <= self::INVENTORY_ZERO_THRESHOLD;
        $businessType = $isService ? 'service' : 'goods';

        // ── TTM income-statement figures ────────────────────────────
        // Revenue is universal. COGS basis depends on business type:
        // service businesses use the broad "operating cost" basis (Class 6
        // ex 68 D&A ex 69 HAO); goods businesses use Class 60 alone.
        $ttm      = $this->fetchTtm($asOf, $isService);
        $revenue  = $ttm['revenue'];
        $cogs     = $ttm['cogs'];
        $cogsAlt  = $ttm['cogs_alt']; // the OTHER basis, for transparency in the view

        // ── Liquidity ratios ────────────────────────────────────────
        $currentRatio = $cl > 0 ? $ca / $cl : null;
        $cashRatio    = $cl > 0 ? $cash / $cl : null;
        $workingCap   = $ca - $cl;

        // Quick Ratio is gated on real inventory presence. Without inventory,
        // (CA − 0) / CL == Current Ratio, so showing both adds no info and
        // mildly misleads ("the strict liquidity test is identical to the
        // loose one — looks good!"). Return null + reason; view hides card.
        $quickRatio       = null;
        $quickRatioReason = null;
        if ($isService) {
            $quickRatioReason = 'Not applicable — service business carries no inventory.';
        } elseif ($cl <= 0) {
            $quickRatioReason = 'No current liabilities in period.';
        } else {
            $quickRatio = ($ca - $inventory) / $cl;
        }

        // ── Cycle ratios ────────────────────────────────────────────
        $dso = $revenue > 0 ? ($ar / $revenue) * 365.0 : null;
        $dpo = $cogs    > 0 ? ($ap / $cogs)    * 365.0 : null;

        // DIO gated on inventory presence.
        $dio       = null;
        $dioReason = null;
        if ($isService) {
            $dioReason = 'Not applicable — service business carries no inventory.';
        } elseif ($cogs <= 0) {
            $dioReason = 'No COGS in trailing-12-month window.';
        } else {
            $dio = ($inventory / $cogs) * 365.0;
        }

        // CCC = DSO + DIO − DPO. For service businesses (no DIO), this
        // simplifies to DSO − DPO — the cash gap from sale to supplier
        // payment, with no inventory dwell time.
        if ($isService) {
            $ccc = ($dso !== null && $dpo !== null) ? $dso - $dpo : null;
        } else {
            $ccc = ($dso !== null && $dpo !== null && $dio !== null)
                ? $dso + $dio - $dpo
                : null;
        }

        return [
            'currency' => $this->detectCurrency(),
            'as_of'    => $asOf->format('Y-m-d'),
            'ttm_window' => [
                'from' => $asOf->modify('-1 year +1 day')->format('Y-m-d'),
                'to'   => $asOf->format('Y-m-d'),
            ],

            // Business-type metadata so the view can adjust the display
            // and explain its choices to the operator.
            'business_type' => $businessType,
            'business_type_explainer' => $isService
                ? sprintf(
                    'No inventory on the balance sheet (≤ %s XAF threshold) → treated as a service business. '
                    . 'COGS basis = SYSCOHADA classes 60+61+62+63+64+65+66+67 (every operating expense except depreciation and HAO/income tax). '
                    . 'Quick Ratio and DIO are hidden because they require inventory to be meaningful. '
                    . 'CCC simplifies to DSO − DPO.',
                    number_format(self::INVENTORY_ZERO_THRESHOLD, 0)
                )
                : 'Inventory on the balance sheet → treated as a goods business. '
                  . 'COGS basis = SYSCOHADA Class 60 (Achats — direct purchases). '
                  . 'All eight working-capital ratios apply.',
            'cogs_basis' => $isService
                ? 'Class 60+61+62+63+64+65+66+67 (operating cost ex. D&A & HAO)'
                : 'Class 60 (Achats — direct purchases)',

            // Headline ratios — null where not applicable
            'ratios' => [
                'current_ratio'      => $currentRatio,
                'quick_ratio'        => $quickRatio,
                'quick_ratio_reason' => $quickRatioReason,
                'cash_ratio'         => $cashRatio,
                'working_capital'    => $workingCap,
                'dso'                => $dso,
                'dpo'                => $dpo,
                'dio'                => $dio,
                'dio_reason'         => $dioReason,
                'ccc'                => $ccc,
            ],

            // Component balances (so the page can show how the ratios decompose)
            'components' => [
                'current_assets'       => $ca,
                'current_liabilities'  => $cl,
                'cash_and_equivalents' => $cash,
                'accounts_receivable'  => $ar,
                'inventory'            => $inventory,
                'accounts_payable'     => $ap,
                'revenue_ttm'          => $revenue,
                'cogs_ttm'             => $cogs,
                'cogs_ttm_alt_basis'   => $cogsAlt, // narrow-basis figure if service, broad if goods
            ],
        ];
    }

    /**
     * Pull balances for every balance-sheet account (classes 3, 4, 5) as of
     * the requested date and bucket them into AR / AP / Cash / Inventory /
     * other current.
     *
     * @return array{
     *   ar:float, ap:float, cash:float, inventory:float,
     *   current_assets:float, current_liabilities:float
     * }
     */
    private function fetchBalanceSheet(\DateTimeImmutable $asOf): array
    {
        $result = [
            'ar' => 0.0, 'ap' => 0.0, 'cash' => 0.0, 'inventory' => 0.0,
            'current_assets' => 0.0, 'current_liabilities' => 0.0,
        ];

        try {
            // 1) Pull the chart of accounts for classes 3, 4, 5 (every code
            // that begins with one of those digits). Single Odoo call.
            $accounts = $this->odoo->searchRead('account.account',
                [
                    '|', '|',
                    ['code', '=like', '3%'],
                    ['code', '=like', '4%'],
                    ['code', '=like', '5%'],
                ],
                ['id', 'code'],
                []
            );
            if (empty($accounts)) return $result;

            $codeByAccount = [];
            $accountIds    = [];
            foreach ($accounts as $a) {
                $aid  = (int)    $a['id'];
                $code = (string) ($a['code'] ?? '');
                if ($code === '') continue;
                $codeByAccount[$aid] = $code;
                $accountIds[] = $aid;
            }
            if (empty($accountIds)) return $result;

            // 2) Aggregate posted move-line balances per account, all dates
            // up to and including the as-of date. read_group keeps the
            // payload tiny.
            $rows = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain'  => [
                    ['parent_state', '=', 'posted'],
                    ['date', '<=', $asOf->format('Y-m-d')],
                    ['account_id', 'in', $accountIds],
                ],
                'fields'  => ['account_id', 'balance:sum'],
                'groupby' => ['account_id'],
                'lazy'    => false,
            ]);

            // 3) Bucket by code prefix.
            foreach ($rows as $r) {
                if (!isset($r['account_id']) || !is_array($r['account_id'])) continue;
                $aid  = (int)   $r['account_id'][0];
                $bal  = (float) ($r['balance'] ?? 0);
                $code = $codeByAccount[$aid] ?? '';
                if ($code === '') continue;

                // Class 3 — Inventory (debit-normal)
                if ($this->codeStartsWithAny($code, self::INVENTORY_PREFIXES)) {
                    if ($bal >= 0) {
                        $result['inventory']      += $bal;
                        $result['current_assets'] += $bal;
                    } else {
                        // Credit balance on inventory account is unusual but
                        // possible (returns / consignment) — treat as
                        // current liability so we don't double-count.
                        $result['current_liabilities'] += -$bal;
                    }
                    continue;
                }

                // Class 41 — AR (debit-normal)
                if ($this->codeStartsWithAny($code, self::AR_PREFIXES)) {
                    if ($bal >= 0) {
                        $result['ar']             += $bal;
                        $result['current_assets'] += $bal;
                    } else {
                        // Credit balance on AR = customer advance / deposit.
                        $result['current_liabilities'] += -$bal;
                    }
                    continue;
                }

                // Class 40 — AP (credit-normal → balance is negative when
                // money is owed to suppliers)
                if ($this->codeStartsWithAny($code, self::AP_PREFIXES)) {
                    if ($bal <= 0) {
                        $result['ap']                  += -$bal;
                        $result['current_liabilities'] += -$bal;
                    } else {
                        // Debit balance on AP = supplier advance / prepayment.
                        $result['current_assets'] += $bal;
                    }
                    continue;
                }

                // Class 5 — Cash & banks (debit-normal)
                if ($this->codeStartsWithAny($code, self::CASH_PREFIXES)) {
                    if ($bal >= 0) {
                        $result['cash']           += $bal;
                        $result['current_assets'] += $bal;
                    } else {
                        // Negative cash = bank overdraft (découvert).
                        $result['current_liabilities'] += -$bal;
                    }
                    continue;
                }

                // Class 4 other (42-49) — split by sign
                if ($this->codeStartsWithAny($code, self::CURRENT_TIERS_PREFIXES)) {
                    if ($bal >= 0) {
                        $result['current_assets'] += $bal;
                    } else {
                        $result['current_liabilities'] += -$bal;
                    }
                }
            }
        } catch (\Throwable $e) {
            // GL not reachable — return zeros; the controller will surface
            // an error banner via the empty-state check.
        }

        return $result;
    }

    /**
     * Trailing-12-month revenue and COGS ending at $asOf.
     *
     * Computes BOTH COGS bases in a single Odoo round-trip and returns the
     * one that matches $isService as the headline `cogs`, with the other
     * exposed as `cogs_alt` for transparency in the view (so the operator
     * can sanity-check the basis choice).
     *
     *   isService=false → cogs = Class 60 (Achats);
     *                     cogs_alt = full operating cost
     *   isService=true  → cogs = full operating cost (Class 6 ex 68/69);
     *                     cogs_alt = Class 60 narrow figure
     *
     * @return array{revenue:float, cogs:float, cogs_alt:float}
     */
    private function fetchTtm(\DateTimeImmutable $asOf, bool $isService): array
    {
        $from = $asOf->modify('-1 year +1 day');
        $result = ['revenue' => 0.0, 'cogs' => 0.0, 'cogs_alt' => 0.0];

        try {
            // Pull every class-6 and class-7 account in one shot; we'll
            // bucket each one into revenue / cogs_narrow / cogs_broad below.
            $accounts = $this->odoo->searchRead('account.account',
                [
                    '|',
                    ['code', '=like', '6%'],
                    ['code', '=like', '7%'],
                ],
                ['id', 'code'],
                []
            );
            if (empty($accounts)) return $result;

            $bucketsOf  = [];   // [aid => ['revenue'|'cogs_narrow'|'cogs_broad', ...]]
            $accountIds = [];
            foreach ($accounts as $a) {
                $aid  = (int)    $a['id'];
                $code = (string) ($a['code'] ?? '');
                if ($code === '') continue;
                $tags = [];

                // Revenue (Class 7 ex 79)
                if ($this->codeStartsWithAny($code, self::REVENUE_PREFIXES)
                 && !$this->codeStartsWithAny($code, self::REVENUE_EXCLUDE_PREFIXES)) {
                    $tags[] = 'revenue';
                }

                // Narrow COGS — Class 60 only
                if ($this->codeStartsWithAny($code, self::COGS_GOODS_PREFIXES)) {
                    $tags[] = 'cogs_narrow';
                }

                // Broad "service-COGS" — every class-6 account except
                // depreciation (68) and HAO/income tax (69)
                if ($this->codeStartsWithAny($code, self::COGS_SERVICE_PREFIXES)
                 && !$this->codeStartsWithAny($code, self::COGS_SERVICE_EXCLUDE_PREFIXES)) {
                    $tags[] = 'cogs_broad';
                }

                if (!empty($tags)) {
                    $bucketsOf[$aid] = $tags;
                    $accountIds[] = $aid;
                }
            }
            if (empty($accountIds)) return $result;

            $rows = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain'  => [
                    ['parent_state', '=', 'posted'],
                    ['date', '>=', $from->format('Y-m-d')],
                    ['date', '<=', $asOf->format('Y-m-d')],
                    ['account_id', 'in', $accountIds],
                ],
                'fields'  => ['account_id', 'balance:sum'],
                'groupby' => ['account_id'],
                'lazy'    => false,
            ]);

            $rev = 0.0; $cogsNarrow = 0.0; $cogsBroad = 0.0;
            foreach ($rows as $r) {
                if (!isset($r['account_id']) || !is_array($r['account_id'])) continue;
                $aid    = (int)   $r['account_id'][0];
                $bal    = (float) ($r['balance'] ?? 0);
                $tags   = $bucketsOf[$aid] ?? [];
                if (in_array('revenue',     $tags, true)) $rev        += $bal;
                if (in_array('cogs_narrow', $tags, true)) $cogsNarrow += $bal;
                if (in_array('cogs_broad',  $tags, true)) $cogsBroad  += $bal;
            }

            // Revenue is credit-positive → flip to magnitude.
            $result['revenue']  = -$rev;
            $result['cogs']     = $isService ? $cogsBroad  : $cogsNarrow;
            $result['cogs_alt'] = $isService ? $cogsNarrow : $cogsBroad;
        } catch (\Throwable $e) {
            // P&L not reachable — leave at zero.
        }

        return $result;
    }

    private function codeStartsWithAny(string $code, array $prefixes): bool
    {
        foreach ($prefixes as $p) {
            if (str_starts_with($code, $p)) return true;
        }
        return false;
    }

    private function detectCurrency(): string
    {
        try {
            $user = $this->odoo->read('res.users', [$this->odoo->authenticate()], ['company_id']);
            $cid  = isset($user[0]['company_id'][0]) ? (int) $user[0]['company_id'][0] : 1;
            $co   = $this->odoo->read('res.company', [$cid], ['currency_id']);
            $crid = isset($co[0]['currency_id'][0]) ? (int) $co[0]['currency_id'][0] : 0;
            if ($crid > 0) {
                $c = $this->odoo->read('res.currency', [$crid], ['name']);
                if (isset($c[0]['name'])) return (string) $c[0]['name'];
            }
        } catch (\Throwable $e) { /* fall through */ }
        return 'XAF';
    }

    /**
     * Liquidity-ratio time-series for the trend chart on Treasury.
     *
     * Returns N+1 data points: N prior month-ends + 1 "as of today"
     * snapshot. Each point carries Current, Quick, and Cash ratios
     * computed from cumulative GL balances at that moment.
     *
     *   Current = Current Assets / Current Liabilities
     *   Quick   = (Current Assets − Inventory) / Current Liabilities
     *   Cash    = (Class 52 + 57 debit) / Current Liabilities
     *
     * Asset / liability classification mirrors fetchBalanceSheet() —
     * see that method's class breakdown for the OHADA code rules.
     *
     * Performance — single big read_group across all time grouped by
     * (account_id, date:month). PHP walks results in chronological
     * order, accumulating per-account cumulative balance, snapshotting
     * at each target date, and computing the three ratios. Two Odoo
     * round-trips total regardless of trend length:
     *
     *   1) account.account searchRead — chart of accounts in classes 3/4/5
     *   2) account.move.line read_group — monthly debit:sum + credit:sum
     *
     * For the current-month "today" snapshot, the read_group's
     * current-month bucket already contains only month-to-date
     * activity (because we filter date <= today). Cumulative-through-
     * current-month therefore = balance as-of-today.
     *
     * Returns:
     *   [
     *     'series'    => [{label, date, current, quick, cash}, ...],
     *     'threshold' => 1.0,
     *     'currency'  => 'XAF',
     *     'as_of'     => '2026-05-07',
     *   ]
     */
    public function liquidityTrend(int $months = 12, ?\DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0, 0);

        $emptyResult = [
            'series'    => [],
            'threshold' => 1.0,
            'currency'  => $this->detectCurrency(),
            'as_of'     => $today->format('Y-m-d'),
        ];

        try {
            // 1) Chart of accounts — extended to classes 3, 4, 5, 6, 7 so
            //    one query covers BOTH the balance-sheet ratios (3/4/5)
            //    AND the CCC denominators (6/7 = COGS / revenue).
            $accounts = $this->odoo->searchRead('account.account', [
                '|', '|', '|', '|',
                ['code', '=like', '3%'],
                ['code', '=like', '4%'],
                ['code', '=like', '5%'],
                ['code', '=like', '6%'],
                ['code', '=like', '7%'],
            ], ['id', 'code'], []);
            if (empty($accounts)) return $emptyResult;

            // Per-account class-bucket lookup. Same code can land in
            // multiple buckets (e.g. service business: a class-6 account
            // is both cogs_broad AND not narrow). We tag every account
            // up front so the per-snapshot loops below are O(1).
            $codeByAccount = [];      // aid → 'YYYYY' code string
            $bucketsOf     = [];      // aid → ['bs','rev','cogs_n','cogs_b']
            foreach ($accounts as $a) {
                $aid  = (int)    ($a['id']   ?? 0);
                $code = (string) ($a['code'] ?? '');
                if ($aid <= 0 || $code === '') continue;
                $codeByAccount[$aid] = $code;
                $tags = [];

                // Balance-sheet flag — anything in class 3/4/5 contributes
                // to current assets / liabilities / inventory / cash via
                // classifyBalances() further down.
                if (str_starts_with($code, '3') || str_starts_with($code, '4') || str_starts_with($code, '5')) {
                    $tags[] = 'bs';
                }
                // Revenue (Class 7 ex 79)
                if ($this->codeStartsWithAny($code, self::REVENUE_PREFIXES)
                 && !$this->codeStartsWithAny($code, self::REVENUE_EXCLUDE_PREFIXES)) {
                    $tags[] = 'rev';
                }
                // Narrow COGS (Class 60 — goods purchases)
                if ($this->codeStartsWithAny($code, self::COGS_GOODS_PREFIXES)) {
                    $tags[] = 'cogs_n';
                }
                // Broad COGS (Class 6 ex 68/69 — service-business proxy)
                if ($this->codeStartsWithAny($code, self::COGS_SERVICE_PREFIXES)
                 && !$this->codeStartsWithAny($code, self::COGS_SERVICE_EXCLUDE_PREFIXES)) {
                    $tags[] = 'cogs_b';
                }
                $bucketsOf[$aid] = $tags;
            }
            if (empty($codeByAccount)) return $emptyResult;

            // 2) Monthly aggregates per account across all classes, all
            //    time up to today. Single read_group; PHP handles the
            //    per-snapshot ratio + CCC math in the loop below.
            $rows = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain'  => [
                    ['parent_state', '=',  'posted'],
                    ['date',         '<=', $today->format('Y-m-d')],
                    ['account_id',   'in', array_keys($codeByAccount)],
                ],
                'fields'  => ['debit:sum', 'credit:sum'],
                'groupby' => ['account_id', 'date:month'],
                'lazy'    => false,
            ]);
            if (empty($rows)) return $emptyResult;

            // 3) Build time-indexed maps. Two parallel structures:
            //    • $bsDeltas[monthKey][aid]     — balance-sheet account deltas (cumulative)
            //    • $revDeltas[monthKey]         — revenue scalar (sign-flipped: revenue is credit-normal)
            //    • $cogsNDeltas[monthKey]       — narrow COGS scalar (class 60)
            //    • $cogsBDeltas[monthKey]       — broad  COGS scalar (class 6 ex 68/69)
            // Revenue/COGS are stored as monthly SCALARS (not per-account)
            // because the TTM aggregation only needs totals, no
            // per-account breakdown for those classes.
            $bsDeltas    = [];
            $revDeltas   = [];
            $cogsNDeltas = [];
            $cogsBDeltas = [];
            $monthKeys   = [];

            foreach ($rows as $r) {
                $aid = is_array($r['account_id'] ?? null) ? (int) $r['account_id'][0] : null;
                if ($aid === null || !isset($codeByAccount[$aid])) continue;

                $monthKey = $this->extractMonthKey($r);
                if ($monthKey === null) continue;

                $delta = (float) ($r['debit'] ?? 0) - (float) ($r['credit'] ?? 0);
                $monthKeys[$monthKey] = true;
                $tags = $bucketsOf[$aid] ?? [];

                if (in_array('bs', $tags, true)) {
                    $bsDeltas[$monthKey][$aid] = ($bsDeltas[$monthKey][$aid] ?? 0.0) + $delta;
                }
                // Revenue lines are credit-normal — flip sign so monthly
                // revenue is positive. (debit − credit) is negative for
                // a sale, so −delta = positive revenue.
                if (in_array('rev', $tags, true)) {
                    $revDeltas[$monthKey] = ($revDeltas[$monthKey] ?? 0.0) + (-$delta);
                }
                // COGS lines are debit-normal — delta is positive.
                if (in_array('cogs_n', $tags, true)) {
                    $cogsNDeltas[$monthKey] = ($cogsNDeltas[$monthKey] ?? 0.0) + $delta;
                }
                if (in_array('cogs_b', $tags, true)) {
                    $cogsBDeltas[$monthKey] = ($cogsBDeltas[$monthKey] ?? 0.0) + $delta;
                }
            }
            if (empty($bsDeltas) && empty($revDeltas) && empty($cogsNDeltas)) return $emptyResult;

            // ── Earliest month with revenue / COGS activity ──────────
            // Used below as a TTM-completeness gate. A snapshot whose
            // trailing-12-month window starts BEFORE this month doesn't
            // have a full year of P&L history to divide by — its DSO/
            // DIO/DPO would be inflated by 12 ÷ (months populated). We
            // mark CCC as null for those points so the chart skips them
            // entirely instead of plotting phantom 60,000-day figures.
            // Bug surfaced 2026-05-08 on Kissywears tenant where data
            // begins Nov 24 → first valid CCC point is Nov 25.
            $activityMonths = array_unique(array_merge(
                array_keys($revDeltas),
                array_keys($cogsNDeltas),
                array_keys($cogsBDeltas)
            ));
            sort($activityMonths);
            $earliestActivityMonth = $activityMonths === [] ? null : $activityMonths[0];

            // 4) Sort month keys chronologically. Build a continuous
            // sequence from earliest data through current month so we
            // don't skip months that had no activity (cumulative carries
            // forward unchanged).
            $allKeys = array_keys($monthKeys);
            sort($allKeys);
            $earliest = $allKeys[0];
            $latest   = $today->format('Y-m');
            $continuous = [];
            $cursor = new \DateTimeImmutable($earliest . '-01');
            $end    = new \DateTimeImmutable($latest . '-01');
            while ($cursor <= $end) {
                $continuous[] = $cursor->format('Y-m');
                $cursor = $cursor->modify('+1 month');
            }

            // 5) Walk continuous month sequence, accumulate per-account
            // cumulative balance, snapshot at each target month-end.
            // Target dates: $months prior month-ends + today.
            $targetMonthKeys = [];
            for ($i = $months; $i >= 1; $i--) {
                $targetMonthKeys[] = $today->modify("first day of -{$i} month")->format('Y-m');
            }
            $targetMonthKeys[] = $today->format('Y-m');   // last point = today's month
            $targetSet = array_flip($targetMonthKeys);

            $cumulative = [];                              // account_id → running BS balance
            $series     = [];

            foreach ($continuous as $mk) {
                if (isset($bsDeltas[$mk])) {
                    foreach ($bsDeltas[$mk] as $aid => $delta) {
                        $cumulative[$aid] = ($cumulative[$aid] ?? 0.0) + $delta;
                    }
                }
                if (!isset($targetSet[$mk])) continue;

                // ── Balance-sheet snapshot → liquidity ratios ──────────
                $snap = $this->classifyBalances($cumulative, $codeByAccount);
                $cl   = $snap['current_liabilities'];
                $ca   = $snap['current_assets'];
                $inv  = $snap['inventory'];
                $cash = $snap['cash_5257'];
                $ar   = $snap['ar'];
                $ap   = $snap['ap'];

                $current = $cl > 0 ? $ca / $cl                   : null;
                $quick   = $cl > 0 ? max(0.0, $ca - $inv) / $cl  : null;
                $cashR   = $cl > 0 ? $cash / $cl                 : null;

                // ── TTM revenue + COGS for CCC denominators ─────────────
                // Sum the trailing 12 months of revenue/COGS deltas
                // ending at $mk. Service-business detection mirrors
                // the as-of-now logic in fetchBalanceSheet/fetchTtm:
                // use narrow COGS (class 60) when inventory is non-trivial,
                // broad COGS (class 6 ex 68/69) when it's effectively zero.
                $ttmFromKey = (new \DateTimeImmutable($mk . '-01'))->modify('-11 months')->format('Y-m');

                // ── TTM-completeness gate ────────────────────────────────
                // If the trailing-12-month window starts before the
                // earliest month with any P&L activity, we don't have a
                // full year of denominators — CCC is mathematically
                // unreliable here. Mark all four cycle metrics null so
                // the view filters this point out instead of plotting it.
                $ttmComplete = ($earliestActivityMonth !== null)
                            && ($ttmFromKey >= $earliestActivityMonth);

                $dso = $dio = $dpo = $cccVal = null;
                $isService = $inv < self::INVENTORY_ZERO_THRESHOLD;

                if ($ttmComplete) {
                    $ttmRev = 0.0; $ttmCogsN = 0.0; $ttmCogsB = 0.0;
                    $cur = new \DateTimeImmutable($ttmFromKey . '-01');
                    $stop = new \DateTimeImmutable($mk . '-01');
                    while ($cur <= $stop) {
                        $k = $cur->format('Y-m');
                        $ttmRev   += $revDeltas[$k]   ?? 0.0;
                        $ttmCogsN += $cogsNDeltas[$k] ?? 0.0;
                        $ttmCogsB += $cogsBDeltas[$k] ?? 0.0;
                        $cur = $cur->modify('+1 month');
                    }
                    $ttmCogs    = $isService ? $ttmCogsB : $ttmCogsN;
                    $revPerDay  = $ttmRev  > 0 ? $ttmRev  / 365.0 : null;
                    $cogsPerDay = $ttmCogs > 0 ? $ttmCogs / 365.0 : null;

                    $dso = $revPerDay  !== null && $ar  > 0 ? round($ar  / $revPerDay,  1) : null;
                    $dio = (!$isService && $cogsPerDay !== null && $inv > 0)
                         ? round($inv / $cogsPerDay, 1) : null;
                    $dpo = $cogsPerDay !== null && $ap  > 0 ? round($ap  / $cogsPerDay, 1) : null;

                    // CCC = DSO + DIO − DPO. For service businesses (no
                    // inventory), DIO is null and CCC simplifies to DSO − DPO.
                    if ($dso !== null && $dpo !== null) {
                        $cccVal = round($dso - $dpo + ($dio ?? 0.0), 1);
                    }
                }

                // Label: "Mon YY" — matches the screenshot the user shared.
                $dt      = new \DateTimeImmutable($mk . '-01');
                $isToday = ($mk === $today->format('Y-m'));
                $date    = $isToday ? $today->format('Y-m-d') : $dt->modify('last day of this month')->format('Y-m-d');
                $label   = $dt->format('M y');

                $series[] = [
                    'label'   => $label,
                    'date'    => $date,
                    'current' => $current === null ? null : round($current, 4),
                    'quick'   => $quick   === null ? null : round($quick,   4),
                    'cash'    => $cashR   === null ? null : round($cashR,   4),
                    // CCC fields — added 2026-05-08 for the second chart
                    'dso'     => $dso,
                    'dio'     => $dio,
                    'dpo'     => $dpo,
                    'ccc'     => $cccVal,
                    'is_service' => $isService,
                ];
            }

            return [
                'series'    => $series,
                'threshold' => 1.0,
                'currency'  => $this->detectCurrency(),
                'as_of'     => $today->format('Y-m-d'),
            ];
        } catch (\Throwable $e) {
            return $emptyResult;
        }
    }

    /**
     * Read a monthKey ('YYYY-MM') from an Odoo read_group row whose
     * groupby includes 'date:month'. Odoo returns the bucket label
     * either as a string in `date:month` (e.g. "March 2026") or as a
     * structured `__range` array with `from`/`to` ISO dates. Try the
     * range first since it's locale-independent.
     */
    private function extractMonthKey(array $row): ?string
    {
        if (isset($row['__range']['date:month']['from'])) {
            return substr((string) $row['__range']['date:month']['from'], 0, 7);
        }
        // Locale-dependent label. Parse via DateTime — handles "March 2026",
        // "Mar 2026", "mars 2026" (French), etc.
        $label = (string) ($row['date:month'] ?? $row['date_month'] ?? '');
        if ($label === '') return null;
        try {
            return (new \DateTimeImmutable($label))->format('Y-m');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Apply OHADA classification rules to a per-account balance map
     * and return aggregated balance-sheet buckets. Mirrors the
     * single-snapshot logic in fetchBalanceSheet() so trend points
     * align exactly with the live "as of now" tile.
     *
     * Adds a `cash_5257` bucket (debit-positive sum on classes 52 + 57
     * only) for the strict-cash-ratio numerator the user asked for.
     */
    private function classifyBalances(array $cumulative, array $codeByAccount): array
    {
        // Adds `ar` and `ap` buckets (in addition to the four others)
        // so the CCC computation in liquidityTrend() can divide by them
        // without re-walking the cumulative map. The two existing
        // buckets (current_assets / current_liabilities) keep their
        // semantics unchanged.
        $r = [
            'current_assets'      => 0.0,
            'current_liabilities' => 0.0,
            'inventory'           => 0.0,
            'cash_5257'           => 0.0,
            'ar'                  => 0.0,
            'ap'                  => 0.0,
        ];
        foreach ($cumulative as $aid => $bal) {
            $code = $codeByAccount[$aid] ?? '';
            if ($code === '') continue;

            // Class 3 — Inventory (debit-normal)
            if ($this->codeStartsWithAny($code, self::INVENTORY_PREFIXES)) {
                if ($bal >= 0) {
                    $r['inventory']      += $bal;
                    $r['current_assets'] += $bal;
                } else {
                    $r['current_liabilities'] += -$bal;
                }
                continue;
            }
            // Class 41 — AR (debit-normal). Track the asset value so
            // the DSO calculation in liquidityTrend() has it directly.
            if ($this->codeStartsWithAny($code, self::AR_PREFIXES)) {
                if ($bal >= 0) {
                    $r['ar']             += $bal;
                    $r['current_assets'] += $bal;
                } else {
                    $r['current_liabilities'] += -$bal;
                }
                continue;
            }
            // Class 40 — AP (credit-normal → balance is negative when
            // money is owed to suppliers). Track the liability value.
            if ($this->codeStartsWithAny($code, self::AP_PREFIXES)) {
                if ($bal <= 0) {
                    $r['ap']                  += -$bal;
                    $r['current_liabilities'] += -$bal;
                } else {
                    $r['current_assets']      += $bal;
                }
                continue;
            }
            // Class 5 — Cash & banks (and cash_5257 strict bucket)
            if ($this->codeStartsWithAny($code, self::CASH_PREFIXES)) {
                $isStrictCash = str_starts_with($code, '52') || str_starts_with($code, '57');
                if ($bal >= 0) {
                    $r['current_assets'] += $bal;
                    if ($isStrictCash) $r['cash_5257'] += $bal;
                } else {
                    $r['current_liabilities'] += -$bal;
                }
                continue;
            }
            // Class 4 other (42-49) — split by sign
            if ($this->codeStartsWithAny($code, self::CURRENT_TIERS_PREFIXES)) {
                if ($bal >= 0) $r['current_assets']      += $bal;
                else           $r['current_liabilities'] += -$bal;
            }
        }
        return $r;
    }
}
