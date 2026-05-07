<?php

namespace App\Http\Controllers;

use App\Odoo\OdooFactory;
use App\Services\GLAccountService;
use App\Services\WorkingCapitalService;

/**
 * Treasury — focused as-of-now snapshot.
 *
 * The page presents four sandboxes at the top of the page, all
 * computed from the live GL with no period filter applied:
 *
 *   1. Cash & cash equivalents — Σ debit-positive balances on
 *      OHADA accounts 57 (caisse) + 52 (banques). Bank accounts
 *      currently in overdraft (credit-positive 52) are excluded
 *      from this figure — they show up under Short-term debt.
 *
 *   2. Long-term debt — net credit balance on OHADA class 16
 *      (emprunts et dettes assimilées).
 *
 *   3. Short-term debt — Σ per-account credit-positive balances
 *      on classes 4* (third-party payables, social, tax) + 52*
 *      (bank overdrafts). Per-account aggregation, not net, so
 *      receivables (411 in normal debit position) don't cancel
 *      payables (401 in normal credit position).
 *
 *   4. Liquidity ratios — current ratio + quick ratio, copied
 *      verbatim from WorkingCapitalService so the two pages tell
 *      the same story.
 *
 * No filter, no period selector. Whatever the latest Odoo refresh
 * has is what gets surfaced. Cached briefly per-tenant via
 * ea_cached so re-renders within the session don't re-query.
 *
 * Each KPI returns null when the underlying Odoo query fails so
 * the view can render a graceful "—" rather than crashing the
 * whole page.
 */
class TreasuryController extends Controller
{
    /** OHADA prefix groups — single source of truth for the four KPIs. */
    private const PREFIX_CASH_BANKS    = '52';   // Banques — debit-positive at rest
    private const PREFIX_CASH_VAULT    = '57';   // Caisse — physical cash
    private const PREFIX_LONG_TERM_DEBT = '16';   // Emprunts et dettes assimilées
    private const PREFIX_THIRD_PARTIES = '4';    // Tiers (suppliers/customers/state/personnel)

    public function index()
    {
        $error    = null;
        $treasury = null;

        try {
            $client = OdooFactory::fromConfig();
            $gl     = new GLAccountService($client);

            // Cache key includes 'asof' (no period) so re-renders within
            // the session reuse the result. Cleared by ea_cache_clear()
            // on data-mutation actions elsewhere.
            $treasury = ea_cached('treasury_asof', function () use ($client, $gl) {
                // ── 1. Cash & cash equivalents ────────────────────────────
                // Sum of debit-positive balances on 52 + 57 (per-account so
                // an overdrawn current account in 52 doesn't cancel the
                // standing cash on hand).
                $cash52 = $gl->sumPositiveDebitBalancesByPrefix(self::PREFIX_CASH_BANKS);
                $cash57 = $gl->sumPositiveDebitBalancesByPrefix(self::PREFIX_CASH_VAULT);
                $cash   = ($cash52 === null || $cash57 === null)
                    ? null
                    : ($cash52 + $cash57);

                // ── 2. Long-term debt ────────────────────────────────────
                // Class 16 (emprunts et dettes assimilées) — naturally
                // credit-positive. creditByPrefix returns signed positive
                // for liabilities so this reads as expected.
                $longTermDebt = $gl->creditByPrefix(self::PREFIX_LONG_TERM_DEBT);

                // ── 3. Short-term debt ───────────────────────────────────
                // Per-account credit-positive balances on 4* + bank
                // overdrafts on 52*. Aggregating per account avoids AR
                // (411 normal debit) cancelling AP (401 normal credit).
                $stClass4 = $gl->sumPositiveCreditBalancesByPrefix(self::PREFIX_THIRD_PARTIES);
                $stOverdraft = $gl->sumPositiveCreditBalancesByPrefix(self::PREFIX_CASH_BANKS);
                $shortTermDebt = ($stClass4 === null || $stOverdraft === null)
                    ? null
                    : ($stClass4 + $stOverdraft);

                // ── Liquidity snapshot (per-account list under the cards) ─
                // Pull every account on classes 52 + 57 with its name and
                // signed balance, then keep only the debit-positive ones
                // (overdrafts excluded — they belong to Short-term debt
                // per the user's spec). Sort high → low so the largest
                // balances are at the top of the snapshot list.
                $liquidityRows = [];
                $rows52 = $gl->accountBalancesByPrefix(self::PREFIX_CASH_BANKS) ?? [];
                $rows57 = $gl->accountBalancesByPrefix(self::PREFIX_CASH_VAULT) ?? [];
                foreach (array_merge($rows52, $rows57) as $r) {
                    if (($r['balance'] ?? 0) < 0) continue;   // overdraft — excluded
                    $liquidityRows[] = [
                        'name'    => $r['name'],
                        'balance' => round((float) $r['balance'], 2),
                    ];
                }
                // Sort by balance desc (greyed zeros sink to the bottom).
                usort($liquidityRows, fn ($a, $b) => $b['balance'] <=> $a['balance']);
                $liquiditySnapshot = [
                    'rows'  => $liquidityRows,
                    'total' => array_sum(array_column($liquidityRows, 'balance')),
                ];

                // ── 4. Liquidity ratios — copied from Working Capital ────
                // Same numbers /working-capital displays, so the two
                // pages don't diverge on rounding or methodology. The
                // service runs as-of-now by default (no asOf passed).
                $wcRatios = ['current' => null, 'quick' => null, 'reason' => null];
                try {
                    $wc = (new WorkingCapitalService($client))->analyze();
                    $wcRatios = [
                        'current' => $wc['ratios']['current_ratio'] ?? null,
                        'quick'   => $wc['ratios']['quick_ratio'] ?? null,
                        'reason'  => $wc['ratios']['quick_ratio_reason'] ?? null,
                    ];
                } catch (\Throwable $e) {
                    // Ratios fall back to null/—; shouldn't sink the page.
                }

                return [
                    'currency' => $this->detectCurrency($client),
                    'as_of'    => (new \DateTimeImmutable('now'))->format('Y-m-d H:i'),

                    'cash_equivalents'  => $cash,
                    'cash_breakdown'    => ['banks' => $cash52, 'vault' => $cash57],

                    'liquidity_snapshot' => $liquiditySnapshot,

                    'long_term_debt'    => $longTermDebt !== null ? abs($longTermDebt) : null,

                    'short_term_debt'        => $shortTermDebt,
                    'short_term_breakdown'   => ['third_parties' => $stClass4, 'overdraft' => $stOverdraft],

                    'liquidity'         => $wcRatios,
                ];
            });
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('treasury', [
            'treasury' => $treasury,
            'error'    => $error,
            'active'   => 'treasury',
            'aiPage'   => 'dashboard',
        ]);
    }

    /**
     * Pull the company default currency once. Independent of the
     * GLAccountService methods so it stays small + cacheable.
     */
    private function detectCurrency($client): string
    {
        try {
            $rows = $client->searchRead('res.company', [], ['currency_id'], [], 1);
            return (string) (($rows[0]['currency_id'][1] ?? null) ?: 'XAF');
        } catch (\Throwable $e) {
            return 'XAF';
        }
    }
}
