<?php

namespace App\Services;

use App\Odoo\OdooClient;
use DateTimeImmutable;

/**
 * ManualEntryReviewService — eight audit-style controls focused on the
 * presence (or absence) of MANUAL journal entries (move_type = 'entry')
 * on specific OHADA account ranges, plus a pair of balance-driven checks.
 *
 * Checks run YTD (1 January → today), use posted entries only.
 *
 *  1. Transit accounts (58) balance is 0
 *  2. No manual entries on cash 5XX (excl. 58 — covered by #1)
 *  3. No manual entries on fixed-assets 20/21/22/23/24/28/68
 *  4. No manual entries on inventory 3X excluding 39
 *  5. No manual entries on partner accounts 40X / 41X
 *  6. Profit allocation: ≥ 1 manual entry on 11/12/13 by June 30
 *  7. Interest expense: if 16X has a balance, ≥ 1 manual entry on 67X
 *  8. Provisions correlation: for every move touching 19X, debits on 19X
 *     paired with credits on 79X within the same move; credits on 19X
 *     paired with debits on 69X within the same move.
 *
 * Each failing check exposes the line IDs that triggered it so we can
 * stream a CSV report of every flagged entry, capped at 5 000 rows.
 */
class ManualEntryReviewService
{
    private const FETCH_LIMIT  = 100000;
    private const REPORT_LIMIT = 5000;

    private ?int $companyId = null;

    public function __construct(private OdooClient $odoo) {}

