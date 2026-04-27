<?php
declare(strict_types=1);

namespace App\Services\Tax;

use App\Odoo\OdooClient;

/**
 * Income Tax Compliance — high-value cash expenditures (Cameroon CGI).
 *
 * Methodology (per CGI cross-references)
 * ─────────────────────────────────────────
 *   • Article 7-A CGI : cash payments above the LF threshold are NOT
 *                       deductible from taxable income.
 *   • Article 17 CGI  : statutory income tax rate — 30 % IS + 10 % CAC
 *                       (Centimes Additionnels Communaux) = 33 %.
 *   • Article 26 CGI  : doctrine of "revenus réputés distribués"; the
 *                       disallowed amount is treated as a hidden
 *                       distribution.
 *   • Article 91 CGI  : IRCM on dividend distributions — 15 % + 10 % CAC
 *                       = 16.5 %.
 *   • Total stacked exposure : 33 % + 16.5 % = 49.5 %.
 *     The stack assumes the DGI requalifies the disallowed amount in full
 *     as a hidden distribution. If the requalification is not pursued,
 *     only the 33 % income-tax leg applies.
 *
 *   • Cash account scope : SYSCOHADA account 57 — Caisse (cash on hand).
 *                          Excludes class 52 (banques) by design.
 *   • Threshold          : XAF 100,000 per line (cf. LF 2024 / 2025).
 *                          Operationally we filter per move-line credit;
 *                          intra-day splits to the same counterparty
 *                          should be aggregated by reviewers.
 *
 * Output shape
 * ────────────
 *   - high_value_cash.{ count, total_amount, total_impact, ... }
 *   - high_value_cash.transactions[] : line-level detail for the table
 *   - high_value_cash.groups[]       : aggregations by (partner, date)
 *   - high_value_cash.threshold_xaf  : the LF threshold in force
 */
final class IncomeTaxComplianceService
{
    private const CASH_ON_HAND_PREFIXES = ['57'];

    /** LF threshold in XAF — XAF 100,000 per line. */
    public const HIGH_VALUE_THRESHOLD_XAF = 100_000.0;

    /** Reference to the LF version used to anchor the threshold. */
    public const THRESHOLD_LF_REFERENCE  = 'LF 2025 — Article 7-A CGI';

    /** Income tax (Article 17 CGI): 30 % IS + 10 % CAC = 33 %. */
    public const IS_RATE_PCT  = 30.0;
    public const CAC_RATE_PCT = 10.0;
    public const CIT_RATE_PCT = 33.0; // 30 * (1 + 10%)

    /** IRCM on hidden distribution (Articles 26, 91 CGI): 15 % + 10 % CAC = 16.5 %. */
    public const IRCM_BASE_PCT = 15.0;
    public const IRCM_RATE_PCT = 16.5; // 15 * (1 + 10%)

    public function __construct(private OdooClient $odoo) {}

    public function analyze(?\DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0, 0);
        $jan1  = new \DateTimeImmutable($today->format('Y') . '-01-01');

        $cashIds = $this->accountIdsByPrefix(self::CASH_ON_HAND_PREFIXES);
        if ($cashIds === []) {
            return $this->emptyResult($jan1, $today);
        }

        // Pull the line-level qualifying transactions.
        $rawLines = $this->odoo->searchRead('account.move.line', [
            ['account_id', 'in', $cashIds],
            ['credit', '>', self::HIGH_VALUE_THRESHOLD_XAF],
            ['date', '>=', $jan1->format('Y-m-d')],
            ['date', '<=', $today->format('Y-m-d')],
            ['parent_state', '=', 'posted'],
        ], ['date', 'credit', 'partner_id', 'journal_id', 'account_id', 'move_id', 'name'],
            ['order' => 'date desc, credit desc']);

        // Resolve missing-attachment flag in batch: any move whose
        // ir.attachment count == 0 is flagged "no doc on file".
        $moveIds = [];
        foreach ($rawLines as $l) {
            if (isset($l['move_id']) && is_array($l['move_id'])) {
                $moveIds[(int) $l['move_id'][0]] = true;
            }
        }
        $moveIds = array_keys($moveIds);
        $movesWithAttachment = [];
        if ($moveIds !== []) {
            $atts = $this->odoo->searchRead('ir.attachment', [
                ['res_model', '=', 'account.move'],
                ['res_id', 'in', $moveIds],
            ], ['res_id'], []);
            foreach ($atts as $a) {
                if (isset($a['res_id'])) $movesWithAttachment[(int) $a['res_id']] = true;
            }
        }

