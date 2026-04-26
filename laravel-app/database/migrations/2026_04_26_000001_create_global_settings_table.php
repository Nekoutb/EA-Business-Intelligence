<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide configuration store — managed exclusively by super admins.
 *
 * Used for values that should be shared across every tenant rather than
 * configured per workspace. The canonical example is the AI advisor
 * provider/key: the platform operator pays the bill, picks Claude vs OpenAI,
 * and rolls model upgrades for everyone in one place.
 *
 * Examples of keys:
 *   AI_PROVIDER, AI_MODEL, AI_API_KEY
 *
 * Values are stored as text. The model layer encrypts secrets transparently
 * (any key matching /KEY|SECRET|PASSWORD|TOKEN/i) using Laravel's Crypt facade,
 * mirroring TenantSetting behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->text('value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_settings');
    }
};
