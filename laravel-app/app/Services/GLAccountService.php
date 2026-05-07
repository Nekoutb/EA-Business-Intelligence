<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * GL-balance helper — reads sums from account.move.line scoped by
 * account-code prefix and an optional date range. The same query
 * pattern an OHADA auditor uses against the trial balance / grand-
 * livre to compute reconciliation figures by hand.
 *
 * Used by ReceivablesService for the GL-backed DSO calculation:
 *   numerator   = balanceByPrefix('41', null, today)
 *   denominator = creditByPrefix ('70', today-90d, today)
 *   DSO         = (numerator / denominator) * 90
 *
 * Single round-trip per call: one searchRead on account.account to
 * resolve the prefix → ids, one read_group on account.move.line to
 * sum debit/credit. Server-side aggregation, not row-by-row.
 *
 * Returns null on any Odoo failure so callers can fall back to a
 * legacy invoice-derived calculation rather than 500ing the page.
 */
final class GLAccountService
{
    public function __construct(private OdooClient $odoo) {}

    /**
     * Net balance (debit − credit) for every account whose code
     * starts with $prefix, optionally bounded by [from, to] on
     * line.date. Result is in the company default currency (XAF on
     * the pilot tenant). Positive for asset/expense classes,
     * negative for liability/equity/revenue classes — by sign
     * convention of double-entry.
     *
     * For the AR side (class 41 — debtor), this returns positive.
     * For the revenue side (class 70 — creditor), it returns
     * negative. Use creditByPrefix() when you want signed-positive
     * revenue movement.
     */
    public function balanceByPrefix(
        string $prefix,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null
    ): ?float {
        $sums = $this->fetchSums($prefix, $from, $to);
        if ($sums === null) return null;
        return ($sums['debit'] ?? 0.0) - ($sums['credit'] ?? 0.0);
    }

    /**
     * Net credit (credit − debit) for every account whose code
     * starts with $prefix, optionally bounded by date. Returns a
     * signed-positive number for natural credit accounts (revenue,
     * liabilities) — i.e. "how much was booked TO this class in
     * the period." For class 70 / 71 / 72 etc this is the right
     * side of the DSO denominator.
     */
    public function creditByPrefix(
        string $prefix,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null
    ): ?float {
        $sums = $this->fetchSums($prefix, $from, $to);
        if ($sums === null) return null;
        return ($sums['credit'] ?? 0.0) - ($sums['debit'] ?? 0.0);
    }

    /**
     * Sum of per-account credit-positive balances under $prefix.
     *
     * For each account whose code starts with $prefix, compute
     * (credit − debit) and KEEP ONLY the positive results (i.e.
     * accounts in net-credit position), then sum those.
     *
     * Why per-account, not net? When the prefix straddles accounts
     * with mixed sign convention, a net total is misleading. Example —
     * "short-term debt = sum of credit balances on 4XXX":
     *
     *   • 401 (Suppliers): credit-positive ⇒ contributes to ST debt
     *   • 411 (Customers): debit-positive  ⇒ DOES NOT contribute
     *   • 411 with overpayment: credit-positive ⇒ DOES contribute
     *
     * Netting all 4XXX accounts together would let AR (411) cancel AP
     * (401), giving a meaningless number. Per-account positive-credit
     * captures the actual short-term obligations the way an OHADA
     * balance sheet would aggregate them.
     *
     * Same shape applies to bank overdrafts (52 in credit) for short
     * term debt while their normal debit balance feeds cash & equivalents.
     *
     * Returns 0.0 when no accounts match (clean zero, distinguishable
     * from null), null when Odoo is unreachable so callers can fall
     * back gracefully.
     */
    public function sumPositiveCreditBalancesByPrefix(
        string $prefix,
        ?\DateTimeImmutable $asOf = null
    ): ?float {
        return $this->sumPerAccountSigned($prefix, $asOf, signCredit: true);
    }

    /**
     * Mirror of sumPositiveCreditBalancesByPrefix() for the debit
     * side: sums (debit − credit) per matching account, keeping only
     * net-debit-positive accounts. Used for cash & equivalents on
     * 52 + 57 — accounts in overdraft (credit-positive 52) are
     * excluded so a single overdrawn current account doesn't
     * silently reduce the company's cash position.
     */
    public function sumPositiveDebitBalancesByPrefix(
        string $prefix,
        ?\DateTimeImmutable $asOf = null
    ): ?float {
        return $this->sumPerAccountSigned($prefix, $asOf, signCredit: false);
    }

