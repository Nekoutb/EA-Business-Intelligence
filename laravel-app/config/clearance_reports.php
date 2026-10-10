<?php

/*
|--------------------------------------------------------------------------
| Clearance management pack (Odoo 19 + elite_clearance)
|--------------------------------------------------------------------------
| The only models and fields the R1–R11 reports may read. Every report run
| first asserts these exist (fields_get) and stops with the missing names —
| never invent a field. Thresholds are tenant-overridable via settings.
*/

return [
    'required_fields' => [
        'logistics.file' => [
            'name', 'partner_id', 'service_type_id', 'customs_regime', 'port_id', 'user_id', 'employee_id',
            'state', 'date_opened', 'date_started', 'date_ops_closed', 'date_billed', 'date_closed', 'create_date',
            'opening_state', 'waiver_state', 'waiver_reason', 'waiver_approved_by_id', 'waiver_date',
            'advance_waiver_state', 'advance_waiver_reason', 'advance_waiver_approved_by_id', 'advance_waiver_date',
            'recharge_state', 'recharge_amount', 'recharge_variance', 'recharge_reason',
            'recharge_ops_approved_by_id', 'recharge_gm_approved_by_id', 'recharge_approved_date',
            'reopen_count', 'reopen_request_state', 'reopen_request_reason', 'reopen_approved_by_id', 'reopen_request_date',
            'oop_total', 'unjustified_advance_total', 'commission_amount', 'customs_fee_amount', 'file_fee_amount',
            'invoice_balance_due', 'missing_mandatory_count', 'documents_complete', 'stage_owner', 'stage_detail',
            'has_billable', 'bill_stands', 'invoices_posted', 'container_count', 'weight_kg', 'cargo_value',
            'shipment_type', 'analytic_account_id', 'partner_disclosure', 'billing_commission_rate',
        ],
        'logistics.expense' => [
            'name', 'file_id', 'category_id', 'description', 'amount', 'vendor_id', 'employee_id',
            'payment_mode', 'journal_id', 'state', 'originating_team',
            'date_requested', 'date_submitted', 'date_approved', 'date_settlement_submitted',
            'date_settlement_approved', 'date_settled', 'date_justification_submitted', 'date_justified',
            'recharge_amount', 'recharge_comment', 'is_billed', 'billed_invoice_id',
            'settlement_move_id', 'justification_move_id', 'is_legacy',
        ],
        'logistics.expense.category' => ['name', 'code', 'justification'],
        'clearance.turnaround' => [
            'step', 'file_id', 'partner_id', 'started', 'completed', 'is_done', 'hours_taken', 'target_hours', 'is_late',
        ],
        'clearance.task' => [
            'kind', 'kind_label', 'file_id', 'partner_id', 'detail', 'amount', 'date_deadline', 'date_landed',
            'originating_team', 'holder_user_id',
        ],
        'account.move' => [
            'name', 'move_type', 'state', 'partner_id', 'invoice_date', 'invoice_date_due', 'amount_untaxed',
            'amount_tax', 'amount_total', 'amount_residual', 'payment_state', 'logistics_file_id', 'is_legacy',
            'clearance_invoice_kind', 'clearance_voided', 'clearance_void_reason', 'clearance_void_date',
            'clearance_credit_reason', 'reversed_entry_id',
        ],
        'account.move.line' => [
            'move_id', 'account_id', 'partner_id', 'date', 'debit', 'credit', 'balance', 'price_subtotal', 'name',
            'clearance_category', 'clearance_service_kind', 'clearance_credited', 'analytic_distribution', 'reconciled',
        ],
        'res.company' => [
            'clearance_oop_account_id', 'clearance_advance_account_id', 'clearance_oop_undercharge_account_id',
            'clearance_oop_overcharge_account_id', 'clearance_commission_account_id', 'clearance_service_fee_account_id',
            'clearance_file_fee_account_id', 'clearance_oop_payable_account_id',
        ],
        'hr.employee' => ['name', 'work_contact_id'],
        'res.partner' => ['name', 'clearance_vat_exempt'],
        'logistics.service.type' => ['code', 'name', 'commission_rate', 'legacy_last_file_ref'],
        'account.journal' => ['name', 'type', 'default_account_id', 'outbound_payment_method_line_ids'],
        'account.payment.method.line' => ['payment_account_id'],
        'mail.message' => ['model', 'res_id', 'date', 'author_id', 'body', 'message_type', 'subtype_id'],
    ],

    // Defaults; per-tenant overrides via tenant settings CLEARANCE_<KEY>.
    'thresholds' => [
        'stage_max_files'      => 10,          // <N>  R2 red flag
        'exposure_limit_xaf'   => 25_000_000,  // <LIMIT> R7 red flag
        'pay_days_flag'        => 60,          // R7
        'approver_share_flag'  => 70.0,        // R8 (%)
        'transit_flag_wdays'   => 5,           // R11 working days
        'go_live'              => '2026-08-31',
    ],
];
