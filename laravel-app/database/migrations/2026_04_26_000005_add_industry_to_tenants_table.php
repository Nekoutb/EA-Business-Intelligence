<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('industry_id')->nullable()->after('onboarded_at')->constrained()->nullOnDelete();
            $table->index('industry_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropForeignKeyIfExists(['industry_id']);
            $table->dropIndex(['industry_id']);
            $table->dropColumn('industry_id');
        });
    }
};
