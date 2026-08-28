<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Odoo\OdooFactory;
use App\Services\WarehouseSyncService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * P2 — warehouse sync.
 *
 * Incremental by default (only records changed since the last run); pass
 * --full for a complete backfill. Safe to re-run either way: rows are
 * upserted on (tenant, odoo id), so nothing duplicates.
 *
 * Manual: php artisan bms:warehouse-sync [--tenant=slug] [--full]
 *
 * A tenant with a second data source (ERP2_* settings) syncs both, one after
 * the other, into the same tables — see App\Services\DataSource.
 */
class WarehouseSync extends Command
{
    protected $signature = 'bms:warehouse-sync
                            {--tenant= : Only this tenant slug}
                            {--full    : Ignore watermarks and re-read everything}
                            {--model=  : Only this Odoo model (res.partner, account.account, account.move, account.move.line)}
                            {--seconds=0 : Stop cleanly after N seconds (0 = no limit); resumes next run}
                            {--reconcile : Also re-check GL line state/existence for the current year}
                            {--year= : Year for --reconcile (default: current)}';

    protected $description = 'Copy Odoo facts into the local warehouse tables (P2)';

    public function handle(): int
    {
        $lock = Cache::lock('bms:warehouse-sync:lock', 7200);
        if (!$lock->get()) {
            $this->warn('A warehouse sync is already running — skipped.');
            return self::SUCCESS;
        }

        @ini_set('memory_limit', '1536M');
        $full = (bool) $this->option('full');
        $only = trim((string) $this->option('tenant'));

        $query = Tenant::real();
        if (defined(Tenant::class . '::STATUS_SUSPENDED')) {
            $query->where('status', '!=', Tenant::STATUS_SUSPENDED);
        }
        if ($only !== '') $query->where('slug', $only);

        $failed = 0;
        try {
            foreach ($query->orderBy('id')->get() as $tenant) {
                $this->line("→ {$tenant->slug}" . ($full ? ' (full backfill)' : ''));
                try {
                    app(TenantContext::class)->set($tenant);

                    // A workspace may feed from two systems (see DataSource):
                    // each configured source syncs on its own, into the same
                    // warehouse tables, in its own id space.
                    foreach (\App\Services\DataSource::all() as $src) {
                        if (!$src->configured()) continue;
                        if (!$src->isPrimary()) $this->line("   [{$src->label()}]");

                        // Direct-database ERPs sync through their own service;
                        // the Odoo path below would only ever see a NullOdooClient.
                        if (!$src->isOdoo()) {
                            if (trim((string) config_env($src->key('ERP_DB_HOST'), '')) === '') {
                                $this->line('   skipped: non-Odoo ERP without database credentials');
                                continue;
                            }
                            $res = (new \App\Services\ErpDbSyncService($tenant, $src))->syncAll(function (string $model, array $r) {
                                $this->line(sprintf('   %-20s %8s rows %7.1fs %s',
                                    $model, number_format($r['rows']), $r['seconds'], $r['error'] ?? ''));
                            });
                            foreach ($res as $r) if ($r['error']) $failed++;
                            continue;
                        }

                        $svc = new WarehouseSyncService(OdooFactory::fromSource($src), $tenant, $src);
                        $budget = (float) $this->option('seconds');
                        $res = $svc->syncAll($full, function (string $model, array $r) {
                            $this->line(sprintf('   %-20s %8s rows %7.1fs %s',
                                $model, number_format($r['rows']), $r['seconds'], $r['error'] ?? ''));
                        }, ($this->option('model') ?: null), $budget > 0 ? microtime(true) + $budget : 0.0);
                        $fixed = $svc->reconcileLineDates();
                        $this->line("   line dates back-filled: " . number_format($fixed));

                        // Link cash application to its parent documents once the
                        // GL lines it references are present.
                        try {
                            $rm = $svc->resolveReconcileMoves();
                            if ($rm > 0) $this->line("   reconcile moves resolved: " . number_format($rm));
                        } catch (\Throwable $e) {
                            $this->line("   reconcile move resolution skipped: " . $e->getMessage());
                        }

                        // Deletions and parent-state changes are invisible to an
                        // upsert-only sync, so they need their own pass. Kept off
                        // the 15-minute tick — it re-reads a whole year of ids —
                        // and scheduled nightly instead.
                        if ($this->option('reconcile')) {
                            $y = (int) ($this->option('year') ?: date('Y'));
                            $svc->setDeadline($budget > 0 ? microtime(true) + $budget : 0.0);
                            $r = $svc->reconcileGlState($y . '-01-01', $y . '-12-31');
                            $this->line(sprintf('   gl reconcile %d: checked %s · states fixed %s · removed %s · %s',
                                $y, number_format($r['checked']), number_format($r['fixed']),
                                number_format($r['removed']),
                                $r['complete'] ? 'complete' : 'incomplete (no deletions)'));
                        }
                        foreach ($res as $r) if ($r['error']) $failed++;
                    }
                } catch (\Throwable $e) {
                    $this->error("   failed: " . $e->getMessage());
                    $failed++;
                }
            }
        } finally {
            $lock->release();
        }

        $this->info($failed === 0 ? 'Warehouse sync complete.' : "Warehouse sync finished with {$failed} error(s).");
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
