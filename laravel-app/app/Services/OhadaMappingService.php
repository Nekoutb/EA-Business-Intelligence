<?php
declare(strict_types=1);

namespace App\Services;

/**
 * SYSCOHADA Système Normal — Trial-Balance to Financial-Statement mapping.
 *
 * Source: AUDCIF / Plan Comptable Général OHADA Révisé,
 * "PRESENTATION DES ETATS FINANCIERS ANNUELS DU SYSTEME NORMAL"
 * pages 980-986 (templates) and 1049-1050 (mapping table).
 *
 * Each financial-statement line is keyed by its OHADA REF code (AD, AE,
 * AF, ... XI, ZA-ZH for the cash flow). The 'codes' list holds the
 * SYSCOHADA account-code prefixes to roll up; 'exclude' removes
 * specific sub-codes; 'sign' restricts inclusion by closing-balance
 * direction (debit / credit) — critical for class-4 tiers and class-5
 * cash, which split between Actif and Passif depending on sign.
 *
 * The 'p' suffix in the OHADA mapping table (e.g. 2811p) means
 * "partial" — those specific sub-codes are split between two BS lines.
 * For practical implementation we treat them inclusively under the
 * primary line (longest-prefix match wins, so the more specific code
 * always claims the account when present).
 */
final class OhadaMappingService
{
    /**
     * BILAN — Actif side per page 1049.
     * AE..AS = Immobilisations; BA..BS = Circulant + Trésorerie.
     */
    public const BILAN_ACTIF = [
        'AE' => ['label' => 'Frais de développement et de prospection',         'codes' => ['211', '2181', '2191']],
        'AF' => ['label' => 'Brevets, licences, logiciels et droits similaires', 'codes' => ['212', '213', '214', '2193']],
        'AG' => ['label' => 'Fonds commercial et droit au bail',                  'codes' => ['215', '216']],
        'AH' => ['label' => 'Autres immobilisations incorporelles',                'codes' => ['217', '218', '2198'],   'exclude' => ['2181']],
        'AJ' => ['label' => 'Terrains',                                            'codes' => ['22']],
        'AK' => ['label' => 'Bâtiments',                                           'codes' => ['231', '232', '233', '237', '2391']],
        'AL' => ['label' => 'Aménagements, agencements et installations',          'codes' => ['234', '235', '238', '2392', '2393']],
        'AM' => ['label' => 'Matériel, mobilier et actifs biologiques',            'codes' => ['24'],                    'exclude' => ['245', '2495']],
        'AN' => ['label' => 'Matériel de transport',                              'codes' => ['245', '2495']],
        'AP' => ['label' => 'Avances et acomptes versés sur immobilisations',     'codes' => ['251', '252']],
        'AR' => ['label' => 'Titres de participation',                             'codes' => ['26']],
        'AS' => ['label' => 'Autres immobilisations financières',                  'codes' => ['27']],
        // ── Circulant ──
        'BA' => ['label' => 'Actif circulant HAO',                                 'codes' => ['485', '488']],
        'BB' => ['label' => 'Stocks et encours',                                    'codes' => ['31', '32', '33', '34', '35', '36', '37', '38']],
        'BH' => ['label' => 'Fournisseurs, avances versées',                       'codes' => ['409']],
        'BI' => ['label' => 'Clients',                                             'codes' => ['41'],  'exclude' => ['419'], 'sign' => 'debit'],
        'BJ' => ['label' => 'Autres créances',                                     'codes' => ['185', '42', '43', '44', '45', '46', '47'], 'exclude' => ['478'], 'sign' => 'debit'],
        // ── Trésorerie Actif ──
        'BQ' => ['label' => 'Titres de placement',                                 'codes' => ['50']],
        'BR' => ['label' => 'Valeurs à encaisser',                                 'codes' => ['51']],
        'BS' => ['label' => 'Banques, chèques postaux, caisse et assimilés',       'codes' => ['52', '53', '54', '55', '57', '581', '582'], 'sign' => 'debit'],
        'BU' => ['label' => 'Ecart de conversion-Actif',                           'codes' => ['478']],
    ];

