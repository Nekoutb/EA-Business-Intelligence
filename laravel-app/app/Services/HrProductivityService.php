<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * HR › Productivity — monthly, from Odoo (read-only).
 *
 *   Employee cost      = Σ class 66 (charges de personnel) + Σ 625 (assurances
 *                        — medical insurance), posted, by accounting month.
 *   Employees          = employee profiles (hr.employee, active) existing at
 *                        the month end (create_date ≤ month end).
 *   Files              = clearance files created in the month (logistics.file
 *                        create_date; Teese-imported files excluded).
 *   Cost per employee  = month's employee cost ÷ employees at month end.
 *   Cost per file      = month's employee cost ÷ files created that month.
 *   Files per dept     = distinct files a department ACTED ON in the month:
 *                        any chatter / tracking entry authored by one of its
 *                        users on the file or on one of its disbursements.
 *                        Department = clearance role group of the author
 *                        (DEPARTMENTS below); a user holding roles in two
 *                        departments counts for both. The built-in
 *                        Administrator account is not a department member.
 */
final class HrProductivityService
{
    /** Department => role-group name patterns (res.groups name, case-insensitive). */
    public const DEPARTMENTS = [
        'Customer service' => ['Head of Customer Service', 'Customer Service Agent'],
        'Operations'       => ['Head of Service Operations', 'Head of Operations', 'Operations Agent'],
        'Finance'          => ['Head of Service Finance', 'Head of Finance', 'Finance Agent'],
        'Transit'          => ['Head of Service Transit', 'Head of Transit', 'Transit Agent'],
        'Logistics'        => ['Head of Service Logistics', 'Head of Logistics', 'Logistics Agent'],
    ];

    public function __construct(private OdooClient $odoo) {}

