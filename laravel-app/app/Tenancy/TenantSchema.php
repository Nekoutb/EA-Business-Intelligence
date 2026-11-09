<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Structure and contents of a client's own database (data layer).
 *
 * The platform database stays the single source of truth for the SHAPE of
 * client tables: provision() replicates each client table's definition from
 * it (foreign keys to control tables such as tenants / users dropped, since
 * those live on the platform only). The schema version is a fingerprint of
 * every client table's column list, so "behind" is detected without keeping
 * a second set of migrations.
 *
 * Migrations that change a client table after go-live must apply to every
 * client database too:
 *
 *     TenantSchema::each(fn ($schema) => $schema->table('wh_fact_invoice',
 *         fn ($t) => $t->string('new_col')->nullable()));
 *
 * A client database that cannot be reached is reported and marked 'behind'
 * rather than failing the whole deploy; the health check keeps flagging it.
 */
final class TenantSchema
{
    /** Client tables besides wh_* (discovered). */
    public const FIXED = [
        'tenant_productivity_months', 'business_insights', 'pl_commentaries', 'tenant_budgets',
        'line_donation_categories', 'partner_donation_categories',
        // parents before children (internal foreign keys)
        'post_retirement_assumptions', 'post_retirement_cbas', 'post_retirement_employees',
        'post_retirement_valuations', 'post_retirement_valuation_lines',
    ];

    /** Created in the client database, never copied (rebuilt by the warm worker). */
    public const CACHE_TABLES = ['cache', 'cache_locks'];

    /** Control tables: foreign keys pointing at these are dropped in a client database. */
    private const CONTROL = ['tenants', 'users'];

    /** Client tables present on the platform database, in copy order. */
    public static function tables(): array
    {
        $all = array_map(fn ($t) => is_array($t) ? $t['name'] : $t, Schema::connection(null)->getTables());
        $wh = array_values(array_filter($all, fn ($n) => str_starts_with($n, 'wh_')));
        sort($wh);
        $fixed = array_values(array_filter(self::FIXED, fn ($n) => in_array($n, $all, true)));
        return array_merge($wh, $fixed);
    }

    /** Fingerprint of the client tables' columns on a connection. */
    public static function fingerprint(?string $conn = null): string
    {
        $sb = Schema::connection($conn);
        $shape = [];
        foreach (array_merge(self::tables(), self::CACHE_TABLES) as $t) {
            if (!$sb->hasTable($t)) { $shape[$t] = null; continue; }
            $cols = $sb->getColumnListing($t);
            sort($cols);
            $shape[$t] = $cols;
        }
        return substr(sha1(json_encode($shape)), 0, 16);
    }

    /** Tables / columns the client database lacks compared with the platform. */
    public static function drift(string $conn = TenantDatabase::CONN): array
    {
        $out = [];
        foreach (array_merge(self::tables(), self::CACHE_TABLES) as $t) {
            if (!Schema::connection($conn)->hasTable($t)) { $out[$t] = 'missing table'; continue; }
            $miss = array_diff(Schema::connection(null)->getColumnListing($t), Schema::connection($conn)->getColumnListing($t));
            if ($miss) $out[$t] = 'missing ' . implode(', ', $miss);
        }
        return $out;
    }

    /**
     * Create the client tables that do not exist yet on the 'tenant'
     * connection (configured by TenantDatabase::target / activate).
     * Returns the list of tables created.
     */
    public static function provision(): array
    {
        $src = DB::connection(null);
        $dst = DB::connection(TenantDatabase::CONN);
        $created = [];
        foreach (array_merge(self::tables(), self::CACHE_TABLES) as $t) {
            if (Schema::connection(TenantDatabase::CONN)->hasTable($t)) continue;
            foreach (self::createStatements($src, $t) as $sql) $dst->statement($sql);
            $created[] = $t;
        }
        return $created;
    }

