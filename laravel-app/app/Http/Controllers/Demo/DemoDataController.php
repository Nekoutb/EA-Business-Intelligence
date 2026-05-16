<?php

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Models\Demo\DemoCommandAudit;
use App\Models\Demo\DemoCustomer;
use App\Models\Demo\DemoInvoice;
use App\Models\Demo\DemoInvoiceLine;
use App\Models\Demo\DemoJournalEntry;
use App\Models\Demo\DemoJournalLine;
use App\Models\Demo\DemoPayment;
use App\Models\Demo\DemoProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Per-table CRUD-lite for the demo data tables, plus four canned-form
 * endpoints for the most common demo operations:
 *
 *   POST /demo/sandbox/data/invoices/add   — add a new invoice (with PO toggle)
 *   POST /demo/sandbox/data/payments/add   — record a payment against an invoice
 *   POST /demo/sandbox/data/journals/add   — book a journal entry (must balance)
 *   POST /demo/sandbox/data/customers/add  — add a customer
 *
 * Every successful write writes an audit row tagged source=canned_form
 * so the visitor can scroll back through what they did from the NL
 * console page.
 */
class DemoDataController extends Controller
{
    /** Tab index — defaults to invoices. */
    public function index(Request $request)
    {
        return redirect('/demo/sandbox/data/invoices');
    }

    public function invoices(Request $request)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $invoices = DemoInvoice::with(['customer', 'lines', 'payments'])
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->limit(200)
            ->get();
        $customers = DemoCustomer::where('tenant_id', $tenant->id)->orderBy('name')->get();
        $products  = DemoProduct::where('tenant_id', $tenant->id)->orderBy('name')->get();

