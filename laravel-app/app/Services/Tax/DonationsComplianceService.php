<?php
declare(strict_types=1);

namespace App\Services\Tax;

use App\Models\LineDonationCategory;
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
 *   • Line-level category resolution (refined 2026-05-05):
 *
 *       Each posted line on 6582/6583 carries an effective category
 *       resolved by this precedence:
 *
 *         line override (line_donation_categories.category)
 *           ──→ if absent ──→  partner default
 *                              (partner_donation_categories.category)
 *           ──→ if absent ──→  Cat 3 fallback (conservative)
 *
 *       Line override = auditor's professional judgement on a specific
 *       posting. Partner default = same auditor's judgement on the
 *       beneficiary in general. Cat 3 fallback = "non-deductible until
 *       proven otherwise" — a line with no explicit categorisation
 *       still enters the deductibility test, but flagged in the UI so
 *       the auditor knows what's outstanding.
 *
 *       Categorisation is "complete" when no line is resolving via the
 *       Cat 3 fallback (i.e., every line has either a line override or
 *       a partner default). Surfaced via the categorisation_complete
 *       flag and cat3_fallback_total/count metadata.
 *
 *   • Beneficiary roll-up: each line is grouped by partner_id for the
 *     summary table; deductible/reintegrated/exposure are summed from
 *     line-level resolution (so a beneficiary with mixed line
 *     overrides shows the correct math). NIU + country come from
 *     res.partner.
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

        // 2. Fetch line-level donation lines in YTD. Carry `id` on the
        // payload so we can match line-level category overrides further
        // down (step 6.5).
        $rawLines = $this->odoo->searchRead('account.move.line', [
            ['account_id', 'in', $donAccountIds],
            ['date', '>=', $jan1->format('Y-m-d')],
            ['date', '<=', $today->format('Y-m-d')],
            ['parent_state', '=', 'posted'],
        ], ['id', 'date', 'debit', 'credit', 'balance', 'partner_id', 'journal_id', 'account_id', 'move_id', 'name'],
            ['order' => 'date desc']);

        // 3. Group by partner_id (back-compat with the partner roll-up
        // table) AND build a flat $flatLines[] keyed by line id for the
        // line-level deductibility resolution further down.
        $byPartner = [];   // [partner_id => ['name' => ?, 'total' => 0, 'lines' => [...]]]
        $unrecordedTotal = 0.0;
        $unrecordedLines = [];
        $flatLines = [];   // canonical line-level list — drives the new line table + totals

        foreach ($rawLines as $l) {
            // Donation expense → debit-positive on 6*. Use balance which is
            // (debit - credit) so positive = outflow. Some bookkeepers post
            // negative balances (e.g. cancellation); we use the magnitude.
            $amount = (float) ($l['balance'] ?? ((float)($l['debit'] ?? 0) - (float)($l['credit'] ?? 0)));
            if ($amount === 0.0) continue;

            $lineId  = (int)    ($l['id'] ?? 0);
            $partner = $l['partner_id'] ?? null;
            $journal = (is_array($l['journal_id'] ?? null)) ? (string) $l['journal_id'][1] : '—';
            $account = (is_array($l['account_id'] ?? null)) ? (string) $l['account_id'][1] : '—';
            // account_id from Odoo is [id, "code label"] — extract the code prefix
            // for storage in the line-override table denormalised column.
            $accountCode = '';
            if (preg_match('/^(\d+)/', $account, $m)) $accountCode = $m[1];
            $moveId  = (is_array($l['move_id']    ?? null)) ? (int)    $l['move_id'][0]    : 0;
            $pid     = (is_array($partner) && isset($partner[0])) ? (int) $partner[0] : null;
            $pname   = (is_array($partner) && isset($partner[1])) ? (string) $partner[1] : null;

            $line = [
                'date'      => (string) ($l['date'] ?? ''),
                'amount'    => round($amount, 2),
                'narration' => (string) ($l['name'] ?? ''),
                'journal'   => $journal,
                'account'   => $account,
                'move_id'   => $moveId,
            ];

            if ($pid !== null) {
                if (!isset($byPartner[$pid])) {
                    $byPartner[$pid] = [
                        'partner_id' => $pid,
                        'name'       => (string) ($pname ?? ''),
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

            // Flat record for line-level resolution. Effective category
            // and downstream apportionment fields are filled in step 6.5.
            $flatLines[] = [
                'id'           => $lineId,
                'move_id'      => $moveId,
                'date'         => (string) ($l['date'] ?? ''),
                'narration'    => (string) ($l['name'] ?? ''),
                'account'      => $account,
                'account_code' => $accountCode,
                'partner_id'   => $pid,
                'partner_name' => $pname,
                'amount'       => round($amount, 2),
            ];
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

        // ─────────────────────────────────────────────────────────────────
        // 6.5. Line-level category resolution.
        //
        // Each line gets an effective_category (1/2/3) by the precedence:
        //
        //     line override  →  partner default  →  Cat 3 fallback
        //
        // Cat 3 fallback is the conservative default: if the auditor
        // hasn't explicitly categorised a line and there's no partner-
        // level default to inherit from, we treat it as non-deductible
        // until proven otherwise. The UI surfaces these via the
        // categorisation_complete flag + cat3_fallback_total/count
        // metadata so the auditor knows what's outstanding.
        // ─────────────────────────────────────────────────────────────────
        $lineIdsInScope = array_filter(array_column($flatLines, 'id'));
        $lineOverrides  = LineDonationCategory::where('tenant_id', $this->tenant->id)
            ->whereIn('odoo_move_line_id', $lineIdsInScope === [] ? [0] : array_values($lineIdsInScope))
            ->get()
            ->keyBy('odoo_move_line_id');

        // Build partner-default lookup for fast inheritance during the
        // per-line walk (partner id → category int|null, is_non_resident).
        $partnerDefaultCat = [];
        $partnerNonResident = [];
        foreach ($localRows as $pid => $local) {
            $partnerDefaultCat[(int) $pid]   = $local->category !== null ? (int) $local->category : null;
            $partnerNonResident[(int) $pid] = (bool) ($local->is_non_resident ?? false);
        }

        // Resolve each line's effective category + source.
        foreach ($flatLines as &$fl) {
            $override = $lineOverrides->get($fl['id']);
            $partnerDefault = $fl['partner_id'] !== null
                ? ($partnerDefaultCat[$fl['partner_id']] ?? null)
                : null;
            $isForeign = $fl['partner_id'] !== null
                ? ($partnerInfo[$fl['partner_id']]['is_foreign'] ?? false)
                : false;
            $isNonResident = $fl['partner_id'] !== null
                ? (($partnerNonResident[$fl['partner_id']] ?? false) || $isForeign)
                : false;

            $fl['line_override_category']   = $override !== null ? (int) $override->category : null;
            $fl['line_override_note']       = $override?->note;
            $fl['line_override_assigned_by']= $override?->assigned_by;
            $fl['line_override_assigned_at']= $override?->updated_at?->toIso8601String();
            $fl['partner_default_category'] = $partnerDefault;
            $fl['is_non_resident']          = $isNonResident;

            if ($fl['line_override_category'] !== null) {
                $fl['effective_category'] = $fl['line_override_category'];
                $fl['category_source']    = 'line_override';
            } elseif ($partnerDefault !== null) {
                $fl['effective_category'] = $partnerDefault;
                $fl['category_source']    = 'partner_default';
            } else {
                // Conservative fallback: non-deductible until proven otherwise.
                $fl['effective_category'] = 3;
                $fl['category_source']    = 'cat3_fallback';
            }
        }
        unset($fl);

        // 7. Apply category rules — totals now sourced from line-level
        // effective categories. unresolved_total is retained for back-
        // compat (always 0 under the new resolution since fallback rolls
        // into Cat 3) so the existing view doesn't break.
        $totalDonations = 0.0;
        $cat1Total = 0.0; $cat2Total = 0.0; $cat3Total = 0.0;
        $cat3FallbackTotal = 0.0; $cat3FallbackCount = 0;
        foreach ($flatLines as $fl) {
            $a = (float) $fl['amount'];
            $totalDonations += $a;
            switch ((int) $fl['effective_category']) {
                case 1:  $cat1Total += $a; break;
                case 2:  $cat2Total += $a; break;
                case 3:  $cat3Total += $a; break;
            }
            if ($fl['category_source'] === 'cat3_fallback') {
                $cat3FallbackTotal += $a;
                $cat3FallbackCount++;
            }
        }
        $unresolvedTotal = 0.0;     // back-compat; fallback rolls into cat3

        // ── Apply ceiling collectively to the non-Cat-1 pool ─────────────
        // Methodology unchanged from the partner-grouped pass — the pool
        // tested against the 0.5% × CA_net ceiling is everything that
        // isn't Cat 1, line-level. Excess over ceiling is reintegrated
        // pro-rata across Cat 2 / Cat 3 by share of the non-Cat-1 pool.
        $cat1Allowed     = $cat1Total;
        $nonCat1Total    = $cat2Total + $cat3Total;
        $excessOverCap   = max(0.0, $nonCat1Total - $ceiling);

        $shareCat2 = $nonCat1Total > 0 ? $cat2Total / $nonCat1Total : 0.0;
        $shareCat3 = $nonCat1Total > 0 ? $cat3Total / $nonCat1Total : 0.0;

        $cat2Reintegrated = round($excessOverCap * $shareCat2, 2);
        $cat3Reintegrated = round($excessOverCap * $shareCat3, 2);
        $cat2Allowed      = $cat2Total - $cat2Reintegrated;
        $cat3Allowed      = $cat3Total - $cat3Reintegrated;

        $totalDeductible   = $cat1Allowed + $cat2Allowed + $cat3Allowed;
        $totalReintegrated = $cat2Reintegrated + $cat3Reintegrated;

        // Per-line apportionment: each line's reintegrated share is the
        // same proportion of its category's total as the category-level
        // reintegration ratio.
        $reintRatioByCat = [
            1 => 0.0,
            2 => $cat2Total > 0 ? $cat2Reintegrated / $cat2Total : 0.0,
            3 => $cat3Total > 0 ? $cat3Reintegrated / $cat3Total : 0.0,
        ];
        foreach ($flatLines as &$fl) {
            $a    = (float) $fl['amount'];
            $cat  = (int)   $fl['effective_category'];
            $ratio = $reintRatioByCat[$cat] ?? 0.0;
            $reint = round($a * $ratio, 2);
            $ded   = round($a - $reint, 2);

            // 15% TSR (Article 225 CGI) on the reintegrated portion of
            // Cat 3 lines whose partner is flagged non-resident.
            $tsr = 0.0;
            if ($reint > 0 && $cat === 3 && $fl['is_non_resident']) {
                $tsr = round($reint * (self::TSR_NON_RESIDENT_PCT / 100.0), 2);
            }
            $cit = round($reint * (self::STACKED_RATE_PCT / 100.0), 2);

            $fl['deductible']     = $ded;
            $fl['reintegrated']   = $reint;
            $fl['exposure_stack'] = $cit;
            $fl['exposure_tsr']   = $tsr;
            $fl['exposure_total'] = round($cit + $tsr, 2);
        }
        unset($fl);

        // 8. Roll line-level math up to the existing beneficiaries[] table
        // (back-compat with the partner roll-up UI). A beneficiary with
        // mixed line overrides will show the correct math because the
        // sums come from line-level resolution.
        $beneAggByPid = [];     // partner_id => [ded, reint, cit, tsr]
        foreach ($flatLines as $fl) {
            $pid = $fl['partner_id'] ?? 0;        // 0 = unrecorded
            if (!isset($beneAggByPid[$pid])) {
                $beneAggByPid[$pid] = ['ded' => 0.0, 'reint' => 0.0, 'cit' => 0.0, 'tsr' => 0.0];
            }
            $beneAggByPid[$pid]['ded']   += (float) $fl['deductible'];
            $beneAggByPid[$pid]['reint'] += (float) $fl['reintegrated'];
            $beneAggByPid[$pid]['cit']   += (float) $fl['exposure_stack'];
            $beneAggByPid[$pid]['tsr']   += (float) $fl['exposure_tsr'];
        }
        foreach ($beneficiaries as &$b) {
            $pid = (int) $b['partner_id'];
            $agg = $beneAggByPid[$pid] ?? ['ded' => 0.0, 'reint' => 0.0, 'cit' => 0.0, 'tsr' => 0.0];
            $b['deductible']     = round($agg['ded'], 2);
            $b['reintegrated']   = round($agg['reint'], 2);
            $b['exposure_stack'] = round($agg['cit'], 2);
            $b['exposure_tsr']   = round($agg['tsr'], 2);
            $b['exposure_total'] = round($agg['cit'] + $agg['tsr'], 2);
        }
        unset($b);

        $totalExposureStack = 0.0;
        $totalTsr           = 0.0;
        foreach ($flatLines as $fl) {
            $totalExposureStack += (float) $fl['exposure_stack'];
            $totalTsr           += (float) $fl['exposure_tsr'];
        }
        $totalExposureStack = round($totalExposureStack, 2);
        $totalTsr           = round($totalTsr, 2);
        $totalExposure      = round($totalExposureStack + $totalTsr, 2);

        $categorisationComplete = ($cat3FallbackCount === 0);

        return [
            'currency'       => $this->detectCurrency(),
            'period'         => [
                'from' => $jan1->format('Y-m-d'),
                'to'   => $today->format('Y-m-d'),
                'year' => (int) $today->format('Y'),
            ],
            'donations'      => [
                'beneficiaries'         => $beneficiaries,
                'lines'                 => $flatLines,         // line-level detail (new)
                'total_donations'       => round($totalDonations, 2),
                'cat1_total'            => round($cat1Total, 2),
                'cat2_total'            => round($cat2Total, 2),
                'cat3_total'            => round($cat3Total, 2),
                'unresolved_total'      => round($unresolvedTotal, 2),

                // Cat 3 fallback metadata — drives the categorisation
                // banner in the UI. count = number of lines resolving
                // via fallback; total = their summed magnitude.
                'cat3_fallback_total'   => round($cat3FallbackTotal, 2),
                'cat3_fallback_count'   => $cat3FallbackCount,
                'categorisation_complete' => $categorisationComplete,
                'line_count'            => count($flatLines),

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
                'beneficiaries'           => [],
                'lines'                   => [],          // line-level detail (new)
                'total_donations'         => 0.0,
                'cat1_total'              => 0.0,
                'cat2_total'              => 0.0,
                'cat3_total'              => 0.0,
                'unresolved_total'        => 0.0,
                'cat3_fallback_total'     => 0.0,
                'cat3_fallback_count'     => 0,
                'categorisation_complete' => true,        // empty = vacuously complete
                'line_count'              => 0,
                'ca_net_ytd'              => 0.0,
                'ceiling_pct'             => self::DEDUCTION_CEILING_PCT,
                'ceiling_xaf'             => 0.0,
                'cat2_allowed'            => 0.0,
                'cat2_reintegrated'       => 0.0,
                'cat3_reintegrated'       => 0.0,
                'total_deductible'        => 0.0,
                'total_reintegrated'      => 0.0,
                'cit_rate_pct'            => self::CIT_RATE_PCT,
                'ircm_rate_pct'           => self::IRCM_RATE_PCT,
                'stacked_rate_pct'        => self::STACKED_RATE_PCT,
                'tsr_rate_pct'            => self::TSR_NON_RESIDENT_PCT,
                'exposure_stacked'        => 0.0,
                'exposure_tsr'            => 0.0,
                'exposure_total'          => 0.0,
                // received-donations stub — caller may override with a
                // real probeReceivedDonations() result so a
                // chart-of-accounts gap on the expense side doesn't
                // suppress the received-donations card.
                'received'                => [
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
     * Upsert a line-level category override. Idempotent — calling with
     * the same line_id replaces the previous override. Pass $category
     * = null to clear the override (line falls back to partner default
     * or Cat 3 fallback). Returns the resulting LineDonationCategory
     * model, or null if the override was cleared.
     *
     * The caller is expected to have already validated $category ∈
     * {1, 2, 3, null} and that the line is one of this tenant's.
     */
    public function setLineCategory(
        int $moveLineId,
        int $moveId,
        ?int $partnerId,
        string $accountCode,
        \DateTimeImmutable $lineDate,
        float $amount,
        ?int $category,
        ?string $note,
        int $assignedByUserId
    ): ?LineDonationCategory {
        if ($category === null) {
            LineDonationCategory::where('tenant_id', $this->tenant->id)
                ->where('odoo_move_line_id', $moveLineId)
                ->delete();
            return null;
        }

        return LineDonationCategory::updateOrCreate(
            [
                'tenant_id'         => $this->tenant->id,
                'odoo_move_line_id' => $moveLineId,
            ],
            [
                'odoo_move_id'    => $moveId,
                'odoo_partner_id' => $partnerId,
                'account_code'    => $accountCode,
                'line_date'       => $lineDate->format('Y-m-d'),
                'amount'          => $amount,
                'category'        => $category,
                'note'            => $note,
                'assigned_by'     => $assignedByUserId,
            ]
        );
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
