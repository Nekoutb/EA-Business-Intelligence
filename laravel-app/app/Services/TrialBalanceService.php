<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Trial Balance — Balance Générale (SYSCOHADA / OHADA format).
 *
 * For each posted account, surfaces the audit-standard 6 amounts
 * grouped into 3 pairs:
 *
 *   Opening balance      | Solde au début       | Débiteur / Créditeur
 *   Movements            | Mouvements           | Débit / Crédit
 *   Closing balance      | Solde de fin         | Débiteur / Créditeur
 *
 * Maths:
 *   opening_balance  = Σ(debit − credit) for all posted lines BEFORE
 *                     period_start
 *   movement_debit   = Σ debit for posted lines IN [period_start, period_end]
 *   movement_credit  = Σ credit for posted lines IN [period_start, period_end]
 *   closing_balance  = opening_balance + movement_debit − movement_credit
 *
 *   For display per OHADA convention:
 *     Opening Dr  = max(opening_balance,  0)
 *     Opening Cr  = max(-opening_balance, 0)
 *     Closing Dr  = max(closing_balance,  0)
 *     Closing Cr  = max(-closing_balance, 0)
 *
 * Period semantics — driven by `periodType`:
 *   'month' → opening = start of selected month
 *             movement = lines within the selected month only
 *             closing = end of selected month
 *   'ytd'   → opening = start of selected year
 *             movement = Jan-1 to end of selected month
 *             closing = end of selected month
 *   'year'  → opening = start of selected year
 *             movement = full selected year (Jan-1 to Dec-31)
 *             closing = Dec-31 of selected year
 *
 * Two Odoo round trips: one read_group for opening balances, one for
 * the period movements. Closing is derived in PHP.
 *
 * Filtering: the service returns ONLY accounts with at least one
 * non-zero column. Accounts that are dormant for the period and have
 * a zero opening balance are dropped — typical OHADA Balance Générale
 * already does this so the report stays auditor-friendly.
 *
 * Footer totals ALWAYS balance:
 *   Σ Opening Dr = Σ Opening Cr
 *   Σ Movement Dr = Σ Movement Cr
 *   Σ Closing Dr = Σ Closing Cr
 * — handy gut check for whether the GL is in trial-balance state.
 */
final class TrialBalanceService
{
    private OdooClient $odoo;

    public function __construct(OdooClient $odoo)
    {
        $this->odoo = $odoo;
    }

