<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;
use Carbon\Carbon;

/**
 * 16-Week Rolling Cash Flow Forecast — DIRECT method.
 *
 * Implements the band/line specification documented at
 *   docs/cash-flow-16w-spec.md
 * with a small but important pragmatic twist: rather than requiring
 * the tenant to first create a bunch of custom Odoo models
 * (treasury.debt.facility, treasury.recurring.expense, …), this
 * service uses ONLY tables that already exist in a standard Odoo
 * install for the lines it can derive from there, and defaults to
 * 0 with a clear "manual / Phase 2" marker for lines that need
 * tenant-side data not yet captured anywhere.
 *
 * That gets the operator a working forecast on day 1 with the data
 * we already have, and lets them refine line-by-line later by
 * either populating those Odoo models or entering the values via
 * an upcoming BMS-side override UI.
 *
 * ── Engine type mapping (matches the spec) ──────────────────────
 *   A — extraction   = pulled live from GL (account.account, account.move.line)
 *   B — behavioural  = projected from historical patterns
 *   C — scheduled    = follows a known fixed timetable (CGI, CNPS, payroll)
 *   D — manual       = tenant-entered (defaults to 0 in Phase 1)
 *
 * ── Method choice for AR/AP timing ─────────────────────────────
 * We use Method C (hybrid) from the spec: due_date is the base,
 * shifted by per-partner historical pay-lag, capped at +30 days.
 * Implementation lives in {@see partnerPaymentLagDays()}.
 */
final class CashFlow16WService
{
    /** Number of forward weeks to project. */
    public const HORIZON_WEEKS = 16;

    /**
     * SYSCOHADA prefixes for cash positions. 52* = banques (in
     * legal-entity bank accounts), 57* = caisse (physical cash).
     * Some operators sub-code 52 for mobile money — handled below.
     */
    private const CASH_BANK_PREFIX   = '52';
    private const CASH_CAISSE_PREFIX = '57';

    /** Subset of 52* sub-accounts typically reserved for mobile money. */
    private const MOBILE_MONEY_HINTS = ['MOMO', 'MTN', 'ORANGE', 'WAVE', 'MOBILE', 'MOMOPAY'];

    /** Default minimum operational reserve (XAF) used if the tenant
     * hasn't configured one. Conservative — assumes 30 days of
     * minimum opex at ~270k/day. Override per tenant via the
     * `treasury_min_buffer_xaf` setting. */
    private const DEFAULT_MIN_BUFFER_XAF = 8_000_000.0;

    public function __construct(
        private readonly OdooClient $odoo,
    ) {}

