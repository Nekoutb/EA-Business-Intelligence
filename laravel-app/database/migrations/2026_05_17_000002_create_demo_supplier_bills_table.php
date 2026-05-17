<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier bills — the buy-side mirror of demo_invoices. Drives Payables
 * (open balances + DPO + aging) and Procurement (PO match rate, total spend).
 *
 * `has_po` flags maverick spend (bill received without a PO) — feeds the
 * GL Quality + Procurement findings.
 *
 * `status`:
 *   draft   — booked but not yet approved for payment
 *   posted  — approved, contributes to AP
 *   paid    — fully paid (all linked supplier_payments sum to total)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_supplier_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('number', 32);                   // BIL-2026-00001
            $table->foreignId('supplier_id')->constrained('demo_suppliers')->cascadeOnDelete();
            $table->date('bill_date');
            $table->date('due_date');
            $table->string('status', 16)->default('posted');// draft|posted|paid
            $table->string('category', 64)->nullable();     // Cost category (Rent, Utilities, COGS, Logistics...)
            $table->decimal('amount_ht', 18, 2)->default(0);
            $table->decimal('vat_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->boolean('has_po')->default(true);
            $table->string('po_number', 64)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'bill_date']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'has_po']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_supplier_bills');
    }
};
