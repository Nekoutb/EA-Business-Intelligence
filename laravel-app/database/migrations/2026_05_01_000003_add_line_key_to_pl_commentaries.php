<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-line commentary on the P&L MTD/YTD views.
 *
 * Was: one commentary per (tenant, year, view, month) — the user typed
 * a single block of text for the whole period.
 *
 * Now: one commentary per (tenant, year, view, month, line_key) — the
 * user types separate notes per P&L line at the end of each row in
 * the variance table. Existing rows are migrated with line_key='overall'
 * (kept for backward compat; no longer displayed inline).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Add an explicit index on tenant_id BEFORE dropping the
        //    composite unique. MySQL was using the composite unique
        //    to satisfy the FK on tenant_id; without an alternative
        //    index, dropping it errors with SQLSTATE[HY000] 1553
        //    "Cannot drop index needed in a foreign key constraint".
        Schema::table('pl_commentaries', function (Blueprint $table) {
            $table->index('tenant_id', 'pl_commentaries_tenant_idx');
        });

        // 2) Add the line_key column with a sensible default for any
        //    existing rows (kept for backward compat — no longer
        //    displayed inline).
        Schema::table('pl_commentaries', function (Blueprint $table) {
            $table->string('line_key', 64)->default('overall')->after('view');
        });

        // 3) Drop the old unique now that the FK has the explicit
        //    index to fall back on.
        Schema::table('pl_commentaries', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'year', 'month', 'view']);
        });

        // 4) New unique includes line_key so each (tenant, year,
        //    month, view, line) gets exactly one commentary.
        Schema::table('pl_commentaries', function (Blueprint $table) {
            $table->unique(['tenant_id', 'year', 'month', 'view', 'line_key']);
        });
    }

    public function down(): void
    {
        Schema::table('pl_commentaries', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'year', 'month', 'view', 'line_key']);
        });
        Schema::table('pl_commentaries', function (Blueprint $table) {
            $table->dropColumn('line_key');
        });
        Schema::table('pl_commentaries', function (Blueprint $table) {
            $table->unique(['tenant_id', 'year', 'month', 'view']);
        });
        Schema::table('pl_commentaries', function (Blueprint $table) {
            $table->dropIndex('pl_commentaries_tenant_idx');
        });
    }
};
