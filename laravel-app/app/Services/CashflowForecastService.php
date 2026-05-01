<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Cashflow Forecast — monthly indirect-method cashflow with prior
 * months as actuals and remaining months as forecast (SMLY).
 *
 * Indirect method (SYSCOHADA-aligned):
 *
 *   Operating CF
 *     Net Result (from P&L)
 *     + D&A (Class 68 — non-cash)
 *     − Δ AR  (Class 41 — increase in AR consumes cash)
 *     − Δ Inventory (Class 3)
 *     + Δ AP  (Class 40 — increase in AP releases cash)
 *
 *   Investing CF
 *     − Capex (positive Class 2 movements — net additions to fixed assets)
 *
 *   Financing CF
 *     ± Δ LT Debt (Class 16-19 changes)
 *     ± Δ Equity / capital injections (Class 10 changes excl. retained earnings)
 *
 *   Net Change in Cash = Operating + Investing + Financing
 *   Ending Cash       = Opening cash + Net change
 *
 * For V1 we focus on Operating CF (the most significant component for
 * a service business) and a simplified Investing / Financing read.
 * Forecast follows the same SMLY logic as PLForecastService.
 */
final class CashflowForecastService
{
    private OdooClient $odoo;

    public function __construct(OdooClient $odoo)
    {
        $this->odoo = $odoo;
    }

    public function analyze(?int $year = null): array
    {
        $today = new \DateTimeImmutable('today');
        $year  = $year ?? (int) $today->format('Y');

        $thruMonth = $year < (int) $today->format('Y')
            ? 12
            : ($year > (int) $today->format('Y') ? 0 : max(0, ((int) $today->format('n')) - 1));

        // 1. Pull P&L net result + D&A monthly (reuse PLForecastService logic
        //    by instantiating it). This keeps the cashflow's net-income
        //    line consistent with the P&L page exactly.
        $plData = (new PLForecastService($this->odoo))->analyze($year);
        $plLines = collect($plData['lines'] ?? []);

        $netByMonth = $plLines->firstWhere('key', 'net_result')['monthly'] ?? array_fill(1, 12, 0.0);
        $daByMonth  = $plLines->firstWhere('key', 'depreciation')['monthly'] ?? array_fill(1, 12, 0.0);
        // D&A on the P&L is stored as negative (cost). For cashflow we
        // ADD it back (it's a non-cash charge), so flip the sign.
        $daAddBack = [];
        foreach ($daByMonth as $m => $v) $daAddBack[$m] = -$v;

        // 2. Working capital changes — Δ AR, Δ Inventory, Δ AP from
        //    balance-sheet snapshots at month-end. Δ this month = month-end
        //    balance − previous month-end balance.
        $arDelta  = $this->fetchMonthlyBalanceDeltas('41', $year);
        $invDelta = $this->fetchMonthlyBalanceDeltas('3',  $year);
        $apDelta  = $this->fetchMonthlyBalanceDeltas('40', $year);

        // For working capital: increase in AR or Inventory consumes cash;
        // increase in AP releases cash. Sign convention for AR is debit-
        // normal (positive delta = cash outflow → −1 sign), for AP credit-
        // normal (negative delta = cash inflow → +1 sign).
        $opCf = [];
        for ($m = 1; $m <= 12; $m++) {
            $opCf[$m] = ($netByMonth[$m] ?? 0)
                      + ($daAddBack[$m] ?? 0)
                      - ($arDelta[$m]   ?? 0)
                      - ($invDelta[$m]  ?? 0)
                      - ($apDelta[$m]   ?? 0);  // Class 40 balance is negative when liability; positive Δ = cash inflow → minus a negative = plus
        }

        // 3. Investing CF — Class 2 (fixed assets) NET additions.
        $capex = $this->fetchMonthlyBalanceDeltas('2', $year);
        $invCf = [];
        for ($m = 1; $m <= 12; $m++) $invCf[$m] = -($capex[$m] ?? 0);

        // 4. Financing CF — Class 16-19 (LT debt) + Class 10 (capital).
        $debtDelta   = $this->fetchMonthlyBalanceDeltas('1', $year);
        $finCf = [];
        for ($m = 1; $m <= 12; $m++) $finCf[$m] = -($debtDelta[$m] ?? 0); // class 1 credit-normal

        // 5. Net change + ending cash trajectory
        $netChange = [];
        for ($m = 1; $m <= 12; $m++) {
            $netChange[$m] = ($opCf[$m] ?? 0) + ($invCf[$m] ?? 0) + ($finCf[$m] ?? 0);
        }

        // Opening cash = Class 5 balance at start of year
        $openingCash = $this->fetchClass5BalanceAt(new \DateTimeImmutable($year . '-01-01'));
        $endingCash = [];
        $running = $openingCash;
        for ($m = 1; $m <= 12; $m++) {
            $running += ($netChange[$m] ?? 0);
            $endingCash[$m] = $running;
        }

        return [
            'currency'    => 'XAF',
            'year'        => $year,
            'thru_month'  => $thruMonth,
            'months'      => $this->buildMonthsHeader($year, $thruMonth),
            'lines'       => [
                ['key' => 'net_result',  'label' => 'Net Result',                 'monthly' => $netByMonth, 'kind' => 'leaf',     'colour' => 'sage'],
                ['key' => 'da',          'label' => '+ Depreciation & amortisation', 'monthly' => $daAddBack, 'kind' => 'leaf',     'colour' => 'brand'],
                ['key' => 'ar',          'label' => '− Δ Accounts Receivable',    'monthly' => $this->negate($arDelta),  'kind' => 'leaf',     'colour' => 'warm'],
                ['key' => 'inv',         'label' => '− Δ Inventory',              'monthly' => $this->negate($invDelta), 'kind' => 'leaf',     'colour' => 'warm'],
                ['key' => 'ap',          'label' => '− Δ Accounts Payable (signed)', 'monthly' => $this->negate($apDelta), 'kind' => 'leaf',     'colour' => 'warm'],
                ['key' => 'op_cf',       'label' => 'Operating Cash Flow',        'monthly' => $opCf,        'kind' => 'subtotal', 'colour' => 'sage'],
                ['key' => 'capex',       'label' => '− Capex (Class 2 net adds)',  'monthly' => $invCf,       'kind' => 'leaf',     'colour' => 'coral'],
                ['key' => 'inv_cf',      'label' => 'Investing Cash Flow',        'monthly' => $invCf,       'kind' => 'subtotal', 'colour' => 'brand'],
                ['key' => 'fin_cf',      'label' => 'Financing Cash Flow',        'monthly' => $finCf,       'kind' => 'subtotal', 'colour' => 'warm'],
                ['key' => 'net_change',  'label' => 'Net Change in Cash',         'monthly' => $netChange,   'kind' => 'subtotal', 'colour' => 'brand'],
                ['key' => 'ending_cash', 'label' => 'Ending Cash Position',       'monthly' => $endingCash,  'kind' => 'subtotal', 'colour' => 'sage'],
            ],
            'opening_cash' => $openingCash,
            'fetched_at'   => now()->toIso8601String(),
        ];
    }

