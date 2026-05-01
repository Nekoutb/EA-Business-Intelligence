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
}
