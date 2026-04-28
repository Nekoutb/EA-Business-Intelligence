<?php
declare(strict_types=1);

namespace App\Services\Tax;

use App\Odoo\OdooClient;

/**
 * Monthly VAT Return — month-window aggregation of Output / Input VAT
 * and the resulting net VAT due to the State (or carry-forward credit).
 *
 * Methodology (SYSCOHADA AUDCIF + Cameroon CGI)
 * ─────────────────────────────────────────────
 *   Output VAT (TVA collectée)
 *     = |Σ balance| on accounts where code starts with `4431`, posted,
 *       date in the selected month. Account 4431 is credit-positive
 *       (Odoo stores negative); we report magnitude.
 *
 *   Input VAT (TVA déductible)
 *     = Σ balance on accounts where code starts with `4452` (capex VAT)
 *       or `4456` (operating VAT), posted, in the selected month.
 *       Both are debit-positive (Odoo stores positive); reported as-is.
 *
 *   Net VAT
 *     = Output − Input. Positive ⇒ owed to State (TVA à décaisser, 4433).
 *                       Negative ⇒ credit carried forward (4434).
 *
 *   Sales subject to VAT (taxable base)
 *     = |Σ balance| on accounts where code starts with `70` (excluding
 *       `709` rebates), posted, in the selected month. SYSCOHADA convention.
 *
 *   Filing window: 15th of the next month (DGI portal monthly declaration).
 *
 * Performance
 *   - All metrics resolved through `read_group` server-aggregations.
 *     Two account-id lookups (one for the 4431+4452+4456+4434 pool, one
 *     for class 70). Each metric = one read_group call.
 *
 * Output shape
 *   [
 *     'currency'      => string,
 *     'period'        => ['year','month','from','to','label','due_date','due_status'],
 *     'output_vat'    => float,    // positive magnitude
 *     'input_vat_capex'   => float,
 *     'input_vat_opex'    => float,
 *     'input_vat'         => float,
 *     'vat_credit_carry'  => float,    // 4434 magnitude
 *     'net_vat_due'   => float,    // signed; positive = owed
 *     'sales_taxable_base' => float,
 *     'breakdown'     => [
 *         ['account' => '4431', 'label' => 'TVA facturée',           'amount' => ..., 'side' => 'credit'],
 *         ['account' => '4452', 'label' => 'TVA déductible / immo',  'amount' => ..., 'side' => 'debit' ],
 *         ['account' => '4456', 'label' => 'TVA déductible / autres','amount' => ..., 'side' => 'debit' ],
 *         ['account' => '4434', 'label' => 'TVA crédit reporté',     'amount' => ..., 'side' => 'debit' ],
 *     ],
 *     'available_months' => [['value'=>'YYYY-MM','label'=>'Apr 2026'], ...],
 *     'as_of'         => 'YYYY-MM-DD',
 *   ]
 */
final class MonthlyVatReturnService
{
    private const VAT_OUTPUT_PREFIX       = '4431';
    private const VAT_INPUT_CAPEX_PREFIX  = '4452';
    private const VAT_INPUT_OPEX_PREFIX   = '4456';
    private const VAT_CREDIT_CARRY_PREFIX = '4434';

    public function __construct(private OdooClient $odoo) {}

