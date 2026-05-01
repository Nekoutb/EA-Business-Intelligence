<?php
declare(strict_types=1);

namespace App\Services;

/**
 * SYSCOHADA — Trial-Balance to Financial-Statement mapping.
 *
 * Each entry maps a SYSCOHADA account-code prefix to a financial-
 * statement line code per the OHADA "Système Normal" templates
 * (AUDCIF AT, BILAN, COMPTE DE RÉSULTAT, TAFIRE).
 *
 * Mapping reference: standard SYSCOHADA chart-of-accounts → OHADA
 * Système Normal financial statement layout. The user has indicated
 * pages 968-997 + 1049-1050 of the OHADA framework reference document
 * carry the canonical templates and per-line mapping; the current
 * service uses the well-known general SYSCOHADA conventions.
 * If/when the customer shares the actual document pages, this table
 * is the single point of update — every downstream service (Bilan,
 * Compte de Résultat, TAFIRE) reads from here.
 *
 * Direction:
 *   'asset'           = debit-normal balance-sheet line
 *   'liability'       = credit-normal balance-sheet line
 *   'split_by_sign'   = balance-sheet line whose direction depends on
 *                       sign (Class 4 tiers, Class 5 cash overdraft)
 *   'income'          = credit-normal P&L line (revenue)
 *   'expense'         = debit-normal P&L line (cost)
 *
 * For balance-sheet accounts, we use the CLOSING balance from the
 * Trial Balance. For P&L accounts we use the period MOVEMENT (the
 * trial balance's movement_dr − movement_cr).
 */
final class OhadaMappingService
{
    /**
     * Bilan ACTIF — fixed asset section codes (AA..AM in AUDCIF
     * Système Normal). Each entry: 'codes' = list of SYSCOHADA prefixes.
     */
    public const BILAN_ACTIF_IMMOBILISE = [
        'AA' => ['label' => 'Charges immobilisées',                   'codes' => ['201', '202']],
        'AC' => ['label' => 'Brevets, licences, logiciels',           'codes' => ['211', '212', '213']],
        'AD' => ['label' => 'Fonds commercial',                        'codes' => ['215']],
        'AE' => ['label' => 'Autres immobilisations incorporelles',   'codes' => ['214', '216', '217', '218']],
        'AF' => ['label' => 'Terrains',                                'codes' => ['22']],
        'AG' => ['label' => 'Bâtiments, installations techniques',     'codes' => ['231', '232', '233']],
        'AH' => ['label' => 'Aménagements, agencements',               'codes' => ['234', '235', '237', '238']],
        'AI' => ['label' => 'Matériel & mobilier',                     'codes' => ['244', '246', '248']],
        'AJ' => ['label' => 'Matériel de transport',                   'codes' => ['245']],
        'AK' => ['label' => 'Avances & acomptes sur immo.',            'codes' => ['251', '252']],
        'AL' => ['label' => 'Titres de participation',                 'codes' => ['26']],
        'AM' => ['label' => 'Autres immobilisations financières',      'codes' => ['27', '28', '29']],
    ];

    public const BILAN_ACTIF_CIRCULANT = [
        'BA' => ['label' => 'Actif circulant HAO',                     'codes' => ['485', '486']],
        'BB' => ['label' => 'Stocks et en-cours',                       'codes' => ['3']],
        'BH' => ['label' => 'Fournisseurs, avances versées',           'codes' => ['409']],
        'BI' => ['label' => 'Clients',                                  'codes' => ['411', '412', '413', '414', '415', '416', '417', '418'], 'sign' => 'debit'],
        'BJ' => ['label' => 'Autres créances',                          'codes' => ['42', '43', '44', '45', '46', '47', '48'],            'sign' => 'debit'],
    ];

    public const BILAN_TRESORERIE_ACTIF = [
        'BQ' => ['label' => 'Titres de placement',                      'codes' => ['50']],
        'BR' => ['label' => 'Valeurs à encaisser',                      'codes' => ['51']],
        'BS' => ['label' => 'Banques, chèques postaux, caisse',         'codes' => ['52', '53', '54', '55', '56', '57', '58'], 'sign' => 'debit'],
    ];

    /**
     * Bilan PASSIF — equity & liability section.
     */
    public const BILAN_CAPITAUX_PROPRES = [
        'CA' => ['label' => 'Capital',                                  'codes' => ['101', '102', '103', '104']],
        'CB' => ['label' => 'Apporteurs, capital non appelé (-)',       'codes' => ['109']],
        'CD' => ['label' => 'Primes et réserves',                       'codes' => ['105', '11']],
        'CE' => ['label' => 'Écarts de réévaluation',                   'codes' => ['106']],
        'CG' => ['label' => 'Réserves indisponibles',                   'codes' => ['111', '112', '113', '118']],
        'CJ' => ['label' => 'Report à nouveau (±)',                     'codes' => ['12']],
        'CL' => ['label' => 'Résultat net de l\'exercice (±)',          'codes' => ['13']],
        'CN' => ['label' => 'Subventions d\'investissement',             'codes' => ['14']],
        'CP' => ['label' => 'Provisions règlementées',                  'codes' => ['15']],
    ];

