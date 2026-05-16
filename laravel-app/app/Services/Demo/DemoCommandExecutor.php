<?php
declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\Demo\DemoAccount;
use App\Models\Demo\DemoCommandAudit;
use App\Models\Demo\DemoCustomer;
use App\Models\Demo\DemoInvoice;
use App\Models\Demo\DemoInvoiceLine;
use App\Models\Demo\DemoJournalEntry;
use App\Models\Demo\DemoJournalLine;
use App\Models\Demo\DemoPayment;
use App\Models\Demo\DemoProduct;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Applies a parsed operation (from DemoCommandParser) to the demo tables.
 *
 * Returns [success, message] — message is a short human-friendly summary
 * the console UI shows back to the visitor ("Created invoice INV-2026-...")
 * or the rejection reason ("Account 999999 not in your chart of accounts").
 *
 * Every call writes to demo_commands_audit so the visitor can scroll back
 * through what they did from the console page.
 */
final class DemoCommandExecutor
{
    /**
     * @return array{success: bool, message: string, op: array}
     */
    public function execute(Tenant $tenant, array $op, string $rawCommand = ''): array
    {
        $action = (string) ($op['action'] ?? 'error');

        try {
            switch ($action) {
                case 'add_invoice':       $result = $this->addInvoice($tenant, $op); break;
                case 'add_payment':       $result = $this->addPayment($tenant, $op); break;
                case 'add_journal_entry': $result = $this->addJournalEntry($tenant, $op); break;
                case 'add_customer':      $result = $this->addCustomer($tenant, $op); break;
                case 'error':             $result = ['success' => false, 'message' => $op['reason'] ?? 'Command unparseable.']; break;
                default:                  $result = ['success' => false, 'message' => "Unsupported action: {$action}"];
            }
        } catch (\Throwable $e) {
            $result = ['success' => false, 'message' => 'Execution failed: ' . $e->getMessage()];
        }

        DemoCommandAudit::create([
            'tenant_id'         => $tenant->id,
            'source'            => DemoCommandAudit::SOURCE_NL,
            'command_text'      => $rawCommand,
            'parsed_operations' => $op,
            'status'            => $result['success'] ? DemoCommandAudit::STATUS_SUCCESS : DemoCommandAudit::STATUS_FAILED,
            'error_msg'         => $result['success'] ? null : $result['message'],
            'applied_at'        => now(),
        ]);

        return $result + ['op' => $op];
    }

    // ── add_invoice ────────────────────────────────────────────────────

    private function addInvoice(Tenant $tenant, array $op): array
    {
        $customerName = trim((string) ($op['customer_name'] ?? ''));
        if ($customerName === '') return ['success' => false, 'message' => 'Customer name required.'];
        $customer = $this->fuzzyCustomer($tenant, $customerName);
        if (!$customer) return ['success' => false, 'message' => "Customer '{$customerName}' not found in this sandbox."];

        $invoiceDate = isset($op['invoice_date']) && $op['invoice_date']
            ? Carbon::parse((string) $op['invoice_date'])
            : now()->startOfDay();
        $amountHt = max(0, (float) ($op['amount_ht'] ?? 0));
        if ($amountHt <= 0) return ['success' => false, 'message' => 'Amount HT must be > 0.'];
        $hasPo = (bool) ($op['has_po'] ?? true);
        $poNumber = $hasPo ? ($op['po_number'] ?? null) : null;
        $qty = max(0.001, (float) ($op['qty'] ?? 1));

        $product = null;
        if (!empty($op['product_name'])) {
            $product = $this->fuzzyProduct($tenant, (string) $op['product_name']);
        }

        $vat   = round($amountHt * 0.1925, 2);
        $total = $amountHt + $vat;

        $invoice = DB::transaction(function () use ($tenant, $customer, $product, $invoiceDate, $amountHt, $vat, $total, $hasPo, $poNumber, $qty, $op) {
            $year = $invoiceDate->year;
            $count = DemoInvoice::where('tenant_id', $tenant->id)->whereYear('invoice_date', $year)->count() + 1;
            $number = sprintf('INV-%04d-%05d', $year, $count);

            $inv = DemoInvoice::create([
                'tenant_id'    => $tenant->id,
                'number'       => $number,
                'customer_id'  => $customer->id,
                'invoice_date' => $invoiceDate,
                'due_date'     => $invoiceDate->copy()->addDays($customer->payment_terms_days),
                'post_date'    => $invoiceDate,
                'status'       => 'posted',
                'amount_ht'    => $amountHt,
                'vat_amount'   => $vat,
                'total'        => $total,
                'has_po'       => $hasPo,
                'po_number'    => $poNumber,
                'notes'        => $op['notes'] ?? null,
            ]);

            DemoInvoiceLine::create([
                'invoice_id'  => $inv->id,
                'product_id'  => $product?->id,
                'description' => $product?->name ?? 'Demo line item',
                'qty'         => $qty,
                'unit_price'  => $product ? (float) $product->unit_price : $amountHt / max($qty, 1),
                'subtotal'    => $amountHt,
                'cogs_amount' => $product ? (float) $product->default_cogs * $qty : $amountHt * 0.6,
            ]);

            return $inv;
        });

        $poTag = $hasPo ? "with PO {$poNumber}" : 'without a purchase order (GL quality flag)';
        return [
            'success' => true,
            'message' => "Created invoice {$invoice->number} for {$customer->name}, amount " . number_format($amountHt, 0) . " XAF HT ({$poTag}). Dashboard + Revenue + Receivables will reflect this on next refresh.",
        ];
    }

