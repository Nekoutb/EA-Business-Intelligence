<?php
declare(strict_types=1);

namespace App\Services\Tax;

use App\Models\PartnerDonationCategory;
use App\Models\Tenant;
use App\Odoo\OdooClient;

/**
 * Donations & libéralités — deductibility test (Article 7 CGI).
 *
 * Account scope corrected 2026-05 — was wrongly using 6713 / 6588 / 638
 * (currency-loss + misc-charge buckets that don't represent donations).
 * SYSCOHADA AUDCIF 2017 codes for donations:
 *
 *   • 6582XX — Dons (donations made by the firm)            ← expense
 *   • 6583XX — Mécenat (corporate sponsorship/patronage)    ← expense
 *   • 845XX  — Dons et libéralités obtenus (received)       ← HAO income
 *
 * Methodology
 * ───────────
 *   • Donations made (deductibility test): SUM of debit balances on
 *     6582XX + 6583XX for posted lines in YTD. These are the lines that
 *     enter the Article 7 CGI deductibility test (0.5 % × CA_net ceiling).
 *
 *   • Donations received (HAO income tracking): SUM of credit balances
 *     on 845XX for posted lines in YTD. Surfaced as a separate analytical
 *     view — received donations are taxable under HAO unless specifically
 *     exempted, and the existing P&L picks them up via class 8 income.
 *     No deductibility test applies to received donations.
 *
 *   • Beneficiary resolution: each line on 6582/6583 is grouped by
 *     partner_id; the resolved category (1/2/3) is read from the local
 *     partner_donation_categories table (per-tenant, cached after
 *     resolution). NIU and country come from res.partner.
 *
 *   • Turnover net of taxes (CA_net_YTD), SYSCOHADA-anchored:
 *         CA_net = |Σ balance(70 except 709)| − |Σ balance(709)|
 *     (sales magnitude minus rebates magnitude, in YTD).
 *
 *   • Ceiling = 0.5 % × CA_net_YTD.
 *
 *   • Category rules (refined 2026-04-28):
 *       Cat 1 — fully deducted, no exposure (uncapped).
 *       Cat 2 + Cat 3 + Unresolved — pooled and tested COLLECTIVELY
 *               against the ceiling. If the combined non-Cat-1 spend is
 *               at or below the ceiling, nothing is reintegrated — the
 *               actual disbursement is within the deductibility cap so
 *               there is no exposure. Above the ceiling, only the EXCESS
 *               is reintegrated, distributed pro-rata across Cat 2 / 3 /
 *               Unresolved by their share of the non-Cat-1 pool.
 *       Non-resident Cat 3 / Unresolved beneficiaries with a non-zero
 *               reintegrated portion bear an additional 15 % TSR
 *               (Article 225 CGI) on that reintegrated amount.
 *       Unresolved beneficiaries are surfaced in red so the user
 *               documents their category, but are NOT conservatively
 *               reintegrated when total spend is below the ceiling.
 *
 *   • Stacked exposure on the reintegrated portion:
 *       33 % statutory income tax (Article 17 CGI)
 *     + 16.5 % IRCM on revenus réputés distribués (Articles 26, 91 CGI)
 *     = 49.5 %.
 *     Plus 15 % TSR (Article 225 CGI) on Cat 3 amounts to non-residents.
 *
 *   • Article references: 7, 17, 26, 91, 225 CGI; SYSCOHADA AUDCIF 2017.
 */
final class DonationsComplianceService
{
    /** Donations made (expense) — Article 7 CGI deductibility test. */
    private const DONATION_ACCOUNT_PREFIXES = ['6582', '6583'];

    /** Donations received (HAO income) — taxable revenue, separate view. */
    private const RECEIVED_DONATION_PREFIXES = ['845'];

    public const DEDUCTION_CEILING_PCT  = 0.5;       // 0.5% of CA_net_YTD
    public const CIT_RATE_PCT           = 33.0;      // 30% IS + 10% CAC
    public const IRCM_RATE_PCT          = 16.5;      // 15% + 10% CAC
    public const STACKED_RATE_PCT       = 49.5;
    public const TSR_NON_RESIDENT_PCT   = 15.0;      // Article 225 CGI

    public function __construct(
        private OdooClient $odoo,
        private Tenant $tenant,
    ) {}

    public function analyze(?\DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0, 0);
        $jan1  = new \DateTimeImmutable($today->format('Y') . '-01-01');

