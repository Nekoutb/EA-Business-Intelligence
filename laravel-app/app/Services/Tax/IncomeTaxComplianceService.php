<?php
declare(strict_types=1);

namespace App\Services\Tax;

use App\Odoo\OdooClient;

/**
 * Income Tax Compliance — surfaces transactions whose tax treatment
 * creates exposure under Cameroon CGI / OHADA rules.
 *
 * High-value cash payments (current rule)
 * ────────────────────────────────────────
 *   Posted cash payments — i.e. credits to a 57* (cash on hand) account
 *   in the YTD window — whose individual line credit exceeds the
 *   XAF 100,000 threshold.
 *
 *   Such payments are non-deductible for corporate income tax purposes.
 *   The exposure is assessed at 49.5% of the disallowed amount:
 *     - 33.0% : Income tax (Impôt sur les Sociétés)
 *     - 16.5% : Withholding tax on deemed dividend distribution
 *
 * Methodology
 * ───────────
 *   - Account scope: any account where `code` starts with `57`.
 *   - Window: YTD (1 Jan to anchor date inclusive).
 *   - Posted only (`parent_state = posted`).
 *   - Threshold filter: `credit > 100,000`.
 *   - Per-line aggregation; we surface the count and total credit value.
 */
final class IncomeTaxComplianceService
{
    /** Account-code prefix considered "cash on hand" under OHADA. */
    private const CASH_ON_HAND_PREFIXES = ['57'];

    /** Disallowance threshold in XAF; lines below this are not flagged. */
    public const HIGH_VALUE_THRESHOLD_XAF = 100_000.0;

    /** Tax exposure rate: 33% CIT + 16.5% WHT on dividends = 49.5%. */
    public const CIT_RATE_PCT      = 33.0;
    public const WHT_DIVIDEND_PCT  = 16.5;

    public function __construct(private OdooClient $odoo) {}

    public function analyze(?\DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0, 0);
        $jan1  = new \DateTimeImmutable($today->format('Y') . '-01-01');

        $cashOnHandIds = $this->accountIdsByPrefix(self::CASH_ON_HAND_PREFIXES);
        if ($cashOnHandIds === []) {
            return $this->emptyResult($jan1, $today);
        }

        // Server-aggregated count + sum of high-value cash credits in YTD.
        $rows = $this->odoo->callModel('account.move.line', 'read_group', [
            'domain' => [
                ['account_id', 'in', $cashOnHandIds],
                ['credit', '>', self::HIGH_VALUE_THRESHOLD_XAF],
                ['date', '>=', $jan1->format('Y-m-d')],
                ['date', '<=', $today->format('Y-m-d')],
                ['parent_state', '=', 'posted'],
            ],
            'fields'  => ['credit:sum'],
            'groupby' => [],
            'lazy'    => false,
        ]);

        $count       = (int)   ($rows[0]['__count'] ?? 0);
        $totalAmount = (float) ($rows[0]['credit']  ?? 0);

        // Tax exposure split.
        $citRate     = self::CIT_RATE_PCT     / 100.0;     // 0.33
        $whtRate     = self::WHT_DIVIDEND_PCT / 100.0;     // 0.165
        $totalRate   = $citRate + $whtRate;                // 0.495
        $citImpact   = round($totalAmount * $citRate,   2);
        $whtImpact   = round($totalAmount * $whtRate,   2);
        $totalImpact = round($totalAmount * $totalRate, 2);

        return [
            'currency'             => $this->detectCurrency(),
            'period'               => [
                'from'  => $jan1->format('Y-m-d'),
                'to'    => $today->format('Y-m-d'),
                'year'  => (int) $today->format('Y'),
            ],
            'high_value_cash'      => [
                'threshold_xaf' => self::HIGH_VALUE_THRESHOLD_XAF,
                'count'         => $count,
                'total_amount'  => round($totalAmount, 2),
                'cit_rate_pct'  => self::CIT_RATE_PCT,
                'wht_rate_pct'  => self::WHT_DIVIDEND_PCT,
                'total_rate_pct'=> round(self::CIT_RATE_PCT + self::WHT_DIVIDEND_PCT, 2),
                'cit_impact'    => $citImpact,
                'wht_impact'    => $whtImpact,
                'total_impact'  => $totalImpact,
            ],
            'as_of'                => $today->format('Y-m-d'),
        ];
    }

    private function accountIdsByPrefix(array $prefixes): array
    {
        if ($prefixes === []) return [];
        $domain = [];
        for ($i = 0; $i < count($prefixes) - 1; $i++) $domain[] = '|';
        foreach ($prefixes as $p) $domain[] = ['code', '=like', $p . '%'];

        $rows = $this->odoo->searchRead('account.account', $domain, ['id'], []);
        return array_map(fn ($r) => (int) $r['id'], $rows);
    }

    private function emptyResult(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return [
            'currency'        => $this->detectCurrency(),
            'period'          => [
                'from' => $from->format('Y-m-d'),
                'to'   => $to->format('Y-m-d'),
                'year' => (int) $to->format('Y'),
            ],
            'high_value_cash' => [
                'threshold_xaf'  => self::HIGH_VALUE_THRESHOLD_XAF,
                'count'          => 0,
                'total_amount'   => 0.0,
                'cit_rate_pct'   => self::CIT_RATE_PCT,
                'wht_rate_pct'   => self::WHT_DIVIDEND_PCT,
                'total_rate_pct' => round(self::CIT_RATE_PCT + self::WHT_DIVIDEND_PCT, 2),
                'cit_impact'     => 0.0,
                'wht_impact'     => 0.0,
                'total_impact'   => 0.0,
            ],
            'as_of'           => $to->format('Y-m-d'),
        ];
    }

    private function detectCurrency(): string
    {
        try {
            $companies = $this->odoo->searchRead('res.company', [], ['currency_id'], [], 1);
            if (!empty($companies) && !empty($companies[0]['currency_id'])) {
                return $companies[0]['currency_id'][1] ?? 'XAF';
            }
        } catch (\Throwable $e) { /* fall through */ }
        return 'XAF';
    }
}