    public function analyze(int $year, ?\DateTimeImmutable $today = null): array
    {
        $today = $today ?? new \DateTimeImmutable('today');
        $lastM = (int) $year < (int) $today->format('Y') ? 12 : (int) $today->format('n');
        $from  = sprintf('%04d-01-01', $year);
        $to    = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $lastM)))->format('Y-m-t');
        $months = [];
        for ($m = 1; $m <= $lastM; $m++) $months[sprintf('%04d-%02d', $year, $m)] = date('M', mktime(0, 0, 0, $m, 1));
        $notes = [];

        // 1. Employee cost by month: 66 + 625.
        $cost66 = $this->monthlyBalance('66%', $from, $to);
        $cost625 = $this->monthlyBalance('625%', $from, $to);

        // 2. Employees existing at each month end.
        $empDates = [];
        foreach ($this->odoo->searchRead('hr.employee', [], ['create_date'], ['limit' => 5000]) as $e) {
            $empDates[] = substr((string) $e['create_date'], 0, 10);
        }

        // 3. Files created per month (clearance only).
        $filesM = array_fill_keys(array_keys($months), 0);
        foreach ($this->odoo->searchRead('logistics.file', [['create_date', '>=', $from . ' 00:00:00'], ['create_date', '<=', $to . ' 23:59:59']],
                     ['create_date', 'state'], ['limit' => 20000]) as $f) {
            if (($f['state'] ?? '') === 'imported') continue;
            $ym = substr((string) $f['create_date'], 0, 7);
            if (isset($filesM[$ym])) $filesM[$ym]++;
        }

        // 4. Department membership from the clearance role groups.
        $deptUsers = []; $roles = [];
        // Odoo's built-in superuser (1) and Administrator (2) are system
        // accounts that hold every role — not department members.
        $adminUids = [1 => true, 2 => true];
        foreach ($this->odoo->searchRead('res.users', [['login', '=', 'admin']], ['id'], []) as $u) $adminUids[(int) $u['id']] = true;
        $groups = $this->odoo->searchRead('res.groups', [['name', '!=', false]], ['name', 'user_ids'], ['limit' => 2000]);
        foreach (self::DEPARTMENTS as $dept => $patterns) {
            $deptUsers[$dept] = []; $roles[$dept] = [];
            foreach ($groups as $g) {
                foreach ($patterns as $pat) {
                    if (strcasecmp(trim((string) $g['name']), $pat) !== 0) continue;
                    $roles[$dept][] = (string) $g['name'];
                    foreach ((array) ($g['user_ids'] ?? []) as $uid) if (!isset($adminUids[(int) $uid])) $deptUsers[$dept][(int) $uid] = true;
                }
            }
            if (!$roles[$dept]) $notes[] = "No {$dept} roles are defined in the clearance application yet.";
        }
        // partner → user, for message authors.
        $allUids = [];
        foreach ($deptUsers as $set) $allUids += $set;
        $partnerToUser = [];
        if ($allUids) {
            foreach ($this->odoo->searchRead('res.users', [['id', 'in', array_keys($allUids)]], ['partner_id', 'name'], []) as $u) {
                if (is_array($u['partner_id'] ?? null)) $partnerToUser[(int) $u['partner_id'][0]] = (int) $u['id'];
            }
        }

        // 5. Actions: messages on files and on their disbursements.
        $expFile = [];
        foreach ($this->odoo->searchRead('logistics.expense', [], ['file_id'], ['limit' => 50000]) as $x) {
            if (is_array($x['file_id'] ?? null)) $expFile[(int) $x['id']] = (int) $x['file_id'][0];
        }
        $touched = [];   // dept => ym => [file => true]
        foreach (['logistics.file', 'logistics.expense'] as $model) {
            $offset = 0;
            do {
                $batch = $this->odoo->searchRead('mail.message', [['model', '=', $model], ['date', '>=', $from . ' 00:00:00'], ['date', '<=', $to . ' 23:59:59']],
                    ['res_id', 'author_id', 'date'], ['offset' => $offset, 'limit' => 5000, 'order' => 'id asc']);
                foreach ($batch as $msg) {
                    $pid = is_array($msg['author_id'] ?? null) ? (int) $msg['author_id'][0] : 0;
                    $uid = $partnerToUser[$pid] ?? null;
                    if (!$uid) continue;
                    $file = $model === 'logistics.file' ? (int) $msg['res_id'] : ($expFile[(int) $msg['res_id']] ?? 0);
                    if (!$file) continue;
                    $ym = substr((string) $msg['date'], 0, 7);
                    foreach ($deptUsers as $dept => $set) if (isset($set[$uid])) $touched[$dept][$ym][$file] = true;
                }
                $offset += 5000;
            } while (count($batch) === 5000);
        }

        // Manual monthly figures (Productivity settings) replace the system
        // counts for the months they cover.
        $ovFiles = ProductivityOverrides::files($year);
        $ovEmp   = ProductivityOverrides::employees($year);
        $natures = ProductivityOverrides::naturesByMonth($year);
        foreach ($ovFiles as $ym => $n) if (isset($filesM[$ym])) $filesM[$ym] = $n;
        if ($ovFiles || $ovEmp) $notes[] = 'Months marked ✎ use manual figures from Productivity settings.';

        // ── Assemble monthly series ──
        $rows = [];
        foreach ($months as $ym => $label) {
            $end = (new \DateTimeImmutable($ym . '-01'))->format('Y-m-t');
            $emp = $ovEmp[$ym] ?? count(array_filter($empDates, fn ($d) => $d !== '' && $d <= $end));
            $cost = ($cost66[$ym] ?? 0.0) + ($cost625[$ym] ?? 0.0);
            $rows[$ym] = [
                'label' => $label, 'cost_66' => $cost66[$ym] ?? 0.0, 'cost_625' => $cost625[$ym] ?? 0.0, 'cost' => $cost,
                'employees' => $emp, 'files' => $filesM[$ym],
                'manual_employees' => isset($ovEmp[$ym]), 'manual_files' => isset($ovFiles[$ym]),
                'natures' => $natures[$ym] ?? [],
                // A month whose payroll is not booked yet has no ratio (not 0).
                'per_employee' => $emp > 0 && abs($cost) > 0.5 ? $cost / $emp : null,
                'per_file' => $filesM[$ym] > 0 && abs($cost) > 0.5 ? $cost / $filesM[$ym] : null,
            ];
        }
        $dept = [];
        foreach (array_keys(self::DEPARTMENTS) as $d) {
            $monthly = []; $year_ = [];
            foreach ($months as $ym => $l) {
                $set = $touched[$d][$ym] ?? [];
                $monthly[$ym] = count($set);
                $year_ += $set;
            }
            $dept[] = ['department' => $d, 'roles' => $roles[$d], 'members' => count($deptUsers[$d]),
                       'monthly' => $monthly, 'ytd' => count($year_)];
        }

        $totCost = array_sum(array_column($rows, 'cost'));
        $totFiles = array_sum($filesM);
        // Year-to-date per file only over months carrying both cost and files,
        // so costs of months without files are not spread over later files.
        $pfCost = 0.0; $pfFiles = 0;
        foreach ($rows as $r) if ($r['per_file'] !== null) { $pfCost += $r['cost']; $pfFiles += $r['files']; }
        if ($totFiles > 0 && $pfFiles === 0) $notes[] = 'Employee cost and clearance files do not overlap yet: payroll is booked for months with no files, and months with files have no payroll posted yet.';
        $empNow = count($empDates);
        $last = end($rows) ?: [];
        if (count($empDates) && min($empDates) > $from) {
            $notes[] = 'Employee profiles were created on ' . min($empDates) . '; earlier months have no headcount, so cost per employee starts from then.';
        }

        return [
            'year' => $year, 'months' => $months, 'rows' => $rows, 'departments' => $dept,
            'nature_names' => array_values(array_unique(array_merge(ProductivityOverrides::natures(), ...array_map('array_keys', array_values($natures) ?: [[]])))),
            'kpis' => [
                'cost_ytd' => $totCost, 'cost_66_ytd' => array_sum(array_column($rows, 'cost_66')), 'cost_625_ytd' => array_sum(array_column($rows, 'cost_625')),
                'employees' => ($ovEmp && ($lastEmp = end($rows)['employees'] ?? null) !== null) ? $lastEmp : $empNow, 'files_ytd' => $totFiles,
                // YTD averages: cost per employee per month on average headcount; cost per file over the year.
                'per_employee_month' => $this->avg(array_column($rows, 'per_employee')),
                'per_file_ytd' => $pfFiles > 0 ? $pfCost / $pfFiles : null,
                'per_file_basis' => ['cost' => $pfCost, 'files' => $pfFiles],
                'last_month' => $last,
            ],
            'notes' => $notes,
        ];
    }

    private function avg(array $vals): ?float
    {
        $v = array_values(array_filter($vals, fn ($x) => $x !== null));
        return $v ? array_sum($v) / count($v) : null;
    }

    /** @return array<string,float> 'Y-m' => Σ balance (debit − credit) */
    private function monthlyBalance(string $like, string $from, string $to): array
    {
        $ids = array_map(fn ($a) => (int) $a['id'], $this->odoo->searchRead('account.account', [['code', '=like', $like]], ['id'], []));
        if (!$ids) return [];
        $out = [];
        foreach ((array) $this->odoo->callModel('account.move.line', 'read_group', [
            'domain'  => [['parent_state', '=', 'posted'], ['account_id', 'in', $ids], ['date', '>=', $from], ['date', '<=', $to]],
            'fields'  => ['balance:sum'], 'groupby' => ['date:month'], 'lazy' => false,
        ]) as $g) {
            $f = $g['__range']['date:month']['from'] ?? $g['__range']['date']['from'] ?? null;
            if (is_string($f)) $out[substr($f, 0, 7)] = ($out[substr($f, 0, 7)] ?? 0.0) + (float) ($g['balance'] ?? 0);
        }
        return $out;
    }
}