        // 1. Resolve donation account ids (6582 + 6583 — Dons + Mécenat).
        $donAccountIds = $this->accountIdsByPrefix(self::DONATION_ACCOUNT_PREFIXES);
        $receivedIds   = $this->accountIdsByPrefix(self::RECEIVED_DONATION_PREFIXES);

        // The deductibility test (the body of analyze() below) requires
        // expense accounts. If neither 6582 nor 6583 exist on the chart,
        // we still want to surface received donations if 845 has data.
        // emptyResult() now carries the received_donations payload too,
        // built fresh below so a chart-of-accounts gap on the expense
        // side doesn't suppress the received-donations card.
        if ($donAccountIds === []) {
            $empty = $this->emptyResult($jan1, $today);
            $empty['donations']['received'] = $this->probeReceivedDonations($receivedIds, $jan1, $today);
            return $empty;
        }

        // 2. Fetch line-level donation lines in YTD.
        $rawLines = $this->odoo->searchRead('account.move.line', [
            ['account_id', 'in', $donAccountIds],
            ['date', '>=', $jan1->format('Y-m-d')],
            ['date', '<=', $today->format('Y-m-d')],
            ['parent_state', '=', 'posted'],
        ], ['date', 'debit', 'credit', 'balance', 'partner_id', 'journal_id', 'account_id', 'move_id', 'name'],
            ['order' => 'date desc']);

        // 3. Group by partner_id; collect partner ids + line metadata for export.
        $byPartner = [];   // [partner_id => ['name' => ?, 'total' => 0, 'lines' => [...]]]
        $unrecordedTotal = 0.0;
        $unrecordedLines = [];

        foreach ($rawLines as $l) {
            // Donation expense → debit-positive on 6*. Use balance which is
            // (debit - credit) so positive = outflow. Some bookkeepers post
            // negative balances (e.g. cancellation); we use the magnitude.
            $amount = (float) ($l['balance'] ?? ((float)($l['debit'] ?? 0) - (float)($l['credit'] ?? 0)));
            if ($amount === 0.0) continue;

            $partner = $l['partner_id'] ?? null;
            $journal = (is_array($l['journal_id'] ?? null)) ? (string) $l['journal_id'][1] : '—';
            $account = (is_array($l['account_id'] ?? null)) ? (string) $l['account_id'][1] : '—';
            $moveId  = (is_array($l['move_id']    ?? null)) ? (int)    $l['move_id'][0]    : 0;

            $line = [
                'date'      => (string) ($l['date'] ?? ''),
                'amount'    => round($amount, 2),
                'narration' => (string) ($l['name'] ?? ''),
                'journal'   => $journal,
                'account'   => $account,
                'move_id'   => $moveId,
            ];

            if (is_array($partner) && isset($partner[0])) {
                $pid = (int) $partner[0];
                if (!isset($byPartner[$pid])) {
                    $byPartner[$pid] = [
                        'partner_id' => $pid,
                        'name'       => (string) ($partner[1] ?? ''),
                        'total'      => 0.0,
                        'lines'      => [],
                    ];
                }
                $byPartner[$pid]['total'] += $amount;
                $byPartner[$pid]['lines'][] = $line;
            } else {
                $unrecordedTotal += $amount;
                $unrecordedLines[] = $line;
            }
        }

        // 4. Hydrate partner detail (NIU, country) from Odoo for the partners we collected.
        $partnerIds = array_keys($byPartner);
        $partnerInfo = [];
        if ($partnerIds !== []) {
            $rows = $this->odoo->searchRead('res.partner',
                [['id', 'in', $partnerIds]],
                ['id', 'name', 'vat', 'country_id'],
                []
            );
            foreach ($rows as $r) {
                $partnerInfo[(int) $r['id']] = [
                    'niu'       => (string) ($r['vat'] ?? ''),
                    'country'   => (is_array($r['country_id'] ?? null)) ? (string) $r['country_id'][1] : '',
                    'is_foreign'=> (is_array($r['country_id'] ?? null))
                                    && strcasecmp((string)($r['country_id'][1] ?? ''), 'Cameroon') !== 0
                                    && (string)($r['country_id'][1] ?? '') !== '',
                ];
            }
        }

