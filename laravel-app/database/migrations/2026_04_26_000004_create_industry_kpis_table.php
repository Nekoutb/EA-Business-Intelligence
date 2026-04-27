<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('industry_kpis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('industry_id')->constrained()->cascadeOnDelete();
            $table->string('kpi_key', 64);
            $table->string('label', 191);
            $table->text('description')->nullable();
            $table->string('formula_key', 128);
            $table->string('module_key', 64);
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->string('unit', 32)->default('$');
            $table->string('color_class', 32)->default('brand');
            $table->text('icon')->nullable();
            $table->timestamps();

            $table->unique(['industry_id', 'kpi_key']);
            $table->index(['industry_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('industry_kpis');
    }
};
