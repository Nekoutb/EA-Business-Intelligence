<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('industry_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('industry_id')->constrained()->cascadeOnDelete();
            $table->string('module_key', 64);
            $table->boolean('enabled_by_default')->default(false);
            $table->boolean('required')->default(false);
            $table->timestamps();

            $table->unique(['industry_id', 'module_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('industry_modules');
    }
};