        // 5. Merge with local PartnerDonationCategory rows for category + non-resident flag.
        $localRows = PartnerDonationCategory::where('tenant_id', $this->tenant->id)
            ->whereIn('odoo_partner_id', $partnerIds === [] ? [0] : $partnerIds)
            ->get()
            ->keyBy('odoo_partner_id');

        $beneficiaries = [];
        foreach ($byPartner as $pid => $bp) {
            $local  = $localRows->get($pid);
            $info   = $partnerInfo[$pid] ?? [];
            $cat    = $local?->category;

            $beneficiaries[] = [
                'partner_id'              => $pid,
                'name'                    => $bp['name'],
                'niu'                     => $local?->partner_niu ?: ($info['niu'] ?? ''),
                'country'                 => $info['country'] ?? '',
                'is_non_resident'         => (bool) ($local?->is_non_resident ?? false)
                                            || (bool) ($info['is_foreign'] ?? false),
                'category'                => $cat,                       // null / 1 / 2 / 3
                'category_label'          => $local?->categoryLabel() ?? 'Unresolved',
                'supporting_doc_type'     => $local?->supporting_doc_type,
                'supporting_doc_reference'=> $local?->supporting_doc_reference,
                'has_supporting_doc'      => !empty($local?->supporting_doc_path),
                'total'                   => round($bp['total'], 2),
                'line_count'              => count($bp['lines']),
                'lines'                   => $bp['lines'],   // for export
            ];
        }
        // Add an "unrecorded" pseudo-row if there are donations with no partner.
        if ($unrecordedTotal !== 0.0) {
            $beneficiaries[] = [
                'partner_id'              => 0,
                'name'                    => '— Unrecorded counterparty —',
                'niu'                     => '',
                'country'                 => '',
                'is_non_resident'         => false,
                'category'                => null,
                'category_label'          => 'Unresolved',
                'supporting_doc_type'     => null,
                'supporting_doc_reference'=> null,
                'has_supporting_doc'      => false,
                'total'                   => round($unrecordedTotal, 2),
                'line_count'              => count($unrecordedLines),
                'lines'                   => $unrecordedLines,
            ];
        }
        // Sort by total desc.
        usort($beneficiaries, fn ($a, $b) => $b['total'] <=> $a['total']);

        // 6. Compute CA_net_YTD (SYSCOHADA: |Σ 70 ex 709| − |Σ 709|).
        // Pull class-70 accounts once, partition into 70-ex-709 vs 709 in PHP,
        // then run 2 server-aggregated read_group calls. Total = 3 Odoo calls,
        // not 6.
        [$accIds70ExcludingRebates, $accIds709] = $this->partitionClass70Accounts();
        $sum70    = $this->sumBalanceWindow($accIds70ExcludingRebates, $jan1, $today);
        $sum709   = $this->sumBalanceWindow($accIds709, $jan1, $today);
        $caNet    = abs($sum70) - abs($sum709);     // sales magnitude − rebates magnitude
        if ($caNet < 0) $caNet = 0;                 // never negative for a healthy ledger
        $ceiling   = round(self::DEDUCTION_CEILING_PCT / 100.0 * $caNet, 2);

        // 7. Apply category rules.
        $totalDonations = array_sum(array_column($beneficiaries, 'total'));
        $cat1Total = 0.0; $cat2Total = 0.0; $cat3Total = 0.0; $unresolvedTotal = 0.0;
        foreach ($beneficiaries as $b) {
            $a = (float) $b['total'];
            switch ((int) ($b['category'] ?? 0)) {
                case 1:  $cat1Total += $a; break;
                case 2:  $cat2Total += $a; break;
                case 3:  $cat3Total += $a; break;
                default: $unresolvedTotal += $a; break;     // null/0
            }
        }

        // ── Apply ceiling collectively to the non-Cat-1 pool ─────────────
        // Methodology refinement (2026-04-28):
        //   The 0.5% × CA_net ceiling is treated as a materiality threshold
        //   over the WHOLE non-Cat-1 spend, not just over Cat 2. If the
        //   firm's combined Cat 2 + Cat 3 + Unresolved spending is ≤ ceiling,
        //   nothing is reintegrated — the actual disbursement falls below
        //   the deductibility cap so there is no exposure.
        //
        //   Above the ceiling, the *excess* is reintegrated and apportioned
        //   pro-rata across the contributing categories by share. Cat 1
        //   amounts are always exempt regardless of ceiling.
        $cat1Allowed     = $cat1Total;                      // always deductible
        $nonCat1Total    = $cat2Total + $cat3Total + $unresolvedTotal;
        $excessOverCap   = max(0.0, $nonCat1Total - $ceiling);

