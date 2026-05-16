<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice header for demo sandboxes.
 *
 * `has_po` is the "invoice without a PO" flag the user called out — it
 * lets the demo show a GL-quality / procurement-control finding ("X% of
 * invoices issued without a matching purchase order"). When a visitor
 * uses the NL console to "add an invoice without a PO", we flip this
 * flag false and the GL Quality module picks it up immediately.
 *
 * `status`:
 *   draft  — entered but not yet posted to the ledger
 *   posted — booked, contributes to revenue + AR
 *   paid   — fully paid (all linked payments sum to total)
 *
 * Amount fields:
 *   amount_ht  — net of VAT
 *   vat_amount — VAT (typically 19.25% in Cameroon)
 *   total      — amount_ht + vat_amount
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('number', 32);
            $table->foreignId('customer_id')->constrained('demo_customers')->cascadeOnDelete();
            $table->date('invoice_date');
            $table->date('due_date');
            $table->date('post_date')->nullable();
            $table->string('status', 16)->default('posted'); // draft|posted|paid|cancelled
            $table->decimal('amount_ht', 18, 2)->default(0);
            $table->decimal('vat_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->boolean('has_po')->default(true);
            $table->string('po_number', 64)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'invoice_date']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'has_po']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_invoices');
    }
};
