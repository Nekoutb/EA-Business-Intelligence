<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2 — warehouse foundation.
 *
 * A local, queryable copy of the Odoo facts the BMS reports on, so pages
 * read indexed SQL instead of making network round-trips per view. Every
 * table is tenant-scoped and keyed on the Odoo id, so a re-run of the
 * backfill updates rows in place rather than duplicating them.
 *
 * Dates are denormalised onto line facts deliberately: nearly every query
 * filters by period, and carrying the date on the line avoids a join on
 * the hottest path.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Watermark per tenant + model, so incremental syncs resume from
        // the last change they saw rather than re-reading history.
        if (!Schema::hasTable('wh_sync_state')) {
            Schema::create('wh_sync_state', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->string('model', 64);
                $t->dateTime('last_write_date')->nullable();
                $t->dateTime('last_run_at')->nullable();
                $t->unsignedBigInteger('rows_synced')->default(0);
                $t->text('last_error')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'model']);
            });
        }

        if (!Schema::hasTable('wh_dim_partner')) {
            Schema::create('wh_dim_partner', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('odoo_id');
                $t->string('name', 255)->nullable();
                $t->string('ref', 64)->nullable();
                $t->boolean('is_company')->default(false);
                $t->dateTime('write_date')->nullable();
                $t->unique(['tenant_id', 'odoo_id']);
                $t->index(['tenant_id', 'name']);
            });
        }

        if (!Schema::hasTable('wh_dim_account')) {
            Schema::create('wh_dim_account', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('odoo_id');
                $t->string('code', 32)->nullable();
                $t->string('name', 255)->nullable();
                // OHADA class = leading digit of the code; stored so class-
                // level aggregation (7 revenue, 6 expense, 4 third parties)
                // never needs a LIKE scan.
                $t->string('account_class', 2)->nullable();
                $t->dateTime('write_date')->nullable();
                $t->unique(['tenant_id', 'odoo_id']);
                $t->index(['tenant_id', 'code']);
                $t->index(['tenant_id', 'account_class']);
            });
        }

        if (!Schema::hasTable('wh_fact_invoice')) {
            Schema::create('wh_fact_invoice', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('odoo_id');
                $t->string('name', 64)->nullable();
                $t->string('move_type', 32)->nullable();
                $t->string('state', 32)->nullable();
                $t->string('payment_state', 32)->nullable();
                $t->date('invoice_date')->nullable();
                $t->date('invoice_date_due')->nullable();
                $t->unsignedBigInteger('partner_odoo_id')->nullable();
                $t->decimal('amount_untaxed', 18, 2)->default(0);
                $t->decimal('amount_total', 18, 2)->default(0);
                $t->decimal('amount_residual', 18, 2)->default(0);
                $t->dateTime('write_date')->nullable();
                $t->unique(['tenant_id', 'odoo_id']);
                $t->index(['tenant_id', 'invoice_date']);
                $t->index(['tenant_id', 'move_type', 'state', 'invoice_date']);
                $t->index(['tenant_id', 'payment_state']);
                $t->index(['tenant_id', 'partner_odoo_id']);
            });
        }

        if (!Schema::hasTable('wh_fact_invoice_line')) {
            Schema::create('wh_fact_invoice_line', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('odoo_id');
                $t->unsignedBigInteger('move_odoo_id')->nullable();
                $t->unsignedBigInteger('account_odoo_id')->nullable();
                $t->unsignedBigInteger('product_odoo_id')->nullable();
                // Denormalised from the parent so period filters need no join.
                $t->date('invoice_date')->nullable();
                $t->string('move_type', 32)->nullable();
                $t->decimal('quantity', 18, 4)->default(0);
                $t->decimal('price_unit', 18, 4)->default(0);
                $t->decimal('discount', 8, 4)->default(0);
                $t->decimal('price_subtotal', 18, 2)->default(0);
                $t->dateTime('write_date')->nullable();
                // Explicit index names: the auto-generated name for the
                // 3-column index is 66 chars, past MySQL's 64-char limit —
                // this CREATE silently never succeeded on MySQL until the
                // 2026_08_26 repair migration.
                $t->unique(['tenant_id', 'odoo_id'], 'wfil_tenant_odoo_uq');
                $t->index(['tenant_id', 'invoice_date'], 'wfil_tenant_date_ix');
                $t->index(['tenant_id', 'account_odoo_id', 'invoice_date'], 'wfil_tenant_acct_date_ix');
                $t->index(['tenant_id', 'move_odoo_id'], 'wfil_tenant_move_ix');
            });
        }
    }

    public function down(): void
    {
        foreach (['wh_fact_invoice_line', 'wh_fact_invoice', 'wh_dim_account', 'wh_dim_partner', 'wh_sync_state'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