        // Pro-rata distribution of the excess back onto each non-Cat-1 bucket.
        $shareCat2  = $nonCat1Total > 0 ? $cat2Total      / $nonCat1Total : 0.0;
        $shareCat3  = $nonCat1Total > 0 ? $cat3Total      / $nonCat1Total : 0.0;
        $shareUnres = $nonCat1Total > 0 ? $unresolvedTotal / $nonCat1Total : 0.0;

        $cat2Reintegrated  = round($excessOverCap * $shareCat2,  2);
        $cat3Reintegrated  = round($excessOverCap * $shareCat3,  2);
        $unresReintegrated = round($excessOverCap * $shareUnres, 2);
        $cat2Allowed       = $cat2Total      - $cat2Reintegrated;
        $cat3Allowed       = $cat3Total      - $cat3Reintegrated;
        $unresAllowed      = $unresolvedTotal - $unresReintegrated;

        $totalDeductible   = $cat1Allowed + $cat2Allowed + $cat3Allowed + $unresAllowed;
        $totalReintegrated = $cat2Reintegrated + $cat3Reintegrated + $unresReintegrated;

        // Per-beneficiary apportionment: each beneficiary's reintegrated
        // share is the same proportion of its category's total as the
        // category-level reintegration ratio.
        $reintRatioByCat = [
            1 => 0.0,
            2 => $cat2Total      > 0 ? $cat2Reintegrated  / $cat2Total      : 0.0,
            3 => $cat3Total      > 0 ? $cat3Reintegrated  / $cat3Total      : 0.0,
            0 => $unresolvedTotal > 0 ? $unresReintegrated / $unresolvedTotal : 0.0,
        ];
        foreach ($beneficiaries as &$b) {
            $a   = (float) $b['total'];
            $cat = (int) ($b['category'] ?? 0);
            $deductible = $reintegrated = 0.0; $tsr = 0.0;

            $ratio        = $reintRatioByCat[$cat] ?? $reintRatioByCat[0];
            $reintegrated = round($a * $ratio, 2);
            $deductible   = round($a - $reintegrated, 2);

            // 15% TSR (Article 225 CGI) applies on the reintegrated portion
            // for Cat 3 / Unresolved beneficiaries flagged as non-resident.
            if ($reintegrated > 0 && ($cat === 3 || $cat === 0) && $b['is_non_resident']) {
                $tsr = round($reintegrated * (self::TSR_NON_RESIDENT_PCT / 100.0), 2);
            }

            $citWht = round($reintegrated * (self::STACKED_RATE_PCT / 100.0), 2);
            $b['deductible']     = $deductible;
            $b['reintegrated']   = $reintegrated;
            $b['exposure_stack'] = $citWht;
            $b['exposure_tsr']   = $tsr;
            $b['exposure_total'] = round($citWht + $tsr, 2);
        }
        unset($b);

        $totalExposureStack = round($totalReintegrated * (self::STACKED_RATE_PCT / 100.0), 2);
        $totalTsr           = 0.0;
        foreach ($beneficiaries as $b) {
            $totalTsr += (float) ($b['exposure_tsr'] ?? 0);
        }
        $totalExposure = round($totalExposureStack + $totalTsr, 2);

