<?php

/*
|--------------------------------------------------------------------------
| Clearance Intelligence — the only Odoo models and fields it may read
|--------------------------------------------------------------------------
| Taken from the Clearance Data Dictionary (build 19.0.61). Every run first
| checks these exist (fields_get) and stops with the missing names: no
| field is ever invented. Read-only: search_read / read / search_count /
| read_group / fields_get only.
*/

return [
    'logistics.file' => [
        'name', 'partner_id', 'service_type_id', 'customs_regime', 'port_id', 'user_id', 'state', 'billing_status',
        'date_opened', 'date_started', 'date_ops_closed', 'date_billed', 'date_closed', 'date_target',
        'commercial_invoice_received', 'documents_received_on', 'delivery_date', 'manifesting_date',
        'transit_start', 'transit_end', 'overall_turnaround_days', 'transit_days',
        'shipment_type', 'container_count', 'equivalent_container_count', 'package_count', 'weight_kg', 'cargo_value',
        'oop_total', 'unjustified_advance_total', 'commission_amount', 'customs_fee_amount', 'file_fee_amount',
        'commission_rate', 'billing_commission_rate', 'billing_commission_skipped',
        'recharge_amount', 'recharge_variance', 'recharge_state', 'reopen_count', 'last_reopen_reason_id',
        'waiver_state', 'advance_waiver_state', 'opening_state', 'missing_mandatory_count', 'documents_complete',
        'stage_owner', 'stage_detail', 'has_billable', 'invoice_balance_due',
        'debours_incurred', 'debours_billed', 'debours_result', 'handling_billed', 'legacy_relief', 'create_date', 'create_uid',
    ],
    'logistics.expense' => [
        'name', 'file_id', 'category_id', 'amount', 'is_reversal', 'vendor_id', 'employee_id', 'payment_mode',
        'journal_id', 'state', 'originating_team', 'date_requested', 'date_submitted', 'date_approved',
        'date_settlement_submitted', 'date_settlement_approved', 'date_settled', 'date_documents_submitted',
        'date_justification_submitted', 'date_justified', 'date_cancelled', 'cancel_reason', 'reassign_file_id',
        'recharge_amount', 'is_billed', 'is_legacy', 'category_is_urgent', 'justification_required',
        'approved_by_id', 'settled_by_id', 'create_uid', 'settlement_submitted_by_id', 'settlement_approved_by_id',
        'cancel_requested_by_id', 'cancelled_by_id', 'date_cancel_requested',
    ],
    'logistics.file.reopening' => ['file_id', 'reason_id', 'target_state', 'from_state', 'date', 'user_id'],
    'logistics.file.document'  => ['file_id', 'document_type_id', 'is_mandatory', 'received', 'date_received'],
    'clearance.turnaround'     => ['step', 'file_id', 'started', 'completed', 'is_done', 'days_taken', 'target_hours', 'is_late'],
    'clearance.task'           => ['kind', 'file_id', 'partner_id', 'amount', 'date_landed', 'originating_team', 'holder_user_id'],
    'account.move' => [
        'name', 'move_type', 'state', 'partner_id', 'invoice_date', 'invoice_date_due', 'amount_untaxed',
        'amount_total', 'amount_residual', 'payment_state', 'logistics_file_id', 'is_legacy', 'clearance_voided',
        'reversed_entry_id', 'create_uid', 'create_date',
    ],
    'account.move.line' => [
        'move_id', 'account_id', 'price_subtotal', 'clearance_category', 'clearance_service_kind',
        'clearance_adjustment', 'is_handling', 'clearance_receivable_kind', 'amount_residual', 'date_maturity',
        'partner_id', 'balance', 'date',
    ],
    'account.payment'        => ['logistics_file_id', 'partner_type', 'amount', 'date', 'state'],
    'logistics.service.type' => ['name', 'code', 'commission_rate'],
    'logistics.reopen.reason'=> ['name'],
    'logistics.expense.category' => ['name', 'code', 'justification', 'is_urgent'],
    'account.account'        => ['code', 'name'],
    'res.company'            => ['clearance_oop_undercharge_account_id', 'clearance_oop_loss_account_id'],
    'res.groups'             => ['name', 'user_ids'],
    'res.users'              => ['name', 'active', 'partner_id'],
    // Who did what, from the chatter: refusals with their reason, "File
    // closed by", and the state-change notes (whose author closed the file
    // for operations).
    'mail.message'           => ['model', 'res_id', 'date', 'author_id', 'body', 'message_type'],
];
