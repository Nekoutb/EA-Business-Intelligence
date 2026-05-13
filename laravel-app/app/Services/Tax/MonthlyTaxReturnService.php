<?php
declare(strict_types=1);

namespace App\Services\Tax;

use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Odoo\OdooClient;

/**
 * Monthly Tax Return — Cameroon DGI I/TVA-IR.
 *
 * Builds the data structure for the official monthly declaration of taxes
 * on turnover and income tax (form I/TVA-IR) for the selected month.
 *
 * The form has 14 numbered sections plus a recap, with line codes L0–L80.
 * Each section returns lines with the form's columns (Base, Taux,
 * Principal, CAC, Pénalités, Total). Where a line maps to a SYSCOHADA
 * account prefix we auto-resolve from Odoo posted move lines in the month
 * window; otherwise the line returns zero so the principal can fill it in
 * before filing.
 */
final class MonthlyTaxReturnService
{
    public function __construct(private OdooClient $odoo) {}

    public function analyze(?string $month = null, ?Tenant $tenant = null): array
    {
        $today = new \DateTimeImmutable('today');
        $month = $month && preg_match('/^\d{4}-\d{2}$/', $month) ? $month : $today->format('Y-m');

        [$year, $m] = array_map('intval', explode('-', $month));
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m));
        $to   = $from->modify('last day of this month');
        $dueDate = $from->modify('+1 month')->modify('+14 days');
        $dueStatus = match (true) {
            $today < $from     => 'future',
            $today <= $dueDate => 'pending',
            default            => 'past_due',
        };

        $idsByPrefix = $this->resolveAccountIdsByPrefix();
        $auto        = $this->autoExtract($idsByPrefix, $from, $to);
        $sections    = $this->buildSections($auto);
        $recap       = $this->buildRecap($sections);
        $totalAPayer = (float) ($recap['total'] ?? 0);
        // Build the side-rail reconciliation that compares the GL ground
        // truth (sum of class-70 movements) against §2's reported CA
        // Global HT (L15) and the sales-ledger total (L10 + L14). The
        // method recomputes the GL total from class-70 accounts in the
        // same period window the rest of the return uses.
        $reconciliation = $this->buildReconciliation($auto, $sections, $from, $to);

        return [
            'declarant' => $this->loadDeclarant($tenant, $from),
            'period'    => [
                'year'       => $year,
                'month'      => $m,
                'from'       => $from->format('Y-m-d'),
                'to'         => $to->format('Y-m-d'),
                'label'      => $from->format('F Y'),
                'mois_fr'    => $this->moisFr($m),
                'due_date'   => $dueDate->format('Y-m-d'),
                'due_status' => $dueStatus,
            ],
            'currency'         => $this->detectCurrency(),
            'sections'         => $sections,
            'recap'            => $recap,
            'reconciliation'   => $reconciliation,
            'total_a_payer'    => round($totalAPayer, 2),
            'available_months' => $this->buildMonthOptions($today),
            'as_of'            => $today->format('Y-m-d'),
        ];
    }

    /* ─── Declarant header (entity info from tenant settings) ─────────── */

    private function loadDeclarant(?Tenant $tenant, \DateTimeImmutable $from): array
    {
        $defaults = [
            'dge_cri' => '', 'cdi_csi_cime' => '', 'regime' => '',
            'niu' => '', 'raison_sociale' => '', 'sigle' => '',
            'ville' => '', 'commune' => '', 'quartier' => '', 'lieu_dit' => '',
            'bp' => '', 'tel_fixe' => '', 'tel_mobile' => '', 'email' => '', 'fax' => '',
        ];
        if ($tenant !== null) {
            $keys = [
                'TAX_DGE_CRI'         => 'dge_cri',
                'TAX_CDI_CSI_CIME'    => 'cdi_csi_cime',
                'TAX_REGIME'          => 'regime',
                'TAX_NIU'             => 'niu',
                'TAX_RAISON_SOCIALE'  => 'raison_sociale',
                'TAX_SIGLE'           => 'sigle',
                'TAX_VILLE'           => 'ville',
                'TAX_COMMUNE'         => 'commune',
                'TAX_QUARTIER'        => 'quartier',
                'TAX_LIEU_DIT'        => 'lieu_dit',
                'TAX_BP'              => 'bp',
                'TAX_TEL_FIXE'        => 'tel_fixe',
                'TAX_TEL_MOBILE'      => 'tel_mobile',
                'TAX_EMAIL'           => 'email',
                'TAX_FAX'             => 'fax',
            ];
            $rows = TenantSetting::where('tenant_id', $tenant->id)
                ->whereIn('key', array_keys($keys))
                ->get()->keyBy('key');
            foreach ($keys as $k => $alias) {
                if ($rows->has($k)) $defaults[$alias] = (string) $rows[$k]->value;
            }
        }
        $defaults['exercice'] = (int) $from->format('Y');
        $defaults['mois_fr']  = $this->moisFr((int) $from->format('n'));
        return $defaults;
    }

    /* ─── Auto-extraction from Odoo ───────────────────────────────────
       Only BASE values are auto-extracted from posted GL movements.
       Every "computed" line (L3, L5, L8, L9, L15, L22, L28, L29, L30,
       L31, L32, L33, L36, L39, L44, L49, L52, L54, L55, L62, L66, L73,
       L77, L80) is derived from these bases via the form's own formulas
       — see buildSections() for the explicit cross-references.
       ──────────────────────────────────────────────────────────────── */

    private function autoExtract(array $idsByPrefix, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $sum = fn (string $prefix) => $this->sumBalanceWindow($idsByPrefix[$prefix] ?? [], $from, $to);

        // §12 RTS — split account 4472XX into four buckets by description
        // keyword (one fetch, four buckets). User spec 2026-05-13:
        //   L67 IRPP  ← descriptions containing "IRPP" / "Impôt sur le Revenu des Personnes Physiques"
        //   L70 FNE   ← descriptions containing "FNE" / "Fonds National d'Emploi"
        //   L71 RAV   ← descriptions containing "RAV" / "Redevance Audio Visuel"
        //   L72 TDL   ← descriptions containing "TDL" / "Taxe de Développement Local"
        // Matching is case-insensitive + accent-folded, first-match-wins
        // in iteration order so we keep IRPP precedence (it's the largest
        // bucket; a line tagged "IRPP - FNE adjustment" goes to IRPP).
        $rtsBuckets = $this->bucketLinesByDescription(
            $idsByPrefix['4472'] ?? [],
            $from, $to,
            [
                'irpp' => ['IRPP', 'impôt sur le revenu', 'impot sur le revenu',
                           'revenu des personnes physiques', 'traitements et salaires'],
                'fne'  => ['FNE', "fonds national d'emploi", "fonds national de l'emploi"],
                'rav'  => ['RAV', 'redevance audio visuel', 'redevance audiovisuel',
                           'redevance audiovisuelle'],
                'tdl'  => ['TDL', 'taxe de développement', 'taxe de developpement',
                           'développement local', 'developpement local'],
            ]
        );

        // §12 L68 — CFC part salariale. User specified 4474XX with a
        // description hint (CFC / Contribution au Crédit Foncier). 4474
        // is dedicated to CFC in SYSCOHADA so the description filter is
        // usually a no-op, but we apply it anyway per spec — keeps the
        // service honest if the tenant ever co-uses 4474 for something else.
        $cfcSalariale = $this->bucketLinesByDescription(
            $idsByPrefix['4474'] ?? [],
            $from, $to,
            [
                'cfc' => ['CFC', 'crédit foncier', 'credit foncier',
                          'contribution au crédit foncier', 'contribution au credit foncier'],
            ]
        )['cfc'] ?? 0.0;

        $salesIds = $this->classSalesAccountIds();

        // §2 — split class 70 sales between L10 (with VAT) and L14 (exempt)
        // by walking the sales journal. L10 + L14 reconciles to the total
        // class 70 movement for the period.
        $caSplit = $this->splitSalesByVat($salesIds, $from, $to);
        $L10 = $caSplit['with_vat'];
        $L14 = $caSplit['exempt'];

        // §3 — prefix-based auto-fill (per user spec):
        //   L18 ← all accounts whose code starts with 4452 (achats locaux)
        //   L19 ← all accounts whose code starts with 4454 (services locaux)
        $L18 = abs($sum('4452'));
        $L19 = abs($sum('4454'));

        // L17 = prior month's L33 — computed inline from the same prefix
        // pools applied to the prior calendar month.
        $priorTo   = $from->modify('-1 day');
        $priorFrom = $priorTo->modify('first day of this month');
        $priorSplit = $this->splitSalesByVat($salesIds, $priorFrom, $priorTo);
        $priorCaWithVat = $priorSplit['with_vat'];
        $prior4452 = abs($this->sumBalanceWindow($idsByPrefix['4452'] ?? [], $priorFrom, $priorTo));
        $prior4454 = abs($this->sumBalanceWindow($idsByPrefix['4454'] ?? [], $priorFrom, $priorTo));
        $priorL22  = $prior4452 + $prior4454;            // L18+L19+L20+L21 (others = 0)
        $priorL28  = $priorCaWithVat * 0.1925;           // TVA on L10 only (exempt stays out)
        $priorL32  = $priorL28 - $priorL22;
        $priorL33  = max(-$priorL32, 0);                 // = previous month's credit carry

        return [
            'L10' => $L10,             // CA opérations taxables (sales WITH VAT)
            'L14' => $L14,             // CA exonéré (sales WITHOUT VAT)
            'L17' => $priorL33,        // Report du crédit antérieur = L33 (mois précédent)
            'L18' => $L18,             // TVA déductible sur achats locaux (4452*)
            'L19' => $L19,             // TVA déductible sur services locaux (4454*)
            'L45' => abs($sum('4471')),// Acompte sur CA retenu à la source — balance of 4471*
            'L42' => abs($sum('4426')),// Précomptes sur loyers (15 %)
            'L0'  => abs($sum('4423')),// TSR pool
            // §12 RTS sources (per user spec 2026-05-13, revised):
            //   L67 IRPP s/ traitements et salaires       ← 4472XX bucketed by "IRPP" keyword
            //   L68 CFC Part Salariale                    ← 4474XX bucketed by "CFC" keyword
            //   L69 CFC Part Patronale                    ← 6413XX plain sum (specific sub-prefix)
            //   L70 FNE                                   ← 4472XX bucketed by "FNE" keyword
            //   L71 Redevance audiovisuelle (RAV)         ← 4472XX bucketed by "RAV" keyword
            //   L72 Taxe de Développement Local (TDL)     ← 4472XX bucketed by "TDL" keyword
            // 4472XX is one Odoo fetch split into 4 buckets above — one round-trip,
            // not four. 4477XX is no longer used for L70 (the user moved FNE to 4472).
            'L67' => $rtsBuckets['irpp'] ?? 0.0,
            'L68' => $cfcSalariale,
            'L69' => abs($sum('6413')),
            'L70' => $rtsBuckets['fne']  ?? 0.0,
            'L71' => $rtsBuckets['rav']  ?? 0.0,
            'L72' => $rtsBuckets['tdl']  ?? 0.0,
        ];
    }

    /**
     * Walk the sales journal and split class-70 credit movements between:
     *   - with_vat: the 70X credits live on a move that ALSO has a 443X credit
     *               (sale carried VAT) — feeds §2 L10.
     *   - exempt:   the 70X credits live on a move with NO 443X credit
     *               (no VAT was charged) — feeds §2 L14.
     *
     * The two buckets are mutually exclusive; their sum reconciles to the
     * absolute total of class-70 (ex 709) for the window.
     */
    private function splitSalesByVat(array $salesIds, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ($salesIds === []) return ['with_vat' => 0.0, 'exempt' => 0.0];

        // Resolve all output-VAT accounts (code starts with 443).
        try {
            $vatAccts = $this->odoo->searchRead('account.account',
                [['code', '=like', '443%']], ['id'], []);
        } catch (\Throwable $e) { return ['with_vat' => 0.0, 'exempt' => 0.0]; }
        $vatIds = array_map(fn ($r) => (int) $r['id'], $vatAccts);

        // All 70X credit lines in window.
        try {
            $salesLines = $this->odoo->searchRead('account.move.line', [
                ['account_id', 'in', $salesIds],
                ['credit', '>', 0],
                ['parent_state', '=', 'posted'],
                ['date', '>=', $from->format('Y-m-d')],
                ['date', '<=', $to->format('Y-m-d')],
            ], ['move_id', 'credit'], []);
        } catch (\Throwable $e) { return ['with_vat' => 0.0, 'exempt' => 0.0]; }
        if (empty($salesLines)) return ['with_vat' => 0.0, 'exempt' => 0.0];

        // Moves that ALSO have a 443X credit are the "VAT-applied" sales.
        $movesWithVat = [];
        if ($vatIds !== []) {
            try {
                $vatLines = $this->odoo->searchRead('account.move.line', [
                    ['account_id', 'in', $vatIds],
                    ['credit', '>', 0],
                    ['parent_state', '=', 'posted'],
                    ['date', '>=', $from->format('Y-m-d')],
                    ['date', '<=', $to->format('Y-m-d')],
                ], ['move_id'], []);
                foreach ($vatLines as $r) {
                    $mid = is_array($r['move_id'] ?? null) ? (int) $r['move_id'][0] : (int) ($r['move_id'] ?? 0);
                    if ($mid > 0) $movesWithVat[$mid] = true;
                }
            } catch (\Throwable $e) { /* fall through with empty set */ }
        }

        // Bucket 70X credits by whether their move had VAT.
        $withVat = 0.0;
        $exempt  = 0.0;
        foreach ($salesLines as $r) {
            $mid    = is_array($r['move_id'] ?? null) ? (int) $r['move_id'][0] : (int) ($r['move_id'] ?? 0);
            $credit = (float) ($r['credit'] ?? 0);
            if (isset($movesWithVat[$mid])) {
                $withVat += $credit;
            } else {
                $exempt += $credit;
            }
        }
        return [
            'with_vat' => round($withVat, 2),
            'exempt'   => round($exempt, 2),
        ];
    }

    /** Resolve an account.account by its exact `code` field. Returns id or null. */
    private function resolveAccountIdByCode(string $code): ?int
    {
        try {
            $rows = $this->odoo->searchRead('account.account', [['code', '=', $code]], ['id'], [], 1);
            if (!empty($rows)) return (int) $rows[0]['id'];
        } catch (\Throwable $e) { /* fall through */ }
        return null;
    }

    /* ─── Section builders ──────────────────────────────────────────── */

    private function line(string $code, string $label, float $base = 0, ?string $rate = null, float $principal = 0, float $cac = 0, float $penalites = 0, ?float $total = null, ?string $unit = null): array
    {
        if ($total === null) $total = $principal + $cac + $penalites;
        return [
            'code'      => $code,
            'label'     => $label,
            'base'      => round($base, 2),
            'rate'      => $rate,
            'principal' => round($principal, 2),
            'cac'       => round($cac, 2),
            'penalites' => round($penalites, 2),
            'total'     => round($total, 2),
            'unit'      => $unit,    // optional display unit (e.g. '%') for the 'amount' layout
        ];
    }

    /**
     * Tax-return reconciliation card — surfaces three figures and two
     * targeted comparisons so the operator can spot whether the §2
     * sales declaration ties to the underlying GL ground truth before
     * filing.
     *
     * Three figures:
     *   1. GL class-70 total (excl. 709 "rabais, remises, ristournes accordés")
     *      — sum of every credit and debit on class 70 accounts for the
     *      declaration window, abs()'d to a positive amount.
     *   2. Sales ledger total = L10 + L14 — total class-70 credits that
     *      came specifically from the SALES JOURNAL (already split by
     *      splitSalesByVat in autoExtract).
     *   3. L15 CA Global HT — what the §2 line declares to the DGI
     *      (L10 + L11 + L12 + L13, recomputed here from the section data
     *      so we don't depend on a precomputed accumulator).
     *
     * Two checks (per user spec 2026-05-13):
     *
     *   Check A · GL Class 70 vs Sales Ledger
     *     Ideal: 0 diff (every class-70 movement should originate from
     *     the sales journal). Non-zero ≠ wrong, but every XAF needs
     *     justification — typically manual JEs, OD reclassifications,
     *     year-end close-outs, or refunds posted outside the sales
     *     journal.
     *
     *   Check B · GL Class 70 vs L15 CA Global HT
     *     Ideal NOT 0 — L15 = L10 + L11 + L12 + L13 (taxable subset)
     *     while GL also includes L14 (exempt). Diff origins are mapped
     *     out below so the user sees which delta is structural vs
     *     suspicious.
     */
    private function buildReconciliation(array $auto, array $sections, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        // GL ground truth: total class-70 movement for the window.
        // Reuses the existing classSalesAccountIds() exclusion (no 709).
        $salesIds  = $this->classSalesAccountIds();
        $gl70      = abs($this->sumBalanceWindow($salesIds, $from, $to));

        // L10 + L14 already computed in autoExtract via splitSalesByVat —
        // these are the sales-journal-sourced class-70 credits.
        $L10  = (float) ($auto['L10'] ?? 0);
        $L14  = (float) ($auto['L14'] ?? 0);
        $sales = $L10 + $L14;

        // L15 is recomputed from §2 line data. We could read it from
        // autoExtract, but the section build is the canonical source
        // (it's where L11/L12/L13 live). Read from $sections instead.
        $l15 = 0.0;
        foreach ($sections as $s) {
            if (($s['id'] ?? '') !== 'tva_ca') continue;
            foreach ($s['lines'] ?? [] as $ln) {
                if (($ln['code'] ?? '') === 'L15') {
                    $l15 = (float) ($ln['principal'] ?? $ln['total'] ?? 0);
                    break 2;
                }
            }
        }

        // Severity bands (% of GL absolute size) — tighter than the
        // generic 1% tolerance because tax declarations face DGI
        // scrutiny: even a 1% slip on a 100M XAF CA is 1M to explain.
        $severity = function (float $diff, float $base): string {
            if ($base <= 0) return 'ok';
            $pct = abs($diff) / $base;
            if ($pct < 0.005) return 'ok';     // < 0.5%
            if ($pct < 0.05)  return 'minor';  // < 5%
            return 'major';                     // ≥ 5%
        };

        // Check A — pure GL vs sales-ledger reconciliation.
        $diffA = $gl70 - $sales;
        $pctA  = $gl70 > 0 ? 100.0 * $diffA / $gl70 : 0.0;
        $checkA = [
            'key'         => 'gl_vs_sales_ledger',
            'label'       => 'Class 70 (GL) vs Sales Ledger',
            'left_label'  => 'GL Class 70 (excl. 709)',
            'left'        => round($gl70, 2),
            'right_label' => 'Sales Ledger (L10 + L14)',
            'right'       => round($sales, 2),
            'diff'        => round($diffA, 2),
            'pct'         => round($pctA, 2),
            'severity'    => $severity($diffA, $gl70),
            'expectation' => 'Should reconcile to zero — every class-70 movement should originate from the sales journal.',
            'origins'     => [
                'Manual journal entries posted directly to class 70 outside the sales journal (accruals, end-of-period adjustments).',
                'Year-end close-out journals reclassifying class 70 to class 13X.',
                'OD (opérations diverses) reclassifications between class-70 sub-accounts.',
                'Customer refund credit notes (out_refund) posted via a refund journal instead of the sales journal.',
                'Class 709 (rabais, remises, ristournes accordés) — excluded from this GL total by design; if your sales journal includes 709 debits the sales ledger figure will be higher than the GL.',
                'Period boundary issues — invoice dates in window but posting dates outside (or vice versa).',
            ],
        ];

        // Check B — GL vs declared CA HT.
        $diffB = $gl70 - $l15;
        $pctB  = $gl70 > 0 ? 100.0 * $diffB / $gl70 : 0.0;
        $checkB = [
            'key'         => 'gl_vs_l15',
            'label'       => 'Class 70 (GL) vs L15 CA Global HT',
            'left_label'  => 'GL Class 70 (excl. 709)',
            'left'        => round($gl70, 2),
            'right_label' => 'L15 CA Global HT (§2)',
            'right'       => round($l15, 2),
            'diff'        => round($diffB, 2),
            'pct'         => round($pctB, 2),
            'severity'    => $severity($diffB, $gl70),
            // L15 = L10 + L11 + L12 + L13 (taxable CA). GL class 70
            // also captures L14 (CA exonéré). So a positive diff (GL >
            // L15) of roughly L14's amount is STRUCTURAL — that's the
            // form's design. We surface this as the first hint so the
            // operator doesn't chase it.
            'expectation' => 'Expected diff ≈ L14 (CA exonéré in GL but not in L15). Any residual after subtracting L14 needs justification.',
            'origins'     => [
                sprintf('Structural: L14 CA exonéré = %s XAF — in GL but excluded from L15 by DGI form design.', number_format($L14, 0, ',', ' ')),
                'L11 (droit d\'accises) is included in L15 but is NOT class 70 — it lives in a separate excise account, so it does NOT inflate the GL side.',
                'Non-sales-journal entries to class 70 (same family of causes as Check A — manual JEs, OD, year-end close-out).',
                'Possible double-counting in §2: L11 was already reported elsewhere or L10/L14 split is mis-tagged for some sales (VAT vs exempt classification).',
                'Sales returns / credit notes posted with positive class-70 amounts instead of negative ones.',
            ],
        ];

        // Headline severity = worst of the two checks. Drives the panel
        // border colour and "OK / À investiguer / Critique" pill.
        $rankings = ['ok' => 0, 'minor' => 1, 'major' => 2];
        $worst = max($rankings[$checkA['severity']], $rankings[$checkB['severity']]);
        $headline = array_search($worst, $rankings, true);

        return [
            'period_from'  => $from->format('Y-m-d'),
            'period_to'    => $to->format('Y-m-d'),
            'figures'      => [
                'gl70_total'         => round($gl70, 2),
                'sales_ledger_total' => round($sales, 2),
                'l15_ca_global'      => round($l15, 2),
            ],
            'checks'       => [$checkA, $checkB],
            'severity'     => $headline,    // 'ok' | 'minor' | 'major'
        ];
    }

    private function buildSections(array $auto): array
    {
        $L = fn (string $k) => (float) ($auto[$k] ?? 0);

        // §0 TSR
        $tsrTotal = $L('L0');
        $s0 = [
            'id' => 'tsr', 'code' => '0', 'title' => 'TAXE SPÉCIALE SUR LE REVENU (TSR)',
            'subtitle' => "Rémunérations versées à l'étranger",
            'lines' => [
                $this->line('—',  'TSR retenue au taux de 15 %', 0, '15 %'),
                $this->line('—',  'TSR retenue au taux de 10 %', 0, '10 %'),
                $this->line('—',  'TSR retenue au taux de 7,5 %', 0, '7,5 %'),
                $this->line('L0', 'TOTAL', principal: $tsrTotal),
            ],
            'total' => $tsrTotal,
        ];

        // §1 Droits d'Accises
        $L1 = 0; $L2 = 0; $L3 = $L1 + $L2; $L4 = 0; $L5 = $L3 + $L4; $L6 = 0; $L7 = $L5 - $L6;
        $L8 = max($L5, $L7); $L9 = $L5 + $L7;
        $s1 = [
            'id' => 'da', 'code' => '1', 'title' => "DROITS D'ACCISES (DA)",
            'subtitle' => "Chiffre d'affaires réalisé",
            'lines' => [
                $this->line('L1', 'CA taxable au taux général',                       $L1, '25 %'),
                $this->line('L2', 'CA taxable au taux réduit',                        $L2, '12,5 %'),
                $this->line('L3', "Droit d'accises ad valorem = L1 + L2",              principal: $L3),
                $this->line('L4', "Droit d'accises spécifique (détails annexés)",      principal: $L4),
                $this->line('L5', "Droits d'accises à reverser = L3 + L4",             principal: $L5),
                $this->line('L6', "Droits d'accises payés à l'importation",            principal: $L6),
                $this->line('L7', "Complément droits d'accises à payer = L5 − L6",     principal: $L7),
                $this->line('L8', 'TOTAL — (L5) ou (L7)',                              principal: $L8),
                $this->line('L9', 'TOTAL — (L5 + L7)',                                  principal: $L9),
            ],
            'total' => $L8,
        ];

        // §2 TVA — CA réalisé
        // L11 reports the DA total computed in §1 (L8 in the standard case).
        // L10/L11/L12 carry both a CA (base) and a TVA amount — the TVA
        // amounts feed forward into §5 L28.
        $L10 = $L('L10');
        $L11 = $L8 ?: $L9;
        $L12 = 0; $L13 = 0;
        $L14 = $L('L14');     // CA exonéré — sales journal walk (70X credit without 4431 credit)
        $L15 = $L10 + $L11 + $L12 + $L13;
        $tvaSurL10 = $L10 * 0.1925;
        $tvaSurL11 = $L11 * 0.1925;
        $tvaSurL12 = 0;     // Specific rates / bases — manual entry would override
        $s2 = [
            'id' => 'tva_ca', 'code' => '2', 'title' => "TAXE SUR LA VALEUR AJOUTÉE (TVA) — Chiffre d'affaires réalisé",
            'subtitle' => 'CA · Taux · Montant de la taxe',
            // Form §2 only has CA · Taux · Montant de la taxe columns — no
            // Principal/CAC/Pénalités split. The view renders accordingly.
            'layout' => 'ca',
            'lines' => [
                $this->line('L10', 'Opérations taxables au taux normal',                  $L10, '19,25 %', principal: $tvaSurL10),
                $this->line('L11', "Montant du droit d'accises (L8 ou L9)",                $L11, '19,25 %', principal: $tvaSurL11),
                $this->line('L12', 'Autres opérations taxables (taux/bases spécifiques)', $L12),
                $this->line('L13', 'Exportations (taxables au taux zéro)',                $L13, '0 %'),
                $this->line('L14', "Chiffre d'affaires exonéré",                          $L14),
                $this->line('L15', 'CA global hors taxes = L10 + L11 + L12 + L13',         principal: $L15),
            ],
            // Section total tracks L28 (TVA collectée on §5 line 381) for
            // consistency — was previously $tvaSurL10 + $tvaSurL11 which
            // silently dropped L12. Currently $tvaSurL12 = 0 so this is
            // a no-op, but locks the two computations in lockstep so a
            // future L12 carrying value can't desync them.
            'total' => $tvaSurL10 + $tvaSurL11 + $tvaSurL12,
        ];

        // §3 TVA déductible
        //   L17 = prior month's L33 (computed inline in autoExtract).
        //   L18 = account 4452000 net movement (achats locaux).
        //   L19 = account 4454000 net movement (services locaux).
        //   L22 = L18 + L19 + L20 + L21        (per user spec — L17 NOT in the sum)
        //   L23 = L16 % × L22
        $L16 = 0;
        $L17 = $L('L17');
        $L18 = $L('L18');
        $L19 = $L('L19');
        $L20 = 0; $L21 = 0;
        $L22 = $L18 + $L19 + $L20 + $L21;
        $L23 = $L16 > 0 ? $L22 * ($L16 / 100) : 0;
        $s3 = [
            'id' => 'tva_ded', 'code' => '3', 'title' => 'TVA DÉDUCTIBLE',
            'subtitle' => 'Achats, services locaux, prorata',
            // Single Montant column per user spec — drop Base/Taux/Principal/CAC/Pénalités.
            'layout' => 'amount',
            'lines' => [
                $this->line('L16', 'Prorata prévisionnel (assujettis partiels) confère DSF n-1',     principal: $L16, unit: '%'),
                $this->line('L17', 'Report du crédite antérieur = L33 de la déclaration précédente', principal: $L17),
                $this->line('L18', 'TVA déductible sur achats locaux',                                 principal: $L18),
                $this->line('L19', 'TVA déductible sur services locaux',                               principal: $L19),
                $this->line('L20', "TVA déductible sur achats à l'étranger",                           principal: $L20),
                $this->line('L21', "TVA déductible sur services et autres rémunérations versés à l'étranger", principal: $L21),
                $this->line('L22', 'Total TVA déductible : L18+L19+L20+L21',                            principal: $L22),
                $this->line('L23', 'TVA déductible au prorata previsionnel (assujettis partiels): L16 X L22', principal: $L23),
            ],
            'total' => $L22,
        ];

        // §4 TVA — Régularisations exceptionnelles
        $L24 = 0; $L25 = 0; $L26 = 0; $L27 = 0;
        $s4 = [
            'id' => 'tva_reg', 'code' => '4', 'title' => 'RÉGULARISATIONS EXCEPTIONNELLES',
            'subtitle' => 'TVA à reverser ou à déduire',
            // Form §4 columns: Principal · CAC · Pénalités · Total — no
            // Base/Taux (these lines carry a single signed amount and
            // CAC/Pénalités are typically 0; the view renders them as
            // dashes which reads cleaner than the 8-col default).
            'layout' => 'tax_due',
            'lines' => [
                $this->line('L24', 'Régularisation TVA déductible / TVA déjà retenue à la source (−)', principal: -$L24),
                $this->line('L25', "Régularisation TVA prise en charge par l'État (−)",                 principal: -$L25),
                $this->line('L26', "Régularisation sur cession d'éléments de l'actif immobilisé à reverser (+)", principal: $L26),
                $this->line('L27', 'Régularisation TVA à reverser et autres (+)',                        principal: $L27),
            ],
            'total' => $L26 + $L27 - $L24 - $L25,
        ];

        // §5 TVA à payer ou crédit — every line here is a cross-reference
        // pulling from earlier sections per the form's own formulas:
        //   L28 = (TVA on L10) + (TVA on L11) + (TVA on L12)        [§2]
        //   L29 = (L22) ou (L23)                                     [§3]
        //   L30 = (L24 + L25)                                        [§4]
        //   L31 = (L26 + L27)                                        [§4]
        //   L32 = L28 − L29 − L30 + L31
        //   L33 = max(−L32, 0)                                       (credit if L32 < 0)
        //   L35 = L33 − L34
        $L28 = $tvaSurL10 + $tvaSurL11 + $tvaSurL12;
        $L29 = max($L22, $L23);
        $L30 = $L24 + $L25;
        $L31 = $L26 + $L27;
        $L32 = $L28 - $L29 - $L30 + $L31;
        $L33 = max(-$L32, 0);
        $L34 = 0; $L35 = $L33 - $L34;
        $tvaAPayer = max($L32, 0);
        $s5 = [
            'id' => 'tva_pay', 'code' => '5', 'title' => 'TVA À PAYER OU CRÉDIT DE TVA',
            'subtitle' => 'Liquidation de la TVA du mois',
            // Form §5 has a single amount column per line — every line is a
            // derived value (L28 = TVA on L10+L11+L12, L32 = L28−L29−…).
            // No base/rate/CAC/pénalités splits.
            'layout' => 'amount',
            'lines' => [
                $this->line('L28', 'TVA collectée (L10 + L11 + L12)',           principal: $L28),
                $this->line('L29', 'TVA déductible : (L22) ou (L23)',           principal: $L29),
                $this->line('L30', 'Régularisation TVA à déduire : (L24 + L25)', principal: $L30),
                $this->line('L31', 'Régularisation TVA à reverser : (L26 + L27)', principal: $L31),
                $this->line('L32', 'TVA à payer : (L28 − L29 − L30 + L31)',     principal: $L32),
                $this->line('L33', 'Crédit de TVA',                              principal: $L33),
                $this->line('L34', 'Remboursement demandé (préciser le montant)', principal: $L34),
                $this->line('L35', 'Crédit à reporter = L33 − L34',              principal: $L35),
            ],
            'total' => $tvaAPayer,
        ];

        // §6 Total TVA à payer — L36 reports L32 (net VAT due from §5),
        // decomposed column-wise:
        //   L36 Total     = L32 (clamped at 0; credit case leaves §6 blank)
        //   L36 CAC       = 10 % × L36 Total
        //   L36 Principal = L36 Total − L36 CAC      (= 90 % × L36 Total)
        //   L37 / L38 stay zero pending manual entry.
        //   L39 = L36 + L37 + L38   column-wise.
        $L36total     = max($L32, 0);
        $L36cac       = $L36total * 0.10;
        $L36principal = $L36total - $L36cac;
        $L37principal = 0; $L37cac = 0; $L37total = 0;
        $L38principal = 0; $L38cac = 0; $L38total = 0;
        $L39principal = $L36principal + $L37principal + $L38principal;
        $L39cac       = $L36cac       + $L37cac       + $L38cac;
        $L39total     = $L36total     + $L37total     + $L38total;
        $s6 = [
            'id' => 'tva_total', 'code' => '6', 'title' => 'TOTAL DE TVA À PAYER',
            'subtitle' => 'Total = L32 · CAC = 10 % du Total · Principal = Total − CAC',
            // Form §6 columns: Principal · CAC · Pénalités · Total — no Base/Taux.
            'layout' => 'tax_due',
            'lines' => [
                $this->line('L36', 'TVA à payer (= L32)',                                    principal: $L36principal, cac: $L36cac, total: $L36total),
                $this->line('L37', 'TVA retenue à la source (entreprise habilitée)',          principal: $L37principal, cac: $L37cac, total: $L37total),
                $this->line('L38', "TVA retenue sur rémunération versée à l'étranger",        principal: $L38principal, cac: $L38cac, total: $L38total),
                $this->line('L39', 'Montant à payer = L36 + L37 + L38',                       principal: $L39principal, cac: $L39cac, total: $L39total),
            ],
            'total' => $L39total,
        ];

        // §7 IR — Acomptes / précomptes à reverser
        $L40 = 0; $L41 = 0; $L42 = $L('L42'); $L43 = 0;
        $L44 = $L40 + $L41 + $L42 + $L43;
        $cacOnL44 = $L44 * 0.10;
        $s7 = [
            'id' => 'ir_rev', 'code' => '7', 'title' => 'ACOMPTES ET PRÉCOMPTES À REVERSER',
            'subtitle' => "Retenues effectuées par l'entreprise",
            // Form §7 columns: Principal · CAC · Pénalités · Total — no
            // Base/Taux. The retention amount is the "principal" the
            // entity must reverser; CAC is computed on top.
            'layout' => 'tax_due',
            'lines' => [
                $this->line('L40', 'Acompte sur CA retenu à la source',                              principal: $L40, cac: $L40 * 0.10),
                $this->line('L41', 'Précomptes sur achats retenus (0,5 % / 1 % / 3 % / 5 % / 10 %)', principal: $L41, cac: $L41 * 0.10),
                $this->line('L42', 'Précomptes sur loyers (15 %)',                                   principal: $L42, cac: $L42 * 0.10),
                $this->line('L43', 'Précomptes sur rémunérations et honoraires (5 %)',               principal: $L43, cac: $L43 * 0.10),
                $this->line('L44', 'TOTAL = L40 + L41 + L42 + L43',                                  principal: $L44, cac: $cacOnL44),
            ],
            'total' => $L44 + $cacOnL44,
        ];

        // §8 IR — Acomptes / précomptes à déduire
        // L45 = Acompte sur CA retenu à la source — sourced from balance(4471):
        //   Total     = balance of account 4471 (any sub-account starting 4471)
        //   CAC       = 10 % × Total
        //   Principal = Total − CAC                  (= 90 % × Total)
        // L46-L48 stay zero pending manual entry.
        // L49 = column-wise sum of L45-L48.
        $L45total     = $L('L45');
        $L45cac       = $L45total * 0.10;
        $L45principal = $L45total - $L45cac;

        $L46principal = 0; $L46cac = 0; $L46total = 0;
        $L47principal = 0; $L47cac = 0; $L47total = 0;
        $L48principal = 0; $L48cac = 0; $L48total = 0;

        $L49principal = $L45principal + $L46principal + $L47principal + $L48principal;
        $L49cac       = $L45cac       + $L46cac       + $L47cac       + $L48cac;
        $L49total     = $L45total     + $L46total     + $L47total     + $L48total;

        $s8 = [
            'id' => 'ir_ded', 'code' => '8', 'title' => 'ACOMPTES ET PRÉCOMPTES À DÉDUIRE',
            'subtitle' => "L45 Total = balance(4471) · CAC 10 % du Total · Principal = Total − CAC",
            // Form §8 columns: Principal · CAC · Pénalités · Total — no
            // Base/Taux. The retention amount feeds back into §9 L52
            // as a déduction; the section header (set below) shows the
            // total deductible at a glance.
            'layout' => 'tax_due',
            'lines' => [
                $this->line('L45', 'Acompte sur CA retenu à la source',                principal: $L45principal, cac: $L45cac, total: $L45total),
                $this->line('L46', 'Précomptes sur achats (0,5 % / 1 % / 3 % / 5 %)',   principal: $L46principal, cac: $L46cac, total: $L46total),
                $this->line('L47', 'Précomptes 15 % sur loyers (déductible sur IR)',    principal: $L47principal, cac: $L47cac, total: $L47total),
                $this->line('L48', 'Précomptes sur rémunérations et honoraires (5 %)',  principal: $L48principal, cac: $L48cac, total: $L48total),
                $this->line('L49', 'TOTAL = L45 + L46 + L47 + L48',                     principal: $L49principal, cac: $L49cac, total: $L49total),
            ],
            // Section header total = L49 (the section's own TOTAL line).
            // Was previously hardcoded to 0 because §8 is deductive — but
            // that conflated two separate concepts: "does this section
            // contribute to total_a_payer?" (no, the deductive part is
            // already credited via §9 L52 — buildRecap below excludes
            // §8 from $defs anyway) vs "what does the section header
            // display?" (the row sum, same as every other section). The
            // displayed total now matches L49 so the user can see the
            // amount deductible at a glance, without affecting recap.
            'total' => $L49total,
        ];

        // §9 IR — Liquidation
        // L50 = Acompte sur CA déclaré at 2 % of L15 (CA global hors taxes):
        //   Base      = L15
        //   Taux      = 2 %
        //   Principal = L15 × 2 %
        //   CAC       = 10 % × Principal
        //   Total     = Principal + CAC
        // L51-L55 propagate column-wise so L54 = (L50 + L51) − (L52 + L53)
        // applies to each column independently and the row totals tie.
        $L50base      = $L15;
        $L50principal = $L15 * 0.02;
        $L50cac       = $L50principal * 0.10;
        $L50total     = $L50principal + $L50cac;

        $L51base      = 0;             // loyers perçus (manual)
        $L51principal = $L51base * 0.15;
        $L51cac       = $L51principal * 0.10;
        $L51total     = $L51principal + $L51cac;

        $L52principal = $L49principal; // = §8's L49 (déductions)
        $L52cac       = $L49cac;
        $L52total     = $L49total;

        $L53principal = 0;             // crédit antérieur (= prior month's L55)
        $L53cac       = 0;
        $L53total     = 0;

        $L54principal = $L50principal + $L51principal - $L52principal - $L53principal;
        $L54cac       = $L50cac       + $L51cac       - $L52cac       - $L53cac;
        $L54total     = $L50total     + $L51total     - $L52total     - $L53total;

        $L55principal = $L54total < 0 ? abs($L54principal) : 0;
        $L55cac       = $L54total < 0 ? abs($L54cac)       : 0;
        $L55total     = $L54total < 0 ? abs($L54total)     : 0;

        $s9 = [
            'id' => 'ir_liq', 'code' => '9', 'title' => 'LIQUIDATION ACOMPTES IMPÔTS SUR LE REVENU',
            'subtitle' => 'L50 base = L15 · taux 2 % · CAC 10 % du Principal',
            'lines' => [
                $this->line('L50', 'Acompte sur CA déclaré',                        $L50base, '2 %',  principal: $L50principal, cac: $L50cac, total: $L50total),
                $this->line('L51', 'Acompte de 15 % sur loyers perçus',             $L51base, '15 %', principal: $L51principal, cac: $L51cac, total: $L51total),
                $this->line('L52', 'Déductions à opérer = L49',                                    principal: $L52principal,                  total: $L52total),
                $this->line('L53', 'Crédit antérieur = L55 déclaration précédente',                principal: $L53principal,                  total: $L53total),
                $this->line('L54', 'Acompte à payer = (L50 + L51) − (L52 + L53)',                  principal: $L54principal, cac: $L54cac,    total: $L54total),
                $this->line('L55', "Crédit d'impôt à reporter (solde négatif L54)",                principal: $L55principal, cac: $L55cac,    total: $L55total),
            ],
            'total' => max($L54total, 0),
        ];

        // §10 IRCM
        $L56 = 0; $L57 = 0; $L58 = 0; $L59 = 0; $L60 = 0; $L61 = 0;
        $L62 = $L56 + $L57 + $L58 + $L59 + $L60 + $L61;
        $cacOnL62 = $L62 * 0.15 * 0.10;
        $s10 = [
            'id' => 'ircm', 'code' => '10', 'title' => 'RETENUES SUR REVENUS DE CAPITAUX MOBILIERS',
            'subtitle' => 'Principal 15 % · CAC 10 %',
            'lines' => [
                $this->line('L56', 'Revenus des actions, parts de capital et assimilés',  principal: $L56 * 0.15, cac: $L56 * 0.15 * 0.10),
                $this->line('L57', 'Dividendes payés hors du Cameroun',                   principal: $L57 * 0.15, cac: $L57 * 0.15 * 0.10),
                $this->line('L58', 'Rémunération des dirigeants et jetons de présence',   principal: $L58 * 0.15, cac: $L58 * 0.15 * 0.10),
                $this->line('L59', 'Revenus des obligations',                             principal: $L59 * 0.15, cac: $L59 * 0.15 * 0.10),
                $this->line('L60', 'Revenus créances, dépôts, cautionnements',            principal: $L60 * 0.15, cac: $L60 * 0.15 * 0.10),
                $this->line('L61', "Gains sur cession d'actions, d'obligations",          principal: $L61 * 0.15, cac: $L61 * 0.15 * 0.10),
                $this->line('L62', 'TOTAL = L56 + L57 + L58 + L59 + L60 + L61',           principal: $L62 * 0.15, cac: $cacOnL62),
            ],
            'total' => $L62 * 0.15 + $cacOnL62,
        ];

        // §11 IRNCS
        $L63 = 0; $L64 = 0; $L65 = 0;
        $L66 = $L63 + $L64 + $L65;
        $cacOnL66 = $L66 * 0.15 * 0.10;
        $s11 = [
            'id' => 'irncs', 'code' => '11', 'title' => 'IMPÔTS SUR REVENUS NON COMMERCIAUX RETENUS À LA SOURCE',
            'subtitle' => "Principal 15 % · CAC 10 % · joindre l'état nominatif",
            'lines' => [
                $this->line('L63', "Rémunérations des sessions des conseils d'administration", principal: $L63 * 0.15, cac: $L63 * 0.15 * 0.10),
                $this->line('L64', 'Primes, gratifications, indemnités et perdiem (commissions, comités ad-hoc ou permanents)', principal: $L64 * 0.15, cac: $L64 * 0.15 * 0.10),
                $this->line('L65', 'Rémunérations des artistes et sportifs',                   principal: $L65 * 0.15, cac: $L65 * 0.15 * 0.10),
                $this->line('L66', 'TOTAL = L63 + L64 + L65',                                  principal: $L66 * 0.15, cac: $cacOnL66),
            ],
            'total' => $L66 * 0.15 + $cacOnL66,
        ];

        // §12 RTS — sources per user spec (2026-05-13, revised):
        //   L67 IRPP s/ traitements et salaires       ← 4472XX, lines tagged "IRPP" / "Impôt sur le Revenu des Personnes Physiques"
        //   L68 CFC Part Salariale                    ← 4474XX, lines tagged "CFC" / "Contribution au Crédit Foncier"
        //   L69 CFC Part Patronale                    ← 6413XX (plain sum)
        //   L70 FNE                                   ← 4472XX, lines tagged "FNE" / "Fonds National d'Emploi"
        //   L71 Redevance audiovisuelle (RAV)         ← 4472XX, lines tagged "RAV" / "Redevance Audio Visuel"
        //   L72 Taxe de Développement Local (TDL)     ← 4472XX, lines tagged "TDL" / "Taxe de Développement Local"
        // All five 4472-based buckets share a single Odoo round-trip
        // (autoExtract uses bucketLinesByDescription); the values
        // arrive here already separated.
        $L67 = $L('L67');
        $L68 = $L('L68');
        $L69 = $L('L69');
        $L70 = $L('L70');
        $L71 = $L('L71');
        $L72 = $L('L72');
        $L73 = $L67 + $L68 + $L69 + $L70 + $L71 + $L72;
        $cacOnL73 = $L73 * 0.10;
        $s12 = [
            'id' => 'rts', 'code' => '12', 'title' => 'IMPÔTS RETENUS SUR SALAIRES',
            'subtitle' => 'Charges sociales et taxes salariales',
            // Form §12 columns: Principal · CAC · Pénalités · Total — no
            // Base/Taux. Salary retentions carry only a principal amount
            // (the taux is implicit in payroll calculations upstream);
            // CAC applies on IRPP (L67) only.
            'layout' => 'tax_due',
            'lines' => [
                $this->line('L67', 'IRPP sur traitements et salaires',           principal: $L67, cac: $L67 * 0.10),
                $this->line('L68', 'Crédit Foncier du Cameroun (part salariale)', principal: $L68),
                $this->line('L69', 'Crédit Foncier du Cameroun (part patronale)', principal: $L69),
                $this->line('L70', "Fonds National de l'Emploi",                  principal: $L70),
                $this->line('L71', 'Redevance audiovisuelle',                     principal: $L71),
                $this->line('L72', 'Taxe de Développement Local (taxe communale)', principal: $L72),
                $this->line('L73', 'TOTAL = L67 + L68 + L69 + L70 + L71 + L72',   principal: $L73, cac: $cacOnL73),
            ],
            'total' => $L73 + $cacOnL73,
        ];

        // §13 PV
        $L74 = 0; $L75 = 0; $L76 = 0; $L77 = 0;
        $s13 = [
            'id' => 'pv', 'code' => '13', 'title' => 'IMPÔTS SUR LES PLUS-VALUES',
            'subtitle' => "En cas de moins-values, pas d'imposition",
            'lines' => [
                $this->line('L74', "Cession d'immobilisations",                  principal: $L74),
                $this->line('L75', 'Cession de titres négociables',               principal: $L75),
                $this->line('L76', 'Plus-value immobilière par les particuliers', principal: $L76),
                $this->line('L77', 'TOTAL plus-values',                            principal: $L77),
            ],
            'total' => $L77,
        ];

        // §14 Timbre d'aéroport
        $L78 = 0; $L79 = 0;
        $L80 = $L78 + $L79;
        $s14 = [
            'id' => 'timbre', 'code' => '14', 'title' => "DROITS DE TIMBRE D'AÉROPORT",
            'subtitle' => 'Vols internationaux : 10 000 F · Vols nationaux : 1 000 F',
            'lines' => [
                $this->line('L78', 'Vols internationaux',  principal: $L78, rate: '10 000 F'),
                $this->line('L79', 'Vols nationaux',       principal: $L79, rate: '1 000 F'),
                $this->line('L80', 'TOTAL = L78 + L79',    principal: $L80),
            ],
            'total' => $L80,
        ];

        return [$s0, $s1, $s2, $s3, $s4, $s5, $s6, $s7, $s8, $s9, $s10, $s11, $s12, $s13, $s14];
    }

    /**
     * Build the TOTAL RÉCAPITULATIF — single Total column per category, with
     * each row's value sourced from the specific reference line on the form
     * (not summed across the section). Per user spec: drop Principal / CAC /
     * Pénalités columns; just disclose the balance at the referenced L-code.
     */
    private function buildRecap(array $sections): array
    {
        // Index sections by id for O(1) lookup.
        $byId = [];
        foreach ($sections as $s) $byId[$s['id']] = $s;

        // Helper: locate a single line in a section by code.
        $find = function (string $sectionId, string $lineCode) use ($byId) {
            $s = $byId[$sectionId] ?? null;
            if ($s === null) return null;
            foreach ($s['lines'] ?? [] as $ln) {
                if (($ln['code'] ?? '') === $lineCode) return $ln;
            }
            return null;
        };

        // Each recap row maps to a single reference line on the form.
        // The displayed Total = that line's `total` field.
        $defs = [
            ['key' => 'tsr',     'label' => 'TSR (= L0)',                                  'section' => 'tsr',       'line' => 'L0'],
            ['key' => 'da',      'label' => "Droit d'accises (= L8 ou L9)",                'section' => 'da',        'line' => 'L8'],
            ['key' => 'tva',     'label' => 'TVA (= L39)',                                 'section' => 'tva_total', 'line' => 'L39'],
            ['key' => 'ac_ir',   'label' => 'AC / IR + Précomptes (= L44)',                'section' => 'ir_rev',    'line' => 'L44'],
            ['key' => 'acompte', 'label' => 'Acompte à payer (= L54)',                     'section' => 'ir_liq',    'line' => 'L54'],
            ['key' => 'ircm',    'label' => 'IRCM (= L62)',                                'section' => 'ircm',      'line' => 'L62'],
            ['key' => 'irncs',   'label' => 'Impôts sur revenus non commerciaux (= L66)',  'section' => 'irncs',     'line' => 'L66'],
            ['key' => 'rts',     'label' => 'RTS / salaires (= L73)',                      'section' => 'rts',       'line' => 'L73'],
            ['key' => 'pv',      'label' => 'Impôts sur la plus-value (= L77)',            'section' => 'pv',        'line' => 'L77'],
            ['key' => 'timbre',  'label' => "Droit de timbre d'aéroport (= L80)",          'section' => 'timbre',    'line' => 'L80'],
        ];

        $rows = [];
        $grandTotal = 0.0;
        foreach ($defs as $d) {
            $line = $find($d['section'], $d['line']);
            $val  = $line ? (float) ($line['total'] ?? 0) : 0.0;
            $rows[] = [
                'key'     => $d['key'],
                'label'   => $d['label'],
                'section' => $d['section'],
                'line'    => $d['line'],
                'total'   => round($val, 2),
            ];
            $grandTotal += $val;
        }

        return [
            'rows'  => $rows,
            'total' => round($grandTotal, 2),
        ];
    }

    /* ─── Odoo helpers ──────────────────────────────────────────────── */

    private function resolveAccountIdsByPrefix(): array
    {
        // Prefix list — feeds account.move.line sums in autoExtract().
        // The longest-match logic below routes each found account to the
        // most specific prefix bucket.
        //
        // §12 RTS — user spec 2026-05-13 (revised):
        //   4472 hosts FOUR State retentions distinguished only by line
        //   description (IRPP / FNE / RAV / TDL). autoExtract pulls the
        //   4472 line-set once and buckets in PHP via
        //   bucketLinesByDescription() — no extra round-trips.
        //   4474 hosts CFC Part Salariale (description-filtered for
        //   safety).
        //   6413 hosts CFC Part Patronale (plain sum).
        //
        // The old 4477 prefix (FNE) is removed — user moved FNE to 4472.
        // The earlier '64' (class-wide Impôts et taxes) prefix is also
        // removed in favour of the narrower 6413.
        $prefixes = [
            '4421', '4423', '4426', '4431', '4434',
            '4452', '4454', '4456', '4471',
            '4472',  // L67 IRPP · L70 FNE · L71 RAV · L72 TDL (description-bucketed)
            '4474',  // L68 — CFC Part Salariale
            '6413',  // L69 — CFC Part Patronale  → 64 Impôts et taxes (specific sub-prefix)
        ];

        $domain = [];
        for ($i = 1; $i < count($prefixes); $i++) $domain[] = '|';
        foreach ($prefixes as $p) $domain[] = ['code', '=like', $p . '%'];

        $rows = [];
        try { $rows = $this->odoo->searchRead('account.account', $domain, ['id', 'code'], []); }
        catch (\Throwable $e) { /* return empty buckets */ }

        $byPrefix = [];
        foreach ($prefixes as $p) $byPrefix[$p] = [];
        foreach ($rows as $r) {
            $code = (string) ($r['code'] ?? '');
            if ($code === '') continue;
            $matched = null;
            foreach ($prefixes as $p) {
                if (str_starts_with($code, $p)) {
                    if ($matched === null || strlen($p) > strlen($matched)) $matched = $p;
                }
            }
            if ($matched !== null) $byPrefix[$matched][] = (int) $r['id'];
        }
        return $byPrefix;
    }

    private function classSalesAccountIds(): array
    {
        try { $rows = $this->odoo->searchRead('account.account', [['code', '=like', '70%']], ['id', 'code'], []); }
        catch (\Throwable $e) { return []; }
        $out = [];
        foreach ($rows as $r) {
            $code = (string) ($r['code'] ?? '');
            if ($code === '' || str_starts_with($code, '709')) continue;
            $out[] = (int) $r['id'];
        }
        return $out;
    }

    private function sumBalanceWindow(array $accountIds, \DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        if ($accountIds === []) return 0.0;
        try {
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
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /**
     * Account 4472XX is a single SYSCOHADA bucket that the user's COA
     * uses for multiple State retentions (IRPP, FNE, RAV, TDL — same
     * account, distinguished only by the line description). To avoid
     * 4 separate Odoo round-trips on the same dataset, we fetch the
     * 4472XX lines ONCE for the period and bucket them by description
     * keyword. Returns an associative array keyed by bucket label, with
     * the absolute balance sum per bucket.
     *
     * Matching is:
     *   - case-insensitive
     *   - accent-fold via mb_convert_encoding + iconv (best-effort —
     *     no-op if iconv is unavailable)
     *   - substring against `name` (account.move.line.name) — Odoo's
     *     line label, which is what shows up in the journal entry detail
     *   - first-match-wins in $bucketKeywords iteration order, so the
     *     caller can prioritise specific buckets over generic ones
     *
     * Unmatched lines are dropped (not double-counted, not surfaced).
     *
     * @param array<string, array<int, string>> $bucketKeywords  e.g. ['irpp' => ['IRPP', 'impôt sur le revenu'], 'fne' => ['FNE', "Fonds National d'Emploi"]]
     * @return array<string, float>
     */
    private function bucketLinesByDescription(
        array $accountIds,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array $bucketKeywords
    ): array {
        $buckets = array_fill_keys(array_keys($bucketKeywords), 0.0);
        if ($accountIds === []) return $buckets;

        // Pre-fold every keyword to lowercase, accent-stripped form so the
        // match loop runs comparable strings (one fold pass at setup, not
        // per-row).
        $foldedKw = [];
        foreach ($bucketKeywords as $bucket => $kws) {
            $foldedKw[$bucket] = array_filter(array_map(
                fn ($k) => $this->foldText((string) $k),
                $kws
            ));
        }

        try {
            $rows = $this->odoo->searchRead('account.move.line', [
                ['account_id', 'in', $accountIds],
                ['date', '>=', $from->format('Y-m-d')],
                ['date', '<=', $to->format('Y-m-d')],
                ['parent_state', '=', 'posted'],
            ], ['name', 'balance'], []);
        } catch (\Throwable $e) {
            return $buckets;
        }

        foreach ($rows as $r) {
            $name = $this->foldText((string) ($r['name'] ?? ''));
            if ($name === '') continue;
            foreach ($foldedKw as $bucket => $kws) {
                foreach ($kws as $kw) {
                    if (str_contains($name, $kw)) {
                        $buckets[$bucket] += (float) ($r['balance'] ?? 0);
                        continue 3; // first-match-wins: skip remaining buckets for this row
                    }
                }
            }
            // No bucket matched — line is dropped. Diagnostic surfacing
            // could be added later if the user wants to see uncategorised
            // 4472 movements.
        }

        // Buckets are absolute amounts (the underlying balance is
        // negative for credit-normal liability accounts).
        foreach ($buckets as $k => $v) $buckets[$k] = abs($v);
        return $buckets;
    }

    /**
     * Normalise a string for substring matching: lowercase + accent fold.
     * "Impôt" → "impot", "Crédit Foncier" → "credit foncier", etc.
     * iconv with TRANSLIT may be unavailable on some PHP builds — falls
     * back to a plain mb_strtolower in that case so matching still works
     * on accent-free keywords.
     */
    private function foldText(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        if (function_exists('iconv')) {
            $folded = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if (is_string($folded) && $folded !== '') return $folded;
        }
        return $s;
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

    private function buildMonthOptions(\DateTimeImmutable $today): array
    {
        $opts = [];
        for ($i = 0; $i < 12; $i++) {
            $d = $today->modify("first day of -{$i} month");
            $opts[] = ['value' => $d->format('Y-m'), 'label' => $d->format('F Y')];
        }
        return $opts;
    }

    private function moisFr(int $m): string
    {
        $names = [1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre'];
        return $names[$m] ?? '';
    }
}
