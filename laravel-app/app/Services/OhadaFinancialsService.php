<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * OHADA Système Normal — Bilan + Compte de Résultat + Tableau des
 * Flux de Trésorerie, built from a Trial Balance snapshot.
 *
 * Layout sources (AUDCIF / Plan Comptable OHADA Révisé):
 *   - Page 980: Bilan Modèle 1 (paysage)
 *   - Pages 981-982: Bilan Modèle 2 (Actif / Passif)
 *   - Page 986: Compte de Résultat
 *   - Page 997: Tableau des Flux de Trésorerie
 *   - Pages 1049-1050: per-line account-code mapping
 *
 * The BS uses closing balances (Trial Balance closing_dr − closing_cr).
 * The Compte de Résultat uses period movements with the income/expense
 * sign convention (income lines flip sign so revenue shows positive).
 * The Cashflow Statement (TFT) uses the OHADA CAFG-based indirect
 * method — see buildTresorerie() below.
 */
final class OhadaFinancialsService
{
    private OdooClient $odoo;
    private OhadaMappingService $mapping;

    public function __construct(OdooClient $odoo, ?OhadaMappingService $mapping = null)
    {
        $this->odoo    = $odoo;
        $this->mapping = $mapping ?? new OhadaMappingService();
    }

    public function analyze(?int $year = null, string $periodType = 'year', ?int $month = null): array
    {
        $tb       = (new TrialBalanceService($this->odoo))->analyze($year, $periodType, $month);
        $rows     = $tb['rows'] ?? [];

        // Bucket TB rows into BS / P&L lines, applying sign-sensitive
        // resolution per the mapping table.
        $byLine    = [];
        $unmapped  = [];

        foreach ($rows as $r) {
            $code   = (string) $r['code'];
            $closing = ((float) $r['closing_dr']) - ((float) $r['closing_cr']);
            $movement = ((float) $r['movement_dr']) - ((float) $r['movement_cr']);

            $resolved = $this->mapping->resolveAccount($code, $closing, $movement);
            if ($resolved === null) { $unmapped[] = $r; continue; }

            $lineCode = $resolved['code'];
            $byLine[$lineCode] ??= [
                'code'     => $lineCode,
                'label'    => $resolved['label'],
                'group'    => $resolved['group'],
                'side'     => $resolved['side'] ?? null,
                'amount'   => 0.0,
                'accounts' => [],
            ];

            // For BS lines, use the absolute closing balance (Bilan
            // shows magnitudes; sign comes from which side the line
            // sits on, not the signed balance).
            // For P&L lines, use period movement with side-flip.
            if ($resolved['group'] === 'p_l') {
                $amount = ($resolved['side'] === 'income') ? -$movement : $movement;
            } else {
                $amount = abs($closing);
            }

            $byLine[$lineCode]['amount'] += $amount;
            $byLine[$lineCode]['accounts'][] = [
                'code'   => $code,
                'name'   => (string) $r['name'],
                'amount' => $amount,
            ];
        }

        // Sort detail accounts within each line by absolute amount desc
        foreach ($byLine as &$line) {
            usort($line['accounts'], fn ($a, $b) => abs($b['amount']) <=> abs($a['amount']));
        }
        unset($line);

        $bilanActif      = $this->buildBilanSection(OhadaMappingService::BILAN_ACTIF_SECTIONS,  OhadaMappingService::BILAN_ACTIF,  $byLine);
        $bilanPassif     = $this->buildBilanSection(OhadaMappingService::BILAN_PASSIF_SECTIONS, OhadaMappingService::BILAN_PASSIF, $byLine);
        $compteDeResultat = $this->buildCompteDeResultat($byLine);
        $tresorerie       = $this->buildTresorerie($byLine, $compteDeResultat['subtotals']);

        $totalActif  = array_sum(array_column($bilanActif['lines'], 'amount'));
        $totalPassif = array_sum(array_column($bilanPassif['lines'], 'amount'));

        return [
            'currency'         => 'XAF',
            'year'             => $tb['year'],
            'month'            => $tb['month'],
            'period_type'      => $tb['period_type'],
            'period_label'     => $tb['period_label'],
            'period_start'     => $tb['period_start'],
            'period_end'       => $tb['period_end'],
            'bilan_actif'      => $bilanActif,
            'bilan_passif'     => $bilanPassif,
            'compte_de_resultat' => $compteDeResultat,
            'tresorerie'       => $tresorerie,
            'totals' => [
                'actif'   => $totalActif,
                'passif'  => $totalPassif,
                'balance' => abs($totalActif - $totalPassif) < 1.0,
            ],
            'unmapped_accounts' => array_slice($unmapped, 0, 50),
            'unmapped_count'    => count($unmapped),
            'fetched_at'        => now()->toIso8601String(),
        ];
    }

