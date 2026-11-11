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
    /** @var array<int,string> user id → name, for role holders */
    public array $userNames = [];
    /** @var array<string,int> 'Y-m' → manual headcount */
    public array $headcountByMonth = [];
    public int $headcountProfiles = 0;
    /** @var array<string,float> 'Y-m' → operating costs (class 6, débours accounts excluded) */
    public array $overheadByMonth = [];
    /** @var array<int,array{0:int,1:string}> file id → [uid, name] of who last closed it for operations */
    public array $opsClosedBy = [];
    /** @var array<int,array{0:int,1:string}> file id → [uid, name] of who last closed it */
    public array $closedBy = [];
    /** @var array<int,array> refusals from the chatter: kind expense|payment, date, by [uid,name], expense id */
    public array $rejections = [];
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
        if ($s->deptByUser) {
            foreach ($d->searchAll('res.users', [['id', 'in', array_keys($s->deptByUser)]], ['name', 'active'], 'role holders') as $u) {
                $s->userNames[(int) $u['id']] = (string) $u['name'];
            }
            // Archived users keep their history but leave the staff list.
            $s->deptByUser = array_intersect_key($s->deptByUser, $s->userNames);
        }

        $s->loadActors($d, $asOf);

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

    /** Refusal notes on a disbursement, by what was refused (elite_clearance's own wording). */
    public const REFUSALS = [
        'Disbursement refused by'               => 'expense', // head of the service refuses the request
        'Returned to its requester by Finance'  => 'expense', // Head of Service Finance sends it back
        'Justification refused'                 => 'expense', // advance documents refused
        'Returned to the Head of Service Finance' => 'payment', // the payer refuses to pay it as it stands
    ];

    /**
     * Who closed files and who refused disbursements: elite_clearance keeps
     * no "closed by" field, so it is read from the chatter - the author of
     * "File closed by …", of the state-change note posted when a file is
     * closed for operations, and of each refusal note.
     */
    private function loadActors(ClearanceData $d, string $asOf): void
    {
        $since = (new \DateTimeImmutable($asOf))->modify('first day of january')->modify('-1 year')->format('Y-m-d') . ' 00:00:00';
        $userByPartner = [];
        foreach ($d->searchAll('res.users', [], ['name', 'partner_id'], 'users (chatter authors)') as $u) {
            if ($pid = ClearanceData::m2oId($u['partner_id'] ?? false)) $userByPartner[$pid] = [(int) $u['id'], (string) $u['name']];
        }
        $actor = fn ($author) => $userByPartner[ClearanceData::m2oId($author) ?? 0] ?? null;
        $fields = ['model', 'res_id', 'date', 'author_id', 'body', 'message_type'];

        // Closed: the latest "File closed by" note of each file.
        foreach ($d->searchAll('mail.message', [['model', '=', 'logistics.file'], ['date', '>=', $since],
                     ['body', 'ilike', 'File closed by']], $fields, 'file closings', 'date') as $m) {
            if ($a = $actor($m['author_id'] ?? false)) $this->closedBy[(int) $m['res_id']] = $a;
        }

        // Closed for operations: the note posted in the same moment as the
        // file's Closed for Operations stamp (the tracked state change).
        $stamp = [];
        foreach ($this->files as $id => $f) {
            $t = $f['date_ops_closed'] ?? null;
            if (is_string($t) && strlen($t) > 10 && $t >= $since) $stamp[(int) $id] = strtotime($t . ' UTC');
        }
        if ($stamp) {
            foreach (array_chunk(array_keys($stamp), 500) as $ids) {
                foreach ($d->searchAll('mail.message', [['model', '=', 'logistics.file'], ['res_id', 'in', $ids],
                             ['message_type', '=', 'notification'], ['date', '>=', $since]], ['res_id', 'date', 'author_id'],
                             'operations closings', 'date') as $m) {
                    $fid = (int) $m['res_id'];
                    $lag = strtotime((string) $m['date'] . ' UTC') - $stamp[$fid];
                    if ($lag >= 0 && $lag <= 30 && !isset($this->opsClosedBy[$fid]) && ($a = $actor($m['author_id'] ?? false))) {
                        $this->opsClosedBy[$fid] = $a;
                    }
                }
            }
        }

        // Refusals on disbursements.
        $dom = [['model', '=', 'logistics.expense'], ['date', '>=', $since]];
        $phr = array_keys(self::REFUSALS);
        for ($i = 1; $i < count($phr); $i++) $dom[] = '|';
        foreach ($phr as $p) $dom[] = ['body', 'ilike', $p];
        foreach ($d->searchAll('mail.message', $dom, $fields, 'refusals', 'date') as $m) {
            $body = strip_tags((string) ($m['body'] ?? ''));
            $kind = null;
            foreach (self::REFUSALS as $p => $k) if (stripos($body, $p) !== false) { $kind = $k; break; }
            if (!$kind) continue;
            $this->rejections[] = ['kind' => $kind, 'date' => (string) $m['date'], 'by' => $actor($m['author_id'] ?? false),
                                   'expense' => (int) $m['res_id']];
        }
    }
}