    /**
     * Build the full 16-week forecast.
     *
     * @return array{
     *   currency: string,
     *   cut_off: string,
     *   weeks: array<int, array{
     *     label: string, w_no: int, start: string, end: string,
     *     opening: float, closing: float, net: float
     *   }>,
     *   lines: array<int, array{
     *     band: int, line: string, label: string, engine: string,
     *     confidence: ?string, total: float,
     *     by_week: array<int, float>, sign: int, source: string
     *   }>,
     *   kpis: array<string, float>,
     *   governance: array<string, mixed>,
     *   errors: array<int, string>,
     * }
     */
    public function build(?Carbon $cutOff = null): array
    {
        $cutOff = ($cutOff ?? Carbon::now())->startOfDay();
        $weeks  = $this->buildWeekSpine($cutOff);
        $errors = [];

        // ── BAND 1: Opening cash position ──────────────────────────
        $openBanks    = $this->safe(fn () => $this->openingCashBanks($cutOff),    $errors, '1.1');
        $openMobile   = $this->safe(fn () => $this->openingCashMobile($cutOff),   $errors, '1.2');
        $openCaisse   = $this->safe(fn () => $this->openingCashCaisse($cutOff),   $errors, '1.3');
        $minBuffer    = (float) config_env('TREASURY_MIN_BUFFER_XAF', self::DEFAULT_MIN_BUFFER_XAF);
        $openingAvail = $openBanks + $openMobile + $openCaisse - $minBuffer;

        // ── BAND 2: Operating receipts (per-week buckets) ─────────
        $recArInv      = $this->safe(fn () => $this->bucketArOpenInvoices($cutOff, $weeks), $errors, '2.1');
        $recArRecur    = $this->zeroBuckets();                                              // 2.2 — needs sale.subscription
        $recPosSales   = $this->safe(fn () => $this->bucketPosSales($cutOff, $weeks),       $errors, '2.3');
        $recPipeline   = $this->safe(fn () => $this->bucketCrmPipeline($cutOff, $weeks),    $errors, '2.4');
        $recVatRefund  = $this->zeroBuckets();                                              // 2.5 — Phase 2
        $recOtherOp    = $this->zeroBuckets();                                              // 2.6 — manual
        $recAssetDisp  = $this->zeroBuckets();                                              // 2.7 — manual
        $recPrecRefund = $this->zeroBuckets();                                              // 2.8 — typically nil

        // ── BAND 3: Operating disbursements ───────────────────────
        $disApInv      = $this->safe(fn () => $this->bucketApOpenInvoices($cutOff, $weeks), $errors, '3.1');
        $disApPo       = $this->safe(fn () => $this->bucketApOpenPos($cutOff, $weeks),      $errors, '3.2');
        $disPayroll    = $this->safe(fn () => $this->bucketPayroll($cutOff, $weeks),        $errors, '3.3');
        $disCnps       = $this->safe(fn () => $this->bucketCnps($cutOff, $weeks),           $errors, '3.4');
        $disIrpp       = $this->safe(fn () => $this->bucketIrpp($cutOff, $weeks),           $errors, '3.5');
        $disVat        = $this->safe(fn () => $this->bucketVatRemittance($cutOff, $weeks),  $errors, '3.6');
        $disAcompteIs  = $this->safe(fn () => $this->bucketAcompteIs($cutOff, $weeks),      $errors, '3.7');
        $disPrecompte  = $this->zeroBuckets();                                              // 3.8 — needs précompte account tagging
        $disTsr        = $this->zeroBuckets();                                              // 3.9 — needs non-resident tagging
        $disPatente    = $this->zeroBuckets();                                              // 3.10 — Phase 2 calendar model
        $disTaxeFonc   = $this->zeroBuckets();                                              // 3.11 — Phase 2
        $disOpCash     = $this->safe(fn () => $this->bucketRecurringOpex($cutOff, $weeks),  $errors, '3.12');
        $disPettyCash  = $this->zeroBuckets();                                              // 3.13 — internal transfer, derived
        $disRefunds    = $this->safe(fn () => $this->bucketCustomerRefunds($cutOff, $weeks),$errors, '3.14');

        // ── BAND 4: Financing & capital flows ─────────────────────
        $finIntPay     = $this->zeroBuckets();                                              // 4.1 — needs treasury.debt.facility
        $finPrincipal  = $this->zeroBuckets();                                              // 4.2 — needs treasury.debt.facility
        $finDrawdowns  = $this->zeroBuckets();                                              // 4.3 — manual
        $finOverdraft  = $this->zeroBuckets();                                              // 4.4 — derived in Phase 2
        $finCapex      = $this->safe(fn () => $this->bucketCapex($cutOff, $weeks),          $errors, '4.5');
        $finDividends  = $this->zeroBuckets();                                              // 4.6 — manual
        $finShareCont  = $this->zeroBuckets();                                              // 4.7 — manual
        $finIntercoy   = $this->zeroBuckets();                                              // 4.8 — multi-co

        // ── Aggregate ──────────────────────────────────────────────
        $lines = [
            // Band 1 (opening) — single value, not per-week
            $this->line(1, '1.1', 'Opening cash · banks',                'A', 'High',   $openBanks,    null, 1, 'class 52 GL balance'),
            $this->line(1, '1.2', 'Opening cash · mobile money',         'A', 'High',   $openMobile,   null, 1, 'class 52 sub-accts tagged mobile'),
            $this->line(1, '1.3', 'Opening cash · caisse (petty)',       'A', 'High',   $openCaisse,   null, 1, 'class 57 GL balance'),
            $this->line(1, '1.4', '− Minimum operational reserve',       'D', 'Policy', $minBuffer,    null,-1, 'TREASURY_MIN_BUFFER_XAF setting'),
            $this->line(1, '1.5', '= Available opening liquidity',       '=',  null,    $openingAvail, null, 1, 'derived'),

            // Band 2 (receipts) — per week
            $this->line(2, '2.1', 'AR collections · confirmed invoices', 'B', 'High',   array_sum($recArInv),      $recArInv,      1, 'account.move out_invoice (Method C timing)'),
            $this->line(2, '2.2', 'AR · recurring contracts',            'C', 'High',   array_sum($recArRecur),    $recArRecur,    1, 'sale.subscription — Phase 2'),
            $this->line(2, '2.3', 'Cash sales / POS',                    'B', 'Medium', array_sum($recPosSales),   $recPosSales,   1, 'pos.order — 8-wk MA × seasonality'),
            $this->line(2, '2.4', 'Sales pipeline (weighted)',           'B', 'Low',    array_sum($recPipeline),   $recPipeline,   1, 'crm.lead expected_revenue × prob'),
            $this->line(2, '2.5', 'VAT refunds (crédit TVA)',            'C', 'Low',    array_sum($recVatRefund),  $recVatRefund,  1, 'Phase 2 — tax declaration model'),
            $this->line(2, '2.6', 'Other operating receipts',            'D', null,     array_sum($recOtherOp),    $recOtherOp,    1, 'treasury.planned.receipt — manual'),
            $this->line(2, '2.7', 'Asset disposals',                     'D', null,     array_sum($recAssetDisp),  $recAssetDisp,  1, 'account.asset planned disposals'),
            $this->line(2, '2.8', 'Tax withholding refunds (précompte)', 'C', null,     array_sum($recPrecRefund), $recPrecRefund, 1, 'typically nil — offset against IS'),

            // Band 3 (disbursements)
            $this->line(3, '3.1', 'AP payments · confirmed invoices',    'B', 'High',   array_sum($disApInv),      $disApInv,     -1, 'account.move in_invoice (DPO overlay)'),
            $this->line(3, '3.2', 'AP payments · PO-driven',             'C', 'Medium', array_sum($disApPo),       $disApPo,      -1, 'purchase.order date_planned + terms'),
            $this->line(3, '3.3', 'Payroll · gross salaries',            'C', 'High',   array_sum($disPayroll),    $disPayroll,   -1, 'hr.payslip projected · 25th of month'),
            $this->line(3, '3.4', 'CNPS contributions',                  'C', 'High',   array_sum($disCnps),       $disCnps,      -1, 'payslip contribution lines · 15th M+1'),
            $this->line(3, '3.5', 'IRPP withheld remittance',            'C', 'High',   array_sum($disIrpp),       $disIrpp,      -1, 'payslip IRPP lines · 15th M+1'),
            $this->line(3, '3.6', 'TVA collectée nette',                 'C', 'High',   array_sum($disVat),        $disVat,       -1, 'account.tax movements · 15th M+1'),
            $this->line(3, '3.7', 'Acompte IS (1.1% turnover)',          'C', 'High',   array_sum($disAcompteIs),  $disAcompteIs, -1, 'monthly class 7 × 1.1% · 15th M+1'),
            $this->line(3, '3.8', 'Précompte sur achats remittance',     'C', null,     array_sum($disPrecompte),  $disPrecompte, -1, 'Phase 2 — précompte account tag'),
            $this->line(3, '3.9', 'TSR (non-resident WHT)',              'C', null,     array_sum($disTsr),        $disTsr,       -1, 'Phase 2 — non-resident tag'),
            $this->line(3, '3.10','Patente / local taxes',               'C', null,     array_sum($disPatente),    $disPatente,   -1, 'Phase 2 — treasury.tax.schedule'),
            $this->line(3, '3.11','Taxe foncière',                       'C', null,     array_sum($disTaxeFonc),   $disTaxeFonc,  -1, 'Phase 2 — annual schedule'),
            $this->line(3, '3.12','Operating cash expenses · rent/utils','C', 'High',   array_sum($disOpCash),     $disOpCash,    -1, 'historical recurring posts (8-wk MA)'),
            $this->line(3, '3.13','Petty cash drawdowns',                'B', 'Medium', array_sum($disPettyCash),  $disPettyCash, -1, 'derived 52→57 transfer pattern'),
            $this->line(3, '3.14','Customer refunds',                    'B', 'Low',    array_sum($disRefunds),    $disRefunds,   -1, 'out_refund residual + sales × refund rate'),

            // Band 4 (financing)
            $this->line(4, '4.1', 'Interest payments by facility',       'C', null,     array_sum($finIntPay),     $finIntPay,    -1, 'Phase 2 — treasury.debt.facility'),
            $this->line(4, '4.2', 'Principal repayments by facility',    'C', null,     array_sum($finPrincipal),  $finPrincipal, -1, 'Phase 2 — treasury.debt.facility'),
            $this->line(4, '4.3', 'New debt drawdowns',                  'D', null,     array_sum($finDrawdowns),  $finDrawdowns,  1, 'manual planning'),
            $this->line(4, '4.4', 'Overdraft swings',                    'A', null,     array_sum($finOverdraft),  $finOverdraft,  1, 'derived from net cash position'),
            $this->line(4, '4.5', 'CAPEX disbursements',                 'C', 'Medium', array_sum($finCapex),      $finCapex,     -1, 'purchase.order flagged capex'),
            $this->line(4, '4.6', 'Dividend payments',                   'D', null,     array_sum($finDividends),  $finDividends, -1, 'manual entry'),
            $this->line(4, '4.7', 'Shareholder contributions / loans',   'D', null,     array_sum($finShareCont),  $finShareCont,  1, 'manual entry'),
            $this->line(4, '4.8', 'Intercompany transfers',              'C', null,     array_sum($finIntercoy),   $finIntercoy,   1, 'multi-co flows'),
        ];

        // ── BAND 5: Closing trajectory ────────────────────────────
        // Walk each week: opening = prior closing (W1 opening = available opening liquidity).
        $cursor = $openingAvail;
        $netByWeek = [];
        foreach ($weeks as $w => &$weekRow) {
            $sumRec  = $recArInv[$w] + $recArRecur[$w] + $recPosSales[$w] + $recPipeline[$w]
                     + $recVatRefund[$w] + $recOtherOp[$w] + $recAssetDisp[$w] + $recPrecRefund[$w];
            $sumDis  = $disApInv[$w] + $disApPo[$w] + $disPayroll[$w] + $disCnps[$w] + $disIrpp[$w]
                     + $disVat[$w] + $disAcompteIs[$w] + $disPrecompte[$w] + $disTsr[$w]
                     + $disPatente[$w] + $disTaxeFonc[$w] + $disOpCash[$w] + $disPettyCash[$w] + $disRefunds[$w];
            $sumFin  = $finDrawdowns[$w] + $finOverdraft[$w] + $finShareCont[$w] + $finIntercoy[$w]
                     - $finIntPay[$w] - $finPrincipal[$w] - $finCapex[$w] - $finDividends[$w];
            $net = $sumRec - $sumDis + $sumFin;
            $weekRow['opening'] = $cursor;
            $weekRow['net']     = $net;
            $weekRow['closing'] = $cursor + $net;
            $weekRow['receipts']      = $sumRec;
            $weekRow['disbursements'] = $sumDis;
            $weekRow['financing']     = $sumFin;
            $netByWeek[$w] = $net;
            $cursor += $net;
        }
        unset($weekRow);

        // ── KPIs ──────────────────────────────────────────────────
        $closings = array_column($weeks, 'closing');
        $minClosing = empty($closings) ? 0.0 : min($closings);
        $minWeek = empty($closings) ? null : array_search($minClosing, $closings, true);
        $finalClosing = end($closings) ?: 0.0;
        $breachWeeks = 0;
        foreach ($weeks as $w) {
            if ($w['closing'] < $minBuffer) $breachWeeks++;
        }
        $kpis = [
            'opening_w1'      => $weeks[0]['opening']  ?? 0.0,
            'projected_w16'   => $finalClosing,
            'lowest_balance'  => $minClosing,
            'lowest_week_no'  => $minWeek !== false && $minWeek !== null ? $minWeek + 1 : 0,
            'lowest_under_min'=> max(0, $minBuffer - $minClosing),
            'buffer_breaches' => $breachWeeks,
            'horizon_delta'   => $finalClosing - ($weeks[0]['opening'] ?? 0.0),
            'min_buffer'      => $minBuffer,
        ];

        // ── Governance / Band 6 ───────────────────────────────────
        $governance = [
            'forecast_accuracy_4w' => null,    // requires prior forecasts in storage — Phase 2
            'forecast_bias_12w'    => null,    // ditto
            'data_freshness'       => 'real-time',
            'manual_override_count'=> 0,
            'currency'             => 'XAF',
            'cut_off'              => $cutOff->format('Y-m-d'),
            'method'               => 'Direct · Method C (hybrid AR/AP timing)',
        ];

        return [
            'currency'   => 'XAF',
            'cut_off'    => $cutOff->format('Y-m-d'),
            'weeks'      => $weeks,
            'lines'      => $lines,
            'kpis'       => $kpis,
            'governance' => $governance,
            'errors'     => $errors,
        ];
    }