        return view('demo.sandbox.data.invoices', [
            'invoices'  => $invoices,
            'customers' => $customers,
            'products'  => $products,
            'tenant'    => $tenant,
            'active'    => 'data',
        ]);
    }

    public function journals(Request $request)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $entries = DemoJournalEntry::with('lines')
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->limit(100)
            ->get();
        $accounts = \App\Models\Demo\DemoAccount::where('tenant_id', $tenant->id)
            ->orderBy('code')->get();

        return view('demo.sandbox.data.journals', [
            'entries'  => $entries,
            'accounts' => $accounts,
            'tenant'   => $tenant,
            'active'   => 'data',
        ]);
    }

    public function customers(Request $request)
    {
        $tenant = $request->attributes->get('demo_tenant');
        return view('demo.sandbox.data.customers', [
            'customers' => DemoCustomer::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'tenant'    => $tenant,
            'active'    => 'data',
        ]);
    }

    public function payments(Request $request)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $payments = DemoPayment::with('invoice.customer')
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('payment_date')
            ->limit(100)
            ->get();
        $openInvoices = DemoInvoice::with('customer')
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['posted'])
            ->orderByDesc('invoice_date')
            ->get();
        return view('demo.sandbox.data.payments', [
            'payments'      => $payments,
            'open_invoices' => $openInvoices,
            'tenant'        => $tenant,
            'active'        => 'data',
        ]);
    }

    // ── Canned-form writes ────────────────────────────────────────────

    public function addInvoice(Request $request)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $data = $request->validate([
            'customer_id'  => ['required', 'integer'],
            'invoice_date' => ['required', 'date'],
            'amount_ht'    => ['required', 'numeric', 'min:0'],
            'has_po'       => ['nullable'],
            'po_number'    => ['nullable', 'string', 'max:64'],
            'notes'        => ['nullable', 'string', 'max:500'],
            'product_id'   => ['nullable', 'integer'],
            'qty'          => ['nullable', 'numeric', 'min:0.001'],
        ]);

        $customer = DemoCustomer::where('tenant_id', $tenant->id)->findOrFail($data['customer_id']);
        $hasPo    = (bool) ($data['has_po'] ?? false);
        $product  = !empty($data['product_id'])
            ? DemoProduct::where('tenant_id', $tenant->id)->find($data['product_id'])
            : null;

        $amountHt = (float) $data['amount_ht'];
        $qty      = (float) ($data['qty'] ?? 1);
        $vat      = round($amountHt * 0.1925, 2);
        $total    = $amountHt + $vat;
        $invDate  = \Carbon\Carbon::parse($data['invoice_date']);

        $invoice = DB::transaction(function () use ($tenant, $customer, $product, $invDate, $amountHt, $vat, $total, $hasPo, $data, $qty) {
            $year = $invDate->year;
            $count = DemoInvoice::where('tenant_id', $tenant->id)
                ->whereYear('invoice_date', $year)->count() + 1;
            $number = sprintf('INV-%04d-%05d', $year, $count);

            $inv = DemoInvoice::create([
                'tenant_id'    => $tenant->id,
                'number'       => $number,
                'customer_id'  => $customer->id,
                'invoice_date' => $invDate,
                'due_date'     => $invDate->copy()->addDays($customer->payment_terms_days),
                'post_date'    => $invDate,
                'status'       => 'posted',
                'amount_ht'    => $amountHt,
                'vat_amount'   => $vat,
                'total'        => $total,
                'has_po'       => $hasPo,
                'po_number'    => $hasPo ? ($data['po_number'] ?? null) : null,
                'notes'        => $data['notes'] ?? null,
            ]);
            DemoInvoiceLine::create([
                'invoice_id'  => $inv->id,
                'product_id'  => $product?->id,
                'description' => $product?->name ?? 'Demo item',
                'qty'         => $qty,
                'unit_price'  => $product ? (float) $product->unit_price : $amountHt / max($qty, 1),
                'subtotal'    => $amountHt,
                'cogs_amount' => $product ? (float) $product->default_cogs * $qty : $amountHt * 0.6,
            ]);
            return $inv;
        });

        DemoCommandAudit::create([
            'tenant_id'         => $tenant->id,
            'source'            => DemoCommandAudit::SOURCE_FORM,
            'parsed_operations' => [
                'action'       => 'add_invoice',
                'invoice_id'   => $invoice->id,
                'customer'     => $customer->name,
                'amount_ht'    => $amountHt,
                'has_po'       => $hasPo,
            ],
            'status'     => DemoCommandAudit::STATUS_SUCCESS,
            'applied_at' => now(),
        ]);

        return redirect('/demo/sandbox/data/invoices')->with('flash', [
            'type' => 'success',
            'msg'  => "Invoice {$invoice->number} created — Dashboard + Revenue + Receivables will reflect this on next refresh.",
        ]);
    }

    public function addPayment(Request $request)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $data = $request->validate([
            'invoice_id'    => ['required', 'integer'],
            'payment_date'  => ['required', 'date'],
            'amount'        => ['required', 'numeric', 'min:0.01'],
            'method'        => ['required', 'in:bank_transfer,cash,cheque,mobile_money'],
            'reference'     => ['nullable', 'string', 'max:64'],
        ]);

        $invoice = DemoInvoice::where('tenant_id', $tenant->id)->findOrFail($data['invoice_id']);

        $payment = DemoPayment::create([
            'tenant_id'    => $tenant->id,
            'invoice_id'   => $invoice->id,
            'payment_date' => $data['payment_date'],
            'amount'       => $data['amount'],
            'method'       => $data['method'],
            'reference'    => $data['reference'] ?? null,
        ]);

        // Flip invoice to 'paid' if fully cleared.
        $paid = $invoice->payments()->sum('amount');
        if ((float) $paid >= (float) $invoice->total - 0.01) {
            $invoice->update(['status' => 'paid']);
        }

        DemoCommandAudit::create([
            'tenant_id'         => $tenant->id,
            'source'            => DemoCommandAudit::SOURCE_FORM,
            'parsed_operations' => [
                'action'     => 'add_payment',
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'amount'     => (float) $data['amount'],
            ],
            'status'     => DemoCommandAudit::STATUS_SUCCESS,
            'applied_at' => now(),
        ]);

        return redirect('/demo/sandbox/data/payments')->with('flash', [
            'type' => 'success',
            'msg'  => "Payment recorded — DSO and Overdue rate will reflect this on next refresh.",
        ]);
    }

    public function addJournalEntry(Request $request)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $data = $request->validate([
            'entry_date'  => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'journal_code'=> ['nullable', 'string', 'max:8'],
            'reference'   => ['nullable', 'string', 'max:64'],
            'lines'       => ['required', 'array', 'min:2'],
            'lines.*.account_code' => ['required', 'string', 'max:16'],
            'lines.*.debit'        => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit'       => ['nullable', 'numeric', 'min:0'],
        ]);

        // Resolve account names + validate balance
        $accounts = \App\Models\Demo\DemoAccount::where('tenant_id', $tenant->id)
            ->pluck('name', 'code')->toArray();

        $totalDebit = 0.0; $totalCredit = 0.0;
        $resolvedLines = [];
        foreach ($data['lines'] as $line) {
            $code  = (string) $line['account_code'];
            $debit = (float) ($line['debit']  ?? 0);
            $credit= (float) ($line['credit'] ?? 0);
            if ($debit > 0 && $credit > 0) {
                throw ValidationException::withMessages(['lines' => "Line for account {$code}: a single line cannot have BOTH debit and credit non-zero."]);
            }
            if ($debit === 0.0 && $credit === 0.0) continue;     // ignore empty rows
            $name = $accounts[$code] ?? null;
            if ($name === null) {
                throw ValidationException::withMessages(['lines' => "Account code {$code} not in this sandbox's chart. Open the Accounts tab to see the available codes."]);
            }
            $resolvedLines[] = ['code' => $code, 'name' => $name, 'debit' => $debit, 'credit' => $credit];
            $totalDebit  += $debit;
            $totalCredit += $credit;
        }

        if (abs($totalDebit - $totalCredit) > 0.01) {
            throw ValidationException::withMessages([
                'lines' => sprintf('Journal entry is not balanced: total debit %s ≠ total credit %s. Adjust before saving.',
                    number_format($totalDebit, 2),
                    number_format($totalCredit, 2)),
            ]);
        }

        $entry = DB::transaction(function () use ($tenant, $data, $resolvedLines) {
            $e = DemoJournalEntry::create([
                'tenant_id'   => $tenant->id,
                'entry_date'  => $data['entry_date'],
                'journal_code'=> $data['journal_code'] ?? 'OD',
                'reference'   => $data['reference'] ?? null,
                'description' => $data['description'],
                'posted'      => true,
            ]);
            foreach ($resolvedLines as $l) {
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

        DemoCommandAudit::create([
            'tenant_id'         => $tenant->id,
            'source'            => DemoCommandAudit::SOURCE_FORM,
            'parsed_operations' => [
                'action'      => 'add_journal_entry',
                'entry_id'    => $entry->id,
                'description' => $data['description'],
                'lines'       => $resolvedLines,
                'total'       => $totalDebit,
            ],
            'status'     => DemoCommandAudit::STATUS_SUCCESS,
            'applied_at' => now(),
        ]);

        return redirect('/demo/sandbox/data/journals')->with('flash', [
            'type' => 'success',
            'msg'  => "Journal entry posted ({$totalDebit} XAF balanced) — P&L will reflect this on next refresh.",
        ]);
    }

    public function addCustomer(Request $request)
    {
        $tenant = $request->attributes->get('demo_tenant');
        $data = $request->validate([
            'name'               => ['required', 'string', 'max:191'],
            'category'           => ['nullable', 'string', 'max:64'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'country'            => ['nullable', 'string', 'max:64'],
            'contact_email'      => ['nullable', 'email', 'max:191'],
        ]);

        $customer = DemoCustomer::create($data + ['tenant_id' => $tenant->id]);

        DemoCommandAudit::create([
            'tenant_id'         => $tenant->id,
            'source'            => DemoCommandAudit::SOURCE_FORM,
            'parsed_operations' => ['action' => 'add_customer', 'customer_id' => $customer->id, 'name' => $customer->name],
            'status'     => DemoCommandAudit::STATUS_SUCCESS,
            'applied_at' => now(),
        ]);

        return redirect('/demo/sandbox/data/customers')->with('flash', [
            'type' => 'success',
            'msg'  => "Customer {$customer->name} added.",
        ]);
    }
}
