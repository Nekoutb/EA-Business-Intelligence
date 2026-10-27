<?php
declare(strict_types=1);

namespace App\Services\ClearanceIntel;

use App\Services\Clearance\ClearanceData;
use App\Services\HrProductivityService;
use App\Services\ProductivityOverrides;

/**
 * Everything Clearance Intelligence reads from Odoo, loaded once (read-only
 * calls through ClearanceData). Legacy (Teese) records are left out: files
 * in state Imported, legacy disbursements and legacy invoices.
 */
final class Dataset
{
    /** @var array<int,array> */ public array $files = [];
    /** @var array<int,array> */ public array $expenses = [];
    /** @var array<int,array> */ public array $moves = [];
    /** @var array<int,array> */ public array $lines = [];
    public array $turnaround = [];
    public array $tasks = [];
    public array $reopenings = [];
    public array $serviceTypes = [];
    public array $reasons = [];
    /** @var array<int,string[]> user id → departments */
    public array $deptByUser = [];
    /** @var array<string,int> 'Y-m' → manual headcount */
    public array $headcountByMonth = [];
    public int $headcountProfiles = 0;
    /** @var array<string,float> 'Y-m' → operating costs (class 6, débours accounts excluded) */
    public array $overheadByMonth = [];
    public array $selection = [];
    public array $warnings = [];
    public array $queryLog = [];

    public static function load(ClearanceData $d, string $asOf): self
    {
        $s = new self();
        $f = config('clearance_intel_fields');
        $missing = [];
        foreach ($f as $model => $need) {
            $have = array_keys($d->fieldsGet($model));
            foreach (array_diff($need, $have) as $x) $missing[] = "{$model}.{$x}";
        }
        if ($missing) throw new \RuntimeException('Odoo schema check failed — missing: ' . implode(', ', $missing));

        foreach (['logistics.file' => ['state', 'customs_regime', 'shipment_type'], 'clearance.turnaround' => ['step'],
                  'clearance.task' => ['kind'], 'logistics.expense' => ['state', 'payment_mode']] as $m => $fields) {
            foreach ($fields as $fld) $s->selection["{$m}.{$fld}"] = $d->selectionLabels($m, $fld);
        }

        $byId = fn (array $rows) => array_column($rows, null, 'id');

        $s->files = $byId(array_filter(
            $d->searchAll('logistics.file', [['state', '!=', 'imported']], $f['logistics.file'], 'clearance files'),
            fn ($r) => ($r['state'] ?? '') !== 'cancel'
        ));
        $s->expenses = $byId($d->searchAll('logistics.expense', [['is_legacy', '=', false]], $f['logistics.expense'], 'disbursements'));
        $s->moves = $byId($d->searchAll('account.move', [
            ['logistics_file_id', '!=', false], ['move_type', 'in', ['out_invoice', 'out_refund']], ['is_legacy', '=', false],
        ], $f['account.move'], 'clearance invoices'));
        if ($s->moves) {
            $s->lines = $byId($d->searchAll('account.move.line', [['move_id', 'in', array_keys($s->moves)]],
                $f['account.move.line'], 'invoice lines'));
        }
        $s->turnaround = $d->searchAll('clearance.turnaround', [], $f['clearance.turnaround'], 'turnaround steps');
        $s->tasks = $d->searchAll('clearance.task', [], $f['clearance.task'], 'waiting actions');
        $s->reopenings = $d->searchAll('logistics.file.reopening', [], $f['logistics.file.reopening'], 're-openings');
        $s->serviceTypes = $byId($d->searchAll('logistics.service.type', [], $f['logistics.service.type'], 'service types'));
        $s->reasons = $byId($d->searchAll('logistics.reopen.reason', [], $f['logistics.reopen.reason'], 're-opening reasons'));

        // Departments from the clearance role groups (same mapping as HR › Productivity).
        $groups = $d->searchAll('res.groups', [['name', '!=', false]], ['name', 'user_ids'], 'role groups');
        foreach (HrProductivityService::DEPARTMENTS as $dept => $patterns) {
            foreach ($groups as $g) {
                foreach ($patterns as $pat) {
                    if (strcasecmp(trim((string) $g['name']), $pat) !== 0) continue;
                    foreach ((array) ($g['user_ids'] ?? []) as $uid) {
                        if ((int) $uid <= 2) continue; // system accounts hold every role
                        $s->deptByUser[(int) $uid][$dept] = $dept;
                    }
                }
            }
        }
        $s->deptByUser = array_map('array_values', $s->deptByUser);

        // Headcount: Productivity settings when switched on, else employee profiles.
        $y = (int) substr($asOf, 0, 4);
        foreach ([$y - 1, $y] as $yy) {
            foreach (ProductivityOverrides::employees($yy) as $ym => $n) if ($n) $s->headcountByMonth[$ym] = (int) $n;
        }
        $s->headcountProfiles = $d->searchCount('hr.employee', []);

        // Operating costs per month: class 6, without the débours absorbed
        // and débours loss accounts (they are counted in contribution).
        $co = $d->searchAll('res.company', [], ['clearance_oop_undercharge_account_id', 'clearance_oop_loss_account_id'], 'company accounts');
        $exclude = [];
        foreach ($co as $c) {
            foreach (['clearance_oop_undercharge_account_id', 'clearance_oop_loss_account_id'] as $k) {
                $id = ClearanceData::m2oId($c[$k] ?? false);
                if ($id) $exclude[$id] = true;
            }
        }
        $ids = [];
        foreach ($d->searchAll('account.account', [['code', '=like', '6%']], ['code'], 'class 6 accounts') as $a) {
            if (!isset($exclude[(int) $a['id']])) $ids[] = (int) $a['id'];
        }
        $start = (new \DateTimeImmutable($asOf))->modify('first day of this month')->modify('-24 months')->format('Y-m-d');
        $s->overheadByMonth = $d->monthlySum($ids, $start, $asOf, 'operating costs');

        $s->warnings = $d->warnings;
        $s->queryLog = $d->queryLog;
        return $s;
    }
}