    // ── add_payment ────────────────────────────────────────────────────

    private function addPayment(Tenant $tenant, array $op): array
    {
        $invoice = null;
        if (!empty($op['invoice_number'])) {
            $invoice = DemoInvoice::where('tenant_id', $tenant->id)
                ->where('number', $op['invoice_number'])
                ->first();
        }
        if (!$invoice && !empty($op['customer_name'])) {
            $customer = $this->fuzzyCustomer($tenant, (string) $op['customer_name']);
            if ($customer) {
                $invoice = DemoInvoice::where('tenant_id', $tenant->id)
                    ->where('customer_id', $customer->id)
                    ->where('status', 'posted')
                    ->orderByDesc('invoice_date')
                    ->first();
            }
        }
        if (!$invoice) return ['success' => false, 'message' => 'Could not resolve which invoice this payment is for.'];

        $payDate = isset($op['payment_date']) && $op['payment_date']
            ? Carbon::parse((string) $op['payment_date'])
            : now()->startOfDay();
        $amount = max(0.01, (float) ($op['amount'] ?? $invoice->outstandingBalance()));
        $method = in_array(($op['method'] ?? 'bank_transfer'), ['bank_transfer', 'cash', 'cheque', 'mobile_money'], true)
            ? (string) $op['method']
            : 'bank_transfer';

        $payment = DemoPayment::create([
            'tenant_id'    => $tenant->id,
            'invoice_id'   => $invoice->id,
            'payment_date' => $payDate,
            'amount'       => $amount,
            'method'       => $method,
            'reference'    => $op['reference'] ?? 'PAY-' . Str::upper(Str::random(6)),
        ]);

        $paid = $invoice->payments()->sum('amount');
        if ((float) $paid >= (float) $invoice->total - 0.01) {
            $invoice->update(['status' => 'paid']);
        }

        return [
            'success' => true,
            'message' => "Recorded payment of " . number_format($amount, 0) . " XAF against {$invoice->number} ({$method}). DSO + overdue rate will recompute on next refresh.",
        ];
    }

    // ── add_journal_entry ──────────────────────────────────────────────