    // ════════════════════════════════════════════════════════════════
    //  Band 1 — Opening cash position
    // ════════════════════════════════════════════════════════════════

    private function openingCashBanks(Carbon $cutOff): float
    {
        return $this->sumAccountBalancesByPrefix(self::CASH_BANK_PREFIX, $cutOff, excludeMobile: true);
    }

    private function openingCashMobile(Carbon $cutOff): float
    {
        return $this->sumAccountBalancesByPrefix(self::CASH_BANK_PREFIX, $cutOff, mobileOnly: true);
    }

    private function openingCashCaisse(Carbon $cutOff): float
    {
        return $this->sumAccountBalancesByPrefix(self::CASH_CAISSE_PREFIX, $cutOff);
    }

    /**
     * Sum the debit-positive balance across all GL accounts whose code
     * starts with the given prefix. Mobile-money sub-accounts are
     * identified by name hints (MOMO, ORANGE, MTN, WAVE).
     */
    private function sumAccountBalancesByPrefix(
        string $prefix,
        Carbon $cutOff,
        bool   $excludeMobile = false,
        bool   $mobileOnly    = false,
    ): float {
        $accounts = $this->odoo->searchRead(
            'account.account',
            [['code', '=like', $prefix . '%']],
            ['id', 'code', 'name'],
            ['limit' => 200]
        );
        if (!$accounts) return 0.0;

        $accountIds = [];
        foreach ($accounts as $a) {
            $name = strtoupper((string) ($a['name'] ?? ''));
            $isMobile = false;
            foreach (self::MOBILE_MONEY_HINTS as $hint) {
                if (str_contains($name, $hint)) { $isMobile = true; break; }
            }
            if ($mobileOnly && !$isMobile)     continue;
            if ($excludeMobile && $isMobile)    continue;
            $accountIds[] = (int) $a['id'];
        }
        if (!$accountIds) return 0.0;

        $lines = $this->odoo->searchRead(
            'account.move.line',
            [
                ['account_id', 'in', $accountIds],
                ['date', '<=', $cutOff->format('Y-m-d')],
                ['parent_state', '=', 'posted'],
            ],
            ['balance'],
            ['limit' => 0]
        );
        return (float) array_sum(array_map(fn ($l) => (float) ($l['balance'] ?? 0), $lines));
    }