    /**
     * Section grouping for the Bilan Actif (used by the view to render
     * sub-section banners). Order is the on-page order.
     */
    public const BILAN_ACTIF_SECTIONS = [
        'IMMOBILISATIONS INCORPORELLES' => ['AE', 'AF', 'AG', 'AH'],
        'IMMOBILISATIONS CORPORELLES'   => ['AJ', 'AK', 'AL', 'AM', 'AN'],
        'AVANCES & ACOMPTES'            => ['AP'],
        'IMMOBILISATIONS FINANCIERES'   => ['AR', 'AS'],
        'ACTIF CIRCULANT'               => ['BA', 'BB', 'BH', 'BI', 'BJ'],
        'TRESORERIE-ACTIF'              => ['BQ', 'BR', 'BS'],
        'ECART DE CONVERSION'           => ['BU'],
    ];

    /**
     * BILAN — Passif side per page 1049.
     */
    public const BILAN_PASSIF = [
        'CA' => ['label' => 'Capital',                                             'codes' => ['101', '102', '103', '104']],
        'CB' => ['label' => 'Apporteurs capital non appelé (-)',                   'codes' => ['109']],
        'CD' => ['label' => 'Primes liées au capital social',                      'codes' => ['105']],
        'CE' => ['label' => 'Ecarts de réévaluation',                              'codes' => ['106']],
        'CF' => ['label' => 'Réserves indisponibles',                              'codes' => ['111', '112', '113']],
        'CG' => ['label' => 'Réserves libres',                                     'codes' => ['118']],
        'CH' => ['label' => 'Report à nouveau (+ ou -)',                           'codes' => ['12']],
        'CJ' => ['label' => 'Résultat net de l\'exercice (bénéfice + ou perte -)', 'codes' => ['13']],
        'CL' => ['label' => 'Subventions d\'investissement',                       'codes' => ['14']],
        'CM' => ['label' => 'Provisions règlementées',                             'codes' => ['15']],
        // ── Dettes Financières ──
        'DA' => ['label' => 'Emprunts et dettes financières diverses',             'codes' => ['16', '181', '182', '183', '184']],
        'DB' => ['label' => 'Dettes de location acquisition',                      'codes' => ['17']],
        'DC' => ['label' => 'Provisions pour risques et charges',                  'codes' => ['19']],
        // ── Passif Circulant ──
        'DH' => ['label' => 'Dettes circulantes HAO',                              'codes' => ['481', '482', '484', '4998']],
        'DI' => ['label' => 'Clients, avances reçues',                             'codes' => ['419']],
        'DJ' => ['label' => 'Fournisseurs d\'exploitation',                        'codes' => ['40'],  'exclude' => ['409'], 'sign' => 'credit'],
        'DK' => ['label' => 'Dettes fiscales et sociales',                         'codes' => ['42', '43', '44'], 'sign' => 'credit'],
        'DM' => ['label' => 'Autres dettes',                                       'codes' => ['185', '45', '46', '47'], 'exclude' => ['479'], 'sign' => 'credit'],
        'DN' => ['label' => 'Provisions pour risques à court terme',               'codes' => ['499', '599'], 'exclude' => ['4998']],
        // ── Trésorerie Passif ──
        'DQ' => ['label' => 'Banques, crédits d\'escompte',                        'codes' => ['564', '565']],
        'DR' => ['label' => 'Banques, établissements financiers et crédits de trésorerie', 'codes' => ['52', '53', '561', '566'], 'sign' => 'credit'],
        'DV' => ['label' => 'Ecart de conversion-Passif',                          'codes' => ['479']],
    ];

    public const BILAN_PASSIF_SECTIONS = [
        'CAPITAUX PROPRES & RESSOURCES ASSIMILEES' => ['CA', 'CB', 'CD', 'CE', 'CF', 'CG', 'CH', 'CJ', 'CL', 'CM'],
        'DETTES FINANCIERES & RESSOURCES ASSIMILEES' => ['DA', 'DB', 'DC'],
        'PASSIF CIRCULANT'                            => ['DH', 'DI', 'DJ', 'DK', 'DM', 'DN'],
        'TRESORERIE-PASSIF'                           => ['DQ', 'DR'],
        'ECART DE CONVERSION'                         => ['DV'],
    ];

