<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer payments against demo invoices.
 *
 * Drives the AR aging + DSO calculations in the Receivables module.
 * A payment that fully clears its invoice flips that invoice's status
 * to "paid"; partial payments leave it as "posted" with a residual
 * balance that the Receivables service computes on the fly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('demo_invoices')->cascadeOnDelete();
            $table->date('payment_date');
            $table->decimal('amount', 18, 2);
            $table->string('method', 32)->default('bank_transfer'); // bank_transfer|cash|cheque|mobile_money
            $table->string('reference', 64)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'payment_date']);
            $table->index(['invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_payments');
    }
};
