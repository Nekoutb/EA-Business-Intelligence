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

    /**
     * Per-journal aggregation (rework 2026-05).
     *
     * Old logic flagged each cash-credit LINE > threshold and ignored
     * the offsetting debit account. Two gaps that miss real cases:
     *
     *   a) Split payments — 5 cash lines of 100K on the same move are
     *      a single 500K cash payment in substance. Per-line filter
     *      missed them entirely (each line at threshold, none above).
     *   b) Cash-to-cash transfers (57 → 57) and 57-to-transit (57 →
     *      47/48) movements were flagged identically to real expense
     *      payments, even though only the latter trigger CGI 7-A.
     *
     * New logic walks the journal-entry level instead:
     *   1. Find every move that has at least one line crediting a
     *      57XX account in YTD.
     *   2. Pull ALL lines of those candidate moves in one round-trip.
     *   3. Per move: SUM the 57-credits → cash_paid. Inspect debit
     *      lines for any account starting with 6 / 1 / 2 / 3 / 4 →
     *      qualifies the move.
     *   4. Filter cash_paid > LF threshold (100K XAF).
     *   5. Risk = 49.5% × cash_paid (per move, not per line).
     *
     * The XLSX export (controller) walks moves[] and emits a
     * JE-header row plus every debit and credit line of each
     * qualifying move so a tax inspector can read the whole journal
     * without cross-referencing back to Odoo.
     */
    public function analyze(?\DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0, 0);
        $jan1  = new \DateTimeImmutable($today->format('Y') . '-01-01');

        $cashIds = $this->accountIdsByPrefix(self::CASH_ON_HAND_PREFIXES);
        if ($cashIds === []) {
            return $this->emptyResult($jan1, $today);
        }

        // Step 1 — find candidate moves (any line crediting 57XX in YTD).
        $cashCreditLines = $this->odoo->searchRead('account.move.line', [
            ['account_id', 'in', $cashIds],
            ['credit', '>', 0],
            ['date', '>=', $jan1->format('Y-m-d')],
            ['date', '<=', $today->format('Y-m-d')],
            ['parent_state', '=', 'posted'],
        ], ['move_id'], []);

        $candidateMoveIds = [];
        foreach ($cashCreditLines as $l) {
            if (is_array($l['move_id'] ?? null)) {
                $candidateMoveIds[(int) $l['move_id'][0]] = true;
            }
        }
        $candidateMoveIds = array_keys($candidateMoveIds);
        if (empty($candidateMoveIds)) {
            return $this->emptyResult($jan1, $today);
        }

        // Step 2 — pull every line of every candidate move.
        $allLines = $this->odoo->searchRead('account.move.line', [
            ['move_id', 'in', $candidateMoveIds],
            ['parent_state', '=', 'posted'],
        ], ['date', 'debit', 'credit', 'partner_id', 'journal_id', 'account_id', 'move_id', 'name'],
            ['order' => 'move_id, id']);

        // Resolve every account_id touched → code + name. One round-trip.
        $accountInfo = [];
        $accountIdsTouched = [];
        foreach ($allLines as $l) {
            if (is_array($l['account_id'] ?? null)) $accountIdsTouched[(int) $l['account_id'][0]] = true;
        }
        if (!empty($accountIdsTouched)) {
            $accs = $this->odoo->searchRead('account.account',
                [['id', 'in', array_keys($accountIdsTouched)]],
                ['id', 'code', 'name'], []);
            foreach ($accs as $a) {
                $accountInfo[(int) $a['id']] = [
                    'code' => (string) ($a['code'] ?? ''),
                    'name' => (string) ($a['name'] ?? ''),
                ];
            }
        }

        // Group lines by move.
        $linesByMove = [];
        foreach ($allLines as $l) {
            if (!is_array($l['move_id'] ?? null)) continue;
            $linesByMove[(int) $l['move_id'][0]][] = $l;
        }

        // Step 3+4 — per move: sum 57-credits, check debit-side filter,
        // apply threshold. Build the moves[] payload as we go.
        $movesData = [];
        $qualifyingMoveIds = [];
        foreach ($linesByMove as $mid => $lines) {
            $cashPaid = 0.0;
            $hasQualifyingDebit = false;
            $moveDate = '';
            $moveName = '';
            $moveJournal = '';
            $partner = null;
            $debitAccounts  = [];   // code => label, dedup
            $creditAccounts = [];   // code => label, dedup
            $linesOut = [];

            foreach ($lines as $l) {
                $aid     = is_array($l['account_id'] ?? null) ? (int) $l['account_id'][0] : 0;
                $code    = $accountInfo[$aid]['code'] ?? '';
                $name    = $accountInfo[$aid]['name'] ?? '';
                $debit   = (float) ($l['debit'] ?? 0);
                $credit  = (float) ($l['credit'] ?? 0);
                $linePartner = is_array($l['partner_id'] ?? null) ? (string) $l['partner_id'][1] : null;
                $accLabel = $code . ($name ? ' — ' . $name : '');

                // Move metadata pulled from any line (all lines of a
                // move share these). First non-empty wins.
                if ($moveDate    === '' && !empty($l['date']))                      $moveDate    = (string) $l['date'];
                if ($moveName    === '' && is_array($l['move_id']    ?? null))      $moveName    = (string) $l['move_id'][1];
                if ($moveJournal === '' && is_array($l['journal_id'] ?? null))      $moveJournal = (string) $l['journal_id'][1];
                if ($partner     === null && $linePartner !== null)                 $partner     = $linePartner;

                // Cash credit on a 57XX → contributes to cash_paid.
                if ($credit > 0 && str_starts_with($code, '57')) {
                    $cashPaid += $credit;
                }

                // Qualifying-debit check: 6XX (charges) OR 1XX/2XX/3XX/4XX.
                if ($debit > 0) {
                    $first = substr($code, 0, 1);
                    if (in_array($first, ['6', '1', '2', '3', '4'], true)) {
                        $hasQualifyingDebit = true;
                    }
                }

                $linesOut[] = [
                    'side'          => $debit > 0 ? 'D' : ($credit > 0 ? 'C' : '—'),
                    'account_code'  => $code,
                    'account_name'  => $name,
                    'account_label' => $accLabel,
                    'description'   => (string) ($l['name'] ?? ''),
                    'partner'       => $linePartner,
                    'debit'         => round($debit, 2),
                    'credit'        => round($credit, 2),
                ];
                if ($debit  > 0 && $code !== '') $debitAccounts[$code]  = $accLabel;
                if ($credit > 0 && $code !== '') $creditAccounts[$code] = $accLabel;
            }

            if (!$hasQualifyingDebit) continue;
            if ($cashPaid <= self::HIGH_VALUE_THRESHOLD_XAF) continue;

            $qualifyingMoveIds[] = $mid;
            $totalRate = (self::CIT_RATE_PCT + self::IRCM_RATE_PCT) / 100.0;
            $movesData[] = [
                'move_id'         => $mid,
                'move_name'       => $moveName,
                'date'            => $moveDate,
                'journal'         => $moveJournal,
                'partner'         => $partner,
                'cash_paid'       => round($cashPaid, 2),
                'risk_amount'     => round($cashPaid * $totalRate, 2),
                'debit_accounts'  => array_values($debitAccounts),
                'credit_accounts' => array_values($creditAccounts),
                'lines'           => $linesOut,
            ];
        }

        // Sort moves by cash_paid descending so heaviest exposure rises.
        usort($movesData, fn ($a, $b) => $b['cash_paid'] <=> $a['cash_paid']);

        // Resolve missing-attachment flag for the qualifying moves.
        $movesWithAttachment = [];
        if (!empty($qualifyingMoveIds)) {
            $atts = $this->odoo->searchRead('ir.attachment', [
                ['res_model', '=', 'account.move'],
                ['res_id', 'in', $qualifyingMoveIds],
            ], ['res_id'], []);
            foreach ($atts as $a) {
                if (isset($a['res_id'])) $movesWithAttachment[(int) $a['res_id']] = true;
            }
        }
        foreach ($movesData as $idx => $m) {
            $movesData[$idx]['missing_supporting'] = !isset($movesWithAttachment[$m['move_id']]);
        }

        // Headline aggregates.
        $totalAmount = 0.0;
        $missingDocCount = 0;
        foreach ($movesData as $m) {
            $totalAmount += (float) $m['cash_paid'];
            if (!empty($m['missing_supporting'])) $missingDocCount++;
        }

        // Tax exposure (33 % CIT + 16.5 % IRCM = 49.5 %).
        $citRate     = self::CIT_RATE_PCT  / 100.0;
        $ircmRate    = self::IRCM_RATE_PCT / 100.0;
        $totalRate   = $citRate + $ircmRate;
        $citImpact   = round($totalAmount * $citRate,   2);
        $ircmImpact  = round($totalAmount * $ircmRate,  2);
        $totalImpact = round($totalAmount * $totalRate, 2);

        // Legacy back-compat: emit each 57-credit line of qualifying
        // moves as a flat transactions[]. Older consumers (AI panel,
        // existing methodology footer copy, any external API) still
        // iterate this. Same data, just denormalised.
        $transactions = [];
        foreach ($movesData as $m) {
            foreach ($m['lines'] as $l) {
                if ($l['credit'] > 0 && str_starts_with($l['account_code'] ?? '', '57')) {
                    $transactions[] = [
                        'date'               => $m['date'],
                        'amount'             => $l['credit'],
                        'partner'            => $m['partner'],
                        'narration'          => $l['description'],
                        'journal'            => $m['journal'],
                        'account'            => $l['account_label'],
                        'missing_supporting' => !empty($m['missing_supporting']),
                        'move_id'            => $m['move_id'],
                    ];
                }
            }
        }

        // Groups for display compatibility (partner + date aggregation).
        $groups = [];
        foreach ($movesData as $m) {
            $key = ($m['partner'] ?? '__UNRECORDED__') . '|' . $m['date'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'partner'         => $m['partner'],
                    'date'            => $m['date'],
                    'count'           => 0,
                    'total'           => 0.0,
                    'any_missing_doc' => false,
                ];
            }
            $groups[$key]['count']++;
            $groups[$key]['total']           += (float) $m['cash_paid'];
            $groups[$key]['any_missing_doc']  = $groups[$key]['any_missing_doc'] || !empty($m['missing_supporting']);
        }
        usort($groups, fn ($a, $b) => $b['total'] <=> $a['total']);
        $groups = array_map(fn ($g) => array_merge($g, ['total' => round($g['total'], 2)]), $groups);

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
                // count = number of qualifying journal entries (was: lines).
                // Reflects the rework: one row per move, not per cash split.
                'count'                => count($movesData),
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

                // moves[] — NEW · per-journal aggregation, drives the
                // XLSX export and any future journal-card UI.
                'moves'                => $movesData,
                // transactions[] — legacy flat list of cash-credit lines
                // on qualifying moves. Kept for back-compat with the
                // AI panel + external API consumers.
                'transactions'         => $transactions,
                'groups'               => $groups,
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
                'moves'                => [],
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
