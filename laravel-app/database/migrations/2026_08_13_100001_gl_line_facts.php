<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2 remainder — make the move-line fact a real GL fact.
 *
 * The sync already pulls account.move.line with NO domain filter, so this
 * table has always held every GL line, not just invoice lines. The name
 * said otherwise and that cost real time: a tie-out was compared against an
 * invoice-lines-only figure and read as a 26,497-row discrepancy when the
 * two sides were simply counting different things. Renaming it to match
 * what it holds is the point of this migration; the extra columns are what
 * let the GL be read from it.
 *
 * Rename is a Schema::rename, so the rows already synced are preserved —
 * no re-backfill needed for what is already there.
 *
 * gl_date is deliberately SEPARATE from invoice_date. reconcileLineDates()
 * overwrites invoice_date with the parent invoice's date so revenue lines
 * report in the invoice's month; the GL must keep the accounting date it
 * was actually booked on, and the two legitimately differ when a document
 * is dated in one period and posted in the next.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wh_fact_invoice_line') && !Schema::hasTable('wh_fact_gl_line')) {
            Schema::rename('wh_fact_invoice_line', 'wh_fact_gl_line');
        }

        if (!Schema::hasTable('wh_fact_gl_line')) {
            return; // Base warehouse migration has not run yet; nothing to extend.
        }

        Schema::table('wh_fact_gl_line', function (Blueprint $t) {
            // Accounting date as booked — never rewritten from the parent.
            if (!Schema::hasColumn('wh_fact_gl_line', 'gl_date')) {
                $t->date('gl_date')->nullable()->after('invoice_date');
            }
            // The two sides of double entry. Odoo stores both as positive
            // magnitudes on opposite columns rather than one signed amount.
            if (!Schema::hasColumn('wh_fact_gl_line', 'debit')) {
                $t->decimal('debit', 18, 2)->default(0)->after('gl_date');
            }
            if (!Schema::hasColumn('wh_fact_gl_line', 'credit')) {
                $t->decimal('credit', 18, 2)->default(0)->after('debit');
            }
            // Posted vs draft lives on the parent move. Every GL figure in
            // the app filters on posted, so it has to be on the line to
            // avoid a join for the most common predicate there is.
            if (!Schema::hasColumn('wh_fact_gl_line', 'parent_state')) {
                $t->string('parent_state', 16)->nullable()->after('credit');
            }
            // Not read yet — the cash-journal projections need it, and
            // carrying it now means those do not force a second backfill.
            if (!Schema::hasColumn('wh_fact_gl_line', 'journal_odoo_id')) {
                $t->unsignedBigInteger('journal_odoo_id')->nullable()->after('parent_state');
            }
        });

        Schema::table('wh_fact_gl_line', function (Blueprint $t) {
            // Serves the shape every GL question takes: these accounts,
            // posted only, up to this date.
            $t->index(['tenant_id', 'account_odoo_id', 'parent_state', 'gl_date'], 'wh_gl_acct_state_date');
            $t->index(['tenant_id', 'journal_odoo_id', 'parent_state', 'gl_date'], 'wh_gl_jrnl_state_date');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('wh_fact_gl_line')) return;

        Schema::table('wh_fact_gl_line', function (Blueprint $t) {
            $t->dropIndex('wh_gl_acct_state_date');
            $t->dropIndex('wh_gl_jrnl_state_date');
            $t->dropColumn(['gl_date', 'debit', 'credit', 'parent_state', 'journal_odoo_id']);
        });

        Schema::rename('wh_fact_gl_line', 'wh_fact_invoice_line');
    }
};
