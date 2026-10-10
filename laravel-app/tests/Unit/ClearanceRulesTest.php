<?php

namespace Tests\Unit;

use App\Services\Clearance\Domain;
use App\Services\Clearance\Rules;
use PHPUnit\Framework\TestCase;

/** Business rules R-a … R-p on small fixed samples — no Odoo calls. */
class ClearanceRulesTest extends TestCase
{
    // R-a
    public function test_ra_live_closed_and_excluded_files(): void
    {
        foreach (['draft', 'in_progress', 'ops_closed'] as $s) $this->assertTrue(Rules::isLiveFile(['state' => $s]));
        $this->assertFalse(Rules::isLiveFile(['state' => 'done']));
        $this->assertTrue(Rules::isClosedFile(['state' => 'done']));
        $this->assertFalse(Rules::isCountedFile(['state' => 'imported']));
        $this->assertFalse(Rules::isCountedFile(['state' => 'cancel']));
        $this->assertTrue(Rules::isCountedFile(['state' => 'done']));
    }

    // R-b
    public function test_rb_voided_invoice_and_its_reversal_are_excluded(): void
    {
        $moves = [
            1 => ['id' => 1, 'state' => 'posted', 'clearance_voided' => false, 'reversed_entry_id' => false, 'is_legacy' => false],
            2 => ['id' => 2, 'state' => 'posted', 'clearance_voided' => true,  'reversed_entry_id' => false, 'is_legacy' => false],
            3 => ['id' => 3, 'state' => 'posted', 'clearance_voided' => false, 'reversed_entry_id' => [2, 'INV/2'], 'is_legacy' => false],
            4 => ['id' => 4, 'state' => 'draft',  'clearance_voided' => false, 'reversed_entry_id' => false, 'is_legacy' => false],
            5 => ['id' => 5, 'state' => 'posted', 'clearance_voided' => false, 'reversed_entry_id' => false, 'is_legacy' => true],
        ];
        $this->assertSame([1], array_keys(Rules::countedMoveIds($moves)));
    }

    // R-c / R-d
    public function test_rc_rd_fees_by_kind_credit_notes_and_adjustment(): void
    {
        $moves = [
            1 => ['id' => 1, 'move_type' => 'out_invoice', 'state' => 'posted'],
            2 => ['id' => 2, 'move_type' => 'out_refund',  'state' => 'posted'],
        ];
        $counted = [1 => true, 2 => true];
        $lines = [
            ['move_id' => [1, 'a'], 'clearance_category' => 'prestation', 'clearance_service_kind' => 'commission',  'price_subtotal' => 100000],
            ['move_id' => [1, 'a'], 'clearance_category' => 'prestation', 'clearance_service_kind' => 'customs_fee', 'price_subtotal' => 50000],
            ['move_id' => [1, 'a'], 'clearance_category' => 'debours',    'clearance_service_kind' => false,        'price_subtotal' => 900000],
            ['move_id' => [1, 'a'], 'clearance_category' => 'adjustment', 'clearance_service_kind' => false,        'price_subtotal' => -20000],
            ['move_id' => [2, 'b'], 'clearance_category' => 'prestation', 'clearance_service_kind' => 'commission',  'price_subtotal' => 10000],
        ];
        $f = Rules::fees($lines, $moves, $counted);
        $this->assertSame(90000.0, $f['commission']);      // 100 000 − 10 000 credit note
        $this->assertSame(50000.0, $f['customs_fee']);
        $this->assertSame(140000.0, $f['fees']);           // débours NOT revenue
        $this->assertSame(-20000.0, $f['undercharge']);
        $this->assertSame(120000.0, $f['net']);
    }

    // R-e is a prohibition (never read analytic balances) — enforced by
    // Rules::fees() using move lines' clearance_category only.
    public function test_re_fees_ignore_analytic_distribution(): void
    {
        $moves = [1 => ['id' => 1, 'move_type' => 'out_invoice']];
        $lines = [['move_id' => [1, 'a'], 'clearance_category' => 'prestation', 'clearance_service_kind' => 'file_fee',
                   'price_subtotal' => 15000, 'analytic_distribution' => ['7' => 100]]];
        $this->assertSame(15000.0, Rules::fees($lines, $moves, [1 => true])['file_fee']);
    }

    // R-f
    public function test_rf_unbilled_disbursement(): void
    {
        $this->assertTrue(Rules::isUnbilledDisbursement(['state' => 'settled', 'is_billed' => false, 'is_legacy' => false]));
        $this->assertTrue(Rules::isUnbilledDisbursement(['state' => 'justified', 'is_billed' => false, 'is_legacy' => false]));
        $this->assertFalse(Rules::isUnbilledDisbursement(['state' => 'settled', 'is_billed' => true, 'is_legacy' => false]));
        $this->assertFalse(Rules::isUnbilledDisbursement(['state' => 'settled', 'is_billed' => false, 'is_legacy' => true]));
        $this->assertFalse(Rules::isUnbilledDisbursement(['state' => 'approved', 'is_billed' => false, 'is_legacy' => false]));
    }

    // R-g
    public function test_rg_open_advance_and_non_justifiable(): void
    {
        $this->assertTrue(Rules::isOpenAdvance(['payment_mode' => 'advance', 'state' => 'justification_submitted', 'is_legacy' => false]));
        $this->assertFalse(Rules::isOpenAdvance(['payment_mode' => 'cash', 'state' => 'settled', 'is_legacy' => false]));
        $this->assertFalse(Rules::isOpenAdvance(['payment_mode' => 'advance', 'state' => 'justified', 'is_legacy' => false]));
        $cats = [3 => ['justification' => 'non_justifiable']];
        $this->assertTrue(Rules::isNonJustifiable(['category_id' => [3, 'Tips']], $cats));
    }

