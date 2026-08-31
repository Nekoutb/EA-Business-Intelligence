<?php
declare(strict_types=1);

namespace App\Services;

/**
 * One of a tenant's data sources.
 *
 * A workspace normally feeds from a single ERP. Some clients run two systems
 * side by side (an accounting package and an operational one, or an old and
 * a new ERP during a migration) and want both in one set of reports. The
 * warehouse holds both: every sync writes to the same wh_* tables, and the
 * reports — which only ever read the warehouse — combine them without
 * knowing there were two.
 *
 * Two things keep the sources apart inside the shared tables:
 *
 *  - SETTINGS. Source 1 keeps the historical keys (ERP_TYPE, ODOO_URL,
 *    ERP_DB_HOST …). Source 2 uses the same names under an ERP2_ prefix
 *    (ERP2_TYPE, ERP2_ODOO_URL, ERP2_DB_HOST …), so nothing that reads the
 *    primary connection had to change.
 *
 *  - IDS. Every fact and dimension row is keyed by (tenant, odoo_id), and a
 *    second system will reuse the same small integers. Source 2 ids are
 *    stored shifted by ID_SPAN (a trillion), which the columns — unsigned
 *    BIGINT — hold comfortably. Foreign references (partner_odoo_id,
 *    move_odoo_id …) are shifted the same way, so joins between a source's
 *    own rows keep working and rows never cross-match the other source.
 *    The shift is applied at write time only; pagination cursors and the
 *    ids sent back to the ERP stay raw.
 */
final class DataSource
{
    /** Gap between sources' id spaces: raw ids stay far below this. */
    public const ID_SPAN = 1_000_000_000_000;

    public const MAX = 2;

    private function __construct(public readonly int $n) {}

    public static function primary(): self
    {
        return new self(1);
    }

    public static function of(int $n): self
    {
        if ($n < 1 || $n > self::MAX) {
            throw new \InvalidArgumentException("data source {$n} is out of range (1..".self::MAX.")");
        }
        return new self($n);
    }

    /** @return list<self> */
    public static function all(): array
    {
        return [new self(1), new self(2)];
    }

    public function isPrimary(): bool
    {
        return $this->n === 1;
    }

    /**
     * The setting key that holds $base for this source.
     * ERP_TYPE → ERP2_TYPE, ODOO_URL → ERP2_ODOO_URL, ERP_DB_HOST → ERP2_DB_HOST.
     */
    public function key(string $base): string
    {
        if ($this->n === 1) return $base;
        return 'ERP' . $this->n . '_' . preg_replace('/^ERP_/', '', $base);
    }

    /** The saved ERP type, normalised; '' when the source is not set up. */
    public function type(): string
    {
        $t = strtolower(trim((string) config_env($this->key('ERP_TYPE'), $this->n === 1 ? 'odoo' : '')));
        return $t === 'none' ? '' : $t;
    }

    public function isOdoo(): bool
    {
        return $this->type() === 'odoo';
    }

    /**
     * Whether this source has been configured for the active tenant: a type
     * and the credentials that type needs. The primary source is always
     * considered configured (its absence surfaces as a connection error,
     * which is the historical behaviour).
     */
    public function configured(): bool
    {
        if ($this->n === 1) return true;
        $type = $this->type();
        if ($type === '') return false;
        $need = match (true) {
            $type === 'odoo' => ['ODOO_URL', 'ODOO_DB'],
            $type === 'rest' => ['ERP_API_URL', 'ERP_API_KEY'],
            default          => ['ERP_DB_HOST', 'ERP_DB_DATABASE'],
        };
        foreach ($need as $k) {
            if (trim((string) config_env($this->key($k), '')) === '') return false;
        }
        return true;
    }

    /** Display name for logs and the sync report. */
    public function label(): string
    {
        $type = $this->type();
        $name = trim((string) config_env($this->key('ERP_NAME'), ''));
        $what = $name !== '' ? $name : ($type !== '' ? ucfirst($type) : 'not configured');
        return $this->n === 1 ? $what : "source {$this->n}: {$what}";
    }

    /** Amount added to every id this source writes into the warehouse. */
    public function idOffset(): int
    {
        return ($this->n - 1) * self::ID_SPAN;
    }

    /** Inclusive id range this source occupies in a warehouse table. */
    public function idRange(): array
    {
        return [$this->idOffset(), $this->idOffset() + self::ID_SPAN - 1];
    }

    /** wh_sync_state row name for a model synced from this source. */
    public function stateModel(string $model): string
    {
        return $this->n === 1 ? $model : "src{$this->n}:{$model}";
    }

    /** wh_key_map entity namespace for surrogate keys minted by this source. */
    public function keyEntity(string $entity): string
    {
        return $this->n === 1 ? $entity : "s{$this->n}:{$entity}";
    }
}
