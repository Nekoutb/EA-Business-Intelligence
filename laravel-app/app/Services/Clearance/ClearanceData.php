<?php

namespace App\Services\Clearance;

use App\Odoo\OdooClient;

/**
 * Read-only data access for the clearance management pack (R1–R11).
 *
 * Only read methods exist on this class — search_read, read,
 * search_count, fields_get — so no report can ever write to Odoo.
 * Results are paged (2000 rows per call) and every query's row count is
 * logged for the report footer. The same class runs against a JSON
 * snapshot (for offline development and tests) when built with fromSnapshot().
 */
class ClearanceData
{
    public const PAGE = 2000;

    /** @var array<int, array{model:string, rows:int, note:string}> */
    public array $queryLog = [];
    /** @var string[] warnings to print on the report, never hidden */
    public array $warnings = [];

    private ?array $snapshot = null;
    private array $memo = [];

    private function __construct(private ?OdooClient $client) {}

    public static function live(OdooClient $client): self
    {
        return new self($client);
    }

    public static function fromSnapshot(array $snapshot): self
    {
        $d = new self(null);
        $d->snapshot = $snapshot;
        return $d;
    }

    /**
     * Assert every field the pack relies on exists (config/clearance_reports.php).
     * Throws with the full list of missing model.field names.
     */
    public function assertSchema(): void
    {
        $missing = [];
        foreach ((array) config('clearance_reports.required_fields') as $model => $need) {
            $have = array_keys($this->fieldsGet($model));
            foreach (array_diff($need, $have) as $f) $missing[] = "{$model}.{$f}";
        }
        if ($missing) {
            throw new \RuntimeException('Odoo schema check failed — missing: ' . implode(', ', $missing));
        }
    }

    public function fieldsGet(string $model): array
    {
        return $this->memo["fg:{$model}"] ??= $this->snapshot !== null
            ? (array) ($this->snapshot['fields'][$model] ?? [])
            : (array) $this->client->callModel($model, 'fields_get', ['attributes' => ['type', 'string', 'selection', 'relation']]);
    }

    /** Selection value → label map for one field. */
    public function selectionLabels(string $model, string $field): array
    {
        $out = [];
        foreach ((array) ($this->fieldsGet($model)[$field]['selection'] ?? []) as $pair) {
            if (is_array($pair) && count($pair) >= 2) $out[(string) $pair[0]] = (string) $pair[1];
        }
        return $out;
    }

    /** search_read across every page. $note labels the query in the log. */
    public function searchAll(string $model, array $domain, array $fields, string $note = '', ?string $order = null): array
    {
        $key = 'sa:' . md5($model . json_encode($domain) . json_encode($fields) . $order);
        if (isset($this->memo[$key])) return $this->memo[$key];
        $t0 = microtime(true);

        if ($this->snapshot !== null) {
            $rows = array_values(array_filter(
                (array) ($this->snapshot['rows'][$model] ?? []),
                fn ($r) => Domain::matches($r, $domain)
            ));
        } else {
            $rows = [];
            for ($offset = 0; ; $offset += self::PAGE) {
                $page = $this->client->searchRead($model, $domain, $fields, array_filter([
                    'limit' => self::PAGE, 'offset' => $offset, 'order' => $order ?? 'id',
                ]));
                $rows = array_merge($rows, $page);
                if (count($page) < self::PAGE) break;
            }
        }
        $this->queryLog[] = ['model' => $model, 'rows' => count($rows), 'note' => $note, 'ms' => (int) round(1000 * (microtime(true) - $t0))];
        return $this->memo[$key] = $rows;
    }