    /**
     * @param int    $year       Fiscal year (default: current)
     * @param string $periodType 'month' | 'ytd' | 'year'
     * @param int    $month      1..12 — used for 'month' and 'ytd'
     */
    public function analyze(?int $year = null, string $periodType = 'ytd', ?int $month = null): array
    {
        $today  = new \DateTimeImmutable('today');
        $year   = $year ?? (int) $today->format('Y');
        $month  = $month ?? max(1, ((int) $today->format('n')) - 1); // last closed month
        $month  = max(1, min(12, $month));

        // Resolve [period_start, period_end]
        $periodType = in_array($periodType, ['month', 'ytd', 'year'], true) ? $periodType : 'ytd';
        switch ($periodType) {
            case 'month':
                $periodStart = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
                $periodEnd   = $periodStart->modify('last day of this month');
                $label       = $periodStart->format('M Y') . ' (single month)';
                break;
            case 'year':
                $periodStart = new \DateTimeImmutable($year . '-01-01');
                $periodEnd   = new \DateTimeImmutable($year . '-12-31');
                $label       = (string) $year . ' (full year)';
                break;
            case 'ytd':
            default:
                $periodStart = new \DateTimeImmutable($year . '-01-01');
                $monthStart  = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
                $periodEnd   = $monthStart->modify('last day of this month');
                $label       = sprintf('YTD %d through %s', $year, $monthStart->format('M Y'));
                break;
        }

        // 1) Pull every account in the chart so we can resolve names
        $accounts = $this->odoo->searchRead('account.account', [],
            ['id', 'code', 'name'], ['order' => 'code']);
        if (empty($accounts)) return $this->emptyResult($year, $periodType, $month, $label, $periodStart, $periodEnd);

        $accountById = [];
        foreach ($accounts as $a) {
            $accountById[(int) $a['id']] = [
                'code' => (string) ($a['code'] ?? ''),
                'name' => (string) ($a['name'] ?? ''),
            ];
        }

        // 2) Opening balances — sum of (debit, credit) for all posted
        //    lines BEFORE period_start. read_group server-side.
        $openingByAccount = $this->fetchAccountSums([
            ['parent_state', '=', 'posted'],
            ['date', '<',  $periodStart->format('Y-m-d')],
        ]);

        // 3) Movement totals — sum of (debit, credit) for posted lines
        //    WITHIN [period_start, period_end].
        $movementByAccount = $this->fetchAccountSums([
            ['parent_state', '=', 'posted'],
            ['date', '>=', $periodStart->format('Y-m-d')],
            ['date', '<=', $periodEnd->format('Y-m-d')],
        ]);

        // 4) Build rows. Account universe = union of accounts with any
        //    non-zero figure across opening or movement.
        $rows = [];
        $totals = [
            'opening_dr' => 0.0, 'opening_cr' => 0.0,
            'movement_dr' => 0.0, 'movement_cr' => 0.0,
            'closing_dr' => 0.0, 'closing_cr' => 0.0,
        ];
        $allIds = array_unique(array_merge(
            array_keys($openingByAccount),
            array_keys($movementByAccount)
        ));
        foreach ($allIds as $aid) {
            $info  = $accountById[$aid] ?? ['code' => '?', 'name' => '?'];
            $oDr   = (float) ($openingByAccount[$aid]['debit']  ?? 0);
            $oCr   = (float) ($openingByAccount[$aid]['credit'] ?? 0);
            $mDr   = (float) ($movementByAccount[$aid]['debit']  ?? 0);
            $mCr   = (float) ($movementByAccount[$aid]['credit'] ?? 0);

            $openingBal = $oDr - $oCr;
            $closingBal = $openingBal + $mDr - $mCr;

            $openingDr = $openingBal >  0 ? $openingBal : 0.0;
            $openingCr = $openingBal <  0 ? -$openingBal : 0.0;
            $closingDr = $closingBal >  0 ? $closingBal : 0.0;
            $closingCr = $closingBal <  0 ? -$closingBal : 0.0;

            // Skip accounts that are silent across all six columns
            if ((abs($openingDr) + abs($openingCr) + abs($mDr) + abs($mCr) + abs($closingDr) + abs($closingCr)) < 0.01) {
                continue;
            }

            $rows[] = [
                'account_id' => $aid,
                'code'       => $info['code'],
                'name'       => $info['name'],
                'class'      => $info['code'] !== '' ? (int) substr($info['code'], 0, 1) : 0,
                'opening_dr' => $openingDr,
                'opening_cr' => $openingCr,
                'movement_dr'=> $mDr,
                'movement_cr'=> $mCr,
                'closing_dr' => $closingDr,
                'closing_cr' => $closingCr,
            ];
            $totals['opening_dr']  += $openingDr;
            $totals['opening_cr']  += $openingCr;
            $totals['movement_dr'] += $mDr;
            $totals['movement_cr'] += $mCr;
            $totals['closing_dr']  += $closingDr;
            $totals['closing_cr']  += $closingCr;
        }

        // Sort by code (default lexicographic order is fine on
        // SYSCOHADA's all-digit codes; for non-numeric codes it still
        // produces a stable, predictable view).
        usort($rows, fn ($a, $b) => strcmp((string) $a['code'], (string) $b['code']));

        // Class subtotals — auditors usually want these
        $byClass = [];
        foreach ($rows as $r) {
            $cls = $r['class'];
            $byClass[$cls] ??= [
                'class' => $cls,
                'opening_dr' => 0.0, 'opening_cr' => 0.0,
                'movement_dr' => 0.0, 'movement_cr' => 0.0,
                'closing_dr' => 0.0, 'closing_cr' => 0.0,
                'count' => 0,
            ];
            $byClass[$cls]['opening_dr']  += $r['opening_dr'];
            $byClass[$cls]['opening_cr']  += $r['opening_cr'];
            $byClass[$cls]['movement_dr'] += $r['movement_dr'];
            $byClass[$cls]['movement_cr'] += $r['movement_cr'];
            $byClass[$cls]['closing_dr']  += $r['closing_dr'];
            $byClass[$cls]['closing_cr']  += $r['closing_cr'];
            $byClass[$cls]['count']++;
        }
        ksort($byClass);

        // Balance check — these should match (gut-feel check)
        $balanceCheck = [
            'opening'  => abs($totals['opening_dr']  - $totals['opening_cr'])  < 1.0,
            'movement' => abs($totals['movement_dr'] - $totals['movement_cr']) < 1.0,
            'closing'  => abs($totals['closing_dr']  - $totals['closing_cr'])  < 1.0,
        ];

        return [
            'currency'      => 'XAF',
            'year'          => $year,
            'month'         => $month,
            'period_type'   => $periodType,
            'period_label'  => $label,
            'period_start'  => $periodStart->format('Y-m-d'),
            'period_end'    => $periodEnd->format('Y-m-d'),
            'rows'          => $rows,
            'totals'        => $totals,
            'class_totals'  => array_values($byClass),
            'balance_check' => $balanceCheck,
            'fetched_at'    => now()->toIso8601String(),
        ];
    }

    /**
     * read_group on account.move.line, grouping by account_id and
     * summing debit and credit. Returns id => ['debit' => float,
     * 'credit' => float].
     *
     * @param array $domain Odoo domain (caller adds the date filters)
     * @return array<int, array{debit:float, credit:float}>
     */
    private function fetchAccountSums(array $domain): array
    {
        try {
            $rows = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain'  => $domain,
                'fields'  => ['account_id', 'debit:sum', 'credit:sum'],
                'groupby' => ['account_id'],
                'lazy'    => false,
            ]);
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            if (!isset($r['account_id']) || !is_array($r['account_id'])) continue;
            $aid = (int) $r['account_id'][0];
            $out[$aid] = [
                'debit'  => (float) ($r['debit']  ?? 0),
                'credit' => (float) ($r['credit'] ?? 0),
            ];
        }
        return $out;
    }

    private function emptyResult(int $year, string $periodType, int $month, string $label, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return [
            'currency'      => 'XAF',
            'year'          => $year,
            'month'         => $month,
            'period_type'   => $periodType,
            'period_label'  => $label,
            'period_start'  => $start->format('Y-m-d'),
            'period_end'    => $end->format('Y-m-d'),
            'rows'          => [],
            'totals'        => [
                'opening_dr' => 0.0, 'opening_cr' => 0.0,
                'movement_dr' => 0.0, 'movement_cr' => 0.0,
                'closing_dr' => 0.0, 'closing_cr' => 0.0,
            ],
            'class_totals'  => [],
            'balance_check' => ['opening' => true, 'movement' => true, 'closing' => true],
            'fetched_at'    => now()->toIso8601String(),
        ];
    }
}