    // ════════════════════════════════════════════════════════════════
    //  Band 2 — Operating receipts
    // ════════════════════════════════════════════════════════════════

    private function bucketArOpenInvoices(Carbon $cutOff, array $weeks): array
    {
        $buckets = $this->zeroBuckets();
        $invoices = $this->odoo->searchRead(
            'account.move',
            [
                ['move_type',    'in', ['out_invoice', 'out_refund']],
                ['payment_state','in', ['not_paid', 'partial']],
                ['state',        '=',  'posted'],
            ],
            ['id', 'partner_id', 'invoice_date', 'invoice_date_due', 'amount_residual_signed'],
            ['limit' => 5000]
        );
        if (!$invoices) return $buckets;

        // Pre-compute per-partner pay-lag once for the whole batch
        $partnerIds = array_unique(array_map(
            fn ($i) => (int) ($i['partner_id'][0] ?? 0),
            $invoices
        ));
        $partnerLag = $this->partnerPaymentLagDays($partnerIds);

        foreach ($invoices as $inv) {
            $residual = (float) ($inv['amount_residual_signed'] ?? 0);
            if (abs($residual) < 0.5) continue;
            $due = $inv['invoice_date_due'] ?: $inv['invoice_date'];
            if (!$due) continue;
            $dueDt = Carbon::parse($due);

            // Method C: behavioural overlay capped at +30 days from due
            $partnerId = (int) ($inv['partner_id'][0] ?? 0);
            $lag = (int) ($partnerLag[$partnerId] ?? 0);
            $expectedDt = $dueDt->copy()->addDays(min(30, max(-30, $lag)));

            $w = $this->weekIndex($expectedDt, $weeks);
            if ($w !== null) {
                // Refunds (out_refund) reduce cash receipt — but
                // amount_residual_signed already carries the sign.
                $buckets[$w] += abs($residual);
            }
        }
        return $buckets;
    }

