<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments OUT — payments we made against supplier bills. Drives
 * Treasury cash-out trend + Payables DPO computation + the cash
 * conversion cycle on the Working Capital page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('bill_id')->constrained('demo_supplier_bills')->cascadeOnDelete();
            $table->date('payment_date');
            $table->decimal('amount', 18, 2);
            $table->string('method', 32)->default('bank_transfer');
            $table->string('reference', 64)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'payment_date']);
            $table->index(['bill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_supplier_payments');
    }
};
