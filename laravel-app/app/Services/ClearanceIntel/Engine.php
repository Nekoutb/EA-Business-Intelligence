<?php

namespace App\Services\ClearanceIntel;

use App\Services\Clearance\ClearanceData as CD;
use App\Services\Clearance\Rules;

/**
 * Clearance Intelligence calculations: per-file facts, the KPI library
 * for any window, breakdowns, 12-month trends, forecasts and triggers.
 * Pure computation over a loaded Dataset — no I/O.
 */
final class Engine
{
    public const LIVE = ['draft', 'in_progress', 'ops_closed'];
    private const FEE_KINDS = ['customs_fee', 'commission', 'file_fee', 'other'];

    /** @var array<int,array{date:string,file:?int,kind:string,amount:float}> */
    private array $events = [];
    /** @var array<int,array> file id → facts */
    public array $facts = [];
    /** @var array<int,string> file id → local date it was first invoiced */
    private array $billedDate = [];
    /** @var array<string,?float> 'Y-m' → operating cost per file billed */
    private array $ohPerFile = [];

    public function __construct(private Dataset $ds, private string $asOf)
    {
        $this->normalizeDates();
        $this->prepareEvents();
        $this->prepareFacts();
    }

    // ── helpers ────────────────────────────────────────────────────────
    // Hot paths: tens of thousands of rows are measured for ~30 windows
    // (period, previous, 12 trend months, 12 forecast months), so dates
    // are converted once and compared as strings.
    private static array $localMemo = [];
    private static array $dayNo = [];

    /**
     * Odoo date ('Y-m-d') or UTC datetime → local 'Y-m-d'. Africa/Douala is
     * UTC+1 all year (no daylight saving): 23:00 UTC and later is the next day.
     */
    public static function d($v): ?string
    {
        if (!$v || !is_string($v)) return null;
        if (strlen($v) <= 10) return $v;
        return self::$localMemo[$v] ??= ((int) substr($v, 11, 2) >= 23
            ? gmdate('Y-m-d', strtotime(substr($v, 0, 10) . ' 00:00:00 UTC') + 86400)
            : substr($v, 0, 10));
    }

    private static function in($v, string $from, string $to): bool
    {
        $d = self::d($v);
        return $d !== null && $d >= $from && $d <= $to;
    }

    /** Whole days between two Odoo dates/datetimes (local), or null. */
    public static function days($a, $b): ?int
    {
        $a = self::d($a); $b = self::d($b);
        if (!$a || !$b) return null;
        $na = self::$dayNo[$a] ??= intdiv((int) strtotime($a . ' 00:00:00 UTC'), 86400);
        $nb = self::$dayNo[$b] ??= intdiv((int) strtotime($b . ' 00:00:00 UTC'), 86400);
        return $nb - $na;
    }

    /** Convert every date/datetime the engine compares to local dates, once. */
    private function normalizeDates(): void
    {
        $conv = function (array &$rows, array $fields) {
            foreach ($rows as &$r) foreach ($fields as $f) if (isset($r[$f]) && is_string($r[$f]) && strlen($r[$f]) > 10) $r[$f] = self::d($r[$f]);
            unset($r);
        };
        $conv($this->ds->turnaround, ['started', 'completed']);
        $conv($this->ds->expenses, ['date_submitted', 'date_approved', 'date_settlement_submitted', 'date_settlement_approved',
            'date_settled', 'date_documents_submitted', 'date_justification_submitted', 'date_justified', 'date_cancelled', 'date_cancel_requested']);
        $conv($this->ds->tasks, ['date_landed']);
        $conv($this->ds->reopenings, ['date']);
        $conv($this->ds->files, ['date_started', 'date_ops_closed', 'date_billed', 'date_closed', 'commercial_invoice_received',
            'documents_received_on', 'transit_start', 'transit_end']);
    }

    private static function avg(array $v): ?float
    {
        $v = array_values(array_filter($v, fn ($x) => $x !== null));
        return $v ? round(array_sum($v) / count($v), 1) : null;
    }

    private static function div($a, $b, int $dp = 1): ?float
    {
        return ($b === null || (float) $b == 0.0 || $a === null) ? null : round((float) $a / (float) $b, $dp);
    }

    private static function workingDays(string $from, string $to): int
    {
        $n = 0;
        for ($t = new \DateTimeImmutable($from); $t->format('Y-m-d') <= $to; $t = $t->modify('+1 day')) {
            if ((int) $t->format('N') <= 5) $n++;
        }
        return $n;
    }