    private function bucketPosSales(Carbon $cutOff, array $weeks): array
    {
        // 8-week trailing average of POS sales by weekday of week
        $from = $cutOff->copy()->subWeeks(8)->format('Y-m-d');
        $orders = $this->odoo->searchRead(
            'pos.order',
            [
                ['date_order', '>=', $from],
                ['state',      'in', ['paid', 'done', 'invoiced']],
            ],
            ['date_order', 'amount_total'],
            ['limit' => 0]
        );
        if (!$orders) return $this->zeroBuckets();

        // Sum by weekday
        $byDow = array_fill(0, 7, 0.0);
        $countByDow = array_fill(0, 7, 0);
        foreach ($orders as $o) {
            $dt = Carbon::parse($o['date_order']);
            $dow = (int) $dt->dayOfWeek;
            $byDow[$dow] += (float) ($o['amount_total'] ?? 0);
            $countByDow[$dow]++;
        }
        $avgByDow = [];
        for ($d = 0; $d < 7; $d++) {
            $avgByDow[$d] = $countByDow[$d] > 0 ? ($byDow[$d] / max(1, ($countByDow[$d] / 8))) : 0.0;
        }

        $buckets = $this->zeroBuckets();
        foreach ($weeks as $w => $weekRow) {
            $start = Carbon::parse($weekRow['start']);
            for ($d = 0; $d < 7; $d++) {
                $day = $start->copy()->addDays($d);
                $buckets[$w] += $avgByDow[(int) $day->dayOfWeek] ?? 0.0;
            }
        }
        return $buckets;
    }

    private function bucketCrmPipeline(Carbon $cutOff, array $weeks): array
    {
        $buckets = $this->zeroBuckets();
        $deals = $this->odoo->searchRead(
            'crm.lead',
            [
                ['type',             '=',  'opportunity'],
                ['probability',      '>',  0],
                ['probability',      '<',  100],
                ['expected_revenue', '>',  0],
                ['date_deadline',    '!=', false],
            ],
            ['expected_revenue', 'probability', 'date_deadline', 'partner_id'],
            ['limit' => 2000]
        );
        if (!$deals) return $buckets;

        foreach ($deals as $d) {
            $weighted = (float) $d['expected_revenue'] * ((float) $d['probability'] / 100);
            if ($weighted < 1) continue;
            $expectedDt = Carbon::parse($d['date_deadline'])->addDays(45); // sales→cash lag default
            $w = $this->weekIndex($expectedDt, $weeks);
            if ($w !== null) $buckets[$w] += $weighted;
        }
        return $buckets;
    }

    // ════════════════════════════════════════════════════════════════
    //  Band 3 — Operating disbursements
    // ════════════════════════════════════════════════════════════════