    public const BILAN_DETTES_FINANCIERES = [
        'DA' => ['label' => 'Emprunts',                                 'codes' => ['16']],
        'DB' => ['label' => 'Dettes de location-acquisition',            'codes' => ['17']],
        'DC' => ['label' => 'Dettes financières diverses',               'codes' => ['181', '182', '183', '184', '185', '186', '188']],
        'DD' => ['label' => 'Provisions pour risques et charges',       'codes' => ['19']],
    ];

    public const BILAN_PASSIF_CIRCULANT = [
        'DH' => ['label' => 'Dettes circulantes HAO',                   'codes' => ['481', '482', '483', '484']],
        'DI' => ['label' => 'Clients, avances reçues',                  'codes' => ['419']],
        'DJ' => ['label' => 'Fournisseurs d\'exploitation',              'codes' => ['401', '402', '403', '408']],
        'DK' => ['label' => 'Dettes fiscales et sociales',              'codes' => ['42', '43', '44'], 'sign' => 'credit'],
        'DM' => ['label' => 'Autres dettes',                             'codes' => ['45', '46', '47'], 'sign' => 'credit'],
    ];

    public const BILAN_TRESORERIE_PASSIF = [
        'DQ' => ['label' => 'Banques, crédits d\'escompte',              'codes' => ['564']],
        'DR' => ['label' => 'Banques, crédits de trésorerie',            'codes' => ['565']],
        'DS' => ['label' => 'Banques, découverts',                       'codes' => ['561'],          'sign' => 'credit'],
    ];

    /**
     * Compte de Résultat (Income Statement) — Système Normal lines.
     * 'income' = positive on the P&L; 'expense' = negative.
     */
    public const COMPTE_DE_RESULTAT = [
        // ─── Activité d'exploitation ───
        'TA' => ['label' => 'Ventes de marchandises',                       'codes' => ['701'], 'side' => 'income'],
        'RA' => ['label' => 'Achats de marchandises',                       'codes' => ['601'], 'side' => 'expense'],
        'RB' => ['label' => 'Variation de stocks de marchandises',          'codes' => ['6031'], 'side' => 'expense'],
        'TB' => ['label' => 'Ventes de produits fabriqués',                 'codes' => ['702'], 'side' => 'income'],
        'TC' => ['label' => 'Travaux et services vendus',                   'codes' => ['704', '705', '706'], 'side' => 'income'],
        'TD' => ['label' => 'Produits accessoires',                         'codes' => ['707', '708'], 'side' => 'income'],
        'TE' => ['label' => 'Production stockée',                            'codes' => ['73'],  'side' => 'income'],
        'TF' => ['label' => 'Production immobilisée',                        'codes' => ['72'],  'side' => 'income'],
        'TG' => ['label' => 'Subventions d\'exploitation',                   'codes' => ['71'],  'side' => 'income'],
        'TH' => ['label' => 'Autres produits',                                'codes' => ['75'],  'side' => 'income'],
        'TI' => ['label' => 'Transferts de charges d\'exploitation',          'codes' => ['781'], 'side' => 'income'],
        'RC' => ['label' => 'Achats de matières premières',                  'codes' => ['602'], 'side' => 'expense'],
        'RD' => ['label' => 'Variation de stocks matières premières',         'codes' => ['6032'], 'side' => 'expense'],
        'RE' => ['label' => 'Autres achats',                                  'codes' => ['604', '605', '608'], 'side' => 'expense'],
        'RF' => ['label' => 'Variation autres approvisionnements',           'codes' => ['6033'], 'side' => 'expense'],
        'RG' => ['label' => 'Transports',                                     'codes' => ['61'],  'side' => 'expense'],
        'RH' => ['label' => 'Services extérieurs',                            'codes' => ['62', '63'], 'side' => 'expense'],
        'RI' => ['label' => 'Impôts et taxes',                                'codes' => ['64'],  'side' => 'expense'],
        'RJ' => ['label' => 'Autres charges',                                  'codes' => ['65'],  'side' => 'expense'],
        'RK' => ['label' => 'Charges de personnel',                           'codes' => ['66'],  'side' => 'expense'],
        'RL' => ['label' => 'Dotations aux amortissements et provisions',     'codes' => ['68'],  'side' => 'expense'],
        // ─── Activité financière ───
        'TQ' => ['label' => 'Revenus financiers et assimilés',                'codes' => ['77'],  'side' => 'income'],
        'RP' => ['label' => 'Frais financiers et charges assimilées',         'codes' => ['67'],  'side' => 'expense'],
        // ─── Activités HAO ───
        'TT' => ['label' => 'Produits HAO',                                   'codes' => ['82', '84'], 'side' => 'income'],
        'RS' => ['label' => 'Charges HAO',                                    'codes' => ['83', '85'], 'side' => 'expense'],
        'RW' => ['label' => 'Participation des travailleurs',                 'codes' => ['87'],  'side' => 'expense'],
        // ─── Tax ───
        'RZ' => ['label' => 'Impôts sur le résultat',                         'codes' => ['89'],  'side' => 'expense'],
    ];

