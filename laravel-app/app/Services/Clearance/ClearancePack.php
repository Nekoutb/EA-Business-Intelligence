<?php

namespace App\Services\Clearance;

/**
 * Shared context for the R1–R11 reports: the period window, lazily-loaded
 * datasets (each read once per run), lookups, and the validation summary
 * that precedes every computation (live files, settled expenses, posted
 * invoices in period, the two R-i reconciliations, R-m per bank).
 */
class ClearancePack
{
    /** Report registry: code → [class, module key, title, cadence]. */
    public const REPORTS = [
        'r1'  => [Reports\R1Cockpit::class,       'clr_r1',  'Executive cockpit',                  'Monthly, weekly refresh'],
        'r2'  => [Reports\R2Pipeline::class,      'clr_r2',  'Files pipeline and bottlenecks',     'Weekly'],
        'r3'  => [Reports\R3Unbilled::class,      'clr_r3',  'Unbilled disbursements',             'Weekly'],
        'r4'  => [Reports\R4Advances::class,      'clr_r4',  'Staff advances',                     'Weekly'],
        'r5'  => [Reports\R5Turnaround::class,    'clr_r5',  'Turnaround against targets',         'Monthly'],
        'r6'  => [Reports\R6Profitability::class, 'clr_r6',  'File and service profitability',     'Monthly / quarterly'],
        'r7'  => [Reports\R7Portfolio::class,     'clr_r7',  'Client portfolio and collections',   'Monthly'],
        'r8'  => [Reports\R8Controls::class,      'clr_r8',  'Controls and exceptions',            'Monthly'],
        'r9'  => [Reports\R9Workload::class,      'clr_r9',  'Workload by queue',                  'Daily'],
        'r10' => [Reports\R10CashOut::class,      'clr_r10', 'Cash out by channel and category',   'Weekly'],
        'r11' => [Reports\R11Transit::class,      'clr_r11', 'Payments in transit by bank',        'Weekly, and at every bank reconciliation'],
    ];

    public string $asOf;         // today, Africa/Douala
    public string $extractedAt;  // DD/MM/YYYY HH:MM
    public string $prevFrom;
    public string $prevTo;
    public array $thresholds;
    private array $cache = [];

