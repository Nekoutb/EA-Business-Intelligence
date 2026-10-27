<?php

/*
|--------------------------------------------------------------------------
| Clearance Intelligence — KPI library, targets and triggers
|--------------------------------------------------------------------------
| One entry per KPI. Targets are PROPOSED defaults: management sets its own
| on the KPI library page (tenant setting CLRI_TARGETS, JSON), and those win.
|
| RAG rule:
|   better = 'up'   → green at or above target, amber at or above trigger, red below it
|   better = 'down' → green at or below target, amber at or below trigger, red above it
|   target = null   → information only (no colour, no alert)
| A KPI in red raises an alert on the Executive overview.
|
| unit: int · xaf · pct · days · ratio
*/

return [
    'sections' => [
        'activity'      => 'Operational activity',
        'productivity'  => 'Productivity',
        'efficiency'    => 'Efficiency & delays',
        'profitability' => 'Profitability',
        'collections'   => 'Collections',
        'leakage'       => 'Leakages & controls',
        'forecast'      => 'Forecasts',
    ],

    'kpis' => [
        // ── Operational activity ─────────────────────────────────────────
        'files_opened' => ['activity', 'Files opened', 'int', 'up', 'Clearance files opened in the period (imported legacy and cancelled files excluded).', 'count(file) where date_opened in period', null, null],
        'files_started' => ['activity', 'Files started', 'int', 'up', 'Files on which work started in the period.', 'count(file) where date_started in period', null, null],
        'files_ops_closed' => ['activity', 'Files closed for operations', 'int', 'up', 'Files whose operational work was completed in the period (state OK for Billing reached).', 'count(file) where date_ops_closed in period', null, null],
        'files_billed' => ['activity', 'Files billed', 'int', 'up', 'Files whose first customer invoice is dated in the period: a file is billed as soon as a standing invoice carries it. The unit used for every per-file ratio.', 'count(file) where first standing invoice date in period', null, null],
        'files_closed' => ['activity', 'Files closed', 'int', 'up', 'Files closed for good in the period.', 'count(file) where date_closed in period', null, null],
        'wip_files' => ['activity', 'Files in progress', 'int', null, 'Live files at the end of the period: draft, in progress or OK for billing.', 'count(file) where state in (draft, in_progress, ops_closed)', null, null],
        'eq_containers' => ['activity', 'Equivalent containers handled', 'int', 'up', 'Containers on files opened in the period; non-containerised cargo converted by weight (Settings → tonnes per container).', 'Σ equivalent_container_count of files opened', null, null],
        'backlog_months' => ['activity', 'Backlog (months of billing)', 'ratio', 'down', 'How many months of billing the current work in progress represents at the period\'s billing pace.', 'files in progress ÷ (files billed ÷ months in period)', 1.0, 1.5],

        // ── Productivity ─────────────────────────────────────────────────
        'files_per_employee' => ['productivity', 'Files billed per employee', 'ratio', 'up', 'Files billed in the period per employee (headcount from Productivity settings, else employee profiles).', 'files billed ÷ headcount', 4.0, 3.0],
        'files_per_employee_month' => ['productivity', 'Files billed per employee per month', 'ratio', 'up', 'The same ratio brought to one month, so periods of different length compare.', 'files billed ÷ headcount ÷ months in period', 4.0, 3.0],
        'revenue_per_employee' => ['productivity', 'Fee revenue per employee', 'xaf', 'up', 'Fee revenue (HT, débours excluded) earned per employee in the period.', 'fees HT ÷ headcount', null, null],
        'files_per_responsible' => ['productivity', 'Files billed per file owner', 'ratio', 'up', 'Average number of files billed per responsible user who billed at least one.', 'files billed ÷ distinct responsible users', null, null],
        'billing_per_day' => ['productivity', 'Files billed per working day', 'ratio', 'up', 'Billing throughput: files billed per working day (Mon–Fri) of the period.', 'files billed ÷ working days', 3.0, 2.0],
        'disbursements_paid' => ['productivity', 'Disbursements paid', 'int', null, 'Disbursements paid out (cash, bank, mobile money or advance) in the period.', 'count(expense) where date_settled in period', null, null],
        'keyed_error_pct' => ['productivity', 'Disbursements keyed in error', 'pct', 'down', 'Share of disbursements keyed in the period that were later cancelled as an error (reassignments to another file counted apart).', 'cancelled with reason error ÷ disbursements keyed', 2.0, 5.0],
        'reassignments' => ['productivity', 'Disbursements reassigned to another file', 'int', 'down', 'Disbursements cancelled in the period because they belonged to another file.', 'count(expense) cancel_reason = reassignment, cancelled in period', 0, 5],

        // ── Efficiency & delays ──────────────────────────────────────────
        'turnaround_days' => ['efficiency', 'Overall turnaround (days)', 'days', 'down', 'Average days from commercial invoice received to delivery, for files delivered in the period.', 'avg(overall_turnaround_days) of files delivered in period', 10.0, 15.0],
        'transit_days' => ['efficiency', 'Transit time (days)', 'days', 'down', 'Average transit time per the service type\'s rule, for files whose transit ended in the period.', 'avg(transit_days)', 5.0, 8.0],
        'open_to_start_days' => ['efficiency', 'Opening → work started (days)', 'days', 'down', 'Average days from file opening to work starting (documents and approvals).', 'avg(date_started − date_opened)', 1.0, 3.0],
        'start_to_ops_close_days' => ['efficiency', 'Work → closed for operations (days)', 'days', 'down', 'Average days of operational work on files closed for operations in the period.', 'avg(date_ops_closed − date_started)', 7.0, 12.0],
        'ops_to_bill_days' => ['efficiency', 'Closed for operations → invoiced (days)', 'days', 'down', 'Average days a finished file waits before it is invoiced. Every day here is cash not yet claimed.', 'avg(first invoice date − date_ops_closed)', 2.0, 5.0],
        'open_to_bill_days' => ['efficiency', 'Opening → invoiced (days)', 'days', 'down', 'End-to-end lead time from opening to invoice, for files billed in the period.', 'avg(first invoice date − date_opened)', 12.0, 20.0],
        'steps_late_pct' => ['efficiency', 'Steps over target', 'pct', 'down', 'Share of measured workflow steps completed in the period that took longer than their configured target (Clearance → Turnaround targets).', 'late steps ÷ completed steps', 10.0, 25.0],
        'expense_cycle_days' => ['efficiency', 'Disbursement request → paid (days)', 'days', 'down', 'Average days from a disbursement request to its payment, for disbursements paid in the period.', 'avg(date_settled − date_requested)', 1.0, 3.0],
        'justification_days' => ['efficiency', 'Advance paid → justified (days)', 'days', 'down', 'Average days staff take to justify an advance, for advances justified in the period.', 'avg(date_justified − date_settled)', 5.0, 10.0],
        'tasks_open' => ['efficiency', 'Actions waiting', 'int', 'down', 'Approvals, payments, justifications and billings waiting on somebody right now (My Tasks).', 'count(clearance.task), ongoing-file rows excluded', 20, 40],
        'tasks_oldest_days' => ['efficiency', 'Oldest waiting action (days)', 'days', 'down', 'Age of the oldest action waiting in any queue.', 'max(today − date_landed)', 3.0, 7.0],
        'docs_complete_pct' => ['efficiency', 'Live files with complete documents', 'pct', 'up', 'Share of live files whose mandatory documents are all received.', 'live files documents_complete ÷ live files', 90.0, 75.0],

        // ── Profitability ────────────────────────────────────────────────
        'fees' => ['profitability', 'Fee revenue (HT)', 'xaf', 'up', 'Fees invoiced on clearance files in the period: HAD, commission on débours, file fee and other services, net of credit notes. Débours are pass-through and excluded.', 'Σ prestation lines of standing invoices − credit notes', null, null],
        'fee_had' => ['profitability', 'Honoraires agréés en douane (HAD)', 'xaf', 'up', 'Customs-broker fee income.', 'Σ lines service kind customs_fee', null, null],
        'fee_commission' => ['profitability', 'Commission on débours', 'xaf', 'up', 'Commission invoiced on out-of-pocket expenses.', 'Σ lines service kind commission', null, null],
        'fee_file' => ['profitability', 'Frais de dossier', 'xaf', 'up', 'File-opening fees invoiced.', 'Σ lines service kind file_fee', null, null],
        'fee_other' => ['profitability', 'Other services', 'xaf', 'up', 'Other billable services.', 'Σ lines service kind other', null, null],
        'revenue_per_file' => ['profitability', 'Fee revenue per file', 'xaf', 'up', 'Average fees invoiced per file billed.', 'fees ÷ files billed', null, null],
        'contribution' => ['profitability', 'Contribution', 'xaf', 'up', 'Fees plus any margin taken on débours, minus débours absorbed (recharged below cost).', 'fees + overcharge − undercharge', null, null],
        'overhead' => ['profitability', 'Operating costs (class 6)', 'xaf', 'down', 'Operating costs booked in the period (class 6), excluding débours absorbed and the débours loss account, which are counted in contribution.', 'Σ class 6 balance − undercharge − loss on débours accounts', null, null],
        'overhead_per_file' => ['profitability', 'Operating cost per file', 'xaf', 'down', 'Operating costs spread evenly over the files billed in the period.', 'overhead ÷ files billed', null, null],
        'net_margin' => ['profitability', 'Net margin', 'xaf', 'up', 'Contribution less operating costs.', 'contribution − overhead', null, null],
        'net_margin_pct' => ['profitability', 'Net margin %', 'pct', 'up', 'Net margin as a share of fee revenue.', 'net margin ÷ fees', 20.0, 10.0],
        'net_margin_per_file' => ['profitability', 'Net margin per file', 'xaf', 'up', 'Average net margin per file billed (fully loaded).', 'contribution per file − operating cost per file', null, null],
        'loss_files_pct' => ['profitability', 'Files billed at a loss', 'pct', 'down', 'Share of files billed whose fees did not cover their share of operating costs and any débours absorbed.', 'files with net margin < 0 ÷ files billed', 10.0, 25.0],
        'commission_yield_pct' => ['profitability', 'Commission yield on débours', 'pct', 'up', 'Commission invoiced as a share of the débours recharged on invoices.', 'commission lines ÷ débours lines', null, null],
        'debours_engaged' => ['profitability', 'Débours paid out', 'xaf', null, 'Out-of-pocket money paid for clients in the period (reversals deducted). Working capital tied up until recharged.', 'Σ amount of expenses paid in period', null, null],

        // ── Collections ──────────────────────────────────────────────────
        'ar_open' => ['collections', 'Open receivables', 'xaf', null, 'Amount still due on standing clearance invoices.', 'Σ amount_residual of posted invoices', null, null],
        'ar_overdue_pct' => ['collections', 'Receivables overdue', 'pct', 'down', 'Share of open receivables past their due date.', 'overdue residual ÷ open residual', 20.0, 40.0],
        'dso' => ['collections', 'Days sales outstanding', 'days', 'down', 'Days of billing still waiting to be collected, on the last 90 days of clearance invoices (TTC, débours included).', 'open receivables ÷ invoiced TTC over 90 days × 90', 30.0, 60.0],

        // ── Leakages & controls ──────────────────────────────────────────
        'unbilled_debours' => ['leakage', 'Débours not yet recharged', 'xaf', 'down', 'Débours incurred on files already invoiced or finished that are not yet on an invoice: money spent for clients and not yet claimed. Débours billed = the débours lines of the file invoices (services lines excluded), credit notes deducted.', 'Σ (débours incurred − débours invoice lines) on files invoiced, OK for billing or closed', 0, 1000000],
        'ops_closed_unbilled' => ['leakage', 'Finished files not invoiced > 5 days', 'int', 'down', 'Files closed for operations more than the trigger days ago and still not invoiced.', 'count(file) state ops_closed and today − date_ops_closed > 5', 0, 3],
        'undercharge' => ['leakage', 'Débours absorbed (recharged below cost)', 'xaf', 'down', 'What the company paid for clients and chose not to recharge, on invoices of the period.', 'Σ negative adjustment lines', 0, 500000],
        'commission_waived' => ['leakage', 'Commission not billed (estimate)', 'xaf', 'down', 'Commission left off files billed in the period (commission skipped, or a rate below the service type\'s), valued at the service type rate on the débours billed.', 'Σ (service rate − billed rate) × débours billed', 0, 250000],
        'unjustified_advances' => ['leakage', 'Unjustified staff advances', 'xaf', 'down', 'Money advanced to staff, paid out and not yet supported by documents (account 421101). Not billable until justified.', 'Σ open advances', 0, 1000000],
        'advances_aged' => ['leakage', 'Advances unjustified > 15 days', 'int', 'down', 'Advances paid more than 15 days ago and still waiting for their documents.', 'count(open advance) where today − date_settled > 15', 0, 3],
        'waivers' => ['leakage', 'Control waivers approved', 'int', 'down', 'Document waivers and unjustified-advance waivers approved on files billed or started in the period.', 'count(file) waiver_state = approved or advance_waiver_state = approved', 0, 3],
        'reopen_rate' => ['leakage', 'Files re-opened', 'pct', 'down', 'Re-openings in the period as a share of files billed: rework and billing corrections.', 'reopenings ÷ files billed', 5.0, 10.0],
        'credit_notes' => ['leakage', 'Credit notes issued (HT)', 'xaf', 'down', 'Value of credit notes on clearance files in the period.', 'Σ amount_untaxed of out_refund', 0, 500000],
        'voided_invoices' => ['leakage', 'Invoices cancelled', 'int', 'down', 'Clearance invoices voided by Billing in the period.', 'count(invoice) clearance_voided', 0, 2],
        'cancelled_debours' => ['leakage', 'Disbursements cancelled', 'int', 'down', 'Disbursements cancelled in the period, errors and reassignments to another file.', 'count(expense) date_cancelled in period', 0, 5],

        // ── Forecasts ────────────────────────────────────────────────────
        'fy_files' => ['forecast', 'Files billed — year-end projection', 'int', null, 'Files billed year to date plus the remaining months at the average of the last three full months.', 'YTD + remaining months × 3-month average', null, null],
        'fy_fees' => ['forecast', 'Fee revenue — year-end projection', 'xaf', null, 'Fee revenue year to date plus the remaining months at the average of the last three full months.', 'YTD + remaining months × 3-month average', null, null],
        'pipeline_fees' => ['forecast', 'Fees in the pipeline', 'xaf', null, 'Fees expected from files in progress, at the average fee per file of the last 90 days.', 'files in progress × average fee per file', null, null],
        'pipeline_debours' => ['forecast', 'Débours to recharge from the pipeline', 'xaf', null, 'Débours already incurred on files in progress, to be recharged when they are billed.', 'Σ débours incurred on live files', null, null],
        'cash_need' => ['forecast', 'Disbursements approved, not yet paid', 'xaf', null, 'Cash the business will pay out shortly: disbursements approved and waiting for payment.', 'Σ expenses approved → settlement approved', null, null],
    ],

    // File-level triggers (the alert list and the file drilldown flags).
    // Days are tenant-overridable through the same CLRI_TARGETS setting (key "t:<name>").
    'triggers' => [
        'not_started'     => ['Opened, work not started', 'File opened more than {days} days ago and work has not started.', 3],
        'wip_long'        => ['Work in progress too long', 'Work started more than {days} days ago and the file is not closed for operations.', 15],
        'unbilled'        => ['Finished, not invoiced', 'Closed for operations more than {days} days ago and not invoiced.', 5],
        'debours_left'    => ['Débours left to recharge', 'The file is billed or finished but carries débours that are on no standing invoice.', 0],
        'loss'            => ['Billed at a loss', 'Fees did not cover the file\'s share of operating costs and the débours absorbed.', 0],
        'absorbed'        => ['Débours absorbed', 'Débours were recharged below cost on this file.', 0],
        'commission_off'  => ['Commission reduced or skipped', 'Commission was left off or billed below the service type\'s rate.', 0],
        'advance_open'    => ['Advance unjustified', 'A staff advance on this file has been waiting for its documents more than {days} days.', 15],
        'overdue'         => ['Invoice overdue', 'An invoice of this file is unpaid more than {days} days past its due date.', 30],
        'reopened'        => ['Re-opened', 'The file was re-opened at least once.', 0],
        'waived'          => ['Control waived', 'Work started without all documents, or billing went ahead with an advance unjustified.', 0],
        'late_steps'      => ['Steps over target', 'At least one workflow step took longer than its target.', 0],
    ],
];