    private static function months(string $from, string $to): float
    {
        $days = (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1;
        return max(1 / 30.44, $days / 30.44);
    }

    public function label(string $model, string $field, $value): string
    {
        return (string) ($this->ds->selection["{$model}.{$field}"][(string) $value] ?? ($value ?: '—'));
    }

    // ── preparation ───────────────────────────────────────────────────
    private function prepareEvents(): void
    {
        $counted = Rules::countedMoveIds($this->ds->moves);
        foreach ($this->ds->lines as $l) {
            $mid = CD::m2oId($l['move_id'] ?? false);
            if (!$mid || !isset($counted[$mid])) continue;
            $m = $this->ds->moves[$mid];
            $sign = ($m['move_type'] ?? '') === 'out_refund' ? -1 : 1;
            $amt = $sign * (float) ($l['price_subtotal'] ?? 0);
            $cat = $l['clearance_category'] ?? false;
            $kind = null;
            if ($cat === 'prestation') {
                $sk = (string) ($l['clearance_service_kind'] ?? '');
                if ($sk === 'handling' || !empty($l['is_handling'])) continue; // due back to the customer
                $kind = in_array($sk, self::FEE_KINDS, true) ? $sk : 'other';
            } elseif ($cat === 'adjustment') {
                $kind = $amt < 0 ? 'undercharge' : 'overcharge';
            } elseif ($cat === 'debours') {
                if (!empty($l['is_handling'])) continue; // due back to the customer
                $kind = 'debours';
            }
            if ($kind === null || $amt == 0.0) continue;
            $this->events[] = ['date' => (string) self::d($m['invoice_date'] ?? null), 'file' => CD::m2oId($m['logistics_file_id'] ?? false),
                               'kind' => $kind, 'amount' => $amt];
        }
    }

    private function prepareFacts(): void
    {
        $byFile = [];
        foreach ($this->events as $e) {
            if ($e['file']) $byFile[$e['file']][$e['kind']] = ($byFile[$e['file']][$e['kind']] ?? 0.0) + $e['amount'];
        }
        // A file is billed once a standing customer invoice carries it: the
        // billed date is its first invoice's date (the file's own Invoiced On
        // stamp is used only when no invoice is linked).
        $firstInv = []; $invCount = [];
        foreach ($this->ds->moves as $m) {
            if (($m['move_type'] ?? '') !== 'out_invoice' || !Rules::billStands($m)) continue;
            $fid = CD::m2oId($m['logistics_file_id'] ?? false);
            $d = self::d($m['invoice_date'] ?? null);
            if (!$fid || !$d) continue;
            $invCount[$fid] = ($invCount[$fid] ?? 0) + 1;
            if (!isset($firstInv[$fid]) || $d < $firstInv[$fid]) $firstInv[$fid] = $d;
        }
        $this->billedDate = [];
        foreach ($this->ds->files as $id => $f) {
            $b = $firstInv[$id] ?? self::d($f['date_billed'] ?? null);
            if ($b) $this->billedDate[$id] = $b;
        }
        // Operating cost per file billed, per month.
        $billedByMonth = [];
        foreach ($this->billedDate as $d) $billedByMonth[substr($d, 0, 7)] = ($billedByMonth[substr($d, 0, 7)] ?? 0) + 1;
        foreach ($this->ds->overheadByMonth as $ym => $oh) {
            $this->ohPerFile[$ym] = !empty($billedByMonth[$ym]) ? $oh / $billedByMonth[$ym] : null;
        }

        $advByFile = []; $expCount = [];
        foreach ($this->ds->expenses as $e) {
            $fid = CD::m2oId($e['file_id'] ?? false);
            if (!$fid) continue;
            if (($e['state'] ?? '') !== 'cancel') $expCount[$fid] = ($expCount[$fid] ?? 0) + 1;
            if (Rules::isOpenAdvance($e)) {
                $age = self::days($e['date_settled'] ?? null, $this->asOf);
                $advByFile[$fid] = max($advByFile[$fid] ?? 0, (int) $age);
            }
        }
        $overdueByFile = [];
        foreach ($this->ds->moves as $m) {
            if (($m['move_type'] ?? '') !== 'out_invoice' || !Rules::billStands($m) || (float) ($m['amount_residual'] ?? 0) <= 0) continue;
            $fid = CD::m2oId($m['logistics_file_id'] ?? false);
            $late = self::days($m['invoice_date_due'] ?? null, $this->asOf);
            if ($fid && $late !== null) $overdueByFile[$fid] = max($overdueByFile[$fid] ?? 0, $late);
        }
        $lateSteps = [];
        foreach ($this->ds->turnaround as $t) {
            if (!empty($t['is_late']) && ($fid = CD::m2oId($t['file_id'] ?? false))) $lateSteps[$fid] = ($lateSteps[$fid] ?? 0) + 1;
        }
        $reopened = [];
        foreach ($this->ds->reopenings as $r) if ($fid = CD::m2oId($r['file_id'] ?? false)) $reopened[$fid] = ($reopened[$fid] ?? 0) + 1;

        $td = fn ($n) => Targets::triggerDays($n);
        foreach ($this->ds->files as $id => $f) {
            $k = $byFile[$id] ?? [];
            $fees = 0.0;
            foreach (self::FEE_KINDS as $fk) $fees += $k[$fk] ?? 0.0;
            $over = $k['overcharge'] ?? 0.0; $under = -($k['undercharge'] ?? 0.0);
            $contribution = $fees + $over - $under;
            $billed = $this->billedDate[$id] ?? null;
            $oh = $billed ? ($this->ohPerFile[substr($billed, 0, 7)] ?? null) : null;
            $net = $billed && $oh !== null ? $contribution - $oh : null;

            $state = (string) ($f['state'] ?? '');
            $incurred = (float) ($f['debours_incurred'] ?? 0);
            // Débours billed = the débours lines of the file's standing invoices, credit notes deducted.
            $dbBilled = $k['debours'] ?? 0.0;
            $unbilledDebours = ($billed || in_array($state, ['ops_closed', 'done'], true)) ? max(0.0, $incurred - $dbBilled) : 0.0;

            $svcRate = (float) ($f['commission_rate'] ?? 0);
            $billedRate = (float) ($f['billing_commission_rate'] ?? 0);
            $waived = 0.0;
            if ($billed && $svcRate > 0 && $dbBilled > 0) {
                if (!empty($f['billing_commission_skipped'])) $waived = $svcRate / 100 * $dbBilled;
                elseif ($billedRate > 0 && $billedRate < $svcRate) $waived = ($svcRate - $billedRate) / 100 * $dbBilled;
            }

            $opened = self::d($f['date_opened'] ?? null);
            $started = self::d($f['date_started'] ?? null);
            $opsClosed = self::d($f['date_ops_closed'] ?? null);
            $flags = [];
            if ($state === 'draft' && $opened && self::days($opened, $this->asOf) > $td('not_started')) $flags[] = 'not_started';
            if ($state === 'in_progress' && $started && self::days($started, $this->asOf) > $td('wip_long')) $flags[] = 'wip_long';
            if ($state === 'ops_closed' && !$billed && $opsClosed && self::days($opsClosed, $this->asOf) > $td('unbilled')) $flags[] = 'unbilled';
            if ($unbilledDebours > 0.5) $flags[] = 'debours_left';
            if ($net !== null && $net < 0) $flags[] = 'loss';
            if ($under > 0.5) $flags[] = 'absorbed';
            if ($waived > 0.5) $flags[] = 'commission_off';
            if (isset($advByFile[$id]) && $advByFile[$id] > $td('advance_open')) $flags[] = 'advance_open';
            if (isset($overdueByFile[$id]) && $overdueByFile[$id] > $td('overdue')) $flags[] = 'overdue';
            if (!empty($reopened[$id]) || (int) ($f['reopen_count'] ?? 0) > 0) $flags[] = 'reopened';
            if (($f['waiver_state'] ?? '') === 'approved' || ($f['advance_waiver_state'] ?? '') === 'approved') $flags[] = 'waived';
            if (!empty($lateSteps[$id])) $flags[] = 'late_steps';

            $this->facts[$id] = [
                'id' => $id, 'name' => (string) ($f['name'] ?? ''), 'client' => CD::m2oName($f['partner_id'] ?? false),
                'client_id' => CD::m2oId($f['partner_id'] ?? false),
                'service' => CD::m2oName($f['service_type_id'] ?? false), 'service_id' => CD::m2oId($f['service_type_id'] ?? false),
                'regime' => $this->label('logistics.file', 'customs_regime', $f['customs_regime'] ?? ''),
                'port' => CD::m2oName($f['port_id'] ?? false), 'owner' => CD::m2oName($f['user_id'] ?? false),
                'owner_id' => CD::m2oId($f['user_id'] ?? false),
                'state' => $state, 'state_label' => $this->label('logistics.file', 'state', $state),
                'shipment' => $this->label('logistics.file', 'shipment_type', $f['shipment_type'] ?? ''),
                'sitting_with' => (string) ($f['stage_owner'] ?: '—'), 'waiting_for' => (string) ($f['stage_detail'] ?: ''),
                'opened' => $opened, 'started' => $started, 'ops_closed' => $opsClosed, 'billed' => $billed,
                'closed' => self::d($f['date_closed'] ?? null), 'delivered' => self::d($f['delivery_date'] ?? null),
                'eq_containers' => (int) ($f['equivalent_container_count'] ?? 0), 'weight' => (float) ($f['weight_kg'] ?? 0),
                'fee_had' => $k['customs_fee'] ?? 0.0, 'fee_commission' => $k['commission'] ?? 0.0,
                'fee_file' => $k['file_fee'] ?? 0.0, 'fee_other' => $k['other'] ?? 0.0, 'fees' => $fees,
                'overcharge' => $over, 'undercharge' => $under, 'contribution' => $contribution,
                'overhead' => $oh, 'net' => $net,
                'debours_incurred' => $incurred, 'debours_billed' => $dbBilled, 'unbilled_debours' => $unbilledDebours,
                'commission_waived' => $waived, 'unjustified' => (float) ($f['unjustified_advance_total'] ?? 0),
                'balance_due' => (float) ($f['invoice_balance_due'] ?? 0),
                'open_to_start' => self::days($opened, $started), 'start_to_ops' => self::days($started, $opsClosed),
                'ops_to_bill' => self::days($opsClosed, $billed), 'open_to_bill' => self::days($opened, $billed),
                'turnaround' => !empty($f['delivery_date']) ? (float) ($f['overall_turnaround_days'] ?: 0) : null,
                'transit' => !empty($f['transit_end']) ? (float) ($f['transit_days'] ?: 0) : null,
                'transit_end' => self::d($f['transit_end'] ?? null),
                'age' => $opened ? self::days($opened, $this->asOf) : null,
                'expenses' => $expCount[$id] ?? 0, 'invoices' => $invCount[$id] ?? 0, 'reopens' => max($reopened[$id] ?? 0, (int) ($f['reopen_count'] ?? 0)),
                'docs_complete' => !empty($f['documents_complete']), 'late_steps' => $lateSteps[$id] ?? 0,
                'flags' => $flags,
            ];
        }
    }

    // ── KPI values for one window ──────────────────────────────────────
    public function kpis(string $from, string $to): array
    {
        $F = $this->facts; $asOf = min($to, $this->asOf);
        $cnt = fn (string $field) => count(array_filter($F, fn ($f) => $f[$field] && $f[$field] >= $from && $f[$field] <= $to));
        $billedFiles = array_filter($F, fn ($f) => $f['billed'] && $f['billed'] >= $from && $f['billed'] <= $to);
        $nBilled = count($billedFiles);
        $months = self::months($from, $to);

        $ev = array_fill_keys(array_merge(self::FEE_KINDS, ['overcharge', 'undercharge', 'debours']), 0.0);
        foreach ($this->events as $e) if ($e['date'] >= $from && $e['date'] <= $to) $ev[$e['kind']] += $e['amount'];
        $fees = $ev['customs_fee'] + $ev['commission'] + $ev['file_fee'] + $ev['other'];
        $under = -$ev['undercharge'];
        $contribution = $fees + $ev['overcharge'] - $under;
        $overhead = 0.0; $ohKnown = false;
        foreach ($this->ds->overheadByMonth as $ym => $v) {
            if ($ym >= substr($from, 0, 7) && $ym <= substr($to, 0, 7)) { $overhead += $v; $ohKnown = true; }
        }
        $net = $contribution - $overhead;

        // Headcount: average of the manual months in the window, else profiles.
        $hc = [];
        foreach ($this->ds->headcountByMonth as $ym => $n) if ($ym >= substr($from, 0, 7) && $ym <= substr($to, 0, 7)) $hc[] = $n;
        $headcount = $hc ? array_sum($hc) / count($hc) : ($this->ds->headcountProfiles ?: null);

        $live = array_filter($F, fn ($f) => in_array($f['state'], self::LIVE, true));
        $owners = count(array_unique(array_filter(array_column($billedFiles, 'owner_id'))));

        // Turnaround view: steps completed in the window.
        $done = 0; $late = 0;
        foreach ($this->ds->turnaround as $t) {
            if (empty($t['is_done']) || !self::in($t['completed'] ?? null, $from, $to)) continue;
            $done++; if (!empty($t['is_late'])) $late++;
        }

        // Disbursements.
        $paid = 0; $engaged = 0.0; $cycle = []; $just = []; $cancelled = 0; $cash = 0.0; $adv = 0.0; $advAged = 0;
        foreach ($this->ds->expenses as $e) {
            $st = (string) ($e['state'] ?? '');
            if ($st !== 'cancel' && self::in($e['date_settled'] ?? null, $from, $to)) {
                $paid++; $engaged += (float) ($e['amount'] ?? 0);
                $cycle[] = self::days($e['date_requested'] ?? null, $e['date_settled'] ?? null);
            }
            if (self::in($e['date_justified'] ?? null, $from, $to)) $just[] = self::days($e['date_settled'] ?? null, $e['date_justified'] ?? null);
            if (self::in($e['date_cancelled'] ?? null, $from, $to)) $cancelled++;
            if (in_array($st, ['approved', 'settlement_submitted', 'settlement_approved'], true)) $cash += (float) ($e['amount'] ?? 0);
            if (Rules::isOpenAdvance($e)) {
                $adv += (float) ($e['amount'] ?? 0);
                if ((self::days($e['date_settled'] ?? null, $asOf) ?? 0) > Targets::triggerDays('advance_open')) $advAged++;
            }
        }

        $keyed = 0; $keyedErr = 0; $reassigned = 0;
        foreach ($this->ds->expenses as $e) {
            if (self::in($e['date_requested'] ?? null, $from, $to)) {
                $keyed++;
                if (($e['state'] ?? '') === 'cancel' && ($e['cancel_reason'] ?? '') === 'error') $keyedErr++;
            }
            if (($e['cancel_reason'] ?? '') === 'reassignment' && self::in($e['date_cancelled'] ?? null, $from, $to)) $reassigned++;
        }

        // Tasks waiting now (ongoing-file rows are a list, not an action).
        $tasks = array_filter($this->ds->tasks, fn ($t) => ($t['kind'] ?? '') !== 'ongoing_file');
        $oldest = null;
        foreach ($tasks as $t) { $a = self::days($t['date_landed'] ?? null, $this->asOf); if ($a !== null) $oldest = max($oldest ?? 0, $a); }

        // Invoices: credit notes, voids, receivables.
        $cn = 0.0; $voided = 0; $open = 0.0; $overdue = 0.0; $ttc90 = 0.0;
        $d90 = (new \DateTimeImmutable($asOf))->modify('-90 days')->format('Y-m-d');
        foreach ($this->ds->moves as $m) {
            $idate = self::d($m['invoice_date'] ?? null);
            if (($m['state'] ?? '') === 'posted' && !empty($m['clearance_voided']) && $idate && $idate >= $from && $idate <= $to) $voided++;
            if (!Rules::billStands($m)) continue;
            if (($m['move_type'] ?? '') === 'out_refund' && $idate >= $from && $idate <= $to) $cn += (float) ($m['amount_untaxed'] ?? 0);
            if (($m['move_type'] ?? '') === 'out_invoice') {
                $res = (float) ($m['amount_residual'] ?? 0);
                $open += $res;
                if ($res > 0 && ($due = self::d($m['invoice_date_due'] ?? null)) && $due < $this->asOf) $overdue += $res;
                if ($idate && $idate > $d90 && $idate <= $asOf) $ttc90 += (float) ($m['amount_total'] ?? 0);
            }
        }
        $reopens = count(array_filter($this->ds->reopenings, fn ($r) => self::in($r['date'] ?? null, $from, $to)));

        $waivers = count(array_filter($F, fn ($f) => in_array('waived', $f['flags'], true)
            && (($f['started'] && $f['started'] >= $from && $f['started'] <= $to) || ($f['billed'] && $f['billed'] >= $from && $f['billed'] <= $to))));

        $col = fn (array $rows, string $k) => array_column($rows, $k);
        $inW = fn (string $field) => array_filter($F, fn ($f) => $f[$field] && $f[$field] >= $from && $f[$field] <= $to);
        $delivered = $inW('delivered');
        $opsClosedIn = $inW('ops_closed');
        $startedIn = $inW('started');

        $v = [
            'files_opened' => $cnt('opened'), 'files_started' => $cnt('started'), 'files_ops_closed' => $cnt('ops_closed'),
            'files_billed' => $nBilled, 'files_closed' => $cnt('closed'), 'wip_files' => count($live),
            'eq_containers' => array_sum($col($inW('opened'), 'eq_containers')),
            'backlog_months' => $nBilled ? round(count($live) / ($nBilled / $months), 1) : null,

            'files_per_employee' => self::div($nBilled, $headcount),
            'files_per_employee_month' => $headcount ? self::div($nBilled / $months, $headcount) : null,
            'revenue_per_employee' => self::div($fees, $headcount, 0),
            'files_per_responsible' => self::div($nBilled, $owners),
            'billing_per_day' => self::div($nBilled, self::workingDays($from, $to)),
            'disbursements_paid' => $paid,
            'keyed_error_pct' => Rules::pct($keyedErr, $keyed), 'reassignments' => $reassigned,

            'turnaround_days' => self::avg($col($delivered, 'turnaround')),
            'transit_days' => self::avg($col($inW('transit_end'), 'transit')),
            'open_to_start_days' => self::avg($col($startedIn, 'open_to_start')),
            'start_to_ops_close_days' => self::avg($col($opsClosedIn, 'start_to_ops')),
            'ops_to_bill_days' => self::avg($col($billedFiles, 'ops_to_bill')),
            'open_to_bill_days' => self::avg($col($billedFiles, 'open_to_bill')),
            'steps_late_pct' => Rules::pct($late, $done),
            'expense_cycle_days' => self::avg($cycle),
            'justification_days' => self::avg($just),
            'tasks_open' => count($tasks),
            'tasks_oldest_days' => $oldest,
            'docs_complete_pct' => Rules::pct(count(array_filter($live, fn ($f) => $f['docs_complete'])), count($live)),

            'fees' => $fees, 'fee_had' => $ev['customs_fee'], 'fee_commission' => $ev['commission'],
            'fee_file' => $ev['file_fee'], 'fee_other' => $ev['other'],
            'revenue_per_file' => self::div($fees, $nBilled, 0),
            'contribution' => $contribution,
            'overhead' => $ohKnown ? $overhead : null,
            'overhead_per_file' => $ohKnown ? self::div($overhead, $nBilled, 0) : null,
            'net_margin' => $ohKnown ? $net : null,
            'net_margin_pct' => null, // set below (can be negative)
            'net_margin_per_file' => $ohKnown ? self::div($net, $nBilled, 0) : null,
            'loss_files_pct' => Rules::pct(count(array_filter($billedFiles, fn ($f) => $f['net'] !== null && $f['net'] < 0)), $nBilled),
            'commission_yield_pct' => Rules::pct($ev['commission'], $ev['debours']),
            'debours_engaged' => $engaged,

            'ar_open' => $open, 'ar_overdue_pct' => Rules::pct($overdue, $open),
            'dso' => $ttc90 > 0 ? round($open / $ttc90 * 90, 1) : null,

            'unbilled_debours' => array_sum($col($F, 'unbilled_debours')),
            'ops_closed_unbilled' => count(array_filter($F, fn ($f) => in_array('unbilled', $f['flags'], true))),
            'undercharge' => $under,
            'commission_waived' => array_sum($col($billedFiles, 'commission_waived')),
            'unjustified_advances' => $adv, 'advances_aged' => $advAged, 'waivers' => $waivers,
            'reopen_rate' => Rules::pct($reopens, $nBilled), 'credit_notes' => $cn, 'voided_invoices' => $voided,
            'cancelled_debours' => $cancelled,
            'cash_need' => $cash,
        ];
        // Net margin % can be negative: Rules::pct only handles the positive case cleanly.
        if ($ohKnown) $v['net_margin_pct'] = $fees > 0 ? round(100 * $net / $fees, 1) : null;
        return $v;
    }

    /** Previous window of the same length (a whole month → the previous month). */
    public static function previousWindow(string $from, string $to): array
    {
        $f = new \DateTimeImmutable($from);
        if (substr($from, 8) === '01' && $to === (new \DateTimeImmutable($to))->format('Y-m-t') && substr($from, 0, 7) === substr($to, 0, 7)) {
            $p = $f->modify('first day of previous month');
            return [$p->format('Y-m-d'), $p->format('Y-m-t')];
        }
        $len = $f->diff(new \DateTimeImmutable($to))->days;
        if (substr($from, 5) === '01-01') { // year to date → same span last year
            return [$f->modify('-1 year')->format('Y-m-d'), (new \DateTimeImmutable($to))->modify('-1 year')->format('Y-m-d')];
        }
        return [$f->modify('-' . ($len + 1) . ' days')->format('Y-m-d'), $f->modify('-1 day')->format('Y-m-d')];
    }

    // ── breakdowns ─────────────────────────────────────────────────────
    public function breakdowns(string $from, string $to): array
    {
        $F = $this->facts;
        $opened = array_filter($F, fn ($f) => $f['opened'] && $f['opened'] >= $from && $f['opened'] <= $to);
        $billed = array_filter($F, fn ($f) => $f['billed'] && $f['billed'] >= $from && $f['billed'] <= $to);
        $live = array_filter($F, fn ($f) => in_array($f['state'], self::LIVE, true));
        $group = function (array $rows, string $by, array $sum = []) {
            $out = [];
            foreach ($rows as $r) {
                $k = (string) ($r[$by] ?: '—');
                $out[$k]['label'] = $k;
                $out[$k]['files'] = ($out[$k]['files'] ?? 0) + 1;
                foreach ($sum as $s) $out[$k][$s] = ($out[$k][$s] ?? 0) + (float) ($r[$s] ?? 0);
            }
            usort($out, fn ($a, $b) => $b['files'] <=> $a['files']);
            return array_values($out);
        };
        $profit = ['fees', 'fee_had', 'fee_commission', 'fee_file', 'fee_other', 'contribution', 'undercharge', 'debours_billed', 'net'];
        $prof = function (array $rows) {
            foreach ($rows as &$r) {
                $r['fee_per_file'] = $r['files'] ? $r['fees'] / $r['files'] : null;
                $r['net_per_file'] = $r['files'] ? $r['net'] / $r['files'] : null;
                $r['margin_pct'] = $r['fees'] > 0 ? round(100 * $r['net'] / $r['fees'], 1) : null;
            }
            usort($rows, fn ($a, $b) => $b['fees'] <=> $a['fees']);
            return $rows;
        };

        // Turnaround per step (completed in window) + steps late in progress.
        $steps = [];
        foreach ($this->ds->turnaround as $t) {
            $s = (string) ($t['step'] ?? '');
            $steps[$s] ??= ['step' => $s, 'label' => $this->label('clearance.turnaround', 'step', $s), 'done' => 0, 'late' => 0,
                            'days' => [], 'target_h' => null, 'open' => 0, 'open_late' => 0];
            if (($t['target_hours'] ?? false) !== false) $steps[$s]['target_h'] = (float) $t['target_hours'];
            if (!empty($t['is_done'])) {
                if (!self::in($t['completed'] ?? null, $from, $to)) continue;
                $steps[$s]['done']++; if (!empty($t['is_late'])) $steps[$s]['late']++;
                $steps[$s]['days'][] = (float) ($t['days_taken'] ?? 0);
            } else {
                $steps[$s]['open']++; if (!empty($t['is_late'])) $steps[$s]['open_late']++;
            }
        }
        foreach ($steps as &$s) {
            $s['avg'] = self::avg($s['days']); $s['median'] = Rules::median($s['days']);
            $s['p90'] = Rules::percentile($s['days'], 90); $s['late_pct'] = Rules::pct($s['late'], $s['done']);
            $s['target_days'] = $s['target_h'] === null ? null : round($s['target_h'] / 24, 1);
            unset($s['days']);
        }
        unset($s);
        $order = array_keys($this->ds->selection['clearance.turnaround.step'] ?? []);
        uksort($steps, fn ($a, $b) => (array_search($a, $order, true) ?: 99) <=> (array_search($b, $order, true) ?: 99));

        // Queues now.
        $queues = [];
        foreach ($this->ds->tasks as $t) {
            $k = (string) ($t['kind'] ?? ''); if ($k === 'ongoing_file') continue;
            $age = self::days($t['date_landed'] ?? null, $this->asOf);
            $queues[$k] ??= ['kind' => $this->label('clearance.task', 'kind', $k), 'count' => 0, 'amount' => 0.0, 'ages' => [], 'b' => [0, 0, 0, 0]];
            $queues[$k]['count']++; $queues[$k]['amount'] += (float) ($t['amount'] ?? 0); $queues[$k]['ages'][] = $age;
            $queues[$k]['b'][$age === null || $age <= 1 ? 0 : ($age <= 3 ? 1 : ($age <= 7 ? 2 : 3))]++;
        }
        foreach ($queues as &$q) { $q['oldest'] = $q['ages'] ? max(array_filter($q['ages'], fn ($x) => $x !== null) ?: [0]) : null; unset($q['ages']); }
        unset($q);
        usort($queues, fn ($a, $b) => $b['count'] <=> $a['count']);

        // Productivity per staff member (every user holding a clearance role,
        // plus anyone else who acted in the period) and per department.
        $users = []; $deptFiles = [];
        $blank = fn (int $uid, string $name) => [
            'uid' => $uid, 'user' => $name, 'dept' => implode(', ', $this->ds->deptByUser[$uid] ?? []) ?: '—',
            'role' => isset($this->ds->deptByUser[$uid]),
            'files' => [], 'opened' => 0, 'billed' => 0, 'keyed' => 0, 'approved' => 0, 'settle_prepared' => 0,
            'settle_approved' => 0, 'paid' => 0, 'cancel_requested' => 0, 'cancel_executed' => 0, 'keyed_errors' => 0,
            'reassignments' => 0, 'approve_days' => [], 'pay_days' => [], 'prep_days' => [], 'own_open_to_bill' => [],
            'own_steps' => 0, 'own_late' => 0, 'waiting' => 0, 'waiting_oldest' => null,
        ];
        foreach ($this->ds->deptByUser as $uid => $_) $users[$uid] = $blank($uid, $this->ds->userNames[$uid] ?? ('User ' . $uid));
        $touch = function ($m2o, ?int $fid, ?string $what) use (&$users, &$deptFiles, $blank) {
            $uid = CD::m2oId($m2o);
            if (!$uid || $uid <= 2) return null;
            $users[$uid] ??= $blank($uid, CD::m2oName($m2o));
            if ($what) $users[$uid][$what]++;
            if ($fid) {
                $users[$uid]['files'][$fid] = true;
                foreach ($this->ds->deptByUser[$uid] ?? [] as $d) $deptFiles[$d][$fid] = true;
            }
            return $uid;
        };
        $ownerM2o = fn (array $f) => $f['owner_id'] ? [$f['owner_id'], $f['owner']] : false;
        foreach ($opened as $f) $touch($ownerM2o($f), $f['id'], 'opened');
        foreach ($billed as $f) {
            if ($uid = $touch($ownerM2o($f), $f['id'], 'billed')) {
                if ($f['open_to_bill'] !== null) $users[$uid]['own_open_to_bill'][] = $f['open_to_bill'];
            }
        }
        foreach ($this->ds->expenses as $e) {
            $fid = CD::m2oId($e['file_id'] ?? false);
            if (self::in($e['date_requested'] ?? null, $from, $to)) {
                $uid = $touch($e['create_uid'] ?? false, $fid, 'keyed');
                if ($uid && ($e['state'] ?? '') === 'cancel' && ($e['cancel_reason'] ?? '') === 'error') $users[$uid]['keyed_errors']++;
            }
            if (self::in($e['date_approved'] ?? null, $from, $to) && ($uid = $touch($e['approved_by_id'] ?? false, $fid, 'approved'))) {
                $users[$uid]['approve_days'][] = self::days($e['date_submitted'] ?? null, $e['date_approved']);
            }
            if (self::in($e['date_settlement_submitted'] ?? null, $from, $to) && ($uid = $touch($e['settlement_submitted_by_id'] ?? false, $fid, 'settle_prepared'))) {
                $users[$uid]['prep_days'][] = self::days($e['date_approved'] ?? null, $e['date_settlement_submitted']);
            }
            if (self::in($e['date_settlement_approved'] ?? null, $from, $to)) $touch($e['settlement_approved_by_id'] ?? false, $fid, 'settle_approved');
            if (self::in($e['date_settled'] ?? null, $from, $to) && ($uid = $touch($e['settled_by_id'] ?? false, $fid, 'paid'))) {
                $users[$uid]['pay_days'][] = self::days($e['date_settlement_approved'] ?? null, $e['date_settled']);
            }
            if (self::in($e['date_cancel_requested'] ?? null, $from, $to)) $touch($e['cancel_requested_by_id'] ?? false, $fid, 'cancel_requested');
            if (self::in($e['date_cancelled'] ?? null, $from, $to)) {
                $touch($e['cancelled_by_id'] ?? false, $fid, 'cancel_executed');
                if (($e['cancel_reason'] ?? '') === 'reassignment') {
                    // The reassignment counts against whoever keyed the cost on the wrong file.
                    if ($k = $touch($e['create_uid'] ?? false, $fid, null)) $users[$k]['reassignments']++;
                }
            }
        }
        // Steps on the files a person owns (the turnaround view records no actor).
        foreach ($this->ds->turnaround as $t) {
            if (empty($t['is_done']) || !self::in($t['completed'] ?? null, $from, $to)) continue;
            $fid = CD::m2oId($t['file_id'] ?? false);
            $uid = $fid ? ($this->facts[$fid]['owner_id'] ?? null) : null;
            if (!$uid || !isset($users[$uid])) continue;
            $users[$uid]['own_steps']++;
            if (!empty($t['is_late'])) $users[$uid]['own_late']++;
        }
        foreach ($this->ds->tasks as $t) {
            if (($t['kind'] ?? '') === 'ongoing_file') continue;
            $uid = CD::m2oId($t['holder_user_id'] ?? false);
            if (!$uid || !isset($users[$uid])) continue;
            $users[$uid]['waiting']++;
            $age = self::days($t['date_landed'] ?? null, $this->asOf);
            if ($age !== null) $users[$uid]['waiting_oldest'] = max($users[$uid]['waiting_oldest'] ?? 0, $age);
        }
        foreach ($users as &$u) {
            $u['files'] = count($u['files']);
            foreach (['approve_days', 'pay_days', 'prep_days', 'own_open_to_bill'] as $k) $u[$k] = self::avg($u[$k]);
            $u['late_pct'] = Rules::pct($u['own_late'], $u['own_steps']);
            $u['error_pct'] = Rules::pct($u['keyed_errors'], $u['keyed']);
        }
        unset($u);
        $users = array_values($users);
        usort($users, fn ($a, $b) => [$b['files'], $b['role']] <=> [$a['files'], $a['role']]);
        $depts = [];
        foreach (array_keys(\App\Services\HrProductivityService::DEPARTMENTS) as $d) {
            $members = count(array_filter($this->ds->deptByUser, fn ($ds) => in_array($d, $ds, true)));
            $n = count($deptFiles[$d] ?? []);
            $depts[] = ['dept' => $d, 'members' => $members, 'files' => $n, 'per_member' => $members ? round($n / $members, 1) : null];
        }

        $billedSorted = array_values($billed);
        usort($billedSorted, fn ($a, $b) => ($a['net'] ?? $a['contribution']) <=> ($b['net'] ?? $b['contribution']));

        return [
            'by_service' => $group($opened, 'service', ['eq_containers', 'weight']),
            'by_regime' => $group($opened, 'regime', ['eq_containers']),
            'by_client' => array_slice($group($opened, 'client', ['eq_containers']), 0, 15),
            'by_port' => $group($opened, 'port'),
            'by_shipment' => $group($opened, 'shipment'),
            'wip_state' => $group($live, 'state_label', ['debours_incurred']),
            'wip_sitting' => $group($live, 'sitting_with', ['debours_incurred']),
            'wip_age' => $this->ageBuckets($live),
            'profit_service' => $prof($group($billed, 'service', $profit)),
            'profit_client' => array_slice($prof($group($billed, 'client', $profit)), 0, 15),
            'profit_regime' => $prof($group($billed, 'regime', $profit)),
            'worst_files' => array_slice($billedSorted, 0, 10),
            'best_files' => array_slice(array_reverse($billedSorted), 0, 10),
            'steps' => array_values($steps),
            'queues' => $queues,
            'users' => $users,
            'departments' => $depts,
        ];
    }

    private function ageBuckets(array $rows): array
    {
        $b = ['0–7 days' => 0, '8–15 days' => 0, '16–30 days' => 0, '31–60 days' => 0, 'over 60 days' => 0];
        foreach ($rows as $r) {
            $a = (int) ($r['age'] ?? 0);
            $k = $a <= 7 ? '0–7 days' : ($a <= 15 ? '8–15 days' : ($a <= 30 ? '16–30 days' : ($a <= 60 ? '31–60 days' : 'over 60 days')));
            $b[$k]++;
        }
        return $b;
    }

    // ── trends and forecast ────────────────────────────────────────────
    public function trends(string $to, int $n = 12): array
    {
        $out = [];
        $end = new \DateTimeImmutable(substr($to, 0, 7) . '-01');
        for ($i = $n - 1; $i >= 0; $i--) {
            $m = $end->modify("-{$i} months");
            $f = $m->format('Y-m-01'); $t = min($m->format('Y-m-t'), $this->asOf);
            if ($f > $this->asOf) continue;
            $k = $this->kpis($f, $t);
            $out[$m->format('Y-m')] = ['label' => $m->format('M y')] + $k;
        }
        return $out;
    }

    public function forecast(string $to): array
    {
        $year = (int) substr($to, 0, 4);
        $curYm = substr($this->asOf, 0, 7);
        $rows = [];
        for ($m = 1; $m <= 12; $m++) {
            $ym = sprintf('%04d-%02d', $year, $m);
            $from = $ym . '-01';
            $last = (new \DateTimeImmutable($from))->format('Y-m-t');
            $rows[$ym] = ['label' => (new \DateTimeImmutable($from))->format('M'), 'actual_files' => null, 'actual_fees' => null,
                          'forecast_files' => null, 'forecast_fees' => null, 'partial' => $ym === $curYm];
            if ($from <= $this->asOf) {
                $k = $this->kpis($from, min($last, $this->asOf));
                $rows[$ym]['actual_files'] = $k['files_billed']; $rows[$ym]['actual_fees'] = $k['fees'];
            }
        }
        $full = array_filter($rows, fn ($r, $ym) => $r['actual_files'] !== null && $ym < $curYm, ARRAY_FILTER_USE_BOTH);
        $last3 = array_slice($full, -3, 3, true);
        $avgFiles = $last3 ? array_sum(array_column($last3, 'actual_files')) / count($last3) : 0;
        $avgFees = $last3 ? array_sum(array_column($last3, 'actual_fees')) / count($last3) : 0;
        $projFiles = array_sum(array_column($full, 'actual_files'));
        $projFees = array_sum(array_column($full, 'actual_fees'));
        foreach ($rows as $ym => &$r) {
            if ($ym >= $curYm || $r['actual_files'] === null) {
                $r['forecast_files'] = round($avgFiles, 1); $r['forecast_fees'] = round($avgFees);
                $projFiles += $avgFiles; $projFees += $avgFees;
            }
        }
        unset($r);

        $d90 = (new \DateTimeImmutable($this->asOf))->modify('-90 days')->format('Y-m-d');
        $k90 = $this->kpis($d90, $this->asOf);
        $avgFee = $k90['revenue_per_file'];
        $live = array_filter($this->facts, fn ($f) => in_array($f['state'], self::LIVE, true));
        $byState = [];
        foreach ($live as $f) {
            $byState[$f['state_label']]['files'] = ($byState[$f['state_label']]['files'] ?? 0) + 1;
            $byState[$f['state_label']]['debours'] = ($byState[$f['state_label']]['debours'] ?? 0) + $f['debours_incurred'];
        }
        return [
            'months' => $rows, 'basis_months' => array_keys($last3),
            'avg_files' => $avgFiles, 'avg_fees' => $avgFees,
            'fy_files' => round($projFiles), 'fy_fees' => round($projFees),
            'avg_fee_90' => $avgFee, 'pipeline_files' => count($live),
            'pipeline_fees' => $avgFee !== null ? count($live) * $avgFee : null,
            'pipeline_debours' => array_sum(array_column($live, 'debours_incurred')),
            'pipeline_by_state' => $byState,
        ];
    }
}
