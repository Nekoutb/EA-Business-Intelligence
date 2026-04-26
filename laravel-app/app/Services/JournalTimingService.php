<?php

namespace App\Services;

use App\Odoo\OdooClient;
use DateTimeImmutable;

/**
 * JournalTimingService — two timing-quality controls on every posted GL
 * line, YTD scope. Each control reports the number of flagged lines and
 * exposes them through the same CSV-download mechanism as the other GL
 * Quality audits.
 *
 *  1. Late posting — (create_date − effective_date) > 30 days
 *     Captures entries recorded long after the period they relate to,
 *     e.g. a December 2025 transaction first booked in February 2026.
 *
 *  2. Weekend / holiday posting — create_date is Saturday, Sunday, or
 *     one of: 1 Jan (New Year), 1 May (Labour Day), 20 May (Cameroon
 *     National Day), 25 Dec (Christmas).
 *
 * Both controls run on the LINE's create_date / date pair (which mirrors
 * the parent move's values for normal operations) so the existing line
 * fetch is enough — no extra round-trip on account.move.
 */
class JournalTimingService
{
    private const FETCH_LIMIT  = 100000;
    private const REPORT_LIMIT = 5000;
    private const DELAY_DAYS   = 30;

    /** Cameroon / OHADA fixed public holidays (MM-DD). */
    private const HOLIDAYS = ['01-01', '05-01', '05-20', '12-25'];

    private ?int $companyId = null;

    public function __construct(private OdooClient $odoo) {}

