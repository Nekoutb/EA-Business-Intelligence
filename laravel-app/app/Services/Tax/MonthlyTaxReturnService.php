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
        $totalAPayer = (float) $recap['total']['total'];

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

        $tvaDedCapex    = abs($sum('4452'));   // input VAT — capex
        $tvaDedOpex     = abs($sum('4456'));   // input VAT — opex
        $tvaCreditCarry = abs($sum('4434'));   // last month's L33 carried on account 4434

        $salesIds = $this->classSalesAccountIds();
        $caHt     = abs($this->sumBalanceWindow($salesIds, $from, $to));

        // Note: L28 is NOT auto-extracted — it is computed in §5 as
        // (TVA on L10) + (TVA on L11) + (TVA on L12), per the form.
        // L33 is also NOT auto-extracted — it is computed as max(-L32, 0).
        // The carry from last month's L33 surfaces here as L17.
        return [
            'L10' => $caHt,                      // CA opérations taxables au taux normal
            'L17' => $tvaCreditCarry,            // Report du crédit antérieur = L33 (mois précédent)
            'L18' => $tvaDedCapex + $tvaDedOpex, // TVA déductible / achats + services locaux
            'L67' => abs($sum('4421')),          // IRPP retenu sur traitements et salaires
            'L42' => abs($sum('4426')),          // Précomptes sur loyers (15 %)
            'L0'  => abs($sum('4423')),          // TSR pool
            'L70' => abs($sum('4477')),          // FNE (autres taxes)
        ];
    }

    /* ─── Section builders ──────────────────────────────────────────── */

    private function line(string $code, string $label, float $base = 0, ?string $rate = null, float $principal = 0, float $cac = 0, float $penalites = 0, ?float $total = null): array
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
        $L12 = 0; $L13 = 0; $L14 = 0;
        $L15 = $L10 + $L11 + $L12 + $L13;
        $tvaSurL10 = $L10 * 0.1925;
        $tvaSurL11 = $L11 * 0.1925;
        $tvaSurL12 = 0;     // Specific rates / bases — manual entry would override
        $s2 = [
            'id' => 'tva_ca', 'code' => '2', 'title' => "TAXE SUR LA VALEUR AJOUTÉE (TVA) — Chiffre d'affaires réalisé",
            'subtitle' => 'CA et taxe collectée',
            'lines' => [
                $this->line('L10', 'Opérations taxables au taux normal',                  $L10, '19,25 %', principal: $tvaSurL10),
                $this->line('L11', "Montant du droit d'accises (L8 ou L9)",                $L11, '19,25 %', principal: $tvaSurL11),
                $this->line('L12', 'Autres opérations taxables (taux/bases spécifiques)', $L12),
                $this->line('L13', 'Exportations (taxables au taux zéro)',                $L13, '0 %'),
                $this->line('L14', "Chiffre d'affaires exonéré",                          $L14),
                $this->line('L15', 'CA global hors taxes = L10 + L11 + L12 + L13',         principal: $L15),
            ],
            'total' => $tvaSurL10 + $tvaSurL11,
        ];

        // §3 TVA déductible
        // L17 is the credit carry from last month's L33 (auto-resolved
        // via account 4434). L22 sums L17+L18+L19+L20+L21 and feeds L29.
        $L16 = 0;
        $L17 = $L('L17');
        $L18 = $L('L18');
        $L19 = 0; $L20 = 0; $L21 = 0;
        $L22 = $L17 + $L18 + $L19 + $L20 + $L21;
        $L23 = $L16 > 0 ? $L22 * ($L16 / 100) : 0;
        $s3 = [
            'id' => 'tva_ded', 'code' => '3', 'title' => 'TVA DÉDUCTIBLE',
            'subtitle' => 'Achats, services, prorata',
            'lines' => [
                $this->line('L16', 'Prorata prévisionnel (assujettis partiels) — cf. DSF n−1',       $L16, '%'),
                $this->line('L17', 'Report du crédit antérieur (L33 de la déclaration précédente)',  $L17),
                $this->line('L18', 'TVA déductible sur achats locaux et services locaux',             principal: $L18),
                $this->line('L19', "TVA déductible sur achats à l'étranger",                          $L19),
                $this->line('L20', "TVA déductible sur services et autres rémunérations versés à l'étranger", $L20),
                $this->line('L21', 'Total TVA déductible (intermédiaire)',                            $L21),
                $this->line('L22', 'Total TVA déductible = L17 + L18 + L19 + L20 + L21',              principal: $L22),
                $this->line('L23', 'TVA déductible au prorata prévisionnel = L16 × L22',              principal: $L23),
            ],
            'total' => $L22,
        ];

        // §4 TVA — Régularisations exceptionnelles
        $L24 = 0; $L25 = 0; $L26 = 0; $L27 = 0;
        $s4 = [
            'id' => 'tva_reg', 'code' => '4', 'title' => 'RÉGULARISATIONS EXCEPTIONNELLES',
            'subtitle' => 'TVA à reverser ou à déduire',
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

        // §6 Total TVA à payer — L36 reports L32 from §5; L39 sums L36-L38.
        //   L36 = L32 (clamped to ≥ 0; in credit cases the form leaves L36 blank)
        //   L39 = L36 + L37 + L38
        $L36 = max($L32, 0);
        $L37 = 0; $L38 = 0;
        $L39 = $L36 + $L37 + $L38;
        $cacOnL36 = $L36 * 0.10;
        $s6 = [
            'id' => 'tva_total', 'code' => '6', 'title' => 'TOTAL DE TVA À PAYER',
            'subtitle' => 'Avec CAC et retenues à la source',
            'lines' => [
                $this->line('L36', 'TVA à payer (L32)',                                    principal: $L36, cac: $cacOnL36),
                $this->line('L37', 'TVA retenue à la source (entreprise habilitée)',        principal: $L37),
                $this->line('L38', "TVA retenue sur rémunération versée à l'étranger",      principal: $L38),
                $this->line('L39', 'Montant à payer = L36 + L37 + L38',                     principal: $L39, cac: $cacOnL36),
            ],
            'total' => $L39 + $cacOnL36,
        ];

        // §7 IR — Acomptes / précomptes à reverser
        $L40 = 0; $L41 = 0; $L42 = $L('L42'); $L43 = 0;
        $L44 = $L40 + $L41 + $L42 + $L43;
        $cacOnL44 = $L44 * 0.10;
        $s7 = [
            'id' => 'ir_rev', 'code' => '7', 'title' => 'ACOMPTES ET PRÉCOMPTES À REVERSER',
            'subtitle' => "Retenues effectuées par l'entreprise",
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
        $L45 = 0; $L46 = 0; $L47 = 0; $L48 = 0;
        $L49 = $L45 + $L46 + $L47 + $L48;
        $s8 = [
            'id' => 'ir_ded', 'code' => '8', 'title' => 'ACOMPTES ET PRÉCOMPTES À DÉDUIRE',
            'subtitle' => "Retenues subies par l'entreprise (à imputer)",
            'lines' => [
                $this->line('L45', 'Acompte sur CA retenu à la source',                principal: $L45),
                $this->line('L46', 'Précomptes sur achats (0,5 % / 1 % / 3 % / 5 %)',   principal: $L46),
                $this->line('L47', 'Précomptes 15 % sur loyers (déductible sur IR)',    principal: $L47),
                $this->line('L48', 'Précomptes sur rémunérations et honoraires (5 %)',  principal: $L48),
                $this->line('L49', 'TOTAL = L45 + L46 + L47 + L48',                     principal: $L49),
            ],
            'total' => 0,
        ];

        // §9 IR — Liquidation
        $L50 = 0; $L51 = 0; $L52 = $L49; $L53 = 0;
        $L54 = ($L50 + $L51) - ($L52 + $L53);
        $L55 = $L54 < 0 ? abs($L54) : 0;
        $acompteIR = max($L54, 0);
        $cacOnL54 = $acompteIR * 0.10;
        $s9 = [
            'id' => 'ir_liq', 'code' => '9', 'title' => 'LIQUIDATION ACOMPTES IMPÔTS SUR LE REVENU',
            'subtitle' => 'Acompte mensuel ou crédit à reporter',
            'lines' => [
                $this->line('L50', 'Acompte sur CA déclaré (0,5 % / 2 % / 3 % / 5 %)', principal: $L50, cac: $L50 * 0.10),
                $this->line('L51', 'Acompte de 15 % sur loyers perçus',                principal: $L51, cac: $L51 * 0.10),
                $this->line('L52', 'Déductions à opérer = L49',                        principal: $L52),
                $this->line('L53', 'Crédit antérieur = L55 déclaration précédente',     principal: $L53),
                $this->line('L54', 'Acompte à payer = (L50 + L51) − (L52 + L53)',       principal: $L54, cac: $cacOnL54),
                $this->line('L55', "Crédit d'impôt à reporter (solde négatif L54)",     principal: $L55),
            ],
            'total' => $acompteIR + $cacOnL54,
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

        // §12 RTS
        $L67 = $L('L67'); $L68 = 0; $L69 = 0; $L70 = $L('L70'); $L71 = 0; $L72 = 0;
        $L73 = $L67 + $L68 + $L69 + $L70 + $L71 + $L72;
        $cacOnL73 = $L73 * 0.10;
        $s12 = [
            'id' => 'rts', 'code' => '12', 'title' => 'IMPÔTS RETENUS SUR SALAIRES',
            'subtitle' => 'Charges sociales et taxes salariales',
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

    private function buildRecap(array $sections): array
    {
        $byId = [];
        foreach ($sections as $s) $byId[$s['id']] = $s;

        $rowFromSection = function (array $s) {
            $principal = 0; $cac = 0; $penalites = 0;
            foreach ($s['lines'] as $ln) {
                $code = (string) ($ln['code'] ?? '');
                if (str_starts_with($code, 'L')) {
                    $principal += (float) ($ln['principal'] ?? 0);
                    $cac       += (float) ($ln['cac']       ?? 0);
                    $penalites += (float) ($ln['penalites'] ?? 0);
                }
            }
            return [
                'principal' => round($principal, 2),
                'cac'       => round($cac, 2),
                'penalites' => round($penalites, 2),
                'total'     => round($principal + $cac + $penalites, 2),
            ];
        };

        $rows = [
            ['key' => 'tsr',     'label' => 'TSR (= L0)',                                    'section_id' => 'tsr'],
            ['key' => 'da',      'label' => "Droit d'accises (= L8 ou L9)",                  'section_id' => 'da'],
            ['key' => 'tva',     'label' => 'TVA (= L39)',                                   'section_id' => 'tva_total'],
            ['key' => 'ac_ir',   'label' => 'AC / IR + Précomptes (= L44)',                  'section_id' => 'ir_rev'],
            ['key' => 'acompte', 'label' => 'Acompte à payer (= L54)',                       'section_id' => 'ir_liq'],
            ['key' => 'ircm',    'label' => 'IRCM (= L62)',                                  'section_id' => 'ircm'],
            ['key' => 'irncs',   'label' => 'Impôts sur revenus non commerciaux (= L66)',    'section_id' => 'irncs'],
            ['key' => 'rts',     'label' => 'RTS / salaires (= L73)',                        'section_id' => 'rts'],
            ['key' => 'pv',      'label' => 'Impôts sur la plus-value (= L77)',              'section_id' => 'pv'],
            ['key' => 'timbre',  'label' => "Droit de timbre d'aéroport (= L80)",            'section_id' => 'timbre'],
        ];

        $totals = ['principal' => 0, 'cac' => 0, 'penalites' => 0, 'total' => 0];
        foreach ($rows as &$r) {
            $sId = $r['section_id'];
            $d = isset($byId[$sId]) ? $rowFromSection($byId[$sId]) : ['principal'=>0,'cac'=>0,'penalites'=>0,'total'=>0];
            $r['principal'] = $d['principal'];
            $r['cac']       = $d['cac'];
            $r['penalites'] = $d['penalites'];
            $r['total']     = $d['total'];
            $totals['principal'] += $r['principal'];
            $totals['cac']       += $r['cac'];
            $totals['penalites'] += $r['penalites'];
            $totals['total']     += $r['total'];
        }
        unset($r);

        return [
            'rows'  => $rows,
            'total' => array_map(fn ($v) => round($v, 2), $totals),
        ];
    }

    /* ─── Odoo helpers ──────────────────────────────────────────────── */

    private function resolveAccountIdsByPrefix(): array
    {
        $prefixes = ['4421', '4423', '4426', '4431', '4434', '4452', '4456', '4477'];

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