    public function __construct(
        public ClearanceData $d,
        public string $from,
        public string $to,
        public string $periodLabel,
        public array $source,        // ['db' => …, 'user' => …]
        array $thresholdOverrides = [],
    ) {
        $now = new \DateTimeImmutable('now', new \DateTimeZone(Rules::TZ));
        $this->asOf = $now->format('Y-m-d');
        $this->extractedAt = $now->format('d/m/Y H:i');
        $len = (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days;
        $isWholeMonth = substr($from, 8) === '01';
        $pf = $isWholeMonth ? (new \DateTimeImmutable($from))->modify('first day of previous month')
                            : (new \DateTimeImmutable($from))->modify('-' . ($len + 1) . ' days');
        $this->prevFrom = $pf->format('Y-m-d');
        $this->prevTo = $isWholeMonth
            ? (substr($to, 0, 10) === (new \DateTimeImmutable($to))->format('Y-m-t')
                ? $pf->format('Y-m-t')                                   // full month → full previous month
                : min($pf->modify('+' . $len . ' days'), $pf->modify('last day of this month'))->format('Y-m-d'))
            : (new \DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
        $this->thresholds = array_merge((array) config('clearance_reports.thresholds'), $thresholdOverrides);
    }

    // ── datasets (read once) ───────────────────────────────────────────
    private function fields(string $model, array $extra = []): array
    {
        return array_values(array_unique(array_merge(['id'], (array) (config('clearance_reports.required_fields')[$model] ?? []), $extra)));
    }

    public function files(): array
    {
        return $this->cache['files'] ??= $this->d->searchAll('logistics.file', [], $this->fields('logistics.file'), 'clearance files');
    }

    /** Files keyed by id. */
    public function filesById(): array
    {
        return $this->cache['filesById'] ??= array_column($this->files(), null, 'id');
    }

    public function expenses(): array
    {
        return $this->cache['expenses'] ??= $this->d->searchAll('logistics.expense',
            [['is_legacy', '=', false]], $this->fields('logistics.expense'), 'disbursements (non-legacy)');
    }

    public function categoriesById(): array
    {
        return $this->cache['cats'] ??= array_column($this->d->searchAll('logistics.expense.category', [],
            $this->fields('logistics.expense.category'), 'expense categories'), null, 'id');
    }

    public function serviceTypesById(): array
    {
        return $this->cache['stypes'] ??= array_column($this->d->searchAll('logistics.service.type', [],
            $this->fields('logistics.service.type'), 'service types'), null, 'id');
    }

    public function employeesById(): array
    {
        return $this->cache['emps'] ??= array_column($this->d->searchAll('hr.employee', [],
            $this->fields('hr.employee'), 'employees'), null, 'id');
    }

    /** Customer invoices and credit notes, non-legacy, any state (voids need posted+voided). */
    public function invoices(): array
    {
        return $this->cache['inv'] ??= $this->d->searchAll('account.move', [
            ['move_type', 'in', ['out_invoice', 'out_refund']], ['is_legacy', '=', false],
        ], $this->fields('account.move'), 'customer invoices and credit notes');
    }

    public function invoicesById(): array
    {
        return $this->cache['invById'] ??= array_column($this->invoices(), null, 'id');
    }

    public function countedInvoiceIds(): array
    {
        return $this->cache['counted'] ??= Rules::countedMoveIds($this->invoicesById());
    }

    /** Posted invoice lines carrying a clearance category. */
    public function invoiceLines(): array
    {
        return $this->cache['invLines'] ??= $this->d->searchAll('account.move.line', [
            ['clearance_category', '!=', false], ['parent_state', '=', 'posted'],
        ], $this->fields('account.move.line', ['parent_state']), 'clearance invoice lines');
    }

    /** Invoice lines of counted moves whose invoice_date falls in [from,to]. */
    public function linesInWindow(string $from, string $to): array
    {
        $inv = $this->invoicesById();
        $ok = $this->countedInvoiceIds();
        return array_values(array_filter($this->invoiceLines(), function ($l) use ($inv, $ok, $from, $to) {
            $mid = ClearanceData::m2oId($l['move_id'] ?? false);
            return $mid && isset($ok[$mid]) && Rules::inPeriod($inv[$mid]['invoice_date'] ?? null, $from, $to);
        }));
    }

    public function partnersById(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $have = $this->cache['partners'] ?? [];
        $need = array_values(array_diff($ids, array_keys($have)));
        if ($need) {
            foreach ($this->d->searchAll('res.partner', [['id', 'in', $need]], $this->fields('res.partner'), 'partners') as $p) {
                $have[(int) $p['id']] = $p;
            }
            $this->cache['partners'] = $have;
        }
        return array_intersect_key($have, array_flip($ids));
    }

    public function turnaround(): array
    {
        return $this->cache['turn'] ??= $this->d->searchAll('clearance.turnaround', [],
            $this->fields('clearance.turnaround'), 'turnaround steps');
    }

    /** step → target hours (first non-empty target seen for the step). */
    public function stepTargets(): array
    {
        if (isset($this->cache['targets'])) return $this->cache['targets'];
        $t = [];
        foreach ($this->turnaround() as $r) {
            if (!isset($t[$r['step']]) && (float) ($r['target_hours'] ?? 0) > 0) $t[$r['step']] = (float) $r['target_hours'];
        }
        return $this->cache['targets'] = $t;
    }

    public function tasks(): array
    {
        return $this->cache['tasks'] ??= $this->d->searchAll('clearance.task', [], $this->fields('clearance.task'), 'open work queue');
    }

    public function journals(): array
    {
        if (isset($this->cache['journals'])) return $this->cache['journals'];
        $j = $this->d->searchAll('account.journal', [['type', 'in', ['bank', 'cash']]], $this->fields('account.journal'), 'payment journals');
        $pmlIds = [];
        foreach ($j as $row) foreach ((array) ($row['outbound_payment_method_line_ids'] ?? []) as $id) $pmlIds[] = (int) $id;
        $pml = $pmlIds ? array_column($this->d->searchAll('account.payment.method.line', [['id', 'in', $pmlIds]],
            $this->fields('account.payment.method.line'), 'payment method lines'), null, 'id') : [];
        foreach ($j as &$row) {
            $row['holding_account_id'] = null;
            foreach ((array) ($row['outbound_payment_method_line_ids'] ?? []) as $id) {
                $acc = ClearanceData::m2oId($pml[(int) $id]['payment_account_id'] ?? false);
                if ($acc) { $row['holding_account_id'] = $acc; $row['holding_account_name'] = ClearanceData::m2oName($pml[(int) $id]['payment_account_id']); break; }
            }
        }
        return $this->cache['journals'] = $j;
    }

    /** Posted lines on the given accounts (for reconciliation detail). */
    public function accountLines(array $accountIds, string $note): array
    {
        $accountIds = array_values(array_filter($accountIds));
        if (!$accountIds) return [];
        $key = 'al:' . implode(',', $accountIds);
        return $this->cache[$key] ??= $this->d->searchAll('account.move.line', [
            ['account_id', 'in', $accountIds], ['parent_state', '=', 'posted'],
        ], $this->fields('account.move.line', ['parent_state', 'journal_id']), $note);
    }

    public function refusals(): array
    {
        if (isset($this->cache['refusals'])) return $this->cache['refusals'];
        $msgs = $this->d->searchAll('mail.message', [
            ['model', 'in', ['logistics.file', 'logistics.expense', 'logistics.billing.service']],
            ['body', 'ilike', 'Reason:'],
        ], $this->fields('mail.message'), 'refusal messages');
        $out = [];
        foreach ($msgs as $m) {
            $p = Rules::parseRefusal((string) ($m['body'] ?? ''));
            if ($p) $out[] = $p + ['date' => $m['date'], 'author' => ClearanceData::m2oName($m['author_id'] ?? false),
                                   'model' => $m['model'], 'res_id' => $m['res_id']];
        }
        usort($out, fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));
        return $this->cache['refusals'] = $out;
    }

    // ── cross-report figures ───────────────────────────────────────────
    public function unbilled(): array
    {
        return $this->cache['unbilled'] ??= array_values(array_filter($this->expenses(), [Rules::class, 'isUnbilledDisbursement']));
    }

    public function openAdvances(): array
    {
        return $this->cache['adv'] ??= array_values(array_filter($this->expenses(), [Rules::class, 'isOpenAdvance']));
    }

    /**
     * Advance holder per expense: employee_id when keyed; otherwise the
     * partner on the settlement entry's line on the staff advance account
     * (the accounting records who holds the cash even when the form doesn't).
     * @return array<int, array{key:string, name:string, partner:?int, source:string}>
     */
    public function advanceHolders(): array
    {
        if (isset($this->cache['holders'])) return $this->cache['holders'];
        $emps = $this->employeesById();
        $advAcc = $this->d->companyAccounts()['clearance_advance_account_id'];
        $byMove = [];
        foreach ($this->accountLines([$advAcc], 'staff advance account lines') as $l) {
            $mid = ClearanceData::m2oId($l['move_id'] ?? false);
            if ($mid && !isset($byMove[$mid]) && ($pid = ClearanceData::m2oId($l['partner_id'] ?? false))) {
                $byMove[$mid] = [$pid, ClearanceData::m2oName($l['partner_id'])];
            }
        }
        $out = [];
        foreach ($this->expenses() as $e) {
            if (($e['payment_mode'] ?? '') !== 'advance') continue;
            if ($eid = ClearanceData::m2oId($e['employee_id'] ?? false)) {
                $out[(int) $e['id']] = ['key' => "e{$eid}", 'name' => ClearanceData::m2oName($e['employee_id']),
                    'partner' => ClearanceData::m2oId($emps[$eid]['work_contact_id'] ?? false), 'source' => 'employee'];
            } elseif (($mid = ClearanceData::m2oId($e['settlement_move_id'] ?? false)) && isset($byMove[$mid])) {
                [$pid, $name] = $byMove[$mid];
                $out[(int) $e['id']] = ['key' => "p{$pid}", 'name' => $name, 'partner' => $pid, 'source' => 'settlement entry'];
            } else {
                $out[(int) $e['id']] = ['key' => 'none', 'name' => '(no holder recorded)', 'partner' => null, 'source' => 'none'];
            }
        }
        return $this->cache['holders'] = $out;
    }

    /** R-i: unbilled vs OOP account, unjustified vs advance account. */
    public function reconciliations(): array
    {
        if (isset($this->cache['recon'])) return $this->cache['recon'];
        $co = $this->d->companyAccounts();
        $bal = $this->d->balanceByAccount([
            ['account_id', 'in', array_values(array_filter([$co['clearance_oop_account_id'], $co['clearance_advance_account_id']]))],
            ['parent_state', '=', 'posted'],
        ], 'OOP + advance account balances');
        $cats = $this->categoriesById();
        $advJustifiable = array_filter($this->openAdvances(), fn ($e) => !Rules::isNonJustifiable($e, $cats));
        return $this->cache['recon'] = [
            'oop' => Rules::reconcile(array_sum(array_column($this->unbilled(), 'amount')), $bal[$co['clearance_oop_account_id']] ?? 0.0)
                + ['label' => 'Unbilled disbursements vs out-of-pocket account (débours engagés)', 'account' => $co['clearance_oop_account_id']],
            'advance' => Rules::reconcile(array_sum(array_column($this->openAdvances(), 'amount')), $bal[$co['clearance_advance_account_id']] ?? 0.0)
                + ['label' => 'Unjustified advances vs staff advance account', 'account' => $co['clearance_advance_account_id'],
                   'justifiable_only' => array_sum(array_column($advJustifiable, 'amount'))],
        ];
    }

    /** R-m per bank journal with a holding account. */
    public function transitByBank(): array
    {
        if (isset($this->cache['transit'])) return $this->cache['transit'];
        $out = [];
        foreach ($this->journals() as $j) {
            if (!$j['holding_account_id']) continue;
            $lines = $this->accountLines([$j['holding_account_id']], 'holding account ' . $j['name']);
            $bankId = ClearanceData::m2oId($j['default_account_id'] ?? false);
            $bankBal = $bankId ? ($this->d->balanceByAccount([['account_id', '=', $bankId], ['parent_state', '=', 'posted']], 'bank balance ' . $j['name'])[$bankId] ?? 0.0) : 0.0;
            $open = array_values(array_filter($lines, fn ($l) => empty($l['reconciled'])));
            $transit = array_sum(array_map(fn ($l) => (float) $l['balance'], $open));
            $holding = array_sum(array_map(fn ($l) => (float) $l['balance'], $lines));
            $out[] = [
                'journal' => $j, 'lines' => $lines, 'open' => $open,
                'issued'  => array_sum(array_map(fn ($l) => (float) $l['credit'], array_filter($lines, fn ($l) => Rules::inPeriod($l['date'], $this->from, $this->to)))),
                'cleared' => array_sum(array_map(fn ($l) => (float) $l['debit'], array_filter($lines, fn ($l) => Rules::inPeriod($l['date'], $this->from, $this->to)))),
                'transit' => $transit, 'bank_balance' => $bankBal,
                // transit is a credit balance (negative); available = bank − |transit|
                'available' => $bankBal + $transit,
                'recon' => Rules::reconcile($transit, $holding),
            ];
        }
        return $this->cache['transit'] = $out;
    }

    /** The validation summary printed before any report body. */
    public function validation(): array
    {
        $posted = array_filter($this->invoices(), fn ($m) => ($m['state'] ?? '') === 'posted'
            && Rules::inPeriod($m['invoice_date'] ?? null, $this->from, $this->to));
        $r = $this->reconciliations();
        return [
            'live_files'       => count(array_filter($this->files(), [Rules::class, 'isLiveFile'])),
            'settled_expenses' => count(array_filter($this->expenses(), fn ($e) => !empty($e['date_settled']))),
            'posted_invoices'  => count($posted),
            'recon_oop'        => $r['oop'],
            'recon_advance'    => $r['advance'],
            'transit'          => array_map(fn ($b) => ['bank' => $b['journal']['name'], 'recon' => $b['recon']], $this->transitByBank()),
            'banks_without_holding' => array_values(array_map(fn ($j) => $j['name'],
                array_filter($this->journals(), fn ($j) => $j['type'] === 'bank' && !$j['holding_account_id']))),
        ];
    }

    // ── shared helpers for reports ─────────────────────────────────────
    public function fileName(mixed $m2o): string
    {
        return ClearanceData::m2oName($m2o);
    }

    public function serviceCode(array $file): string
    {
        $sid = ClearanceData::m2oId($file['service_type_id'] ?? false);
        return (string) ($this->serviceTypesById()[$sid]['code'] ?? '—');
    }

    /** Date the file entered its current state (for "days in current state"). */
    public static function stateSince(array $f): ?string
    {
        return match ($f['state'] ?? '') {
            'draft'       => $f['date_opened'] ?: $f['create_date'],
            'in_progress' => $f['date_started'] ?: $f['date_opened'],
            'ops_closed'  => $f['date_ops_closed'] ?: ($f['date_started'] ?: $f['date_opened']),
            default       => null,
        } ?: null;
    }

    /** Turnaround step whose target applies to a file in a given state. */
    public const STATE_STEP = ['draft' => 'file_start', 'in_progress' => 'file_ops_close', 'ops_closed' => 'file_bill'];
}
