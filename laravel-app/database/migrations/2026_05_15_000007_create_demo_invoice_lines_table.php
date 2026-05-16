<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice line items — sit between demo_invoices and demo_products.
 *
 * Carrying COGS at line level lets the demo P&L module compute gross
 * margin per product / per invoice / per period without needing a
 * separate inventory ledger. cogs_amount defaults to product.default_cogs
 * × qty at line insert time but can be overridden by the visitor in
 * the data table editor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('demo_invoices')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('demo_products')->nullOnDelete();
            $table->string('description', 255);
            $table->decimal('qty', 12, 3)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('cogs_amount', 18, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_invoice_lines');
    }
};
