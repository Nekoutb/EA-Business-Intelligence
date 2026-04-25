<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\TenantService;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Bootstrap a working multi-tenant install:
 *   - One super-admin (super@local / changeme) for the /admin backend
 *   - One "demo" tenant with all default modules enabled
 *   - One owner user (owner@demo.local / changeme) for that tenant
 *
 * Idempotent: re-running tops up missing rows but never duplicates.
 */
class TenancySeeder extends Seeder
{
    public function run(): void
    {
        // 1. Super admin (no tenant_id).
        User::firstOrCreate(
            ['email' => 'super@local', 'tenant_id' => null],
            [
                'name'     => 'Super Admin',
                'password' => Hash::make('changeme'),
                'role'     => User::ROLE_SUPER_ADMIN,
                'status'   => User::STATUS_ACTIVE,
            ]
        );

        // 2. Demo tenant.
        $demo = Tenant::firstOrCreate(
            ['slug' => 'demo'],
            [
                'name'   => 'Demo Tenant',
                'brand'  => 'Elite Advisors Management Suite',
                'status' => Tenant::STATUS_ACTIVE,
                'plan'   => 'standard',
            ]
        );

        // 3. Default services from registry (top-level offerings).
        foreach (config('modules.services', []) as $service) {
            $enabled = (bool) ($service['default'] ?? false);
            TenantService::updateOrCreate(
                ['tenant_id' => $demo->id, 'service_key' => $service['key']],
                ['enabled' => $enabled, 'enabled_at' => $enabled ? now() : null]
            );
        }

        // 4. Default modules from registry.
        foreach (config('modules.modules', []) as $module) {
            $enabled = (bool) ($module['default'] ?? false) || (bool) ($module['core'] ?? false);
            TenantModule::updateOrCreate(
                ['tenant_id' => $demo->id, 'module_key' => $module['key']],
                ['enabled' => $enabled, 'enabled_at' => $enabled ? now() : null]
            );
        }

        // 4. Owner user for the demo tenant.
        User::firstOrCreate(
            ['email' => 'owner@demo.local', 'tenant_id' => $demo->id],
            [
                'name'     => 'Demo Owner',
                'password' => Hash::make('changeme'),
                'role'     => User::ROLE_OWNER,
                'status'   => User::STATUS_ACTIVE,
            ]
        );
    }
}
