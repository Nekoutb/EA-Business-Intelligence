<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreate wh_fact_invoice_line where it is missing.
 *
 * Production has 2026_08_12_100001 marked as run but no
 * wh_fact_invoice_line table (discovered 2026-08-26 when the Elimelec
 * ERP sync tried to fill it) — most plausibly the table was dropped in
 * an ops intervention after the migration ran. Same definition, same
 * hasTable guard, so environments that do have it are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wh_fact_invoice_line')) {
            Schema::create('wh_fact_invoice_line', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('odoo_id');
                $t->unsignedBigInteger('move_odoo_id')->nullable();
                $t->unsignedBigInteger('account_odoo_id')->nullable();
                $t->unsignedBigInteger('product_odoo_id')->nullable();
                $t->date('invoice_date')->nullable();
                $t->string('move_type', 32)->nullable();
                $t->decimal('quantity', 18, 4)->default(0);
                $t->decimal('price_unit', 18, 4)->default(0);
                $t->decimal('discount', 8, 4)->default(0);
                $t->decimal('price_subtotal', 18, 2)->default(0);
                $t->dateTime('write_date')->nullable();
                $t->unique(['tenant_id', 'odoo_id']);
                $t->index(['tenant_id', 'invoice_date']);
                $t->index(['tenant_id', 'account_odoo_id', 'invoice_date']);
                $t->index(['tenant_id', 'move_odoo_id']);
            });
        }
    }

    public function down(): void
    {
        // Intentionally left blank: never drop a possibly shared table on rollback.
    }
};
