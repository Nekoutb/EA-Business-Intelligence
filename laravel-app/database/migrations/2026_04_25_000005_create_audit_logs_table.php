<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for sensitive operations.
 *
 *   action  = e.g. 'tenant.create', 'module.toggle', 'user.invite',
 *                  'impersonate.start', 'impersonate.stop', 'settings.update'
 *   target  = optional human-readable target identifier (slug / id / etc.)
 *   payload = arbitrary JSON context (e.g. {from:false,to:true})
 *
 * tenant_id is nullable for super-admin global actions (create tenant, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64);
            $table->string('target', 191)->nullable();
            $table->json('payload')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