    private function negate(array $vec): array
    {
        $out = [];
        foreach ($vec as $k => $v) $out[$k] = -$v;
        return $out;
    }

    /**
     * For each month of the year, compute the change in account balance
     * (sum of class N) from start-of-month to end-of-month.
     */
    private function fetchMonthlyBalanceDeltas(string $classPrefix, int $year): array
    {
        $deltas = array_fill(1, 12, 0.0);
        try {
            $accts = $this->odoo->searchRead('account.account',
                [['code', '=like', $classPrefix . '%']], ['id'], []);
            if (empty($accts)) return $deltas;
            $ids = array_column($accts, 'id');

            $rows = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain'  => [
                    ['parent_state', '=', 'posted'],
                    ['date', '>=', $year . '-01-01'],
                    ['date', '<=', $year . '-12-31'],
                    ['account_id', 'in', $ids],
                ],
                'fields'  => ['balance:sum'],
                'groupby' => ['date:month'],
                'lazy'    => false,
            ]);

            foreach ($rows as $r) {
                $label = (string) ($r['date:month'] ?? '');
                $bal   = (float) ($r['balance'] ?? 0);
                $month = $this->parseMonthLabel($label);
                if ($month >= 1 && $month <= 12) $deltas[$month] = $bal;
            }
        } catch (\Throwable $e) { /* leave at zero */ }
        return $deltas;
    }

    /** Cash + bank balance at a specific date (Class 5 sum). */
    private function fetchClass5BalanceAt(\DateTimeImmutable $asOf): float
    {
        try {
            $accts = $this->odoo->searchRead('account.account',
                [['code', '=like', '5%']], ['id'], []);
            if (empty($accts)) return 0.0;
            $ids = array_column($accts, 'id');
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
            return (float) ($rows[0]['balance'] ?? 0);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    private function parseMonthLabel(string $label): int
    {
        $months = [
            'January'=>1,'February'=>2,'March'=>3,'April'=>4,'May'=>5,'June'=>6,
            'July'=>7,'August'=>8,'September'=>9,'October'=>10,'November'=>11,'December'=>12,
            'janvier'=>1,'février'=>2,'mars'=>3,'avril'=>4,'mai'=>5,'juin'=>6,
            'juillet'=>7,'août'=>8,'septembre'=>9,'octobre'=>10,'novembre'=>11,'décembre'=>12,
        ];
        foreach ($months as $name => $m) if (stripos($label, $name) !== false) return $m;
        return 0;
    }

    private function buildMonthsHeader(int $year, int $thruMonth): array
    {
        $names = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $out[$m] = ['num' => $m, 'label' => $names[$m - 1], 'is_forecast' => $m > $thruMonth];
        }
        return $out;
    }
}
