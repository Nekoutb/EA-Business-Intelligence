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

    /**
     * Disbursement projection categories. Each maps a cash-journal
     * debit prefix to the user-facing label used in the
     * "Next 30-day disbursements" sandbox. Order here is irrelevant —
     * the view sorts by projected amount descending.
     *
     * Methodology: for each prefix, sum the debits posted via cash
     * journals (type cash|bank) in the YTD window through the end of
     * the previous full month, then divide by the number of complete
     * months elapsed → monthly run-rate → projection for the next 30
     * days. Class 2 (fixed assets) deliberately omitted per the
     * user spec — those are non-recurrent. Class 5 also omitted to
     * keep cash↔cash transfers from polluting the disbursement view.
     */
    private const DISBURSEMENT_PREFIXES = [
        '16'  => 'Loan reimbursements',
        '42'  => 'Salary payments',
        '43'  => 'Social insurance contributions',
        '44'  => 'Tax payments',
        '401' => 'Supplier payments',
    ];

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
            //
            // Bumped 'treasury_asof' → 'treasury_asof_v2' on 2026-05-07
            // to bust stale cached zero-disbursement results from the
            // pre-fix journal-type bug (cash journals filter was too
            // narrow; rewritten to "moves crediting 52/57" which captures
            // general-journal cash payments too).
            $treasury = ea_cached('treasury_asof_v2', function () use ($client, $gl) {
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
                    $bal = (float) ($r['balance'] ?? 0);
                    // < 0 → overdraft (excluded; lives on Short-term debt card).
                    // < 0.5 → effectively zero (excluded per user spec
                    // 2026-05-07: "remove the accounts with zero balances").
                    if ($bal < 0.5) continue;
                    $liquidityRows[] = [
                        'name'    => $r['name'],
                        'balance' => round($bal, 2),
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

                // ── Next 30-day disbursements ────────────────────────────
                // Forward-looking projection of cash outflows for the next
                // 30 days, computed from YTD-monthly-average history per
                // category. Methodology per user spec 2026-05-07:
                //
                //   1. Cash-journal debits to balance-sheet accounts
                //      (excl. fixed assets in class 2 + cash in class 5)
                //      grouped into known buckets — averaged YTD monthly.
                //   2. Direct expense-to-cash entries (Dr 6XX / Cr 52|57
                //      in the same move) — averaged YTD monthly, added
                //      to the supplier-payments line on top of the
                //      401 cash-journal average.
                //   3. AP ledger — actual supplier invoices due within
                //      the next 30 days (residual amounts), surfaced as
                //      a separate line.
                //
                // Window: from Jan 1 of current year through the END of
                // the last complete month. Months basis = current_month - 1
                // (clamped to ≥1 so January doesn't divide by zero). This
                // mirrors the user's example: in May, average the 4
                // complete months Jan-Apr.
                $today = new \DateTimeImmutable('today');
                $year  = (int) $today->format('Y');
                $monthNow = (int) $today->format('n');
                $jan1  = new \DateTimeImmutable("{$year}-01-01");
                if ($monthNow >= 2) {
                    $ytdEnd = (new \DateTimeImmutable("{$year}-{$monthNow}-01"))->modify('-1 day');
                    $monthsBasis = $monthNow - 1;
                } else {
                    // January: average the partial month so far. Basis = 1
                    // so projection ≈ what's been spent so far.
                    $ytdEnd = $today;
                    $monthsBasis = 1;
                }

                // Step 1 — cash-journal debits per known category.
                $byPrefix = $gl->cashJournalDebitsByPrefixes(
                    array_keys(self::DISBURSEMENT_PREFIXES),
                    $jan1, $ytdEnd
                ) ?? [];

                // Step 2 — direct expense-to-cash (Dr 6XX / Cr 52|57).
                // Added on top of the 401 cash-journal average to form
                // the combined supplier-payments line.
                $directCashYtd = $gl->directExpenseToCashDebits(
                    '6',
                    [self::PREFIX_CASH_BANKS, self::PREFIX_CASH_VAULT],
                    $jan1, $ytdEnd
                ) ?? 0.0;

                // Step 3 — AP invoices due in next 30 days.
                // Best-effort: the searchRead can fail on tenants that
                // don't expose payment_state — fall back to 0.
                $apDueWindow = $today->modify('+30 days');
                $apDue30 = 0.0;
                try {
                    $apInvoices = $client->searchRead('account.move', [
                        ['move_type', '=', 'in_invoice'],
                        ['state', '=', 'posted'],
                        ['payment_state', 'in', ['not_paid', 'partial', 'in_payment']],
                        ['invoice_date_due', '>=', $today->format('Y-m-d')],
                        ['invoice_date_due', '<=', $apDueWindow->format('Y-m-d')],
                    ], ['amount_residual'], []);
                    foreach ($apInvoices as $inv) {
                        $apDue30 += (float) ($inv['amount_residual'] ?? 0);
                    }
                } catch (\Throwable $e) { /* leave $apDue30 = 0 */ }

                // Build the row list. Per the user's 2026-05-07 spec, the
                // Supplier payments line combines THREE components into a
                // single row (no separate AP-due row):
                //
                //   1. avg(401 cash-journal debits)        — Source 1
                //   2. avg(direct 6XX→cash debits)         — Source 2
                //   3. AP invoices due in next 30 days     — Source 3
                //
                // The first two are YTD-monthly averages; the third is an
                // actual amount from the AP ledger. Sum them all to a
                // single projection so the auditor sees one supplier-
                // payments number that already accounts for known
                // invoices coming due.
                $disbRows = [];
                foreach (self::DISBURSEMENT_PREFIXES as $prefix => $label) {
                    $ytd = (float) ($byPrefix[$prefix] ?? 0);
                    $supplierIncludesAPDue = false;
                    if ($prefix === '401') {
                        // Components 1 + 2 — both averaged YTD.
                        $ytd += $directCashYtd;
                    }
                    $monthly = $monthsBasis > 0 ? $ytd / $monthsBasis : 0.0;
                    if ($prefix === '401' && $apDue30 > 0.5) {
                        // Component 3 — actual amount, added on top.
                        $monthly += $apDue30;
                        $supplierIncludesAPDue = true;
                    }
                    $disbRows[] = [
                        'label'           => $label,
                        'kind'            => 'avg',
                        'ytd'             => round($ytd, 2),
                        'projection'      => round($monthly, 2),
                        'basis'           => $monthsBasis,
                        'includes_ap_due' => $supplierIncludesAPDue,
                        'ap_due_30d'      => $supplierIncludesAPDue ? round($apDue30, 2) : null,
                    ];
                }
                // Sort high → low; rows with zero projection sink to the
                // bottom (matches the liquidity-snapshot UX).
                usort($disbRows, fn ($a, $b) => $b['projection'] <=> $a['projection']);
                $disbursements = [
                    'rows'         => $disbRows,
                    'total'        => array_sum(array_column($disbRows, 'projection')),
                    'months_basis' => $monthsBasis,
                    'window_from'  => $jan1->format('Y-m-d'),
                    'window_to'    => $ytdEnd->format('Y-m-d'),
                ];

                return [
                    'currency' => $this->detectCurrency($client),
                    'as_of'    => (new \DateTimeImmutable('now'))->format('Y-m-d H:i'),

                    'cash_equivalents'  => $cash,
                    'cash_breakdown'    => ['banks' => $cash52, 'vault' => $cash57],

                    'liquidity_snapshot' => $liquiditySnapshot,
                    'disbursements'      => $disbursements,

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