    /**
     * @param string|null $month  YYYY-MM. Defaults to current calendar month.
     */
    public function analyze(?string $month = null): array
    {
        $today = new \DateTimeImmutable('today');
        $month = $month && preg_match('/^\d{4}-\d{2}$/', $month) ? $month : $today->format('Y-m');

        // Window: first to last day of selected month.
        [$year, $m] = array_map('intval', explode('-', $month));
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m));
        $to   = $from->modify('last day of this month');
        $dueDate = $from->modify('+1 month')->modify('+14 days'); // 15th of next month
        $dueStatus = match (true) {
            $today < $from           => 'future',
            $today <= $dueDate       => 'pending',
            default                  => 'past_due',
        };

        // Resolve account ids by prefix in one shot.
        $accountIds = $this->resolveVatAccountIds();

        // Window aggregations.
        $output     = $this->sumBalanceWindow($accountIds[self::VAT_OUTPUT_PREFIX]       ?? [], $from, $to);
        $inputCapex = $this->sumBalanceWindow($accountIds[self::VAT_INPUT_CAPEX_PREFIX]  ?? [], $from, $to);
        $inputOpex  = $this->sumBalanceWindow($accountIds[self::VAT_INPUT_OPEX_PREFIX]   ?? [], $from, $to);
        $creditCarry= $this->sumBalanceWindow($accountIds[self::VAT_CREDIT_CARRY_PREFIX] ?? [], $from, $to);

        $outputAbs   = abs($output);    // 4431 stored as credit (negative)
        $inputAbs    = $inputCapex + $inputOpex;     // both debit-positive
        $netVatDue   = $outputAbs - $inputAbs;       // signed

        // Class-70 taxable base (sales).
        $salesAccountIds = $this->classSalesAccountIds();
        $sales           = $this->sumBalanceWindow($salesAccountIds, $from, $to);
        $salesBase       = abs($sales);

        return [
            'currency'           => $this->detectCurrency(),
            'period'             => [
                'year'        => $year,
                'month'       => $m,
                'from'        => $from->format('Y-m-d'),
                'to'          => $to->format('Y-m-d'),
                'label'       => $from->format('F Y'),
                'due_date'    => $dueDate->format('Y-m-d'),
                'due_status'  => $dueStatus,
            ],
            'output_vat'         => round($outputAbs, 2),
            'input_vat_capex'    => round($inputCapex, 2),
            'input_vat_opex'     => round($inputOpex, 2),
            'input_vat'          => round($inputAbs, 2),
            'vat_credit_carry'   => round(abs($creditCarry), 2),
            'net_vat_due'        => round($netVatDue, 2),
            'sales_taxable_base' => round($salesBase, 2),
            'breakdown'          => [
                ['account' => '4431', 'label' => 'TVA facturée (output)',          'amount' => round($outputAbs, 2),   'side' => 'credit'],
                ['account' => '4452', 'label' => 'TVA déductible / immobilisations','amount' => round($inputCapex, 2), 'side' => 'debit' ],
                ['account' => '4456', 'label' => 'TVA déductible / autres B&S',    'amount' => round($inputOpex, 2),   'side' => 'debit' ],
                ['account' => '4434', 'label' => 'TVA crédit reporté',             'amount' => round(abs($creditCarry), 2), 'side' => 'debit' ],
            ],
            'available_months'   => $this->buildMonthOptions($today),
            'as_of'              => $today->format('Y-m-d'),
        ];
    }

    /**
     * Build a "last 12 months" dropdown including the current month.
     * Newest first.
     */
    private function buildMonthOptions(\DateTimeImmutable $today): array
    {
        $opts = [];
        for ($i = 0; $i < 12; $i++) {
            $d = $today->modify("first day of -{$i} month");
            $opts[] = [
                'value' => $d->format('Y-m'),
                'label' => $d->format('F Y'),
            ];
        }
        return $opts;
    }

    /**
     * Resolve all VAT-related account ids in one Odoo call. Returns a
     * map keyed by prefix → list of ids whose code starts with that prefix.
     */
    private function resolveVatAccountIds(): array
    {
        $rows = $this->odoo->searchRead('account.account',
            [
                '|', '|', '|',
                ['code', '=like', self::VAT_OUTPUT_PREFIX       . '%'],
                ['code', '=like', self::VAT_INPUT_CAPEX_PREFIX  . '%'],
                ['code', '=like', self::VAT_INPUT_OPEX_PREFIX   . '%'],
                ['code', '=like', self::VAT_CREDIT_CARRY_PREFIX . '%'],
            ],
            ['id', 'code'], []
        );
        $byPrefix = [
            self::VAT_OUTPUT_PREFIX        => [],
            self::VAT_INPUT_CAPEX_PREFIX   => [],
            self::VAT_INPUT_OPEX_PREFIX    => [],
            self::VAT_CREDIT_CARRY_PREFIX  => [],
        ];
        foreach ($rows as $r) {
            $code = (string) ($r['code'] ?? '');
            if ($code === '') continue;
            foreach (array_keys($byPrefix) as $p) {
                if (str_starts_with($code, $p)) {
                    $byPrefix[$p][] = (int) $r['id'];
                    break;
                }
            }
        }
        return $byPrefix;
    }

    /**
     * Class-70 sales accounts EXCLUDING 709 (rebates). Single Odoo call.
     */
    private function classSalesAccountIds(): array
    {
        $rows = $this->odoo->searchRead('account.account',
            [['code', '=like', '70%']],
            ['id', 'code'], []
        );
        $out = [];
        foreach ($rows as $r) {
            $code = (string) ($r['code'] ?? '');
            if ($code === '' || str_starts_with($code, '709')) continue;
            $out[] = (int) $r['id'];
        }
        return $out;
    }

    private function sumBalanceWindow(array $accountIds, \DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        if ($accountIds === []) return 0.0;
        $agg = $this->odoo->callModel('account.move.line', 'read_group', [
            'domain' => [
                ['account_id', 'in', $accountIds],
                ['date', '>=', $from->format('Y-m-d')],
                ['date', '<=', $to->format('Y-m-d')],
                ['parent_state', '=', 'posted'],
            ],
            'fields'  => ['balance:sum'],
            'groupby' => [],
            'lazy'    => false,
        ]);
        return (float) ($agg[0]['balance'] ?? 0);
    }

    private function detectCurrency(): string
    {
        try {
            $companies = $this->odoo->searchRead('res.company', [], ['currency_id'], [], 1);
            if (!empty($companies) && !empty($companies[0]['currency_id'])) {
                return $companies[0]['currency_id'][1] ?? 'XAF';
            }
        } catch (\Throwable $e) { /* fall through */ }
        return 'XAF';
    }
}