    private function buildBilanSection(array $sectionGroups, array $defs, array $byLine): array
    {
        $output = ['sections' => [], 'lines' => []];
        foreach ($sectionGroups as $title => $codes) {
            $sectionLines = [];
            $sectionTotal = 0.0;
            foreach ($codes as $code) {
                $def  = $defs[$code] ?? null;
                if ($def === null) continue;
                $line = $byLine[$code] ?? ['code' => $code, 'label' => $def['label'], 'amount' => 0.0, 'accounts' => []];
                $line['code']  = $code;
                $line['label'] = $def['label'];
                $sectionLines[] = $line;
                $sectionTotal  += (float) $line['amount'];
                $output['lines'][] = $line;
            }
            $output['sections'][] = [
                'title' => $title,
                'lines' => $sectionLines,
                'total' => $sectionTotal,
            ];
        }
        return $output;
    }

    private function buildCompteDeResultat(array $byLine): array
    {
        $leaves = [];
        foreach (OhadaMappingService::COMPTE_DE_RESULTAT as $code => $def) {
            $line = $byLine[$code] ?? null;
            $leaves[$code] = [
                'code'     => $code,
                'label'    => $def['label'],
                'amount'   => $line['amount'] ?? 0.0,
                'side'     => $def['side'],
                'accounts' => $line['accounts'] ?? [],
            ];
        }

        // Compute subtotals — order matters because some subtotals
        // reference earlier ones (XG = XE + XF, XI = XG + XH − RQ − RS).
        $subtotals = [];
        foreach (OhadaMappingService::COMPTE_DE_RESULTAT_SUBTOTALS as $code => $spec) {
            $val = 0.0;
            foreach (($spec['add'] ?? []) as $addCode) {
                if (isset($subtotals[$addCode])) $val += $subtotals[$addCode]['amount'];
                elseif (isset($leaves[$addCode])) $val += $leaves[$addCode]['amount'];
            }
            foreach (($spec['sub'] ?? []) as $subCode) {
                if (isset($subtotals[$subCode])) $val -= $subtotals[$subCode]['amount'];
                elseif (isset($leaves[$subCode])) $val -= $leaves[$subCode]['amount'];
            }
            $subtotals[$code] = ['code' => $code, 'label' => $spec['label'], 'amount' => $val];
        }

        $rows = [];
        foreach (OhadaMappingService::COMPTE_DE_RESULTAT_DISPLAY_ORDER as $code) {
            if (isset($subtotals[$code])) {
                $rows[] = ['code' => $code, 'label' => $subtotals[$code]['label'], 'amount' => $subtotals[$code]['amount'], 'kind' => 'subtotal'];
            } elseif (isset($leaves[$code])) {
                $rows[] = [
                    'code'     => $code,
                    'label'    => $leaves[$code]['label'],
                    'amount'   => $leaves[$code]['amount'],
                    'side'     => $leaves[$code]['side'],
                    'kind'     => 'leaf',
                    'accounts' => $leaves[$code]['accounts'],
                ];
            }
        }

        return ['rows' => $rows, 'leaves' => $leaves, 'subtotals' => $subtotals];
    }