    // R-h
    public function test_rh_waived_advance(): void
    {
        $this->assertTrue(Rules::isAdvanceWaived(['advance_waiver_state' => 'approved']));
        $this->assertFalse(Rules::isAdvanceWaived(['advance_waiver_state' => 'requested']));
    }

    // R-i
    public function test_ri_reconciliation_flags_a_difference(): void
    {
        $lines = [
            ['account_id' => [47, '471'], 'parent_state' => 'posted', 'balance' => 300000],
            ['account_id' => [47, '471'], 'parent_state' => 'posted', 'balance' => -50000],
            ['account_id' => [47, '471'], 'parent_state' => 'draft',  'balance' => 999999],
            ['account_id' => [42, '421'], 'parent_state' => 'posted', 'balance' => 10],
        ];
        $bal = Rules::accountBalance($lines, 47);
        $this->assertSame(250000.0, $bal);
        $this->assertTrue(Rules::reconcile(250000, $bal)['ok']);
        $r = Rules::reconcile(260000, $bal);
        $this->assertFalse($r['ok']);
        $this->assertSame(10000.0, $r['difference']);
    }

    // R-j
    public function test_rj_median_p90_and_percent(): void
    {
        $v = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        $this->assertSame(5.5, Rules::median($v));
        $this->assertEqualsWithDelta(9.1, Rules::percentile($v, 90), 0.0001);
        $this->assertSame(33.3, Rules::pct(1, 3));
        $this->assertNull(Rules::pct(1, 0));
    }

    // R-k
    public function test_rk_period_uses_local_douala_date(): void
    {
        // 23:30 UTC on 30 Sep is 00:30 on 1 Oct in Douala (UTC+1).
        $this->assertSame('2026-10-01', Rules::localDate('2026-09-30 23:30:00'));
        $this->assertFalse(Rules::inPeriod('2026-09-30 23:30:00', '2026-09-01', '2026-09-30'));
        $this->assertTrue(Rules::inPeriod('2026-09-30', '2026-09-01', '2026-09-30'));
        $this->assertSame(5, Rules::workingDays('2026-10-05', '2026-10-12')); // Mon→next Mon
    }

    // R-l is a footer statement (groups held by the API user) — formatting only.

    // R-m
    public function test_rm_in_transit_is_unreconciled_holding_balance(): void
    {
        $lines = [
            ['account_id' => [512, 'holding'], 'parent_state' => 'posted', 'balance' => -100000, 'reconciled' => false],
            ['account_id' => [512, 'holding'], 'parent_state' => 'posted', 'balance' => -40000,  'reconciled' => true],
            ['account_id' => [512, 'holding'], 'parent_state' => 'posted', 'balance' => 40000,   'reconciled' => true],
        ];
        $transit = Rules::accountBalance($lines, 512, fn ($l) => empty($l['reconciled']));
        $this->assertSame(-100000.0, $transit);
        $this->assertSame(-100000.0, Rules::accountBalance($lines, 512)); // equals holding balance → reconciles
    }

    // R-n
    public function test_rn_commission_rate_from_file_then_service_type(): void
    {
        $types = [1 => ['commission_rate' => 5.0]];
        $this->assertSame(7.5, Rules::commissionRate(['billing_commission_rate' => 7.5, 'service_type_id' => [1, 'IM']], $types));
        $this->assertSame(5.0, Rules::commissionRate(['billing_commission_rate' => 0, 'service_type_id' => [1, 'IM']], $types));
    }

    // R-o is presentation (line comment beside each adjusted line).

    // R-p
    public function test_rp_refusal_parsing(): void
    {
        $r = Rules::parseRefusal('<p>File 2026IM0148.</p><p>Disbursement refused. Reason: duplicate receipt</p>');
        $this->assertSame('Disbursement refused', $r['kind']);
        $this->assertSame('duplicate receipt', $r['reason']);
        $this->assertTrue($r['has_reason']);
        $empty = Rules::parseRefusal('<p>Settlement returned. Reason:</p>');
        $this->assertFalse($empty['has_reason']);
        $this->assertNull(Rules::parseRefusal('<p>Expense approved.</p>'));
    }

    public function test_formatting_xaf_dates(): void
    {
        $this->assertSame('1 240 000', Rules::xaf(1240000));
        $this->assertSame('−20 000', Rules::xaf(-20000));
        $this->assertSame('31/08/2026', Rules::fdate('2026-08-31'));
    }

    public function test_domain_evaluator(): void
    {
        $row = ['state' => 'settled', 'is_billed' => false, 'partner_id' => [7, 'ACME'], 'body' => 'x Reason: y'];
        $this->assertTrue(Domain::matches($row, [['state', 'in', ['settled', 'justified']], ['is_billed', '=', false]]));
        $this->assertTrue(Domain::matches($row, ['|', ['state', '=', 'draft'], ['partner_id', '=', 7]]));
        $this->assertFalse(Domain::matches($row, [['partner_id', '!=', 7]]));
        $this->assertTrue(Domain::matches($row, [['body', 'ilike', 'reason:']]));
    }
}