        return [
            'currency'       => $this->detectCurrency(),
            'period'         => [
                'from' => $jan1->format('Y-m-d'),
                'to'   => $today->format('Y-m-d'),
                'year' => (int) $today->format('Y'),
            ],
            'donations'      => [
                'beneficiaries'         => $beneficiaries,
                'total_donations'       => round($totalDonations, 2),
                'cat1_total'            => round($cat1Total, 2),
                'cat2_total'            => round($cat2Total, 2),
                'cat3_total'            => round($cat3Total, 2),
                'unresolved_total'      => round($unresolvedTotal, 2),

                'ca_net_ytd'            => round($caNet, 2),
                'ceiling_pct'           => self::DEDUCTION_CEILING_PCT,
                'ceiling_xaf'           => $ceiling,

                'cat2_allowed'          => round($cat2Allowed, 2),
                'cat2_reintegrated'     => round($cat2Reintegrated, 2),
                'cat3_reintegrated'     => round($cat3Reintegrated, 2),

                'total_deductible'      => round($totalDeductible, 2),
                'total_reintegrated'    => round($totalReintegrated, 2),

                'cit_rate_pct'          => self::CIT_RATE_PCT,
                'ircm_rate_pct'         => self::IRCM_RATE_PCT,
                'stacked_rate_pct'      => self::STACKED_RATE_PCT,
                'tsr_rate_pct'          => self::TSR_NON_RESIDENT_PCT,

                'exposure_stacked'      => $totalExposureStack,
                'exposure_tsr'          => round($totalTsr, 2),
                'exposure_total'        => $totalExposure,

                // Received donations (845XX) — separate analytical view.
                // Surfaces who gave what; no deductibility test applies
                // (received donations are HAO income, taxable as revenue
                // unless specifically exempted). The view renders this
                // beneath the deductibility-test card.
                'received'              => $this->probeReceivedDonations($receivedIds, $jan1, $today),
            ],
            'as_of'          => $today->format('Y-m-d'),
        ];
    }

    /**
     * Donations received probe — sum credit balances on 845XX in YTD,
     * grouped by partner. Returns:
     *   {
     *     account_count: int,             // how many 845XX accounts found
     *     total_received: float,          // total credit-positive balance
     *     by_partner: [
     *       { partner: string|null, niu: string|null, country: string|null,
     *         total: float, line_count: int },
     *       ...
     *     ],
     *   }
     * Best-effort: any Odoo failure returns a zeroed structure.
     */
    private function probeReceivedDonations(
        array $accountIds,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): array {
        $empty = [
            'account_count' => count($accountIds),
            'total_received' => 0.0,
            'by_partner'    => [],
        ];
        if ($accountIds === []) return $empty;

        try {
            // Pull line-level so we can group by partner. Same domain
            // pattern as the donation-expense probe but the sign is
            // flipped — received donations are CREDIT balances on 845XX,
            // so the magnitude lives on the credit column.
            $lines = $this->odoo->searchRead('account.move.line', [
                ['account_id', 'in', $accountIds],
                ['date', '>=', $from->format('Y-m-d')],
                ['date', '<=', $to->format('Y-m-d')],
                ['parent_state', '=', 'posted'],
            ], ['date', 'debit', 'credit', 'balance', 'partner_id', 'name'], []);
        } catch (\Throwable $e) {
            return $empty;
        }

        $byPartner = []; // partner_id => ['name', 'niu', 'country', 'total', 'lines']
        $unrecorded = ['partner' => null, 'niu' => null, 'country' => null, 'total' => 0.0, 'line_count' => 0];
        $total = 0.0;

        foreach ($lines as $l) {
            // Class 8 received donations sit credit-positive. Use
            // -balance so a credit-positive entry yields a positive
            // "received" magnitude.
            $bal    = (float) ($l['balance'] ?? ((float)($l['debit'] ?? 0) - (float)($l['credit'] ?? 0)));
            $amount = -$bal;
            if ($amount === 0.0) continue;

            $total += $amount;

            $partnerInfo = $l['partner_id'] ?? null;
            if (is_array($partnerInfo) && !empty($partnerInfo[0])) {
                $pid = (int) $partnerInfo[0];
                $name = (string) ($partnerInfo[1] ?? '');
                if (!isset($byPartner[$pid])) {
                    $byPartner[$pid] = [
                        'partner'    => $name ?: ('Partner #' . $pid),
                        'niu'        => null,
                        'country'    => null,
                        'total'      => 0.0,
                        'line_count' => 0,
                    ];
                }
                $byPartner[$pid]['total']      += $amount;
                $byPartner[$pid]['line_count']++;
            } else {
                $unrecorded['total']      += $amount;
                $unrecorded['line_count']++;
            }
        }

        // Resolve NIU + country in one batch (best-effort).
        if (!empty($byPartner)) {
            try {
                $partnerRows = $this->odoo->searchRead(
                    'res.partner',
                    [['id', 'in', array_keys($byPartner)]],
                    ['id', 'vat', 'country_id'],
                    []
                );
                foreach ($partnerRows as $p) {
                    $pid = (int) ($p['id'] ?? 0);
                    if (!isset($byPartner[$pid])) continue;
                    $byPartner[$pid]['niu']     = !empty($p['vat']) ? (string) $p['vat'] : null;
                    $byPartner[$pid]['country'] = (is_array($p['country_id'] ?? null) && !empty($p['country_id'][1]))
                        ? (string) $p['country_id'][1] : null;
                }
            } catch (\Throwable $e) { /* leave NIU + country null */ }
        }

        // Sort partners by total received DESC; append "unrecorded" if any.
        $rows = array_values($byPartner);
        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);
        if ($unrecorded['line_count'] > 0) {
            $unrecorded['partner'] = '— Unrecorded —';
            $unrecorded['total']   = round($unrecorded['total'], 2);
            $rows[] = $unrecorded;
        }
        $rows = array_map(fn ($r) => array_merge($r, ['total' => round($r['total'], 2)]), $rows);

        return [
            'account_count'  => count($accountIds),
            'total_received' => round($total, 2),
            'by_partner'     => $rows,
        ];
    }

    /**
     * Pull all class-70 accounts in a single Odoo call and partition into
     * (70-excluding-709, 709) buckets in PHP. Returns [accIds70ExRebates, accIds709].
     */
    private function partitionClass70Accounts(): array
    {
        $rows = $this->odoo->searchRead('account.account',
            [['code', '=like', '70%']],
            ['id', 'code'], []
        );
        $a70 = []; $a709 = [];
        foreach ($rows as $r) {
            $code = (string) ($r['code'] ?? '');
            if ($code === '') continue;
            if (str_starts_with($code, '709')) {
                $a709[] = (int) $r['id'];
            } else {
                $a70[]  = (int) $r['id'];
            }
        }
        return [$a70, $a709];
    }

    /**
     * Σ posted balance on `accountIds` for lines in [from, to]. Single
     * server-aggregated read_group call.
     */
    private function sumBalanceWindow(array $accountIds, \DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        if ($accountIds === []) return 0.0;
        $agg = $this->odoo->callModel('account.move.line', 'read_group', [
            'domain' => [
                ['account_id', 'in', $accountIds],
                ['date', '>=', $from->format('Y-m-d')],
                ['date', '<=', $to->format('Y-m-d')],
                ['parent_state', '=', 'posted'],
            ],
            'fields'  => ['balance:sum'],
            'groupby' => [],
            'lazy'    => false,
        ]);
        return (float) ($agg[0]['balance'] ?? 0);
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
            'currency' => $this->detectCurrency(),
            'period'   => [
                'from' => $from->format('Y-m-d'),
                'to'   => $to->format('Y-m-d'),
                'year' => (int) $to->format('Y'),
            ],
            'donations' => [
                'beneficiaries'      => [],
                'total_donations'    => 0.0,
                'cat1_total'         => 0.0,
                'cat2_total'         => 0.0,
                'cat3_total'         => 0.0,
                'unresolved_total'   => 0.0,
                'ca_net_ytd'         => 0.0,
                'ceiling_pct'        => self::DEDUCTION_CEILING_PCT,
                'ceiling_xaf'        => 0.0,
                'cat2_allowed'       => 0.0,
                'cat2_reintegrated'  => 0.0,
                'cat3_reintegrated'  => 0.0,
                'total_deductible'   => 0.0,
                'total_reintegrated' => 0.0,
                'cit_rate_pct'       => self::CIT_RATE_PCT,
                'ircm_rate_pct'      => self::IRCM_RATE_PCT,
                'stacked_rate_pct'   => self::STACKED_RATE_PCT,
                'tsr_rate_pct'       => self::TSR_NON_RESIDENT_PCT,
                'exposure_stacked'   => 0.0,
                'exposure_tsr'       => 0.0,
                'exposure_total'     => 0.0,
                // received-donations stub — caller may override with a
                // real probeReceivedDonations() result so a
                // chart-of-accounts gap on the expense side doesn't
                // suppress the received-donations card.
                'received'           => [
                    'account_count'  => 0,
                    'total_received' => 0.0,
                    'by_partner'     => [],
                ],
            ],
            'as_of'    => $to->format('Y-m-d'),
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

    /**
     * Best-effort write-back to res.partner.x_donation_category_cm.
     * Returns null on success, error message string on failure.
     */
    public function syncCategoryToOdoo(int $partnerId, ?int $category): ?string
    {
        try {
            $value = $category === null ? false : ('cat_' . $category);
            $this->odoo->callModel('res.partner', 'write', [
                'args' => [[$partnerId], ['x_donation_category_cm' => $value]],
            ]);
            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }
}
