<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant per-year P&L budget uploaded via the front-end.
 *
 * Storage: one row per (tenant, year). The `data` JSON holds a flat
 * map of P&L line keys → 12 monthly amounts in XAF, e.g.:
 *
 *   {
 *     "revenue":       {"1": 45000000, "2": 50000000, ...},
 *     "direct_costs":  {"1": -25000000, "2": -28000000, ...},
 *     "opex":          {"1": -10000000, ...},
 *     "depreciation":  {"1": -3000000, ...},
 *     "net_financial": {"1": -1000000, ...},
 *     "income_tax":    {"1": -2000000, ...}
 *   }
 *
 * Sign convention matches the displayed P&L: revenue positive, costs
 * negative. Amounts are stored at full precision (XAF, not millions)
 * to match the GL data the P&L Forecast service compares against.
 *
 * The PLForecastService consults this table when assembling the
 * Budget column on the MTD/YTD/Full-Year views; absence falls back
 * to PY × growth factor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->integer('year');
            $table->json('data');
            $table->string('source_filename')->nullable();
            $table->foreignId('uploaded_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_budgets');
    }
};
