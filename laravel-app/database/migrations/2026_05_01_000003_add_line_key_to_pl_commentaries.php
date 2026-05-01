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
        Schema::table('pl_commentaries', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'year', 'month', 'view']);
        });
        Schema::table('pl_commentaries', function (Blueprint $table) {
            $table->string('line_key', 64)->default('overall')->after('view');
            $table->unique(['tenant_id', 'year', 'month', 'view', 'line_key']);
        });
    }

    public function down(): void
    {
        Schema::table('pl_commentaries', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'year', 'month', 'view', 'line_key']);
            $table->dropColumn('line_key');
        });
        Schema::table('pl_commentaries', function (Blueprint $table) {
            $table->unique(['tenant_id', 'year', 'month', 'view']);
        });
    }
};