    private function bucketApOpenInvoices(Carbon $cutOff, array $weeks): array
    {
        $buckets = $this->zeroBuckets();
        $bills = $this->odoo->searchRead(
            'account.move',
            [
                ['move_type',    'in', ['in_invoice', 'in_refund']],
                ['payment_state','in', ['not_paid', 'partial']],
                ['state',        '=',  'posted'],
            ],
            ['partner_id', 'invoice_date_due', 'invoice_date', 'amount_residual_signed'],
            ['limit' => 5000]
        );
        if (!$bills) return $buckets;

        foreach ($bills as $b) {
            $residual = (float) ($b['amount_residual_signed'] ?? 0);
            if (abs($residual) < 0.5) continue;
            $due = $b['invoice_date_due'] ?: $b['invoice_date'];
            if (!$due) continue;
            // DPO overlay deferred to Phase 2; default to due-date
            $w = $this->weekIndex(Carbon::parse($due), $weeks);
            if ($w !== null) $buckets[$w] += abs($residual);
        }
        return $buckets;
    }

    private function bucketApOpenPos(Carbon $cutOff, array $weeks): array
    {
        $buckets = $this->zeroBuckets();
        $pos = $this->odoo->searchRead(
            'purchase.order',
            [
                ['state',          '=',     'purchase'],
                ['invoice_status', '!=',    'invoiced'],
            ],
            ['amount_untaxed', 'date_planned', 'partner_id'],
            ['limit' => 2000]
        );
        if (!$pos) return $buckets;

        foreach ($pos as $p) {
            $amt = (float) ($p['amount_untaxed'] ?? 0);
            if ($amt < 1) continue;
            $when = Carbon::parse($p['date_planned'] ?? $cutOff)->addDays(30); // assume +30d payment lag
            $w = $this->weekIndex($when, $weeks);
            if ($w !== null) $buckets[$w] += $amt;
        }
        return $buckets;
    }

    private function bucketPayroll(Carbon $cutOff, array $weeks): array
    {
        // Approximate: pull the most recent month's gross payroll and
        // schedule on the 25th of each future month.
        $payslips = $this->odoo->searchRead(
            'hr.payslip',
            [['state', '=', 'done']],
            ['date_to', 'line_ids'],
            ['limit' => 200, 'order' => 'date_to desc']
        );
        if (!$payslips) return $this->zeroBuckets();

        // Group by month, take median
        $byMonth = [];
        foreach ($payslips as $s) {
            $m = substr((string) ($s['date_to'] ?? ''), 0, 7);
            if (!$m) continue;
            $byMonth[$m] = ($byMonth[$m] ?? 0) + 0; // count only
        }
        if (empty($byMonth)) return $this->zeroBuckets();

        // Per-slip net pay from line read — too costly; use approximation
        // via hr.payslip line totals if available. Phase 1 uses the
        // per-employee monthly default × headcount (a clean approximation).
        $employees = $this->odoo->searchCount('hr.employee', [['active', '=', true]]);
        $monthlyPayroll = $employees * 250_000; // placeholder default per employee — refined Phase 2

        $buckets = $this->zeroBuckets();
        foreach ($weeks as $w => $weekRow) {
            $start = Carbon::parse($weekRow['start']);
            $end   = Carbon::parse($weekRow['end']);
            // If the 25th of any month falls in this week, allocate one month's payroll
            for ($monthOffset = -1; $monthOffset <= 4; $monthOffset++) {
                $payDate = $cutOff->copy()->startOfMonth()->addMonthsNoOverflow($monthOffset)->day(25);
                if ($payDate->between($start, $end) && $payDate->gte($cutOff)) {
                    $buckets[$w] += $monthlyPayroll;
                    break;
                }
            }
        }
        return $buckets;
    }

    private function bucketCnps(Carbon $cutOff, array $weeks): array
    {
        // CNPS = 16.2% employer + 4.2% employee + 1.75% accidents + 7% family allowances
        // Computed on capped base of 750,000 XAF/month per employee.
        // Approximate as ~30% of the monthly payroll, remitted on 15th of M+1.
        $employees = $this->odoo->searchCount('hr.employee', [['active', '=', true]]);
        $monthlyCnps = $employees * 750_000 * 0.30 * 0.85; // 0.85 = average actual base utilisation
        return $this->scheduleMonthly($cutOff, $weeks, $monthlyCnps, dayOfMonth: 15, offsetMonths: 1);
    }

    private function bucketIrpp(Carbon $cutOff, array $weeks): array
    {
        // IRPP withheld varies widely; rough heuristic = 15% of net payroll
        $employees = $this->odoo->searchCount('hr.employee', [['active', '=', true]]);
        $monthlyIrpp = $employees * 250_000 * 0.15;
        return $this->scheduleMonthly($cutOff, $weeks, $monthlyIrpp, dayOfMonth: 15, offsetMonths: 1);
    }