    /**
     * Sum of account.move.line balance per account for a domain — aggregated
     * in Odoo (read_group, then Odoo 19's formatted_read_group), falling back
     * to paging the lines when neither is available.
     * @return array<int,float> account id → balance
     */
    public function balanceByAccount(array $domain, string $note = ''): array
    {
        if ($this->snapshot === null) {
            foreach ([
                ['read_group', ['domain' => $domain, 'fields' => ['balance:sum'], 'groupby' => ['account_id'], 'lazy' => false]],
                ['formatted_read_group', ['domain' => $domain, 'groupby' => ['account_id'], 'aggregates' => ['balance:sum']]],
            ] as [$method, $kw]) {
                try {
                    $groups = (array) $this->client->callModel('account.move.line', $method, $kw);
                    $out = [];
                    foreach ($groups as $g) {
                        $id = self::m2oId($g['account_id'] ?? false);
                        if ($id) $out[$id] = (float) ($g['balance'] ?? $g['balance:sum'] ?? 0);
                    }
                    $this->queryLog[] = ['model' => 'account.move.line', 'rows' => count($groups), 'note' => "{$note} ({$method})"];
                    return $out;
                } catch (\Throwable $e) {
                    // try the next aggregation method
                }
            }
        }
        $out = [];
        foreach ($this->searchAll('account.move.line', $domain, ['account_id', 'balance'], "{$note} (paged)") as $l) {
            $id = self::m2oId($l['account_id'] ?? false);
            if ($id) $out[$id] = ($out[$id] ?? 0.0) + (float) $l['balance'];
        }
        return $out;
    }

    /**
     * Posted balance per month ('Y-m' => Σ debit − credit) on the given
     * accounts — read_group by date:month, falling back to paging the lines.
     */
    public function monthlySum(array $accountIds, string $from, string $to, string $note = ''): array
    {
        if (!$accountIds) return [];
        $domain = [['parent_state', '=', 'posted'], ['account_id', 'in', array_values($accountIds)], ['date', '>=', $from], ['date', '<=', $to]];
        if ($this->snapshot === null) {
            try {
                $out = [];
                $groups = (array) $this->client->callModel('account.move.line', 'read_group', [
                    'domain' => $domain, 'fields' => ['balance:sum'], 'groupby' => ['date:month'], 'lazy' => false,
                ]);
                foreach ($groups as $g) {
                    $f = $g['__range']['date:month']['from'] ?? $g['__range']['date']['from'] ?? null;
                    if (is_string($f)) $out[substr($f, 0, 7)] = ($out[substr($f, 0, 7)] ?? 0.0) + (float) ($g['balance'] ?? 0);
                }
                $this->queryLog[] = ['model' => 'account.move.line', 'rows' => count($groups), 'note' => "{$note} (read_group by month)"];
                return $out;
            } catch (\Throwable $e) {
                // fall back to paging the lines
            }
        }
        $out = [];
        foreach ($this->searchAll('account.move.line', $domain, ['date', 'balance'], "{$note} (paged)") as $l) {
            $ym = substr((string) $l['date'], 0, 7);
            $out[$ym] = ($out[$ym] ?? 0.0) + (float) $l['balance'];
        }
        return $out;
    }

    public function searchCount(string $model, array $domain): int
    {
        return $this->snapshot !== null
            ? count(array_filter((array) ($this->snapshot['rows'][$model] ?? []), fn ($r) => Domain::matches($r, $domain)))
            : $this->client->searchCount($model, $domain);
    }

    /** The company's clearance account settings, read once. */
    public function companyAccounts(): array
    {
        if (isset($this->memo['company'])) return $this->memo['company'];
        $f = config('clearance_reports.required_fields')['res.company'];
        $rows = $this->searchAll('res.company', [], array_merge(['name'], $f), 'company settings');
        $c = $rows[0] ?? [];
        $out = ['name' => $c['name'] ?? ''];
        foreach ($f as $k) $out[$k] = self::m2oId($c[$k] ?? false);
        return $this->memo['company'] = $out;
    }

    /** Record a warning unless a query that should return rows came back empty. */
    public function expectRows(array $rows, string $what): array
    {
        if (!$rows) $this->warnings[] = "No {$what} found for this period — check the data before relying on this section.";
        return $rows;
    }

    // ── many2one helpers ───────────────────────────────────────────────
    public static function m2oId(mixed $v): ?int
    {
        return is_array($v) ? (int) $v[0] : ($v ? (int) $v : null);
    }

    public static function m2oName(mixed $v, string $empty = '—'): string
    {
        return is_array($v) ? (string) ($v[1] ?? $empty) : $empty;
    }
}
