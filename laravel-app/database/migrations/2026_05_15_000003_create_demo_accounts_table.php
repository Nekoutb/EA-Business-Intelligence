<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chart of accounts for demo sandboxes — OHADA-flavoured (classes 1-9).
 *
 * Scoped per tenant_id so each demo visitor's sandbox carries its own
 * editable copy. Real BMS pulls accounts from Odoo; in demo mode the
 * services read straight from this table.
 *
 * Account classes follow OHADA SYSCOHADA:
 *   1 = Capital            (capital, reserves, results)
 *   2 = Fixed assets
 *   3 = Inventory
 *   4 = Receivables / payables / VAT
 *   5 = Treasury (cash, banks)
 *   6 = Expenses (COGS, opex)
 *   7 = Revenue
 *   8 = Other revenue / expenses
 *   9 = Internal commitments
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 16);                  // e.g. 4111, 70110, 44711
            $table->string('name', 191);
            $table->unsignedTinyInteger('class');        // 1..9
            $table->string('type', 32);                  // asset|liability|equity|revenue|expense
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'class']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_accounts');
    }
};
