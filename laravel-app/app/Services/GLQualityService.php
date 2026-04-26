<?php

namespace App\Services;

use App\Odoo\OdooClient;
use App\Odoo\OdooFactory;
use DateTimeImmutable;

/**
 * GLQualityService — sub-ledger vs trial balance reconciliations.
 *
 * Five reconciliations, all at the same as-of date (today, YTD scope),
 * all reading from account.move.line on the same companyId.
 *
 * 1. Accounts Receivable (debit-natural, account codes 41X)
 *    sub-ledger    = Σ balance for 41X accounts WHERE partner_id IS NOT NULL
 *    trial balance = same query without the partner_id filter
 *
 * 2. Accounts Payable (credit-natural, account codes 40X)
 *    Same logic as AR; abs() applied on both sides so the user sees
 *    positive figures matching Odoo's Aged Payable report.
 *
 * 3. Inventory
 *    stock side    = stock.quant × product.standard_price (live snapshot)
 *    trial balance = Σ balance for 3X accounts excluding 39X impairment
 *
 * 4. Fixed Assets — Original Value (gross cost basis)
 *    sub-ledger    = Σ account.asset.original_value WHERE state='open'
 *    trial balance = Σ balance for 2X accounts EXCLUDING 25/26/27/28/29
 *                    (excludes accumulated depreciation and impairments
 *                    so we land on gross cost only).
 *
 * 5. Fixed Assets — Net Book Value
 *    sub-ledger    = Σ account.asset.book_value WHERE state='open'
 *    trial balance = Σ balance for 2X accounts EXCLUDING 25/26/27/29
 *                    (INCLUDES 28 — accumulated depreciation is credit-
 *                    natural, so adding its negative balance subtracts
 *                    depreciation from gross cost, giving NBV).
 *
 * All five use the same accuracy formula:
 *   accuracy_% = 100 − min(100, |variance| / max(|left|, |right|, 0.01) × 100)
 */
class GLQualityService
{
    /** Cached resolved company id. */
    private ?int $companyId = null;

    /** Cached chart-of-accounts subsets. */
    private ?array $receivableAccountsCache = null;
    private ?array $payableAccountsCache    = null;
    private ?array $inventoryAccountsCache  = null;
    private ?array $all2xAccountsCache      = null;
    private array  $fixedAssetAccountsCache = []; // keyed by exclusion-list string
    private ?array $fixedAssetSubLedgerCache = null;
    private ?array $internalLocationIds     = null;

    public function __construct(private OdooClient $odoo) {}

    /**
     * Run both reconciliations for the supplied "today".
     */
    public function analyze(DateTimeImmutable $today): array
    {
        $errors = [];
        $asOfDate = $today;

        $receivables   = $this->safe(fn() => $this->reconcileReceivables($asOfDate),       $errors, 'receivables')      ?? $this->emptyReconciliation();
        $payables      = $this->safe(fn() => $this->reconcilePayables($asOfDate),          $errors, 'payables')         ?? $this->emptyReconciliation();
        $inventory     = $this->safe(fn() => $this->reconcileInventory($asOfDate),         $errors, 'inventory')        ?? $this->emptyReconciliation();
        $faOriginal    = $this->safe(fn() => $this->reconcileFixedAssetsOriginal($asOfDate), $errors, 'fixed_assets_original') ?? $this->emptyReconciliation();
        $faBook        = $this->safe(fn() => $this->reconcileFixedAssetsBook($asOfDate),    $errors, 'fixed_assets_book')      ?? $this->emptyReconciliation();
        $currency      = $this->safe(fn() => $this->detectCurrency(),                      $errors, 'currency')         ?? 'USD';

        // Build exclusion rules for the description-quality audit:
        // skip an account range from text-quality checks when its
        // reconciliation is already perfectly matched.
        $exclusions = $this->buildDescriptionExclusions($receivables, $payables, $inventory);

        $descriptionQuality = $this->safe(
            fn() => (new DescriptionQualityService($this->odoo))->analyze($today, $exclusions),
            $errors,
            'description_quality'
        ) ?? null;

        $manualEntryReview = $this->safe(
            fn() => (new ManualEntryReviewService($this->odoo))->analyze($today),
            $errors,
            'manual_entry_review'
        ) ?? null;

        return [
            'receivables'           => $receivables,
            'payables'              => $payables,
            'inventory'             => $inventory,
            'fixed_assets_original' => $faOriginal,
            'fixed_assets_book'     => $faBook,
            'description_quality'   => $descriptionQuality,
            'manual_entry_review'   => $manualEntryReview,
            'currency'              => $currency,
            'as_of'                 => $asOfDate->format('Y-m-d'),
            'period_label'          => 'YTD ' . $today->format('Y'),
            'error'                 => empty($errors) ? null : implode(' | ', $errors),
        ];
    }

