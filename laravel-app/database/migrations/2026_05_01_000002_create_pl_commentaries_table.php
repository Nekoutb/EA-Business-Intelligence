<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant P&L commentary saved against the MTD / YTD views.
 *
 * Storage shape:
 *   tenant_id  — owner
 *   year       — fiscal year on the view at save time
 *   month      — 0 for YTD, 1..12 for MTD
 *   view       — 'mtd' | 'ytd' (mostly redundant given month sentinel
 *                but kept explicit so the schema reads cleanly)
 *   commentary — free-text; user reviews and saves notes here
 *   saved_by   — user who last saved
 *
 * Unique key (tenant, year, month, view) — one commentary per period
 * per tenant. Subsequent saves overwrite. The user reads back by
 * navigating to the same (year, month, view) selection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pl_commentaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->integer('year');
            $table->integer('month'); // 0 = YTD, 1..12 = MTD
            $table->string('view', 16);
            $table->text('commentary')->nullable();
            $table->foreignId('saved_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'year', 'month', 'view']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pl_commentaries');
    }
};