    /**
     * Per-account balances (with name) for every account whose code
     * starts with $prefix. Returns one row per account with its
     * Odoo-resolved name and the signed balance (debit − credit).
     *
     * Used by the Treasury liquidity-snapshot card to list each bank
     * and cash account individually with its current balance.
     *
     * Two Odoo round-trips:
     *   1. searchRead(account.account, code =like prefix%) → id+code+name
     *   2. read_group(account.move.line, account_id) → debit:sum + credit:sum
     *
     * Returns:
     *   [
     *     ['id' => 12, 'code' => '521001', 'name' => 'Bank (ECOBANK)',
     *      'debit' => 50000000.0, 'credit' => 2750000.0,
     *      'balance' => 47250000.0],
     *     ...
     *   ]
     *
     * Accounts with no posted lines appear with debit/credit/balance
     * all 0.0 — caller decides whether to suppress, grey out, or show
     * them. Returns null on Odoo failure so callers can fall back.
     */
    public function accountBalancesByPrefix(
        string $prefix,
        ?\DateTimeImmutable $asOf = null
    ): ?array {
        try {
            $accounts = $this->odoo->searchRead(
                'account.account',
                [['code', '=like', $prefix . '%']],
                ['id', 'code', 'name'],
                ['order' => 'code asc']
            );
            if (empty($accounts)) return [];

            $ids = array_map(fn ($a) => (int) $a['id'], $accounts);

            $domain = [
                ['account_id', 'in', $ids],
                ['parent_state', '=', 'posted'],
            ];
            if ($asOf) $domain[] = ['date', '<=', $asOf->format('Y-m-d')];

            $groups = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain'  => $domain,
                'fields'  => ['debit:sum', 'credit:sum'],
                'groupby' => ['account_id'],
                'lazy'    => false,
            ]);

            // Index group results by account_id for an O(1) join below.
            $byId = [];
            foreach ((array) $groups as $g) {
                $aid = is_array($g['account_id'] ?? null) ? (int) $g['account_id'][0] : null;
                if ($aid === null) continue;
                $byId[$aid] = [
                    'debit'  => (float) ($g['debit']  ?? 0),
                    'credit' => (float) ($g['credit'] ?? 0),
                ];
            }

