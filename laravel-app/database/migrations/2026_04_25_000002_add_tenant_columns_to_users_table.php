<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the default users table for multi-tenant auth.
 *
 *   - tenant_id   : null for super-admins; FK to tenants for tenant users
 *   - role        : 'super_admin' | 'owner' | 'analyst' | 'viewer'
 *   - status      : 'active' | 'disabled'
 *   - last_login_at + last_login_ip for audit
 *
 * The (tenant_id, email) compound unique constraint allows the same email
 * to belong to multiple tenants (rare but useful for consultants).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')
                ->constrained('tenants')->nullOnDelete();
            $table->string('role', 32)->default('viewer')->after('password');
            $table->string('status', 20)->default('active')->after('role');
            $table->timestamp('last_login_at')->nullable()->after('status');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
        });

        // Drop the global unique on email; re-add as a compound (tenant_id, email).
        // SQLite path: rebuild the unique via a raw drop/add since named indexes vary.
        Schema::table('users', function (Blueprint $table) {
            try { $table->dropUnique('users_email_unique'); } catch (\Throwable $e) { /* may not exist on SQLite */ }
        });
        Schema::table('users', function (Blueprint $table) {
            $table->unique(['tenant_id', 'email'], 'users_tenant_email_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            try { $table->dropUnique('users_tenant_email_unique'); } catch (\Throwable $e) {}
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn(['role', 'status', 'last_login_at', 'last_login_ip']);
            $table->unique('email');
        });
    }
};