    /**
     * COMPTE DE RESULTAT per page 1050.
     */
    public const COMPTE_DE_RESULTAT = [
        'TA' => ['label' => 'Ventes de marchandises',                                       'codes' => ['701'], 'side' => 'income'],
        'RA' => ['label' => 'Achats de marchandises',                                       'codes' => ['601'], 'side' => 'expense'],
        'RB' => ['label' => 'Variation de stocks de marchandises',                          'codes' => ['6031'], 'side' => 'expense'],
        'TB' => ['label' => 'Ventes de produits fabriqués',                                 'codes' => ['702', '703', '704'], 'side' => 'income'],
        'TC' => ['label' => 'Travaux, services vendus',                                     'codes' => ['705', '706'], 'side' => 'income'],
        'TD' => ['label' => 'Produits accessoires',                                         'codes' => ['707'], 'side' => 'income'],
        'TE' => ['label' => 'Production stockée (ou déstockage)',                            'codes' => ['73'],  'side' => 'income'],
        'TF' => ['label' => 'Production immobilisée',                                        'codes' => ['72'],  'side' => 'income'],
        'TG' => ['label' => 'Subventions d\'exploitation',                                   'codes' => ['71'],  'side' => 'income'],
        'TH' => ['label' => 'Autres produits',                                                'codes' => ['75'],  'side' => 'income'],
        'TI' => ['label' => 'Transferts de charges d\'exploitation',                         'codes' => ['781'], 'side' => 'income'],
        'RC' => ['label' => 'Achats de matières premières et fournitures liées',             'codes' => ['602'], 'side' => 'expense'],
        'RD' => ['label' => 'Variation de stocks de matières premières et fournitures liées','codes' => ['6032'], 'side' => 'expense'],
        'RE' => ['label' => 'Autres achats',                                                  'codes' => ['604', '605', '608'], 'side' => 'expense'],
        'RF' => ['label' => 'Variation de stocks d\'autres approvisionnements',              'codes' => ['6033'], 'side' => 'expense'],
        'RG' => ['label' => 'Transports',                                                    'codes' => ['61'],  'side' => 'expense'],
        'RH' => ['label' => 'Services extérieurs',                                            'codes' => ['62', '63'], 'side' => 'expense'],
        'RI' => ['label' => 'Impôts et taxes',                                               'codes' => ['64'],  'side' => 'expense'],
        'RJ' => ['label' => 'Autres charges',                                                 'codes' => ['65'],  'side' => 'expense'],
        'RK' => ['label' => 'Charges de personnel',                                          'codes' => ['66'],  'side' => 'expense'],
        'TJ' => ['label' => 'Reprises d\'amortissements, de provisions et dépréciations',    'codes' => ['791', '798', '799'], 'side' => 'income'],
        'RL' => ['label' => 'Dotations aux amortissements, aux provisions et dépréciations', 'codes' => ['681', '691'], 'side' => 'expense'],
        'TK' => ['label' => 'Revenus financiers et assimilés',                               'codes' => ['77'],  'side' => 'income'],
        'TL' => ['label' => 'Reprises de provisions et dépréciations financières',           'codes' => ['797'], 'side' => 'income'],
        'TM' => ['label' => 'Transferts de charges financières',                              'codes' => ['787'], 'side' => 'income'],
        'RM' => ['label' => 'Frais financiers et charges assimilés',                          'codes' => ['67'],  'side' => 'expense'],
        'RN' => ['label' => 'Dotations aux provisions et aux dépréciations financières',     'codes' => ['697'], 'side' => 'expense'],
        'TN' => ['label' => 'Produits des cessions d\'immobilisations',                       'codes' => ['82'],  'side' => 'income'],
        'TO' => ['label' => 'Autres Produits HAO',                                            'codes' => ['84', '86', '88'], 'side' => 'income'],
        'RO' => ['label' => 'Valeurs comptables des cessions d\'immobilisations',            'codes' => ['81'],  'side' => 'expense'],
        'RP' => ['label' => 'Autres Charges HAO',                                             'codes' => ['83', '85'], 'side' => 'expense'],
        'RQ' => ['label' => 'Participation des travailleurs',                                 'codes' => ['87'],  'side' => 'expense'],
        'RS' => ['label' => 'Impôts sur le résultat',                                         'codes' => ['89'],  'side' => 'expense'],
    ];

