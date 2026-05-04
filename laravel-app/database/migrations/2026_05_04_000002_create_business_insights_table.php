<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * business_insights — daily Claude-generated narrative summary for each
 * tenant. Drives the Business Insights section of the Platform Admin
 * dashboard. One row per tenant per day; the latest is what /admin
 * renders.
 *
 *   - kpis_snapshot: the input given to the LLM (auditable + diffable)
 *   - insight_md   : full markdown body
 *   - summary      : 1-2 sentence headline shown on the index card
 *   - priority     : urgent | watch | good (parsed from response)
 *   - status       : ok | failed | skipped (skipped = no Odoo data yet)
 *   - input/output_tokens: cost tracking (Haiku is ~$1/MTok input, $5/MTok output)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->timestamp('generated_at')->index();
            $table->string('model', 64);
            $table->json('kpis_snapshot')->nullable();
            $table->longText('insight_md')->nullable();
            $table->string('summary', 500)->nullable();
            $table->string('priority', 16)->default('watch');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->string('status', 16)->default('ok');
            $table->string('error_msg', 500)->nullable();
            $table->timestamps();

            // (tenant, generated_at) is the typical lookup pattern for
            // "latest for tenant X" queries.
            $table->index(['tenant_id', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_insights');
    }
};
