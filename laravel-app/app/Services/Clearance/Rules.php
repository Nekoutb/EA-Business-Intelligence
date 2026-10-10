<?php

namespace App\Services\Clearance;

/**
 * The pack's business rules (R-a … R-p) as pure functions over plain
 * Odoo row arrays — no I/O, so each rule is unit-tested on fixed samples
 * (tests/Unit/ClearanceRulesTest.php).
 */
class Rules
{
    public const TZ = 'Africa/Douala';

    // ── R-a  file liveness; legacy/imported exclusion ──────────────────
    public const LIVE_STATES = ['draft', 'in_progress', 'ops_closed'];

    public static function isLiveFile(array $f): bool
    {
        return in_array($f['state'] ?? '', self::LIVE_STATES, true);
    }

    public static function isClosedFile(array $f): bool
    {
        return ($f['state'] ?? '') === 'done';
    }

    /** Files that count in figures: not imported (Teese history), not cancelled. */
    public static function isCountedFile(array $f): bool
    {
        return !in_array($f['state'] ?? '', ['imported', 'cancel'], true);
    }

    // ── R-b  a bill stands ──────────────────────────────────────────────
    public static function billStands(array $move): bool
    {
        return ($move['state'] ?? '') === 'posted' && empty($move['clearance_voided']) && empty($move['is_legacy']);
    }

    /**
     * Ids of moves that count as revenue documents: standing, and not the
     * mirror reversal of a voided invoice.
     * @param array<int,array> $moves keyed by id
     */
    public static function countedMoveIds(array $moves): array
    {
        $voided = [];
        foreach ($moves as $m) {
            if (($m['state'] ?? '') === 'posted' && !empty($m['clearance_voided'])) $voided[(int) $m['id']] = true;
        }
        $ids = [];
        foreach ($moves as $m) {
            if (!self::billStands($m)) continue;
            $rev = ClearanceData::m2oId($m['reversed_entry_id'] ?? false);
            if ($rev && isset($voided[$rev])) continue; // reversal of a voided invoice
            $ids[(int) $m['id']] = true;
        }
        return $ids;
    }

    // ── R-c / R-d  fee income and recharge adjustment ──────────────────
    public const SERVICE_KINDS = ['commission', 'customs_fee', 'file_fee', 'other'];

    /**
     * Fees HT by service kind + adjustment, from invoice lines.
     * out_invoice adds, out_refund subtracts; only counted moves.
     * @return array{commission:float,customs_fee:float,file_fee:float,other:float,fees:float,adjustment:float,undercharge:float,overcharge:float,net:float}
     */
    public static function fees(array $lines, array $movesById, array $countedIds): array
    {
        $o = array_fill_keys(self::SERVICE_KINDS, 0.0) + ['adjustment' => 0.0, 'undercharge' => 0.0, 'overcharge' => 0.0];
        foreach ($lines as $l) {
            $mid = ClearanceData::m2oId($l['move_id'] ?? false);
            if (!$mid || !isset($countedIds[$mid])) continue;
            $type = $movesById[$mid]['move_type'] ?? '';
            $sign = $type === 'out_invoice' ? 1 : ($type === 'out_refund' ? -1 : 0);
            if ($sign === 0) continue;
            $amt = $sign * (float) ($l['price_subtotal'] ?? 0);
            $cat = $l['clearance_category'] ?? false;
            if ($cat === 'prestation') {
                $k = in_array($l['clearance_service_kind'] ?? '', self::SERVICE_KINDS, true) ? $l['clearance_service_kind'] : 'other';
                $o[$k] += $amt;
            } elseif ($cat === 'adjustment') {
                $o['adjustment'] += $amt;
                if ($amt < 0) $o['undercharge'] += $amt; else $o['overcharge'] += $amt;
            }
        }
        $o['fees'] = $o['commission'] + $o['customs_fee'] + $o['file_fee'] + $o['other'];
        $o['net']  = $o['fees'] + $o['adjustment'];
        return $o;
    }

    // ── R-f  unbilled disbursements ────────────────────────────────────
    public static function isUnbilledDisbursement(array $e): bool
    {
        return in_array($e['state'] ?? '', ['settled', 'justified'], true)
            && empty($e['is_billed']) && empty($e['is_legacy']);
    }

    // ── R-g  unjustified advances ──────────────────────────────────────
    public const OPEN_ADVANCE_STATES = ['settled', 'justification_submitted', 'justification_ops_approved'];

    public static function isOpenAdvance(array $e): bool
    {
        return ($e['payment_mode'] ?? '') === 'advance'
            && in_array($e['state'] ?? '', self::OPEN_ADVANCE_STATES, true)
            && empty($e['is_legacy']);
    }

    /** Non-justifiable category → never waits for justification; listed apart. */
    public static function isNonJustifiable(array $e, array $categoriesById): bool
    {
        $cid = ClearanceData::m2oId($e['category_id'] ?? false);
        return ($categoriesById[$cid]['justification'] ?? '') === 'non_justifiable';
    }

    // ── R-h  waived advance still owed ─────────────────────────────────
    public static function isAdvanceWaived(array $f): bool
    {
        return ($f['advance_waiver_state'] ?? '') === 'approved';
    }

    // ── R-i / R-m  reconciliation ──────────────────────────────────────
    /** @return array{report:float,ledger:float,difference:float,ok:bool} */
    public static function reconcile(float $reportTotal, float $ledgerBalance): array
    {
        $diff = round($reportTotal - $ledgerBalance);
        return ['report' => round($reportTotal), 'ledger' => round($ledgerBalance), 'difference' => $diff, 'ok' => $diff == 0.0];
    }