    /**
     * Subtotal definitions — derived lines computed from the leaves above.
     * 'add' = leaf line codes to add; 'sub' = leaf line codes to subtract;
     * 'add_sub' = subtotal codes to add (allows chaining).
     */
    public const COMPTE_DE_RESULTAT_SUBTOTALS = [
        'XA' => ['label' => 'MARGE COMMERCIALE',
                 'add' => ['TA'], 'sub' => ['RA', 'RB']],
        'XB' => ['label' => 'CHIFFRE D\'AFFAIRES',
                 'add' => ['TA', 'TB', 'TC', 'TD']],
        'XC' => ['label' => 'VALEUR AJOUTEE',
                 'add' => ['XB', 'TE', 'TF', 'TG', 'TH', 'TI'],
                 'sub' => ['RA', 'RB', 'RC', 'RD', 'RE', 'RF', 'RG', 'RH', 'RI', 'RJ']],
        'XD' => ['label' => 'EXCEDENT BRUT D\'EXPLOITATION (EBE)',
                 'add' => ['XC'], 'sub' => ['RK']],
        'XE' => ['label' => 'RESULTAT D\'EXPLOITATION',
                 'add' => ['XD', 'TJ'], 'sub' => ['RL']],
        'XF' => ['label' => 'RESULTAT FINANCIER',
                 'add' => ['TK', 'TL', 'TM'], 'sub' => ['RM', 'RN']],
        'XG' => ['label' => 'RESULTAT DES ACTIVITES ORDINAIRES',
                 'add' => ['XE', 'XF']],
        'XH' => ['label' => 'RESULTAT HORS ACTIVITES ORDINAIRES',
                 'add' => ['TN', 'TO'], 'sub' => ['RO', 'RP']],
        'XI' => ['label' => 'RESULTAT NET',
                 'add' => ['XG', 'XH'], 'sub' => ['RQ', 'RS']],
    ];

    /**
     * Display order for the Compte de Résultat — interleaves leaves
     * and subtotals exactly as in the Système Normal template
     * (page 986).
     */
    public const COMPTE_DE_RESULTAT_DISPLAY_ORDER = [
        'TA','RA','RB','XA',
        'TB','TC','TD','XB',
        'TE','TF','TG','TH','TI',
        'RC','RD','RE','RF','RG','RH','RI','RJ',
        'XC','RK','XD',
        'TJ','RL','XE',
        'TK','TL','TM','RM','RN','XF',
        'XG',
        'TN','TO','RO','RP','XH',
        'RQ','RS','XI',
    ];

    /**
     * Resolve an account_code to its OHADA financial-statement line
     * given its closing balance (sign-aware).
     *
     * Walks the BS first then the P&L, picking the line with the
     * LONGEST matching prefix that satisfies any 'exclude' / 'sign'
     * constraints. Returns null when no line matches.
     *
     * @return ?array{group:string, section:string, code:string, label:string, side:?string}
     */
    public function resolveAccount(string $accountCode, float $closingBalance = 0.0, float $movement = 0.0): ?array
    {
        $accountCode = trim($accountCode);
        if ($accountCode === '') return null;

        $candidates = [
            ['data' => self::BILAN_ACTIF,         'group' => 'bilan_actif'],
            ['data' => self::BILAN_PASSIF,        'group' => 'bilan_passif'],
            ['data' => self::COMPTE_DE_RESULTAT,  'group' => 'p_l'],
        ];

        $bestMatch  = null;
        $bestLength = 0;

        foreach ($candidates as $bucket) {
            foreach ($bucket['data'] as $code => $entry) {
                // Check exclusions first — a more specific exclusion
                // always wins (longer-prefix exclusions kick the
                // account out of this line).
                $excluded = false;
                foreach (($entry['exclude'] ?? []) as $exclude) {
                    if (str_starts_with($accountCode, $exclude)) {
                        $excluded = true;
                        break;
                    }
                }
                if ($excluded) continue;

                // Find the longest matching include-prefix
                $matchLen = 0;
                foreach ($entry['codes'] as $prefix) {
                    if (str_starts_with($accountCode, $prefix) && strlen($prefix) > $matchLen) {
                        $matchLen = strlen($prefix);
                    }
                }
                if ($matchLen === 0) continue;

                // Sign filter (Class 4 tiers, Class 5 cash split)
                $sign = $entry['sign'] ?? null;
                if ($sign === 'debit'  && $closingBalance < 0)  continue;
                if ($sign === 'credit' && $closingBalance > 0)  continue;

                if ($matchLen > $bestLength) {
                    $bestLength = $matchLen;
                    $bestMatch  = [
                        'group'   => $bucket['group'],
                        'code'    => $code,
                        'label'   => $entry['label'],
                        'side'    => $entry['side'] ?? null,
                    ];
                }
            }
        }

        return $bestMatch;
    }
}
