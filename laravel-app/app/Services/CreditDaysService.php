<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * DSO and DPO on a trailing three-month window, from the general ledger.
 *
 *   DSO = class 41 balance today ÷ credit sales of the last 90 days × 90
 *         credit sales = class 70 credited in entries that debit a class 41 account
 *   DPO = class 40 balance today ÷ credit purchases of the last 90 days × 90
 *         credit purchases = class 6 debited in entries that credit a 401 account
 *
 * Balances are taken from the start of the books to the as-of date. Credit
 * notes inside the window are netted. Switched on per client with the tenant
 * setting DSO_DPO_METHOD = credit90; every other client keeps the legacy
 * calculation.
 */
final class CreditDaysService
{
    public const WINDOW_DAYS = 90;

    private GLAccountService $gl;

    public function __construct(OdooClient $odoo)
    {
        $this->gl = new GLAccountService($odoo);
    }

    public static function enabled(): bool
    {
        return strtolower(trim((string) config_env('DSO_DPO_METHOD', 'legacy'))) === 'credit90';
    }

    /** @return array{days:?float,numerator:?float,denominator:?float,window_from:string,window_to:string,window_days:int}|null */
    public function dso(\DateTimeImmutable $asOf): ?array
    {
        $from = $asOf->modify('-' . (self::WINDOW_DAYS - 1) . ' days');
        $ar = $this->gl->balanceByPrefix('41', null, $asOf);
        $sales = $this->gl->counterpartMovement('70', '41', $from, $asOf, true);
        if ($ar === null || $sales === null) return null;
        return $this->pack($ar, $sales, $from, $asOf);
    }

    /** Same shape as dso(); AP is shown as a positive (credit) balance. */
    public function dpo(\DateTimeImmutable $asOf): ?array
    {
        $from = $asOf->modify('-' . (self::WINDOW_DAYS - 1) . ' days');
        $ap = $this->gl->creditByPrefix('40', null, $asOf);
        $purchases = $this->gl->counterpartMovement('6', '401', $from, $asOf, false);
        if ($ap === null || $purchases === null) return null;
        return $this->pack($ap, $purchases, $from, $asOf);
    }

    /**
     * Month-end series for the year (current month: as of today).
     * @return array{dso: array<int,array{label:string,dso:?float}>, dpo: array<int,array{label:string,dpo:?float}>}
     */
    public function monthlySeries(?int $year = null, ?\DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0, 0);
        $year = $year ?? (int) $today->format('Y');
        $last = $year < (int) $today->format('Y') ? 12 : (int) $today->format('n');
        $out = ['dso' => [], 'dpo' => []];
        for ($m = 1; $m <= $last; $m++) {
            $end = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m)))->modify('last day of this month');
            if ($end > $today) $end = $today;
            $label = $end->format('M');
            $s = $this->dso($end);
            $p = $this->dpo($end);
            $out['dso'][] = ['label' => $label, 'dso' => $s && $s['days'] !== null ? round($s['days'], 1) : null];
            $out['dpo'][] = ['label' => $label, 'dpo' => $p && $p['days'] !== null ? round($p['days'], 1) : null];
        }
        return $out;
    }

    private function pack(float $balance, float $flow, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return [
            'days'        => ($flow > 0 && $balance > 0) ? $balance / $flow * self::WINDOW_DAYS : null,
            'numerator'   => $balance,
            'denominator' => $flow,
            'window_from' => $from->format('Y-m-d'),
            'window_to'   => $to->format('Y-m-d'),
            'window_days' => self::WINDOW_DAYS,
        ];
    }
}