    private function addJournalEntry(Tenant $tenant, array $op): array
    {
        $entryDate = isset($op['entry_date']) && $op['entry_date']
            ? Carbon::parse((string) $op['entry_date'])
            : now()->startOfDay();
        $description = trim((string) ($op['description'] ?? 'Journal entry'));

        $linesIn = (array) ($op['lines'] ?? []);
        if (count($linesIn) < 2) return ['success' => false, 'message' => 'A journal entry needs at least two lines.'];

        $accountMap = DemoAccount::where('tenant_id', $tenant->id)->pluck('name', 'code')->toArray();
        $totalDebit = 0.0; $totalCredit = 0.0;
        $resolved = [];
        foreach ($linesIn as $l) {
            $code  = (string) ($l['account_code'] ?? '');
            $debit = max(0.0, (float) ($l['debit']  ?? 0));
            $credit= max(0.0, (float) ($l['credit'] ?? 0));
            if ($debit > 0 && $credit > 0) {
                return ['success' => false, 'message' => "Line for {$code}: cannot have both debit AND credit non-zero."];
            }
            if ($debit === 0.0 && $credit === 0.0) continue;
            if (!isset($accountMap[$code])) {
                return ['success' => false, 'message' => "Account code {$code} not in your chart of accounts."];
            }
            $resolved[] = ['code' => $code, 'name' => $accountMap[$code], 'debit' => $debit, 'credit' => $credit];
            $totalDebit  += $debit;
            $totalCredit += $credit;
        }
        if (abs($totalDebit - $totalCredit) > 0.01) {
            return ['success' => false, 'message' => sprintf('Journal not balanced: total debit %s ≠ total credit %s.', number_format($totalDebit, 2), number_format($totalCredit, 2))];
        }

        $entry = DB::transaction(function () use ($tenant, $entryDate, $description, $op, $resolved) {
            $e = DemoJournalEntry::create([
                'tenant_id'   => $tenant->id,
                'entry_date'  => $entryDate,
                'journal_code'=> $op['journal_code'] ?? 'OD',
                'reference'   => $op['reference'] ?? null,
                'description' => $description,
                'posted'      => true,
            ]);
            foreach ($resolved as $l) {
                DemoJournalLine::create([
                    'entry_id'     => $e->id,
                    'account_code' => $l['code'],
                    'account_name' => $l['name'],
                    'debit'        => $l['debit'],
                    'credit'       => $l['credit'],
                ]);
            }
            return $e;
        });

        return [
            'success' => true,
            'message' => "Posted journal entry: \"{$description}\" — " . count($resolved) . " lines, " . number_format($totalDebit, 0) . " XAF balanced. P&L will reflect this on next refresh.",
        ];
    }

    // ── add_customer ───────────────────────────────────────────────────

    private function addCustomer(Tenant $tenant, array $op): array
    {
        $name = trim((string) ($op['name'] ?? ''));
        if ($name === '') return ['success' => false, 'message' => 'Customer name required.'];
        if (DemoCustomer::where('tenant_id', $tenant->id)->where('name', $name)->exists()) {
            return ['success' => false, 'message' => "Customer '{$name}' already exists."];
        }

        $customer = DemoCustomer::create([
            'tenant_id'          => $tenant->id,
            'name'               => $name,
            'category'           => $op['category'] ?? null,
            'payment_terms_days' => max(0, min(365, (int) ($op['payment_terms_days'] ?? 30))),
            'country'            => $op['country'] ?? 'CM',
            'contact_email'      => $op['contact_email'] ?? null,
        ]);

        return [
            'success' => true,
            'message' => "Created customer {$customer->name} ({$customer->category}, {$customer->payment_terms_days}d terms).",
        ];
    }

    // ── Fuzzy resolution helpers ───────────────────────────────────────

    private function fuzzyCustomer(Tenant $tenant, string $q): ?DemoCustomer
    {
        $exact = DemoCustomer::where('tenant_id', $tenant->id)->where('name', $q)->first();
        if ($exact) return $exact;
        return DemoCustomer::where('tenant_id', $tenant->id)
            ->where('name', 'like', '%' . $q . '%')
            ->first();
    }

    private function fuzzyProduct(Tenant $tenant, string $q): ?DemoProduct
    {
        $exact = DemoProduct::where('tenant_id', $tenant->id)->where('name', $q)->first();
        if ($exact) return $exact;
        return DemoProduct::where('tenant_id', $tenant->id)
            ->where('name', 'like', '%' . $q . '%')
            ->first();
    }
}