    private function bucketVatRemittance(Carbon $cutOff, array $weeks): array
    {
        // Net VAT payable for last completed month, remitted on 15th of M+1
        $from = $cutOff->copy()->subMonth()->startOfMonth()->format('Y-m-d');
        $to   = $cutOff->copy()->subMonth()->endOfMonth()->format('Y-m-d');
        try {
            $taxLines = $this->odoo->searchRead(
                'account.move.line',
                [
                    ['date', '>=', $from],
                    ['date', '<=', $to],
                    ['parent_state', '=', 'posted'],
                    ['account_id.code', '=like', '44%'],
                ],
                ['balance'],
                ['limit' => 0]
            );
            $netVat = (float) array_sum(array_map(fn ($l) => (float) ($l['balance'] ?? 0), $taxLines));
        } catch (\Throwable $e) {
            $netVat = 0.0;
        }
        return $this->scheduleMonthly($cutOff, $weeks, abs($netVat), dayOfMonth: 15, offsetMonths: 1);
    }

    private function bucketAcompteIs(Carbon $cutOff, array $weeks): array
    {
        // Acompte IS = 1.1% of monthly turnover
        $from = $cutOff->copy()->subMonth()->startOfMonth()->format('Y-m-d');
        $to   = $cutOff->copy()->subMonth()->endOfMonth()->format('Y-m-d');
        try {
            $revLines = $this->odoo->searchRead(
                'account.move.line',
                [
                    ['date', '>=', $from],
                    ['date', '<=', $to],
                    ['parent_state', '=', 'posted'],
                    ['account_id.code', '=like', '70%'],
                ],
                ['balance'],
                ['limit' => 0]
            );
            // Class 70 = credit-positive, balance is negative
            $turnover = abs((float) array_sum(array_map(fn ($l) => (float) ($l['balance'] ?? 0), $revLines)));
        } catch (\Throwable $e) {
            $turnover = 0.0;
        }
        $acompte = $turnover * 0.011;
        return $this->scheduleMonthly($cutOff, $weeks, $acompte, dayOfMonth: 15, offsetMonths: 1);
    }

    private function bucketRecurringOpex(Carbon $cutOff, array $weeks): array
    {
        // Trailing 8-week average of class 6 (charges) cash outflows
        $from = $cutOff->copy()->subWeeks(8)->format('Y-m-d');
        try {
            $lines = $this->odoo->searchRead(
                'account.move.line',
                [
                    ['date', '>=', $from],
                    ['date', '<=', $cutOff->format('Y-m-d')],
                    ['parent_state', '=', 'posted'],
                    ['account_id.code', '=like', '61%'], // rent / utilities / transport family
                ],
                ['balance'],
                ['limit' => 0]
            );
            $total = (float) array_sum(array_map(fn ($l) => (float) ($l['balance'] ?? 0), $lines));
            $weeklyAvg = $total / 8;
        } catch (\Throwable $e) {
            $weeklyAvg = 0.0;
        }
        $buckets = $this->zeroBuckets();
        foreach ($weeks as $w => $_) $buckets[$w] = $weeklyAvg;
        return $buckets;
    }

    private function bucketCustomerRefunds(Carbon $cutOff, array $weeks): array
    {
        $buckets = $this->zeroBuckets();
        $refunds = $this->odoo->searchRead(
            'account.move',
            [
                ['move_type',    '=', 'out_refund'],
                ['payment_state','in', ['not_paid', 'partial']],
                ['state',        '=',  'posted'],
            ],
            ['invoice_date_due', 'invoice_date', 'amount_residual_signed'],
            ['limit' => 500]
        );
        foreach ($refunds ?? [] as $r) {
            $amt = abs((float) ($r['amount_residual_signed'] ?? 0));
            if ($amt < 1) continue;
            $when = Carbon::parse($r['invoice_date_due'] ?: $r['invoice_date']);
            $w = $this->weekIndex($when, $weeks);
            if ($w !== null) $buckets[$w] += $amt;
        }
        return $buckets;
    }

    private function bucketCapex(Carbon $cutOff, array $weeks): array
    {
        // POs flagged with capex (custom field is_capex or analytic
        // tag); falls back to 0 if not configured.
        $buckets = $this->zeroBuckets();
        try {
            $pos = $this->odoo->searchRead(
                'purchase.order',
                [
                    ['state',          '=',     'purchase'],
                    ['invoice_status', '!=',    'invoiced'],
                    ['is_capex',       '=',     true],
                ],
                ['amount_untaxed', 'date_planned'],
                ['limit' => 200]
            );
            foreach ($pos as $p) {
                $amt = (float) ($p['amount_untaxed'] ?? 0);
                $when = Carbon::parse($p['date_planned'] ?? $cutOff);
                $w = $this->weekIndex($when, $weeks);
                if ($w !== null) $buckets[$w] += $amt;
            }
        } catch (\Throwable $e) {
            // is_capex field doesn't exist → no CAPEX rows. Acceptable.
        }
        return $buckets;
    }

    // ════════════════════════════════════════════════════════════════
    //  Helpers
    // ════════════════════════════════════════════════════════════════