    /** CREATE statements for one table, as defined on the platform, without control-table foreign keys. */
    public static function createStatements(\Illuminate\Database\Connection $src, string $table): array
    {
        $driver = $src->getDriverName();
        if ($driver === 'sqlite') {
            $rows = $src->select("select type, sql from sqlite_master where tbl_name = ? and sql is not null order by type = 'index'", [$table]);
            $out = [];
            foreach ($rows as $r) $out[] = $r->type === 'table' ? self::stripControlFks((string) $r->sql, 'sqlite') : (string) $r->sql;
            return $out;
        }
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $row = (array) $src->selectOne('SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`');
            $sql = (string) ($row['Create Table'] ?? array_values($row)[1]);
            $sql = preg_replace('/\sAUTO_INCREMENT=\d+/', '', $sql);
            return [self::stripControlFks($sql, 'mysql')];
        }
        throw new \RuntimeException("Client databases are not supported on the '{$driver}' driver yet.");
    }

    private static function stripControlFks(string $sql, string $driver): string
    {
        $ctl = implode('|', self::CONTROL);
        if ($driver === 'mysql') {
            // ,\n  CONSTRAINT `x` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
            return preg_replace('/,\s*CONSTRAINT `[^`]+` FOREIGN KEY \([^)]*\) REFERENCES `(?:' . $ctl . ')` \([^)]*\)[^,\n]*/i', '', $sql);
        }
        // sqlite: , foreign key("tenant_id") references "tenants"("id") on delete cascade
        return preg_replace('/,\s*foreign key\s*\([^)]*\)\s*references\s*"?(?:' . $ctl . ')"?\s*\([^)]*\)(?:\s+on (?:delete|update) (?:cascade|set null|restrict|no action))*/i', '', $sql);
    }

    /**
     * Copy one client's rows from the platform database into its own
     * database (the 'tenant' connection). The client's rows already there
     * are replaced, so the copy can be repeated (e.g. the final pass at
     * switch-over). Returns [table => [platform, copied]].
     */
    public static function copy(Tenant $tenant, ?callable $progress = null): array
    {
        $src = DB::connection(null);
        $dst = DB::connection(TenantDatabase::CONN);
        $report = [];
        self::foreignKeys($dst, false);
        try {
            foreach (self::tables() as $t) {
                if (!Schema::connection(null)->hasColumn($t, 'tenant_id')) { $report[$t] = ['skipped: no tenant_id', 0]; continue; }
                $dst->table($t)->where('tenant_id', $tenant->id)->delete();
                $n = 0;
                $hasId = Schema::connection(null)->hasColumn($t, 'id');
                $q = $src->table($t)->where('tenant_id', $tenant->id);
                $write = function ($rows) use ($dst, $t, &$n) {
                    $batch = array_map(fn ($r) => (array) $r, $rows->all());
                    foreach (array_chunk($batch, 500) as $chunk) $dst->table($t)->insert($chunk);
                    $n += count($batch);
                };
                if ($hasId) $q->chunkById(2000, $write);
                else $q->orderBy(Schema::connection(null)->getColumnListing($t)[0])->chunk(2000, $write);
                $report[$t] = [$src->table($t)->where('tenant_id', $tenant->id)->count(), $dst->table($t)->where('tenant_id', $tenant->id)->count()];
                if ($progress) $progress($t, $report[$t]);
            }
        } finally {
            self::foreignKeys($dst, true);
        }
        return $report;
    }

    /** Row counts per client table on both sides: [table => [platform, client db]]. */
    public static function compare(Tenant $tenant): array
    {
        $out = [];
        foreach (self::tables() as $t) {
            if (!Schema::connection(null)->hasColumn($t, 'tenant_id')) continue;
            $a = DB::connection(null)->table($t)->where('tenant_id', $tenant->id)->count();
            $b = Schema::connection(TenantDatabase::CONN)->hasTable($t)
                ? DB::connection(TenantDatabase::CONN)->table($t)->where('tenant_id', $tenant->id)->count() : null;
            $out[$t] = [$a, $b];
        }
        return $out;
    }

    /**
     * Run a schema change on the platform database AND on every client
     * database (see class doc). Unreachable client databases are logged
     * and marked 'behind' instead of aborting the migration.
     */
    public static function each(callable $change): void
    {
        $change(Schema::connection(null));
        if (!Schema::hasColumn('tenants', 'data_location')) return;
        foreach (Tenant::query()->whereIn('data_location', ['ea', 'client'])->get() as $t) {
            try {
                TenantDatabase::target($t, TenantDatabase::location($t));
                $change(Schema::connection(TenantDatabase::CONN));
            } catch (\Throwable $e) {
                Log::error("Client database schema change failed for {$t->slug}: " . $e->getMessage());
                $t->forceFill(['db_status' => 'behind'])->saveQuietly();
            } finally {
                TenantDatabase::reset();
            }
        }
    }

    private static function foreignKeys(\Illuminate\Database\Connection $c, bool $on): void
    {
        $d = $c->getDriverName();
        if ($d === 'sqlite') $c->statement('PRAGMA foreign_keys = ' . ($on ? 'ON' : 'OFF'));
        elseif (in_array($d, ['mysql', 'mariadb'], true)) $c->statement('SET FOREIGN_KEY_CHECKS = ' . ($on ? '1' : '0'));
    }
}