    /** Balance of posted lines on one account (debit − credit). */
    public static function accountBalance(array $lines, ?int $accountId, ?callable $filter = null): float
    {
        if (!$accountId) return 0.0;
        $b = 0.0;
        foreach ($lines as $l) {
            if (ClearanceData::m2oId($l['account_id'] ?? false) !== $accountId) continue;
            if (($l['parent_state'] ?? 'posted') !== 'posted') continue;
            if ($filter && !$filter($l)) continue;
            $b += (float) ($l['balance'] ?? ((float) ($l['debit'] ?? 0) - (float) ($l['credit'] ?? 0)));
        }
        return $b;
    }

    // ── R-j  robust statistics ─────────────────────────────────────────
    public static function percentile(array $values, float $p): ?float
    {
        $v = array_values(array_filter($values, fn ($x) => $x !== null && $x !== false));
        if (!$v) return null;
        sort($v);
        $idx = ($p / 100) * (count($v) - 1);
        $lo = (int) floor($idx); $hi = (int) ceil($idx);
        return (float) ($v[$lo] + ($v[$hi] - $v[$lo]) * ($idx - $lo));
    }

    public static function median(array $values): ?float
    {
        return self::percentile($values, 50);
    }

    public static function pct(float $part, float $whole): ?float
    {
        return $whole > 0 ? round(100 * $part / $whole, 1) : null;
    }

    // ── R-k  period + dates in Africa/Douala ───────────────────────────
    /** Odoo Date ('Y-m-d') or Datetime (UTC 'Y-m-d H:i:s') → local 'Y-m-d', or null. */
    public static function localDate(mixed $v): ?string
    {
        if (!$v || !is_string($v)) return null;
        if (strlen($v) <= 10) return $v;
        return (new \DateTimeImmutable($v, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone(self::TZ))->format('Y-m-d');
    }

    public static function inPeriod(mixed $v, string $from, string $to): bool
    {
        $d = self::localDate($v);
        return $d !== null && $d >= $from && $d <= $to;
    }

    /** Whole days from a date to $asOf (local). */
    public static function ageDays(mixed $v, string $asOf): ?int
    {
        $d = self::localDate($v);
        if (!$d) return null;
        return (int) (new \DateTimeImmutable($d))->diff(new \DateTimeImmutable($asOf))->format('%r%a');
    }

    /** Working days (Mon–Fri) between a date and $asOf. */
    public static function workingDays(mixed $v, string $asOf): ?int
    {
        $d = self::localDate($v);
        if (!$d) return null;
        $n = 0;
        for ($t = new \DateTimeImmutable($d); $t < new \DateTimeImmutable($asOf); $t = $t->modify('+1 day')) {
            if ((int) $t->format('N') <= 5) $n++;
        }
        return $n;
    }

    public static function bucket(?int $days, array $edges): string
    {
        // $edges e.g. [15 => '0–15', 30 => '16–30', 60 => '31–60', PHP_INT_MAX => '>60']
        foreach ($edges as $max => $label) if ($days !== null && $days <= $max) return $label;
        return (string) end($edges);
    }

    // ── R-n  VAT ───────────────────────────────────────────────────────
    public static function commissionRate(array $file, array $serviceTypesById): ?float
    {
        $r = $file['billing_commission_rate'] ?? false;
        if ($r !== false && $r !== null && (float) $r > 0) return (float) $r;
        $sid = ClearanceData::m2oId($file['service_type_id'] ?? false);
        $r = $serviceTypesById[$sid]['commission_rate'] ?? null;
        return $r !== null && $r !== false ? (float) $r : null;
    }

    // ── R-p  refusals from chatter ─────────────────────────────────────
    public static function stripHtml(string $html): string
    {
        $t = preg_replace('/<\s*br\s*\/?>|<\/p>/i', "\n", $html);
        $t = html_entity_decode(strip_tags((string) $t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/[ \t]+/', ' ', $t));
    }

    /** @return array{kind:string,reason:string,has_reason:bool}|null null when not a refusal message */
    public static function parseRefusal(string $html): ?array
    {
        $text = self::stripHtml($html);
        $pos = stripos($text, 'Reason:');
        if ($pos === false) return null;
        $before = trim(substr($text, 0, $pos));
        // The kind is the last sentence before "Reason:".
        $sentences = preg_split('/(?<=[.!?])\s+|\n+/', $before, -1, PREG_SPLIT_NO_EMPTY);
        $kind = trim((string) end($sentences), " .\t\n");
        $reason = trim(substr($text, $pos + strlen('Reason:')));
        return ['kind' => $kind !== '' ? $kind : '(unlabelled)', 'reason' => $reason, 'has_reason' => $reason !== ''];
    }

    // ── formatting ─────────────────────────────────────────────────────
    public static function xaf(float|int|null $n): string
    {
        if ($n === null) return '—';
        $s = number_format(abs(round((float) $n)), 0, '', ' ');
        return ($n < 0 && round((float) $n) != 0 ? '−' : '') . $s;
    }

    public static function fdate(mixed $v): string
    {
        $d = self::localDate($v);
        return $d ? (new \DateTimeImmutable($d))->format('d/m/Y') : '—';
    }

    public static function fpct(?float $p): string
    {
        return $p === null ? '—' : number_format($p, 1) . ' %';
    }

    /** One table cell, by column format (text · int · xaf · pct · date · days · hours). */
    public static function cell(mixed $v, string $fmt): string
    {
        if ($v === null || $v === false || $v === '') return '—';
        return match ($fmt) {
            'xaf'   => self::xaf((float) $v),
            'int'   => number_format((int) $v, 0, '', ' '),
            'pct'   => self::fpct((float) $v),
            'date'  => self::fdate($v),
            'days'  => number_format((int) $v, 0, '', ' '),
            'hours' => number_format((float) $v, 1, '.', ' '),
            default => (string) $v,
        };
    }
}