        // Build line-level transactions list.
        $transactions = [];
        $totalAmount  = 0.0;
        foreach ($rawLines as $l) {
            $credit  = (float) ($l['credit'] ?? 0);
            $totalAmount += $credit;

            $partner = (is_array($l['partner_id'] ?? null)) ? (string) $l['partner_id'][1] : null;
            $journal = (is_array($l['journal_id'] ?? null)) ? (string) $l['journal_id'][1] : '—';
            $account = (is_array($l['account_id'] ?? null)) ? (string) $l['account_id'][1] : '—';
            $moveId  = (is_array($l['move_id']    ?? null)) ? (int)    $l['move_id'][0]    : 0;

            $transactions[] = [
                'date'                => (string) ($l['date'] ?? ''),
                'amount'              => round($credit, 2),
                'partner'             => $partner,                 // null if unrecorded
                'narration'           => (string) ($l['name'] ?? ''),
                'journal'             => $journal,
                'account'             => $account,
                'missing_supporting'  => !isset($movesWithAttachment[$moveId]),
                'move_id'             => $moveId,
            ];
        }

        // Group by (partner_id_or_unknown, date) for display.
        $groups = [];
        foreach ($transactions as $tx) {
            $key = ($tx['partner'] ?? '__UNRECORDED__') . '|' . $tx['date'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'partner'             => $tx['partner'],
                    'date'                => $tx['date'],
                    'count'               => 0,
                    'total'               => 0.0,
                    'any_missing_doc'     => false,
                ];
            }
            $groups[$key]['count']++;
            $groups[$key]['total']           += $tx['amount'];
            $groups[$key]['any_missing_doc']  = $groups[$key]['any_missing_doc'] || $tx['missing_supporting'];
        }
        // Sort groups by total descending so the heaviest exposure rises.
        usort($groups, fn ($a, $b) => $b['total'] <=> $a['total']);
        $groups = array_map(fn ($g) => array_merge($g, ['total' => round($g['total'], 2)]), $groups);

        // Tax exposure split (33 % CIT + 16.5 % IRCM = 49.5 %).
        $citRate     = self::CIT_RATE_PCT  / 100.0;        // 0.33
        $ircmRate    = self::IRCM_RATE_PCT / 100.0;        // 0.165
        $totalRate   = $citRate + $ircmRate;               // 0.495
        $citImpact   = round($totalAmount * $citRate,   2);
        $ircmImpact  = round($totalAmount * $ircmRate,  2);
        $totalImpact = round($totalAmount * $totalRate, 2);

        $missingDocCount = 0;
        foreach ($transactions as $tx) {
            if ($tx['missing_supporting']) $missingDocCount++;
        }

        return [
            'currency'              => $this->detectCurrency(),
            'period'                => [
                'from' => $jan1->format('Y-m-d'),
                'to'   => $today->format('Y-m-d'),
                'year' => (int) $today->format('Y'),
            ],
            'high_value_cash'       => [
                'threshold_xaf'        => self::HIGH_VALUE_THRESHOLD_XAF,
                'threshold_reference'  => self::THRESHOLD_LF_REFERENCE,
                'count'                => count($transactions),
                'group_count'          => count($groups),
                'total_amount'         => round($totalAmount, 2),
                'missing_doc_count'    => $missingDocCount,

                'is_rate_pct'          => self::IS_RATE_PCT,
                'cac_rate_pct'         => self::CAC_RATE_PCT,
                'cit_rate_pct'         => self::CIT_RATE_PCT,
                'ircm_base_pct'        => self::IRCM_BASE_PCT,
                'ircm_rate_pct'        => self::IRCM_RATE_PCT,
                'total_rate_pct'       => round(self::CIT_RATE_PCT + self::IRCM_RATE_PCT, 2),

                'cit_impact'           => $citImpact,
                'ircm_impact'          => $ircmImpact,
                'total_impact'         => $totalImpact,

                'transactions'         => $transactions,    // line-level
                'groups'               => $groups,          // aggregated by partner+date
            ],
            'as_of'                 => $today->format('Y-m-d'),
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
                'threshold_xaf'        => self::HIGH_VALUE_THRESHOLD_XAF,
                'threshold_reference'  => self::THRESHOLD_LF_REFERENCE,
                'count'                => 0,
                'group_count'          => 0,
                'total_amount'         => 0.0,
                'missing_doc_count'    => 0,
                'is_rate_pct'          => self::IS_RATE_PCT,
                'cac_rate_pct'         => self::CAC_RATE_PCT,
                'cit_rate_pct'         => self::CIT_RATE_PCT,
                'ircm_base_pct'        => self::IRCM_BASE_PCT,
                'ircm_rate_pct'        => self::IRCM_RATE_PCT,
                'total_rate_pct'       => round(self::CIT_RATE_PCT + self::IRCM_RATE_PCT, 2),
                'cit_impact'           => 0.0,
                'ircm_impact'          => 0.0,
                'total_impact'         => 0.0,
                'transactions'         => [],
                'groups'               => [],
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