            $rows = [];
            foreach ($accounts as $a) {
                $aid = (int) $a['id'];
                $sum = $byId[$aid] ?? ['debit' => 0.0, 'credit' => 0.0];
                $rows[] = [
                    'id'      => $aid,
                    'code'    => (string) ($a['code'] ?? ''),
                    'name'    => (string) ($a['name'] ?? ''),
                    'debit'   => (float)  $sum['debit'],
                    'credit'  => (float)  $sum['credit'],
                    'balance' => (float)  ($sum['debit'] - $sum['credit']),
                ];
            }
            return $rows;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Σ debit on cash-journal lines for accounts matching ANY of the
     * given prefixes, in the [from, to] window. Returns one entry
     * per prefix, e.g.:
     *
     *     ['16' => 8_400_000.0, '42' => 50_000_000.0, '401' => 22_300_000.0]
     *
     * Used by the Treasury "Next 30-day disbursements" projection:
     * average each prefix's YTD cash-out by the number of complete
     * months elapsed → monthly run-rate → next-month projection.
     *
     * "Cash journal" = account.journal.type ∈ {cash, bank}. The
     * filter sits on the journal_id of each move line (server-side),
     * so this stays a single read_group call regardless of how many
     * prefixes are passed.
     *
     * 3 Odoo round-trips total:
     *   1) account.journal — resolve cash + bank journal ids
     *   2) account.account — resolve every account matching any prefix
     *   3) account.move.line — read_group debit:sum by account_id
     *
     * Returns null on Odoo failure; an empty input returns an empty
     * array.
     */
    public function cashJournalDebitsByPrefixes(
        array $prefixes,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to
    ): ?array {
        if ($prefixes === []) return [];
        try {
            // 1) Cash + bank journal ids
            $journals = $this->odoo->searchRead(
                'account.journal',
                [['type', 'in', ['cash', 'bank']]],
                ['id'],
                []
            );
            if (empty($journals)) return array_fill_keys($prefixes, 0.0);
            $journalIds = array_map(fn ($j) => (int) $j['id'], $journals);

            // 2) Accounts matching any prefix — single OR domain
            $domain = [];
            for ($i = 0; $i < count($prefixes) - 1; $i++) $domain[] = '|';
            foreach ($prefixes as $p) $domain[] = ['code', '=like', $p . '%'];
            $accounts = $this->odoo->searchRead('account.account', $domain, ['id', 'code'], []);
            if (empty($accounts)) return array_fill_keys($prefixes, 0.0);

            // Reverse lookup: account_id → which prefix it matches
            // (longest-prefix-match wins so '401' beats '4').
            $sortedPrefixes = $prefixes;
            usort($sortedPrefixes, fn ($a, $b) => strlen($b) - strlen($a));
            $prefixById = [];
            foreach ($accounts as $a) {
                $code = (string) ($a['code'] ?? '');
                foreach ($sortedPrefixes as $p) {
                    if (str_starts_with($code, $p)) {
                        $prefixById[(int) $a['id']] = $p;
                        break;
                    }
                }
            }
            if (empty($prefixById)) return array_fill_keys($prefixes, 0.0);

            // 3) Sum debit by account_id, scoped to cash journals + window
            $mlDomain = [
                ['account_id',   'in', array_keys($prefixById)],
                ['journal_id',   'in', $journalIds],
                ['parent_state', '=',  'posted'],
                ['debit',        '>',  0],
            ];
            if ($from) $mlDomain[] = ['date', '>=', $from->format('Y-m-d')];
            if ($to)   $mlDomain[] = ['date', '<=', $to->format('Y-m-d')];

            $rows = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain'  => $mlDomain,
                'fields'  => ['debit:sum'],
                'groupby' => ['account_id'],
                'lazy'    => false,
            ]);

            $sums = array_fill_keys($prefixes, 0.0);
            foreach ((array) $rows as $r) {
                $aid = is_array($r['account_id'] ?? null) ? (int) $r['account_id'][0] : null;
                if ($aid === null || !isset($prefixById[$aid])) continue;
                $sums[$prefixById[$aid]] += (float) ($r['debit'] ?? 0);
            }
            return $sums;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Σ debit on lines whose account.code matches $expensePrefix AND
     * whose parent move has at least one line with a credit on an
     * account whose code matches any of $cashPrefixes.
     *
     * Captures the "cash purchase" pattern — Dr 632X / Cr 57 (or 52)
     * booked in a single move without going through 401. The user's
     * Treasury spec adds the YTD-monthly-average of these to the
     * "Supplier payments" projection on top of the 401 cash-journal
     * average.
     *
     * Two-step query:
     *   1) Find every move that has a credit on a cash account in
     *      the window. Returns distinct move_ids.
     *   2) Within those moves, sum debit on the expense prefix.
     *
     * This is a slight over-approximation: a move could have a
     * credit-on-cash unrelated to the 632X debit (a complex compound
     * journal). In practice 95%+ of OHADA cash purchases are simple
     * 2-line moves so the over-count is negligible. Trade-off
     * accepted vs. line-pair matching, which would require pulling
     * every move's full line set and processing in PHP.
     */
    public function directExpenseToCashDebits(
        string $expensePrefix,
        array $cashPrefixes,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to
    ): ?float {
        try {
            // 1) Resolve cash-account ids
            $cashDomain = [];
            for ($i = 0; $i < count($cashPrefixes) - 1; $i++) $cashDomain[] = '|';
            foreach ($cashPrefixes as $p) $cashDomain[] = ['code', '=like', $p . '%'];
            $cashAccounts = $this->odoo->searchRead('account.account', $cashDomain, ['id'], []);
            if (empty($cashAccounts)) return 0.0;
            $cashIds = array_map(fn ($a) => (int) $a['id'], $cashAccounts);

            // 2) Find move_ids that credit cash in the window
            $creditDomain = [
                ['account_id',   'in', $cashIds],
                ['credit',       '>',  0],
                ['parent_state', '=',  'posted'],
            ];
            if ($from) $creditDomain[] = ['date', '>=', $from->format('Y-m-d')];
            if ($to)   $creditDomain[] = ['date', '<=', $to->format('Y-m-d')];

            $cashLines = $this->odoo->searchRead('account.move.line',
                $creditDomain, ['move_id'], []);
            if (empty($cashLines)) return 0.0;
            $moveIds = array_values(array_unique(array_map(
                fn ($l) => is_array($l['move_id']) ? (int) $l['move_id'][0] : 0,
                $cashLines
            )));
            if (empty($moveIds)) return 0.0;

            // 3) Resolve expense-account ids matching $expensePrefix
            $expAccounts = $this->odoo->searchRead('account.account',
                [['code', '=like', $expensePrefix . '%']],
                ['id'], []);
            if (empty($expAccounts)) return 0.0;
            $expIds = array_map(fn ($a) => (int) $a['id'], $expAccounts);

            // 4) Sum debit on expense lines belonging to those moves
            $debitDomain = [
                ['move_id',      'in', $moveIds],
                ['account_id',   'in', $expIds],
                ['debit',        '>',  0],
                ['parent_state', '=',  'posted'],
            ];
            if ($from) $debitDomain[] = ['date', '>=', $from->format('Y-m-d')];
            if ($to)   $debitDomain[] = ['date', '<=', $to->format('Y-m-d')];

            $rows = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain'  => $debitDomain,
                'fields'  => ['debit:sum'],
                'groupby' => [],
                'lazy'    => false,
            ]);
            $row = $rows[0] ?? null;
            return is_array($row) ? (float) ($row['debit'] ?? 0) : 0.0;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── internals ────────────────────────────────────────────────────

    /**
     * Per-account sign-positive aggregation. Single Odoo round-trip:
     * resolve prefix → account ids, then read_group by account_id
     * summing debit:sum + credit:sum, then filter in PHP.
     *
     * @param bool $signCredit  true ⇒ keep credit-positive accounts (credit − debit > 0)
     *                          false ⇒ keep debit-positive accounts  (debit − credit > 0)
     */
    private function sumPerAccountSigned(
        string $prefix,
        ?\DateTimeImmutable $asOf,
        bool $signCredit
    ): ?float {
        try {
            $accounts = $this->odoo->searchRead(
                'account.account',
                [['code', '=like', $prefix . '%']],
                ['id', 'code'],
                []
            );
            if (empty($accounts)) return 0.0;
            $ids = array_map(fn ($a) => (int) $a['id'], $accounts);

            $domain = [
                ['account_id', 'in', $ids],
                ['parent_state', '=', 'posted'],
            ];
            if ($asOf) $domain[] = ['date', '<=', $asOf->format('Y-m-d')];

            $rows = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain'  => $domain,
                'fields'  => ['debit:sum', 'credit:sum'],
                'groupby' => ['account_id'],
                'lazy'    => false,
            ]);

            $total = 0.0;
            foreach ((array) $rows as $r) {
                $debit  = (float) ($r['debit']  ?? 0);
                $credit = (float) ($r['credit'] ?? 0);
                $signedBalance = $signCredit ? ($credit - $debit) : ($debit - $credit);
                if ($signedBalance > 0) $total += $signedBalance;
            }
            return $total;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Returns ['debit' => float, 'credit' => float] aggregated across
     * every account whose code starts with $prefix, scoped to posted
     * lines in the optional date window. Null when the chart returns
     * no matching accounts or Odoo is unreachable.
     */
    private function fetchSums(
        string $prefix,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to
    ): ?array {
        try {
            // 1) Resolve which accounts match the prefix. account.account.code
            //    is a string in Odoo, so a like '41%' filter is the natural
            //    way to find every sub-account under class 41.
            $accounts = $this->odoo->searchRead(
                'account.account',
                [['code', '=like', $prefix . '%']],
                ['id', 'code'],
                []
            );
            if (empty($accounts)) return null;
            $ids = array_map(fn ($a) => (int) $a['id'], $accounts);

            // 2) Sum (debit, credit) on posted lines for those accounts.
            //    read_group server-side — single round trip, no row data
            //    pulled to PHP.
            $domain = [
                ['account_id', 'in', $ids],
                ['parent_state', '=', 'posted'],
            ];
            if ($from) $domain[] = ['date', '>=', $from->format('Y-m-d')];
            if ($to)   $domain[] = ['date', '<=', $to->format('Y-m-d')];

            $rows = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain'  => $domain,
                'fields'  => ['debit:sum', 'credit:sum'],
                'groupby' => [],
                'lazy'    => false,
            ]);
            $row = $rows[0] ?? null;
            if (!is_array($row)) return ['debit' => 0.0, 'credit' => 0.0];

            return [
                'debit'  => (float) ($row['debit']  ?? 0),
                'credit' => (float) ($row['credit'] ?? 0),
            ];
        } catch (\Throwable $e) {
            // Odoo unreachable, account.move.line not exposed, malformed
            // chart — caller falls back gracefully on null.
            return null;
        }
    }
}