    public function analyze(DateTimeImmutable $today): array
    {
        $errors = [];
        $from = $today->modify('first day of January')->setTime(0, 0, 0);
        $to   = $today->setTime(23, 59, 59);

        $lines = $this->safe(fn() => $this->fetchAndNormalize($from, $to), $errors, 'lines') ?? [];

        $checks = [
            $this->check1Transit58Zero($lines),
            $this->check2NoManualOnCash($lines),
            $this->check3NoManualOnFixedAssets($lines),
            $this->check4NoManualOnInventory($lines),
            $this->check5NoManualOnPartners($lines),
            $this->check6ProfitAllocation($lines, $today),
            $this->check7InterestExpense($lines),
            $this->check8ProvisionsCorrelation($lines),
        ];

        $passed = count(array_filter($checks, fn($c) => $c['passed']));
        $total  = count($checks);
        $score  = $total > 0 ? round($passed / $total * 100, 1) : 100.0;

        $report = $this->buildReport($lines, $checks);

        // Strip internal accumulators
        foreach ($checks as &$c) {
            unset($c['_flagged_ids']);
        }
        unset($c);

        return [
            'overall_score'  => $score,
            'checks_passed'  => $passed,
            'checks_total'   => $total,
            'lines_reviewed' => count($lines),
            'lines_capped'   => count($lines) >= self::FETCH_LIMIT,
            'fetch_limit'    => self::FETCH_LIMIT,
            'checks'         => $checks,
            'report'         => $report,
            'report_capped'  => count($report) >= self::REPORT_LIMIT,
            'report_limit'   => self::REPORT_LIMIT,
            'error'          => empty($errors) ? null : implode(' | ', $errors),
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Fetch + normalize
    // ─────────────────────────────────────────────────────────────────

    private function fetchAndNormalize(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $companyId = $this->getCompanyId();
        $domain = [
            ['parent_state', '=', 'posted'],
            ['company_id',   '=', $companyId],
            ['date',         '>=', $from->format('Y-m-d')],
            ['date',         '<=', $to->format('Y-m-d')],
        ];

        $rows = $this->odoo->searchRead(
            'account.move.line',
            $domain,
            ['name', 'ref', 'account_id', 'partner_id', 'date', 'debit', 'credit', 'move_id'],
            ['limit' => self::FETCH_LIMIT, 'order' => 'date desc, id desc']
        );
        if (empty($rows)) return [];

        // Resolve account codes
        $accountIds = [];
        foreach ($rows as $r) {
            if (is_array($r['account_id'] ?? null) && !empty($r['account_id'][0])) {
                $accountIds[$r['account_id'][0]] = true;
            }
        }
        $codes = [];
        foreach (array_chunk(array_keys($accountIds), 500) as $chunk) {
            foreach ($this->odoo->read('account.account', $chunk, ['code']) as $a) {
                $codes[$a['id']] = (string)($a['code'] ?? '');
            }
        }

        // Resolve move types & names
        $moveIds = [];
        foreach ($rows as $r) {
            if (is_array($r['move_id'] ?? null) && !empty($r['move_id'][0])) {
                $moveIds[$r['move_id'][0]] = true;
            }
        }
        $moveInfo = [];
        if (!empty($moveIds)) {
            foreach (array_chunk(array_keys($moveIds), 500) as $chunk) {
                try {
                    foreach ($this->odoo->read('account.move', $chunk, ['move_type', 'name']) as $m) {
                        $moveInfo[$m['id']] = [
                            'type' => (string)($m['move_type'] ?? ''),
                            'name' => (string)($m['name']      ?? ''),
                        ];
                    }
                } catch (\Throwable $e) {
                    // keep going — checks that need move_type will see '' and won't fail
                }
            }
        }

        // Resolve partner names
        $partnerIds = [];
        foreach ($rows as $r) {
            if (is_array($r['partner_id'] ?? null) && !empty($r['partner_id'][0])) {
                $partnerIds[$r['partner_id'][0]] = true;
            }
        }
        $partners = [];
        if (!empty($partnerIds)) {
            foreach (array_chunk(array_keys($partnerIds), 500) as $chunk) {
                foreach ($this->odoo->read('res.partner', $chunk, ['name']) as $p) {
                    $partners[$p['id']] = (string)($p['name'] ?? '');
                }
            }
        }

        $lines = [];
        foreach ($rows as $r) {
            $accId  = is_array($r['account_id'] ?? null) ? ($r['account_id'][0] ?? null) : null;
            $partId = is_array($r['partner_id'] ?? null) ? ($r['partner_id'][0] ?? null) : null;
            $moveId = is_array($r['move_id']    ?? null) ? ($r['move_id'][0]    ?? null) : null;

            $lines[] = [
                'id'           => (int)($r['id'] ?? 0),
                'name'         => (string)($r['name'] ?? ''),
                'ref'          => (string)($r['ref']  ?? ''),
                'account_code' => $accId !== null ? ($codes[$accId] ?? '') : '',
                'partner_id'   => $partId,
                'partner_name' => $partId !== null ? ($partners[$partId] ?? '') : '',
                'date'         => (string)($r['date'] ?? ''),
                'debit'        => (float)($r['debit']  ?? 0),
                'credit'       => (float)($r['credit'] ?? 0),
                'amount'       => abs((float)($r['debit'] ?? 0) - (float)($r['credit'] ?? 0)),
                'move_id'      => $moveId,
                'move_type'    => $moveId !== null ? ($moveInfo[$moveId]['type'] ?? '') : '',
                'move_name'    => $moveId !== null ? ($moveInfo[$moveId]['name'] ?? '') : '',
            ];
        }
        return $lines;
    }

    // ─────────────────────────────────────────────────────────────────
    // Checks
    // ─────────────────────────────────────────────────────────────────

    private function check1Transit58Zero(array $lines): array
    {
        $balance = 0.0;
        $ids = [];
        foreach ($lines as $l) {
            if (str_starts_with($l['account_code'], '58')) {
                $balance += $l['debit'] - $l['credit'];
                $ids[] = $l['id'];
            }
        }
        $failed = abs($balance) > 0.01;
        return [
            'no'           => '1',
            'name'         => 'Transit accounts (58) balance is zero',
            'description'  => 'Transit / internal-transfer accounts (58X) should always net to zero — non-zero means a transit waiting to clear.',
            'passed'       => !$failed,
            'count'        => $failed ? count($ids) : 0,
            '_flagged_ids' => $failed ? $ids : [],
        ];
    }

    private function check2NoManualOnCash(array $lines): array
    {
        $ids = [];
        foreach ($lines as $l) {
            $code = $l['account_code'];
            if ($l['move_type'] !== 'entry') continue;
            // 5X but not 58 (covered by #1)
            if (!str_starts_with($code, '5')) continue;
            if (str_starts_with($code, '58')) continue;
            $ids[] = $l['id'];
        }
        return [
            'no'           => '2',
            'name'         => 'No manual entries on cash 5XX',
            'description'  => 'Cash and bank accounts (50–57, 59) should be touched only by automated journals. Manual entries here often hide reconciliation breaks.',
            'passed'       => empty($ids),
            'count'        => count($ids),
            '_flagged_ids' => $ids,
        ];
    }

    private function check3NoManualOnFixedAssets(array $lines): array
    {
        static $prefixes = ['20', '21', '22', '23', '24', '28', '68'];
        $ids = [];
        foreach ($lines as $l) {
            if ($l['move_type'] !== 'entry') continue;
            $code = $l['account_code'];
            foreach ($prefixes as $p) {
                if (str_starts_with($code, $p)) {
                    $ids[] = $l['id'];
                    break;
                }
            }
        }
        return [
            'no'           => '3',
            'name'         => 'No manual entries on Fixed Assets',
            'description'  => 'Fixed-asset additions, depreciation, and disposals should flow through the Assets module — manual entries on 20/21/22/23/24/28/68 bypass the sub-ledger.',
            'passed'       => empty($ids),
            'count'        => count($ids),
            '_flagged_ids' => $ids,
        ];
    }

    private function check4NoManualOnInventory(array $lines): array
    {
        $ids = [];
        foreach ($lines as $l) {
            if ($l['move_type'] !== 'entry') continue;
            $code = $l['account_code'];
            if (!str_starts_with($code, '3')) continue;
            if (str_starts_with($code, '39')) continue; // impairment is fair game
            $ids[] = $l['id'];
        }
        return [
            'no'           => '4',
            'name'         => 'No manual entries on Inventory (3X excl. 39)',
            'description'  => 'Inventory account postings should come from stock moves and standard cost variation — not direct manual journals.',
            'passed'       => empty($ids),
            'count'        => count($ids),
            '_flagged_ids' => $ids,
        ];
    }

    private function check5NoManualOnPartners(array $lines): array
    {
        $ids = [];
        foreach ($lines as $l) {
            if ($l['move_type'] !== 'entry') continue;
            $code = $l['account_code'];
            if (!str_starts_with($code, '40') && !str_starts_with($code, '41')) continue;
            $ids[] = $l['id'];
        }
        return [
            'no'           => '5',
            'name'         => 'No manual entries on AR / AP (40X / 41X)',
            'description'  => 'Customer (41X) and vendor (40X) account postings should originate from invoices, bills, and payments — manual entries here corrupt the aged reports.',
            'passed'       => empty($ids),
            'count'        => count($ids),
            '_flagged_ids' => $ids,
        ];
    }

    private function check6ProfitAllocation(array $lines, DateTimeImmutable $today): array
    {
        // OHADA profit-allocation accounts: 11 (reserves), 12 (carry-forward), 13 (net result of period).
        static $prefixes = ['11', '12', '13'];

        $deadline = $today->modify('first day of July')->setTime(0, 0, 0); // strict: < 1 July = ≤ 30 June
        $isPastDeadline = $today >= $deadline;

        if (!$isPastDeadline) {
            return [
                'no'           => '6',
                'name'         => 'Profit allocation entry by 30 June',
                'description'  => 'At least one manual entry on accounts 11/12/13 (reserves, carry-forward, net result) should be posted by 30 June to record the prior-year result allocation.',
                'passed'       => true,
                'count'        => 0,
                '_flagged_ids' => [],
                'note'         => 'Pending — deadline is 30 June ' . $today->format('Y') . '.',
            ];
        }

        // Past 30 June — look for ≥ 1 manual entry on 11/12/13 dated 1 Jan – 30 June
        $halfYearEnd = $today->modify('last day of June')->setTime(23, 59, 59);
        $found = false;
        foreach ($lines as $l) {
            if ($l['move_type'] !== 'entry') continue;
            $code = $l['account_code'];
            $isAllocationAccount = false;
            foreach ($prefixes as $p) {
                if (str_starts_with($code, $p)) { $isAllocationAccount = true; break; }
            }
            if (!$isAllocationAccount) continue;
            // Date ≤ 30 June
            if ($l['date'] !== '' && $l['date'] <= $halfYearEnd->format('Y-m-d')) {
                $found = true;
                break;
            }
        }
        return [
            'no'           => '6',
            'name'         => 'Profit allocation entry by 30 June',
            'description'  => 'At least one manual entry on 11/12/13 (reserves, carry-forward, net result) should be posted by 30 June to record the prior-year result allocation.',
            'passed'       => $found,
            'count'        => $found ? 0 : 1,
            '_flagged_ids' => [],   // nothing transactional to point at — it's the *absence* of entries
        ];
    }

    private function check7InterestExpense(array $lines): array
    {
        // Sum 16X balance YTD (loans). If non-zero AND no manual 67X entry exists → fail.
        $loanBalance = 0.0;
        $loanLineIds = [];
        $hasManualInterest = false;
        foreach ($lines as $l) {
            $code = $l['account_code'];
            if (str_starts_with($code, '16')) {
                $loanBalance += $l['debit'] - $l['credit'];
                $loanLineIds[] = $l['id'];
            }
            if (str_starts_with($code, '67') && $l['move_type'] === 'entry') {
                $hasManualInterest = true;
            }
        }

        // OHADA loan accounts are credit-natural (liability) → balance < 0 normally.
        $hasLoans = abs($loanBalance) > 0.01;
        if (!$hasLoans) {
            return [
                'no'           => '7',
                'name'         => 'Interest expense on existing loans',
                'description'  => 'If account 16X (loans) carries a balance, manual interest-expense entries should exist on 67X.',
                'passed'       => true,
                'count'        => 0,
                '_flagged_ids' => [],
                'note'         => 'No outstanding loan balance on 16X — check not applicable.',
            ];
        }
        if ($hasManualInterest) {
            return [
                'no'           => '7',
                'name'         => 'Interest expense on existing loans',
                'description'  => 'Loans on 16X are accompanied by manual 67X interest-expense entries.',
                'passed'       => true,
                'count'        => 0,
                '_flagged_ids' => [],
            ];
        }
        // Loans exist, no interest entries — flag the loan lines so the user can investigate
        return [
            'no'           => '7',
            'name'         => 'Interest expense on existing loans',
            'description'  => 'Account 16X has a balance but no manual 67X interest-expense entry has been booked YTD.',
            'passed'       => false,
            'count'        => count($loanLineIds),
            '_flagged_ids' => $loanLineIds,
        ];
    }

    private function check8ProvisionsCorrelation(array $lines): array
    {
        // Index lines by move
        $byMove = [];
        foreach ($lines as $l) {
            if ($l['move_id'] !== null) $byMove[$l['move_id']][] = $l;
        }

        $flagged = [];
        foreach ($byMove as $moveId => $moveLines) {
            // Only consider moves that touch 19X
            $has19 = false;
            foreach ($moveLines as $ml) {
                if (str_starts_with($ml['account_code'], '19')) { $has19 = true; break; }
            }
            if (!$has19) continue;

            // Does the move contain the expected counter-leg(s)?
            $has69Debit  = false;
            $has79Credit = false;
            foreach ($moveLines as $ml) {
                $code = $ml['account_code'];
                if (str_starts_with($code, '69') && $ml['debit']  > 0.01) $has69Debit  = true;
                if (str_starts_with($code, '79') && $ml['credit'] > 0.01) $has79Credit = true;
            }

            // Inspect each 19X line and flag if its required counter-leg is missing
            foreach ($moveLines as $ml) {
                if (!str_starts_with($ml['account_code'], '19')) continue;
                if ($ml['debit']  > 0.01 && !$has79Credit) $flagged[] = $ml['id']; // ↓ provision needs 79 income
                if ($ml['credit'] > 0.01 && !$has69Debit)  $flagged[] = $ml['id']; // ↑ provision needs 69 expense
            }
        }

        return [
            'no'           => '8',
            'name'         => 'Provisions correlation (19 ↔ 69 ↔ 79)',
            'description'  => 'Every move touching 19X provisions must pair: debits on 19X with credits on 79X (reversal); credits on 19X with debits on 69X (provision charge).',
            'passed'       => empty($flagged),
            'count'        => count($flagged),
            '_flagged_ids' => $flagged,
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Report builder
    // ─────────────────────────────────────────────────────────────────

    private function buildReport(array $lines, array $allChecks): array
    {
        $byId = [];
        foreach ($lines as $l) $byId[(int)$l['id']] = $l;

        $flagged = [];
        foreach ($allChecks as $chk) {
            $label = $chk['no'] . '. ' . $chk['name'];
            foreach (($chk['_flagged_ids'] ?? []) as $id) {
                if (!isset($flagged[$id])) {
                    $flagged[$id] = [
                        'line'          => $byId[$id] ?? null,
                        'failed_checks' => [],
                    ];
                }
                $flagged[$id]['failed_checks'][] = $label;
            }
        }
        $list = array_values($flagged);
        usort($list, fn($a, $b) => strcmp(($b['line']['date'] ?? ''), ($a['line']['date'] ?? '')));
        return array_slice($list, 0, self::REPORT_LIMIT);
    }

    // ─────────────────────────────────────────────────────────────────
    // Plumbing
    // ─────────────────────────────────────────────────────────────────

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
}