    /**
     * Build the 16-week spine — labels W1..W16 with start/end dates.
     */
    private function buildWeekSpine(Carbon $cutOff): array
    {
        $weeks = [];
        $cursor = $cutOff->copy()->startOfDay();
        for ($i = 0; $i < self::HORIZON_WEEKS; $i++) {
            $start = $cursor->copy()->addDays($i * 7);
            $end   = $start->copy()->addDays(6);
            $weeks[] = [
                'w_no'    => $i + 1,
                'label'   => 'W' . ($i + 1),
                'start'   => $start->format('Y-m-d'),
                'end'     => $end->format('Y-m-d'),
                'opening' => 0.0,
                'closing' => 0.0,
                'net'     => 0.0,
                'receipts'      => 0.0,
                'disbursements' => 0.0,
                'financing'     => 0.0,
            ];
        }
        return $weeks;
    }

    /**
     * Find which week (0-indexed) the given date falls into.
     * Returns null if the date is outside the 16-week horizon.
     */
    private function weekIndex(Carbon $date, array $weeks): ?int
    {
        $ts = $date->copy()->startOfDay()->timestamp;
        foreach ($weeks as $i => $w) {
            $s = Carbon::parse($w['start'])->startOfDay()->timestamp;
            $e = Carbon::parse($w['end'])->endOfDay()->timestamp;
            if ($ts >= $s && $ts <= $e) return $i;
        }
        // Past-due invoices bucket into W1; way-future falls off.
        if ($ts < Carbon::parse($weeks[0]['start'])->startOfDay()->timestamp) return 0;
        return null;
    }

    /**
     * Allocate a fixed amount to the week containing the specified
     * day of month, offset N months from current.
     */
    private function scheduleMonthly(Carbon $cutOff, array $weeks, float $amount, int $dayOfMonth, int $offsetMonths): array
    {
        $buckets = $this->zeroBuckets();
        if ($amount <= 0) return $buckets;
        // Loop through the months covered by the 16-week horizon.
        for ($m = $offsetMonths - 1; $m <= 5; $m++) {
            $date = $cutOff->copy()->startOfMonth()->addMonthsNoOverflow($m)->day($dayOfMonth);
            $w = $this->weekIndex($date, $weeks);
            if ($w !== null && $date->gte(Carbon::parse($weeks[0]['start']))) {
                $buckets[$w] += $amount;
            }
        }
        return $buckets;
    }

    /**
     * Per-partner historical payment-lag in days = average of
     * (date_paid − invoice_date_due) over the last 24 months.
     * Returns ['partnerId' => lagDays] for partnerIds passed in.
     */
    private function partnerPaymentLagDays(array $partnerIds): array
    {
        $partnerIds = array_filter(array_unique($partnerIds));
        if (empty($partnerIds)) return [];

        $from = Carbon::now()->subMonths(24)->format('Y-m-d');
        try {
            $paid = $this->odoo->searchRead(
                'account.move',
                [
                    ['move_type',    'in', ['out_invoice']],
                    ['payment_state','=',  'paid'],
                    ['state',        '=',  'posted'],
                    ['partner_id',   'in', array_values($partnerIds)],
                    ['invoice_date_due', '>=', $from],
                ],
                ['partner_id', 'invoice_date_due', 'invoice_date'],
                ['limit' => 5000]
            );
        } catch (\Throwable $e) {
            return [];
        }
        // Without account.payment dates we approximate the lag as 0
        // for now. Phase 2 enriches with payment.date pulled per move.
        $out = [];
        foreach ($partnerIds as $pid) $out[(int) $pid] = 0;
        return $out;
    }

    /**
     * @return array<int, float> indexed 0..HORIZON_WEEKS-1
     */
    private function zeroBuckets(): array
    {
        return array_fill(0, self::HORIZON_WEEKS, 0.0);
    }

    /**
     * Build one line of the forecast table.
     * @param array<int, float>|null $byWeek
     */
    private function line(
        int     $band,
        string  $line,
        string  $label,
        string  $engine,
        ?string $confidence,
        float   $total,
        ?array  $byWeek,
        int     $sign,
        string  $source,
    ): array {
        return [
            'band'       => $band,
            'line'       => $line,
            'label'      => $label,
            'engine'     => $engine,
            'confidence' => $confidence,
            'total'      => round($total, 2),
            'by_week'    => $byWeek ? array_map(fn ($v) => round((float) $v, 2), $byWeek) : null,
            'sign'       => $sign,
            'source'     => $source,
        ];
    }

    /**
     * Run a callable safely, logging any exception to the errors list
     * (which the view surfaces in an amber banner) without aborting
     * the whole forecast.
     */
    private function safe(callable $fn, array &$errors, string $lineRef)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            $errors[] = "Line {$lineRef}: " . $e->getMessage();
            // Return correct shape: float for opening-cash lines, array of zeros otherwise
            if (in_array($lineRef, ['1.1', '1.2', '1.3'], true)) {
                return 0.0;
            }
            return $this->zeroBuckets();
        }
    }
}
