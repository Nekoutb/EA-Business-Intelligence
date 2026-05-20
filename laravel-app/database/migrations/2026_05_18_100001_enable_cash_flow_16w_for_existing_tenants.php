<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enable the cash_flow_16w module for every existing production
 * (non-demo) tenant, upserting both the module row and the parent
 * business_intelligence service row.
 *
 * Same pattern as the post-retirement-benefits backfill — uses
 * UPSERT semantics rather than INSERT-IF-NOT-EXISTS so existing
 * disabled rows get flipped to enabled. Safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tenants = DB::table('tenants')
            ->where(function ($q) {
                $q->where('is_demo', false)->orWhereNull('is_demo');
            })
            ->select('id')
            ->get();

        $now = now();
        foreach ($tenants as $t) {
            $this->upsertService($t->id, 'business_intelligence', $now);
            $this->upsertModule($t->id,  'cash_flow_16w',          $now);
        }
    }

    public function down(): void
    {
        // No-op on rollback to avoid disabling a module the operator
        // may now depend on.
    }

    private function upsertService(int $tenantId, string $key, $now): void
    {
        $existing = DB::table('tenant_services')
            ->where('tenant_id', $tenantId)->where('service_key', $key)->first();
        if ($existing) {
            if (!$existing->enabled) {
                DB::table('tenant_services')->where('id', $existing->id)->update([
                    'enabled' => true, 'enabled_at' => $now, 'updated_at' => $now,
                ]);
            }
        } else {
            DB::table('tenant_services')->insert([
                'tenant_id' => $tenantId, 'service_key' => $key, 'enabled' => true,
                'enabled_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function upsertModule(int $tenantId, string $key, $now): void
    {
        $existing = DB::table('tenant_modules')
            ->where('tenant_id', $tenantId)->where('module_key', $key)->first();
        if ($existing) {
            if (!$existing->enabled) {
                DB::table('tenant_modules')->where('id', $existing->id)->update([
                    'enabled' => true, 'enabled_at' => $now, 'updated_at' => $now,
                ]);
            }
        } else {
            DB::table('tenant_modules')->insert([
                'tenant_id' => $tenantId, 'module_key' => $key, 'enabled' => true,
                'enabled_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
};