    public function analyze(DateTimeImmutable $today): array
    {
        $errors = [];
        $from = $today->modify('first day of January')->setTime(0, 0, 0);
        $to   = $today->setTime(23, 59, 59);

        $lines = $this->safe(fn() => $this->fetchAndNormalize($from, $to), $errors, 'lines') ?? [];

        $checks = [
            $this->check1LatePosting($lines),
            $this->check2WeekendOrHoliday($lines),
        ];

        $passed = count(array_filter($checks, fn($c) => $c['passed']));
        $total  = count($checks);
        $score  = $total > 0 ? round($passed / $total * 100, 1) : 100.0;

        $report = $this->buildReport($lines, $checks);

        foreach ($checks as &$c) {
            unset($c['_flagged_ids']);
        }
        unset($c);

        return [
            'overall_score'  => $score,
            'checks_passed'  => $passed,
            'checks_total'   => $total,
            'lines_reviewed' => count($lines),
            'lines_capped'   => count($lines) >= self::FETCH_LIMIT,
            'fetch_limit'    => self::FETCH_LIMIT,
            'checks'         => $checks,
            'report'         => $report,
            'report_capped'  => count($report) >= self::REPORT_LIMIT,
            'report_limit'   => self::REPORT_LIMIT,
            'error'          => empty($errors) ? null : implode(' | ', $errors),
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Fetch + normalize
    // ─────────────────────────────────────────────────────────────────

    private function fetchAndNormalize(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $companyId = $this->getCompanyId();
        $domain = [
            ['parent_state', '=', 'posted'],
            ['company_id',   '=', $companyId],
            ['date',         '>=', $from->format('Y-m-d')],
            ['date',         '<=', $to->format('Y-m-d')],
        ];

        $rows = $this->odoo->searchRead(
            'account.move.line',
            $domain,
            ['name', 'ref', 'account_id', 'partner_id', 'date', 'create_date', 'debit', 'credit', 'move_id'],
            ['limit' => self::FETCH_LIMIT, 'order' => 'date desc, id desc']
        );
        if (empty($rows)) return [];

        // Resolve account codes (slim — just the code)
        $accountIds = [];
        foreach ($rows as $r) {
            if (is_array($r['account_id'] ?? null) && !empty($r['account_id'][0])) {
                $accountIds[$r['account_id'][0]] = true;
            }
        }
        $codes = [];
        foreach (array_chunk(array_keys($accountIds), 500) as $chunk) {
            foreach ($this->odoo->read('account.account', $chunk, ['code']) as $a) {
                $codes[$a['id']] = (string)($a['code'] ?? '');
            }
        }

        // Resolve move types & names (for the CSV "Move" column)
        $moveIds = [];
        foreach ($rows as $r) {
            if (is_array($r['move_id'] ?? null) && !empty($r['move_id'][0])) {
                $moveIds[$r['move_id'][0]] = true;
            }
        }
        $moveInfo = [];
        if (!empty($moveIds)) {
            foreach (array_chunk(array_keys($moveIds), 500) as $chunk) {
                try {
                    foreach ($this->odoo->read('account.move', $chunk, ['move_type', 'name']) as $m) {
                        $moveInfo[$m['id']] = [
                            'type' => (string)($m['move_type'] ?? ''),
                            'name' => (string)($m['name']      ?? ''),
                        ];
                    }
                } catch (\Throwable $e) { /* keep going */ }
            }
        }

        // Resolve partner names (for the CSV)
        $partnerIds = [];
        foreach ($rows as $r) {
            if (is_array($r['partner_id'] ?? null) && !empty($r['partner_id'][0])) {
                $partnerIds[$r['partner_id'][0]] = true;
            }
        }
        $partners = [];
        if (!empty($partnerIds)) {
            foreach (array_chunk(array_keys($partnerIds), 500) as $chunk) {
                foreach ($this->odoo->read('res.partner', $chunk, ['name']) as $p) {
                    $partners[$p['id']] = (string)($p['name'] ?? '');
                }
            }
        }

        $lines = [];
        foreach ($rows as $r) {
            $accId  = is_array($r['account_id'] ?? null) ? ($r['account_id'][0] ?? null) : null;
            $partId = is_array($r['partner_id'] ?? null) ? ($r['partner_id'][0] ?? null) : null;
            $moveId = is_array($r['move_id']    ?? null) ? ($r['move_id'][0]    ?? null) : null;

            $lines[] = [
                'id'            => (int)($r['id'] ?? 0),
                'name'          => (string)($r['name'] ?? ''),
                'ref'           => (string)($r['ref']  ?? ''),
                'account_code'  => $accId !== null ? ($codes[$accId] ?? '') : '',
                'partner_id'    => $partId,
                'partner_name'  => $partId !== null ? ($partners[$partId] ?? '') : '',
                'date'          => (string)($r['date'] ?? ''),
                'create_date'   => (string)($r['create_date'] ?? ''),
                'amount'        => abs((float)($r['debit'] ?? 0) - (float)($r['credit'] ?? 0)),
                'move_id'       => $moveId,
                'move_type'     => $moveId !== null ? ($moveInfo[$moveId]['type'] ?? '') : '',
                'move_name'     => $moveId !== null ? ($moveInfo[$moveId]['name'] ?? '') : '',
            ];
        }
        return $lines;
    }

    // ─────────────────────────────────────────────────────────────────
    // Checks
    // ─────────────────────────────────────────────────────────────────

    private function check1LatePosting(array $lines): array
    {
        $flagged = [];
        foreach ($lines as $l) {
            $effective = $l['date']        ?? '';
            $recorded  = $l['create_date'] ?? '';
            if ($effective === '' || $recorded === '') continue;

            // create_date is a datetime ('YYYY-MM-DD HH:MM:SS'); date is just the day.
            $eff = strtotime($effective);
            $rec = strtotime(substr($recorded, 0, 10)); // truncate time → midnight UTC
            if ($eff === false || $rec === false) continue;

            $delayDays = ($rec - $eff) / 86400;
            if ($delayDays > self::DELAY_DAYS) {
                $flagged[] = $l['id'];
            }
        }
        return [
            'no'           => '1',
            'name'         => 'Recording delay > ' . self::DELAY_DAYS . ' days',
            'description'  => 'Lines whose system create_date is more than ' . self::DELAY_DAYS . ' days after their accounting (effective) date — e.g. a December transaction first recorded in February.',
            'passed'       => empty($flagged),
            'count'        => count($flagged),
            '_flagged_ids' => $flagged,
        ];
    }

    private function check2WeekendOrHoliday(array $lines): array
    {
        $flagged = [];
        foreach ($lines as $l) {
            $created = $l['create_date'] ?? '';
            if ($created === '') continue;

            try {
                $dt = new DateTimeImmutable(substr($created, 0, 10));
            } catch (\Throwable $e) {
                continue;
            }

            // 6 = Saturday, 7 = Sunday (ISO-8601)
            $dow = (int)$dt->format('N');
            if ($dow >= 6) {
                $flagged[] = $l['id'];
                continue;
            }
            // Public holiday match by MM-DD
            $md = $dt->format('m-d');
            if (in_array($md, self::HOLIDAYS, true)) {
                $flagged[] = $l['id'];
            }
        }
        return [
            'no'           => '2',
            'name'         => 'Recorded on weekend or public holiday',
            'description'  => 'Lines whose create_date falls on a Saturday, Sunday, or a fixed Cameroon/OHADA public holiday (1 Jan New Year, 1 May Labour Day, 20 May National Day, 25 Dec Christmas).',
            'passed'       => empty($flagged),
            'count'        => count($flagged),
            '_flagged_ids' => $flagged,
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Report
    // ─────────────────────────────────────────────────────────────────

    private function buildReport(array $lines, array $allChecks): array
    {
        $byId = [];
        foreach ($lines as $l) $byId[(int)$l['id']] = $l;

        $flagged = [];
        foreach ($allChecks as $chk) {
            $label = $chk['no'] . '. ' . $chk['name'];
            foreach (($chk['_flagged_ids'] ?? []) as $id) {
                if (!isset($flagged[$id])) {
                    $flagged[$id] = [
                        'line'          => $byId[$id] ?? null,
                        'failed_checks' => [],
                    ];
                }
                $flagged[$id]['failed_checks'][] = $label;
            }
        }
        $list = array_values($flagged);
        usort($list, fn($a, $b) => strcmp(($b['line']['date'] ?? ''), ($a['line']['date'] ?? '')));
        return array_slice($list, 0, self::REPORT_LIMIT);
    }

    // ─────────────────────────────────────────────────────────────────
    // Plumbing
    // ─────────────────────────────────────────────────────────────────

    private function getCompanyId(): int
    {
        if ($this->companyId !== null) return $this->companyId;
        try {
            $uid  = $this->odoo->authenticate();
            $user = $this->odoo->read('res.users', [$uid], ['company_id']);
            $cid  = $user[0]['company_id'] ?? null;
            $this->companyId = is_array($cid) ? (int)($cid[0] ?? 1) : 1;
        } catch (\Throwable $e) {
            $this->companyId = 1;
        }
        return $this->companyId;
    }

    private function safe(callable $fn, array &$errors, string $tag)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            $errors[] = "{$tag}: " . $e->getMessage();
            return null;
        }
    }
}