    /**
     * Tableau des Flux de Trésorerie per page 997.
     *
     * V1 implementation — uses CAFG calculation from the income statement
     * subtotals (EBE + adjustments) rather than year-over-year balance
     * changes. Working-capital movements (FB-FE), investing flows (FF-FJ)
     * and financing flows (FK-FQ) are placeholder-zero in this version
     * because they require year-over-year balance comparison which the
     * current single-period TB doesn't provide.
     *
     * Refinement path: pull TB for both N and N-1 (already supported by
     * TrialBalanceService — call analyze(year-1, 'year')) and compute
     * variations directly. This is a follow-up enhancement; the structure
     * is in place so the next pass only needs to populate the variation
     * lines.
     */
    private function buildTresorerie(array $byLine, array $cdrSubtotals): array
    {
        // CAFG = EBE + (revenus financiers + transferts ch fin + produits HAO + transferts ch HAO)
        //        - (frais financiers + participation + impôt s/résultat)
        //        + (valeurs comptables cessions courantes - produits cessions courantes)
        //
        // For V1 we approximate from the CdR subtotals:
        //   CAFG ≈ XD (EBE) + TK (Revenus financiers) - RM (Frais financiers)
        //          + TO (Produits HAO) - RP (Charges HAO) - RQ (Participation) - RS (Impôt)
        $ebe         = $cdrSubtotals['XD']['amount'] ?? 0.0;
        $finRev      = $byLine['TK']['amount'] ?? 0.0;
        $finExp      = $byLine['RM']['amount'] ?? 0.0;
        $hAoProd     = ($byLine['TN']['amount'] ?? 0.0) + ($byLine['TO']['amount'] ?? 0.0);
        $hAoCh       = ($byLine['RO']['amount'] ?? 0.0) + ($byLine['RP']['amount'] ?? 0.0);
        $particip    = $byLine['RQ']['amount'] ?? 0.0;
        $tax         = $byLine['RS']['amount'] ?? 0.0;

        $cafg = $ebe + $finRev - $finExp + $hAoProd - $hAoCh - $particip - $tax;

        // For the variation lines and investing/financing flows, V1 returns
        // zeros — the next iteration will pull prior-year TB and diff.
        $rows = [
            ['code' => 'ZA', 'label' => 'Trésorerie nette au 1er janvier', 'amount' => null, 'kind' => 'header'],
            ['code' => '',   'label' => 'Flux de trésorerie provenant des activités opérationnelles', 'amount' => null, 'kind' => 'group'],
            ['code' => 'FA', 'label' => 'Capacité d\'Autofinancement Globale (CAFG)', 'amount' => $cafg, 'kind' => 'leaf'],
            ['code' => 'FB', 'label' => '− Variation Actif circulant HAO',                  'amount' => 0.0, 'kind' => 'leaf'],
            ['code' => 'FC', 'label' => '− Variation des stocks',                            'amount' => 0.0, 'kind' => 'leaf'],
            ['code' => 'FD', 'label' => '− Variation des créances',                          'amount' => 0.0, 'kind' => 'leaf'],
            ['code' => 'FE', 'label' => '+ Variation du passif circulant',                   'amount' => 0.0, 'kind' => 'leaf'],
            ['code' => 'ZB', 'label' => 'Flux de trésorerie provenant des activités opérationnelles', 'amount' => $cafg, 'kind' => 'subtotal'],
            ['code' => '',   'label' => 'Flux de trésorerie provenant des activités d\'investissements', 'amount' => null, 'kind' => 'group'],
            ['code' => 'FF', 'label' => '− Décaissements liés aux acquisitions d\'immobilisations incorporelles', 'amount' => 0.0, 'kind' => 'leaf'],
            ['code' => 'FG', 'label' => '− Décaissements liés aux acquisitions d\'immobilisations corporelles',    'amount' => 0.0, 'kind' => 'leaf'],
            ['code' => 'FH', 'label' => '− Décaissements liés aux acquisitions d\'immobilisations financières',    'amount' => 0.0, 'kind' => 'leaf'],
            ['code' => 'FI', 'label' => '+ Encaissements liés aux cessions d\'immobilisations incorporelles et corporelles', 'amount' => 0.0, 'kind' => 'leaf'],
            ['code' => 'FJ', 'label' => '+ Encaissements liés aux cessions d\'immobilisations financières',         'amount' => 0.0, 'kind' => 'leaf'],
            ['code' => 'ZC', 'label' => 'Flux de trésorerie provenant des activités d\'investissement',             'amount' => 0.0, 'kind' => 'subtotal'],
            ['code' => '',   'label' => 'Flux de trésorerie provenant du financement par les capitaux propres',     'amount' => null, 'kind' => 'group'],
            ['code' => 'FK', 'label' => '+ Augmentations de capital par apports nouveaux',  'amount' => 0.0, 'kind' => 'leaf'],
            ['code' => 'FL', 'label' => '+ Subventions d\'investissement reçues',           'amount' => 0.0, 'kind' => 'leaf'],
            ['code' => 'FM', 'label' => '− Prélèvements sur le capital',                    'amount' => 0.0, 'kind' => 'leaf'],
            ['code' => 'FN', 'label' => '− Dividendes versés',                              'amount' => 0.0, 'kind' => 'leaf'],
            ['code' => 'ZD', 'label' => 'Flux de trésorerie provenant des capitaux propres', 'amount' => 0.0, 'kind' => 'subtotal'],
            ['code' => '',   'label' => 'Trésorerie provenant du financement par les capitaux étrangers', 'amount' => null, 'kind' => 'group'],
            ['code' => 'FO', 'label' => '+ Emprunts',                                        'amount' => 0.0, 'kind' => 'leaf'],
            ['code' => 'FP', 'label' => '+ Autres dettes financières',                      'amount' => 0.0, 'kind' => 'leaf'],
            ['code' => 'FQ', 'label' => '− Remboursements des emprunts et autres dettes financières', 'amount' => 0.0, 'kind' => 'leaf'],
            ['code' => 'ZE', 'label' => 'Flux de trésorerie provenant des capitaux étrangers',         'amount' => 0.0, 'kind' => 'subtotal'],
            ['code' => 'ZF', 'label' => 'Flux de trésorerie provenant des activités de financement (D + E)', 'amount' => 0.0, 'kind' => 'subtotal'],
            ['code' => 'ZG', 'label' => 'VARIATION DE LA TRÉSORERIE NETTE DE LA PÉRIODE (B + C + F)',  'amount' => $cafg, 'kind' => 'total'],
            ['code' => 'ZH', 'label' => 'Trésorerie nette au 31 Décembre (G + A)',           'amount' => null, 'kind' => 'total'],
        ];

        return [
            'rows'    => $rows,
            'cafg'    => $cafg,
            'note'    => 'V1: CAFG calculé depuis le Compte de Résultat. Variations de stocks/créances/dettes et flux d\'investissement/financement nécessitent une comparaison N/N-1 — disponible dans la prochaine itération.',
        ];
    }
}