    /**
     * Subtotal definitions — derived lines, not from accounts directly.
     */
    public const COMPTE_DE_RESULTAT_SUBTOTALS = [
        'XA' => ['label' => 'MARGE COMMERCIALE',         'add' => ['TA'], 'sub' => ['RA', 'RB']],
        'XB' => ['label' => 'CHIFFRE D\'AFFAIRES',        'add' => ['TA', 'TB', 'TC', 'TD'], 'sub' => []],
        'XC' => ['label' => 'VALEUR AJOUTÉE',            'add' => ['TA', 'TB', 'TC', 'TD', 'TE', 'TF', 'TG', 'TH', 'TI'], 'sub' => ['RA', 'RB', 'RC', 'RD', 'RE', 'RF', 'RG', 'RH', 'RI', 'RJ']],
        'XD' => ['label' => 'EXCÉDENT BRUT D\'EXPLOITATION (EBE)', 'from' => 'XC', 'sub' => ['RK']],
        'XE' => ['label' => 'RÉSULTAT D\'EXPLOITATION',  'from' => 'XD', 'sub' => ['RL']],
        'XF' => ['label' => 'RÉSULTAT FINANCIER',        'add' => ['TQ'], 'sub' => ['RP']],
        'XG' => ['label' => 'RÉSULTAT DES ACTIVITÉS ORDINAIRES', 'from' => 'XE', 'add_codes' => ['XF']],
        'XH' => ['label' => 'RÉSULTAT HAO',              'add' => ['TT'], 'sub' => ['RS']],
        'XI' => ['label' => 'RÉSULTAT AVANT IMPÔTS',     'from' => 'XG', 'add_codes' => ['XH'], 'sub' => ['RW']],
        'XJ' => ['label' => 'RÉSULTAT NET',              'from' => 'XI', 'sub' => ['RZ']],
    ];

    /**
     * Resolve account_code → BS or P&L line code. Walks the longest-
     * prefix that matches; returns null if no match.
     *
     * @return ?array{section:string, code:string, label:string, sign:?string, side:?string}
     */
    public function resolveAccount(string $accountCode): ?array
    {
        if ($accountCode === '') return null;

        $sections = [
            'actif_immobilise'   => ['data' => self::BILAN_ACTIF_IMMOBILISE,   'group' => 'bilan_actif'],
            'actif_circulant'    => ['data' => self::BILAN_ACTIF_CIRCULANT,    'group' => 'bilan_actif'],
            'tresorerie_actif'   => ['data' => self::BILAN_TRESORERIE_ACTIF,   'group' => 'bilan_actif'],
            'capitaux_propres'   => ['data' => self::BILAN_CAPITAUX_PROPRES,   'group' => 'bilan_passif'],
            'dettes_financieres' => ['data' => self::BILAN_DETTES_FINANCIERES, 'group' => 'bilan_passif'],
            'passif_circulant'   => ['data' => self::BILAN_PASSIF_CIRCULANT,   'group' => 'bilan_passif'],
            'tresorerie_passif'  => ['data' => self::BILAN_TRESORERIE_PASSIF,  'group' => 'bilan_passif'],
            'compte_resultat'    => ['data' => self::COMPTE_DE_RESULTAT,        'group' => 'p_l'],
        ];

        $bestMatch  = null;
        $bestLength = 0;
        foreach ($sections as $sectionName => $section) {
            foreach ($section['data'] as $code => $entry) {
                foreach ($entry['codes'] as $prefix) {
                    if (str_starts_with($accountCode, $prefix) && strlen($prefix) > $bestLength) {
                        $bestLength = strlen($prefix);
                        $bestMatch = [
                            'section' => $sectionName,
                            'group'   => $section['group'],
                            'code'    => $code,
                            'label'   => $entry['label'],
                            'sign'    => $entry['sign'] ?? null,
                            'side'    => $entry['side'] ?? null,
                        ];
                    }
                }
            }
        }
        return $bestMatch;
    }

    /**
     * For a sign-sensitive line ('debit' or 'credit' filter), tells
     * whether the closing balance qualifies for inclusion. Returns
     * true if the line is sign-agnostic.
     */
    public function signMatches(?string $sign, float $closingBalance): bool
    {
        if ($sign === null)        return true;
        if ($sign === 'debit')     return $closingBalance >= 0;
        if ($sign === 'credit')    return $closingBalance <  0;
        return true;
    }
}