    /**
     * Skip lines on AR / AP / Inventory accounts from the description audit
     * when the corresponding reconciliation is at 100% — those accounts are
     * already proven correct by the sub-ledger ↔ trial-balance match.
     * If a reconciliation is below 100%, those lines stay in scope so the
     * description checks can help triage where the variance hides.
     */
    private function buildDescriptionExclusions(array $ar, array $ap, array $inv): array
    {
        $threshold = 99.99; // floating-point tolerance for "100%"
        $exclusions = [];
        if ((float)($ar['accuracy_pct']  ?? 0) >= $threshold) {
            $exclusions[] = [
                'starts_with' => '41',
                'label'       => '41XX (AR fully reconciled)',
            ];
        }
        if ((float)($ap['accuracy_pct']  ?? 0) >= $threshold) {
            $exclusions[] = [
                'starts_with' => '401',
                'label'       => '401XX (AP fully reconciled)',
            ];
        }
        if ((float)($inv['accuracy_pct'] ?? 0) >= $threshold) {
            $exclusions[] = [
                'starts_with'        => '3',
                'except_starts_with' => ['39'],
                'label'              => '3X excl. 39 (Inventory fully reconciled)',
            ];
        }
        return $exclusions;
    }

    // ─────────────────────────────────────────────────────────────────
    // Accounts Receivable reconciliation
    // ─────────────────────────────────────────────────────────────────

    private function reconcileReceivables(DateTimeImmutable $asOfDate): array
    {
        $accounts = $this->findReceivableAccounts();
        if (empty($accounts)) {
            return $this->emptyReconciliation('No 41X receivable accounts found in the chart of accounts.');
        }
        $accountIds = array_column($accounts, 'id');

        // Trial balance side: every posting on a 41X account, no partner filter.
        $trialBalance = $this->sumGLBalance($accountIds, $asOfDate, withPartnerOnly: false);

        // Sub-ledger side: postings WITH a partner (matches the Aged Receivable
        // report — every customer line is attached to a partner).
        $subLedger    = $this->sumGLBalance($accountIds, $asOfDate, withPartnerOnly: true);

        $byAccount = $this->balancesByAccount($accountIds, $accounts, $asOfDate);

        return [
            'left_label'      => 'Sub-ledger (Aged Receivable)',
            'left_value'      => $subLedger,
            'right_label'     => 'Trial balance (41X accounts)',
            'right_value'     => $trialBalance,
            'variance'        => round($trialBalance - $subLedger, 2),
            'accuracy_pct'    => $this->accuracyPercent($subLedger, $trialBalance),
            'subtitle'        => 'Sub-ledger vs trial balance · accounts 41% · variance flags receivable postings without a partner.',
            'by_account'      => $byAccount,
            'breakdown_total' => $trialBalance,  // 41X are debit-natural; signed = abs
        ];
    }

