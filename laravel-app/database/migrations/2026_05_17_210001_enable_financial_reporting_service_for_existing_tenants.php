<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enable the financial_reporting service for every existing
 * production (non-demo) tenant that doesn't have it yet.
 *
 * Why this exists: the EnsureModuleEnabled middleware checks
 * the parent SERVICE before the module:
 *
 *   if ($serviceKey && !$this->context->serviceEnabled($serviceKey)) {
 *       throws HttpException(403, "service not activated...");
 *   }
 *
 * The previous migration
 * (2026_05_17_200001_enable_post_retirement_benefits_for_existing_tenants)
 * only enabled the post_retirement_benefits MODULE row in
 * tenant_modules — but if the parent financial_reporting SERVICE
 * row in tenant_services doesn't exist (or is enabled=false),
 * the service-level check fires first and the module is still
 * unreachable.
 *
 * That's why /post-retirement-benefits still 404'd after the
 * first backfill ran. This migration fixes it by guaranteeing
 * the service row exists with enabled=true for every non-demo
 * tenant.
 *
 * Safe to re-run if needed: uses upsert semantics via the
 * service_key column check.
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
            // ── 1. Enable the financial_reporting SERVICE ──
            // The middleware checks the SERVICE before the MODULE,
            // so if this row is missing or disabled we get a 403
            // (or 404 with a stale route cache) even though the
            // module row is on.
            $this->upsertService($t->id, 'financial_reporting', $now);

            // ── 2. Enable the post_retirement_benefits MODULE ──
            // The previous migration (200001) only INSERTED rows
            // and used whereNotIn — so it skipped tenants whose
            // seeder had already created a row with enabled=false.
            // This upsert flips those existing rows to enabled=true,
            // covering the case the earlier migration missed.
            $this->upsertModule($t->id, 'post_retirement_benefits', $now);
        }
    }

    public function down(): void
    {
        // Leave service rows in place on rollback — disabling a
        // service that other Financial Reporting modules also use
        // would be more disruptive than a no-op.
    }

    private function upsertService(int $tenantId, string $key, $now): void
    {
        $existing = DB::table('tenant_services')
            ->where('tenant_id', $tenantId)
            ->where('service_key', $key)
            ->first();

        if ($existing) {
            if (!$existing->enabled) {
                DB::table('tenant_services')
                    ->where('id', $existing->id)
                    ->update([
                        'enabled'    => true,
                        'enabled_at' => $now,
                        'updated_at' => $now,
                    ]);
            }
        } else {
            DB::table('tenant_services')->insert([
                'tenant_id'   => $tenantId,
                'service_key' => $key,
                'enabled'     => true,
                'enabled_at'  => $now,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    private function upsertModule(int $tenantId, string $key, $now): void
    {
        $existing = DB::table('tenant_modules')
            ->where('tenant_id', $tenantId)
            ->where('module_key', $key)
            ->first();

        if ($existing) {
            if (!$existing->enabled) {
                DB::table('tenant_modules')
                    ->where('id', $existing->id)
                    ->update([
                        'enabled'    => true,
                        'enabled_at' => $now,
                        'updated_at' => $now,
                    ]);
            }
        } else {
            DB::table('tenant_modules')->insert([
                'tenant_id'  => $tenantId,
                'module_key' => $key,
                'enabled'    => true,
                'enabled_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