    /**
     * Sum balances on a list of accounts up to a date.
     * If $withPartnerOnly is true, only postings where partner_id is set are counted.
     */
    private function sumGLBalance(array $accountIds, DateTimeImmutable $asOfDate, bool $withPartnerOnly = false): float
    {
        $domain = [
            ['parent_state', '=', 'posted'],
            ['account_id',   'in', $accountIds],
            ['company_id',   '=', $this->getCompanyId()],
            ['date',         '<=', $asOfDate->format('Y-m-d')],
        ];
        if ($withPartnerOnly) {
            $domain[] = ['partner_id', '!=', false];
        }

        $total = 0.0;
        try {
            $g = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain'  => $domain,
                'fields'  => ['balance:sum'],
                'groupby' => [],
            ]);
            $total = (float)($g[0]['balance'] ?? 0);
        } catch (\Throwable $e) {
            // Fallback to client-side aggregation if read_group is rejected
            $rows = $this->odoo->searchRead('account.move.line', $domain, ['balance'], ['limit' => 200000]);
            foreach ($rows as $r) {
                $total += (float)($r['balance'] ?? 0);
            }
        }
        return round($total, 2);
    }

    // ─────────────────────────────────────────────────────────────────
    // Accounts Payable reconciliation
    // ─────────────────────────────────────────────────────────────────

    private function reconcilePayables(DateTimeImmutable $asOfDate): array
    {
        $accounts = $this->findPayableAccounts();
        if (empty($accounts)) {
            return $this->emptyReconciliation('No 40X payable accounts found in the chart of accounts.');
        }
        $accountIds = array_column($accounts, 'id');

        // Both totals are negative on the trial balance because 40X are
        // credit-natural liabilities. We take abs() before comparison so
        // the user sees the amount owed as a positive figure (matching
        // Odoo's Aged Payable report header).
        $trialBalanceSigned = $this->sumGLBalance($accountIds, $asOfDate, withPartnerOnly: false);
        $subLedgerSigned    = $this->sumGLBalance($accountIds, $asOfDate, withPartnerOnly: true);

        $trialBalanceAbs = abs($trialBalanceSigned);
        $subLedgerAbs    = abs($subLedgerSigned);

        // Per-account breakdown: keep signed values so the user can cross-
        // reference each row with Odoo's Trial Balance report (which shows
        // 73,542 for 401100 and -100,000 for 401101 in the screenshot).
        $byAccount = $this->balancesByAccount($accountIds, $accounts, $asOfDate);

        return [
            'left_label'      => 'Sub-ledger (Aged Payable)',
            'left_value'      => $subLedgerAbs,
            'right_label'     => 'Trial balance (40X accounts)',
            'right_value'     => $trialBalanceAbs,
            'variance'        => round($trialBalanceAbs - $subLedgerAbs, 2),
            'accuracy_pct'    => $this->accuracyPercent($subLedgerAbs, $trialBalanceAbs),
            'subtitle'        => 'Sub-ledger vs trial balance · accounts 40% · totals shown as absolute values (40X are credit-natural liabilities, signed negative on the trial balance).',
            'by_account'      => $byAccount,
            'breakdown_total' => round($trialBalanceSigned, 2),  // signed, matches sum of signed rows
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Inventory reconciliation
    // ─────────────────────────────────────────────────────────────────

    private function reconcileInventory(DateTimeImmutable $asOfDate): array
    {
        // Stock side: live stock.quant × standard_price
        $stockValue = $this->getStockValue();

        // Trial balance side: 3X accounts (excluding 39X impairment)
        $accounts = $this->findInventoryAccounts();
        if (empty($accounts)) {
            return [
                'left_label'   => 'Stock ledger (inventory)',
                'left_value'   => $stockValue,
                'right_label'  => 'Trial balance (3X accounts, excl. 39%)',
                'right_value'  => 0.0,
                'variance'     => -$stockValue,
                'accuracy_pct' => $this->accuracyPercent($stockValue, 0.0),
                'subtitle'     => 'No 3X inventory accounts found in the chart of accounts.',
                'by_account'   => [],
            ];
        }
        $accountIds = array_column($accounts, 'id');

        $trialBalance = $this->sumGLBalance($accountIds, $asOfDate);
        $byAccount    = $this->balancesByAccount($accountIds, $accounts, $asOfDate);

        return [
            'left_label'      => 'Stock ledger (inventory)',
            'left_value'      => $stockValue,
            'right_label'     => 'Trial balance (3X accounts, excl. 39%)',
            'right_value'     => $trialBalance,
            'variance'        => round($trialBalance - $stockValue, 2),
            'accuracy_pct'    => $this->accuracyPercent($stockValue, $trialBalance),
            'subtitle'        => 'Stock ledger vs trial balance · accounts 3% (excl. 39% impairment).',
            'by_account'      => $byAccount,
            'breakdown_total' => $trialBalance,  // 3X are debit-natural; signed = abs
        ];
    }

    /**
     * Live stock value: sum stock.quant.quantity × product.standard_price,
     * filtered to internal locations.
     */
    private function getStockValue(): float
    {
        $companyId = $this->getCompanyId();

        $domain = [['company_id', '=', $companyId]];
        $locIds = $this->getInternalLocationIds();
        if (!empty($locIds)) {
            $domain[] = ['location_id', 'in', $locIds];
        }

        $quants = $this->odoo->searchRead(
            'stock.quant',
            $domain,
            ['product_id', 'quantity'],
            ['limit' => 10000]
        );

        // Aggregate qty by product
        $qtyByProduct = [];
        foreach ($quants as $q) {
            $pid = is_array($q['product_id'] ?? null) ? ($q['product_id'][0] ?? null) : null;
            if (!$pid) continue;
            $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + (float)($q['quantity'] ?? 0);
        }

        if (empty($qtyByProduct)) {
            return 0.0;
        }

        // Batch-read product costs
        $total = 0.0;
        foreach (array_chunk(array_keys($qtyByProduct), 500) as $chunk) {
            $rows = $this->odoo->read('product.product', $chunk, ['standard_price']);
            foreach ($rows as $r) {
                $cost = (float)($r['standard_price'] ?? 0);
                $qty  = (float)($qtyByProduct[$r['id']] ?? 0);
                $total += $qty * $cost;
            }
        }
        return round($total, 2);
    }

    // ─────────────────────────────────────────────────────────────────
    // Fixed Assets reconciliations (Original Value + Net Book Value)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Sub-ledger fetch — single round-trip on account.asset, results
     * cached so both reconciliations share the read.
     */
    private function getFixedAssetSubLedger(): array
    {
        if ($this->fixedAssetSubLedgerCache !== null) {
            return $this->fixedAssetSubLedgerCache;
        }

        // Try the modern model name first; fall back to the legacy one.
        $modelTried = ['account.asset', 'account.asset.asset'];
        $assets = null;
        $modelUsed = null;
        $lastError = null;

        foreach ($modelTried as $model) {
            try {
                $assets = $this->odoo->searchRead(
                    $model,
                    [
                        ['company_id', '=', $this->getCompanyId()],
                        ['state',      '=', 'open'],
                    ],
                    ['original_value', 'book_value', 'value_residual'],
                    ['limit' => 10000]
                );
                $modelUsed = $model;
                break;
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        if ($assets === null) {
            return $this->fixedAssetSubLedgerCache = [
                'available'      => false,
                'count'          => 0,
                'original_value' => 0.0,
                'book_value'     => 0.0,
                'error'          => $lastError ?: 'account.asset model not found',
            ];
        }

        $original = 0.0;
        $book     = 0.0;
        foreach ($assets as $a) {
            $original += (float)($a['original_value'] ?? 0);
            // Prefer book_value; fall back to value_residual on older Odoo
            $bv = $a['book_value'] ?? $a['value_residual'] ?? 0;
            $book += (float)$bv;
        }

        return $this->fixedAssetSubLedgerCache = [
            'available'      => true,
            'count'          => count($assets),
            'original_value' => round($original, 2),
            'book_value'     => round($book, 2),
            'model'          => $modelUsed,
        ];
    }

    private function reconcileFixedAssetsOriginal(DateTimeImmutable $asOfDate): array
    {
        $sub = $this->getFixedAssetSubLedger();
        if (!($sub['available'] ?? false)) {
            return $this->emptyReconciliation('account.asset model not available — fixed assets sub-ledger requires the Odoo Accounting Assets module.');
        }
        $subLedger = (float)$sub['original_value'];

        // Trial balance: 2X accounts EXCLUDING 25, 26, 27, 28, 29 → gross cost basis only.
        $accounts = $this->findFixedAssetAccounts(['25', '26', '27', '28', '29']);
        if (empty($accounts)) {
            return $this->emptyReconciliation('No matching 2X fixed-asset accounts found in the chart of accounts.');
        }
        $accountIds = array_column($accounts, 'id');

        $trialBalance = $this->sumGLBalance($accountIds, $asOfDate, withPartnerOnly: false);
        $byAccount    = $this->balancesByAccount($accountIds, $accounts, $asOfDate);

        return [
            'left_label'      => 'Sub-ledger (Assets table — Original Value)',
            'left_value'      => $subLedger,
            'right_label'     => 'Trial balance (2X, excl. 25/26/27/28/29)',
            'right_value'     => $trialBalance,
            'variance'        => round($trialBalance - $subLedger, 2),
            'accuracy_pct'    => $this->accuracyPercent($subLedger, $trialBalance),
            'subtitle'        => 'Original asset value · sub-ledger from Accounting → Assets · trial balance excludes accumulated depreciation (28) and impairments (29) so it represents gross cost only.',
            'by_account'      => $byAccount,
            'breakdown_total' => $trialBalance,
        ];
    }

    private function reconcileFixedAssetsBook(DateTimeImmutable $asOfDate): array
    {
        $sub = $this->getFixedAssetSubLedger();
        if (!($sub['available'] ?? false)) {
            return $this->emptyReconciliation('account.asset model not available — fixed assets sub-ledger requires the Odoo Accounting Assets module.');
        }
        $subLedger = (float)$sub['book_value'];

        // Trial balance: 2X EXCLUDING 25/26/27/29 — INCLUDES 28 (accumulated
        // depreciation, credit-natural, negative). Adding 28 to gross 2X
        // gives NBV: gross_cost + (− accum_depreciation) = NBV.
        $accounts = $this->findFixedAssetAccounts(['25', '26', '27', '29']);
        if (empty($accounts)) {
            return $this->emptyReconciliation('No matching 2X fixed-asset accounts found in the chart of accounts.');
        }
        $accountIds = array_column($accounts, 'id');

        $trialBalance = $this->sumGLBalance($accountIds, $asOfDate, withPartnerOnly: false);
        $byAccount    = $this->balancesByAccount($accountIds, $accounts, $asOfDate);

        return [
            'left_label'      => 'Sub-ledger (Assets table — Net Book Value)',
            'left_value'      => $subLedger,
            'right_label'     => 'Trial balance (2X, excl. 25/26/27/29)',
            'right_value'     => $trialBalance,
            'variance'        => round($trialBalance - $subLedger, 2),
            'accuracy_pct'    => $this->accuracyPercent($subLedger, $trialBalance),
            'subtitle'        => 'Net book value · sub-ledger from Accounting → Assets · trial balance includes 28 (accumulated depreciation, credit-natural) so the total reflects gross cost net of depreciation.',
            'by_account'      => $byAccount,
            'breakdown_total' => $trialBalance,
        ];
    }

    /**
     * Resolve 2X chart-of-accounts entries excluding the prefixes given.
     * Caches the underlying 2X fetch so each variant is cheap.
     */
    private function findFixedAssetAccounts(array $excludePrefixes): array
    {
        $key = implode(',', $excludePrefixes);
        if (isset($this->fixedAssetAccountsCache[$key])) {
            return $this->fixedAssetAccountsCache[$key];
        }

        if ($this->all2xAccountsCache === null) {
            $rows = $this->odoo->searchRead(
                'account.account',
                [['code', '=like', '2%']],
                ['code', 'name'],
                ['limit' => 1000, 'order' => 'code asc']
            );
            $this->all2xAccountsCache = array_values(array_filter(
                $rows,
                fn($a) => str_starts_with((string)($a['code'] ?? ''), '2')
            ));
        }

        $filtered = array_values(array_filter($this->all2xAccountsCache, function ($a) use ($excludePrefixes) {
            $code = (string)($a['code'] ?? '');
            foreach ($excludePrefixes as $excl) {
                if (str_starts_with($code, $excl)) return false;
            }
            return true;
        }));

        return $this->fixedAssetAccountsCache[$key] = $filtered;
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Per-account breakdown for the disclosure table inside each box.
     * Returns [{code, name, balance}, ...] sorted by code.
     */
    private function balancesByAccount(array $accountIds, array $accounts, DateTimeImmutable $asOfDate): array
    {
        $accountMap = [];
        foreach ($accounts as $a) {
            $accountMap[$a['id']] = [
                'code'    => $a['code'] ?? '',
                'name'    => $a['name'] ?? '',
                'balance' => 0.0,
            ];
        }

        $domain = [
            ['parent_state', '=', 'posted'],
            ['account_id',   'in', $accountIds],
            ['company_id',   '=', $this->getCompanyId()],
            ['date',         '<=', $asOfDate->format('Y-m-d')],
        ];

        try {
            $groups = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain'  => $domain,
                'fields'  => ['account_id', 'balance:sum'],
                'groupby' => ['account_id'],
            ]);
            foreach ($groups as $g) {
                $aid = is_array($g['account_id'] ?? null) ? ($g['account_id'][0] ?? null) : null;
                if ($aid && isset($accountMap[$aid])) {
                    $accountMap[$aid]['balance'] = round((float)($g['balance'] ?? 0), 2);
                }
            }
        } catch (\Throwable $e) {
            $rows = $this->odoo->searchRead('account.move.line', $domain, ['account_id', 'balance'], ['limit' => 200000]);
            foreach ($rows as $r) {
                $aid = is_array($r['account_id'] ?? null) ? ($r['account_id'][0] ?? null) : null;
                if ($aid && isset($accountMap[$aid])) {
                    $accountMap[$aid]['balance'] += (float)($r['balance'] ?? 0);
                }
            }
            foreach ($accountMap as &$row) {
                $row['balance'] = round($row['balance'], 2);
            }
            unset($row);
        }

        // Drop zero-balance accounts; sort by code
        $list = array_values(array_filter($accountMap, fn($a) => abs($a['balance']) > 0.005));
        usort($list, fn($a, $b) => strcmp($a['code'], $b['code']));
        return $list;
    }

    /** OHADA receivable accounts: code starts with 41. */
    private function findReceivableAccounts(): array
    {
        if ($this->receivableAccountsCache !== null) {
            return $this->receivableAccountsCache;
        }
        $accounts = $this->odoo->searchRead(
            'account.account',
            [['code', '=like', '41%']],
            ['code', 'name'],
            ['limit' => 1000, 'order' => 'code asc']
        );
        $accounts = array_values(array_filter($accounts, fn($a) => str_starts_with((string)($a['code'] ?? ''), '41')));
        return $this->receivableAccountsCache = $accounts;
    }

    /** OHADA payable accounts: code starts with 40 (suppliers, advances, etc.). */
    private function findPayableAccounts(): array
    {
        if ($this->payableAccountsCache !== null) {
            return $this->payableAccountsCache;
        }
        $accounts = $this->odoo->searchRead(
            'account.account',
            [['code', '=like', '40%']],
            ['code', 'name'],
            ['limit' => 1000, 'order' => 'code asc']
        );
        $accounts = array_values(array_filter($accounts, fn($a) => str_starts_with((string)($a['code'] ?? ''), '40')));
        return $this->payableAccountsCache = $accounts;
    }

    /** OHADA inventory accounts: code starts with 3, but not 39. */
    private function findInventoryAccounts(): array
    {
        if ($this->inventoryAccountsCache !== null) {
            return $this->inventoryAccountsCache;
        }
        $accounts = $this->odoo->searchRead(
            'account.account',
            [['code', '=like', '3%']],
            ['code', 'name'],
            ['limit' => 1000, 'order' => 'code asc']
        );
        $accounts = array_values(array_filter($accounts, function ($a) {
            $code = (string)($a['code'] ?? '');
            return str_starts_with($code, '3') && !str_starts_with($code, '39');
        }));
        return $this->inventoryAccountsCache = $accounts;
    }

    private function getInternalLocationIds(): array
    {
        if ($this->internalLocationIds !== null) {
            return $this->internalLocationIds;
        }
        try {
            $rows = $this->odoo->searchRead(
                'stock.location',
                [['usage', '=', 'internal']],
                ['id'],
                ['limit' => 500]
            );
            $this->internalLocationIds = array_column($rows, 'id');
        } catch (\Throwable $e) {
            $this->internalLocationIds = [];
        }
        return $this->internalLocationIds;
    }

    private function accuracyPercent(float $a, float $b): float
    {
        $denom = max(abs($a), abs($b));
        if ($denom < 0.01) {
            return 100.0; // both effectively zero
        }
        $gap = min(100.0, abs($b - $a) / $denom * 100.0);
        return round(max(0.0, 100.0 - $gap), 2);
    }

    private function detectCurrency(): string
    {
        try {
            $companyId = $this->getCompanyId();
            $company = $this->odoo->read('res.company', [$companyId], ['currency_id']);
            $curRef = $company[0]['currency_id'] ?? null;
            if (is_array($curRef) && !empty($curRef[0])) {
                $cur = $this->odoo->read('res.currency', [$curRef[0]], ['name']);
                return $cur[0]['name'] ?? 'USD';
            }
        } catch (\Throwable $e) { /* fall through */ }
        return 'USD';
    }

    private function getCompanyId(): int
    {
        if ($this->companyId !== null) return $this->companyId;
        try {
            $uid  = $this->odoo->authenticate();
            $user = $this->odoo->read('res.users', [$uid], ['company_id']);
            $cid  = $user[0]['company_id'] ?? null;
            $this->companyId = is_array($cid) ? (int)($cid[0] ?? 1) : 1;
        } catch (\Throwable $e) {
            $this->companyId = 1;
        }
        return $this->companyId;
    }

    private function safe(callable $fn, array &$errors, string $tag)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            $errors[] = "{$tag}: " . $e->getMessage();
            return null;
        }
    }

    private function emptyReconciliation(string $note = ''): array
    {
        return [
            'left_label'      => '—',
            'left_value'      => 0.0,
            'right_label'     => '—',
            'right_value'     => 0.0,
            'variance'        => 0.0,
            'accuracy_pct'    => 100.0,
            'subtitle'        => $note ?: 'No data available.',
            'by_account'      => [],
            'breakdown_total' => 0.0,
        ];
    }
}
