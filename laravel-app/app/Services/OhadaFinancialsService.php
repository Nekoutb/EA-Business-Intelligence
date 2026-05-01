<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Build the OHADA SYSCOHADA Système Normal financial-statement
 * package from a TrialBalance snapshot. Reads the same data the
 * Trial Balance page renders (via `TrialBalanceService::analyze`),
 * then maps each posted account to its Bilan / Compte de Résultat
 * line via `OhadaMappingService`.
 *
 * Returns three structured payloads:
 *   - bilan_actif         (Assets side of the balance sheet)
 *   - bilan_passif        (Equity + Liabilities side)
 *   - compte_de_resultat  (Income statement, with derived subtotals)
 *
 * Closing balances (Trial Balance closing_dr − closing_cr) feed BS lines.
 * Period movements (movement_dr − movement_cr, sign-flipped for income)
 * feed P&L lines.
 *
 * Sign-sensitive lines (e.g. Class 4 tiers split by direction) only
 * pull accounts whose closing balance has the expected sign — so a
 * customer with a credit balance ends up under "Clients, avances
 * reçues" not under "Clients".
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

    /**
     * @param int    $year       Fiscal year
     * @param string $periodType 'month' | 'ytd' | 'year' (matches TrialBalanceService)
     * @param int    $month      Month (1-12) — used for 'month' / 'ytd'
     */
    public function analyze(?int $year = null, string $periodType = 'year', ?int $month = null): array
    {
        $tb = (new TrialBalanceService($this->odoo))->analyze($year, $periodType, $month);
        $rows = $tb['rows'] ?? [];

        // Bucket accounts by line code, accumulating the right amount per
        // line type. Two passes — Bilan (closing balance) and CR (period
        // movements).
        $byLine   = [];        // line_code => ['amount' => float, 'accounts' => [...]]
        $unmapped = [];        // accounts that didn't match any line

        foreach ($rows as $r) {
            $code = (string) $r['code'];
            $resolved = $this->mapping->resolveAccount($code);
            if ($resolved === null) {
                $unmapped[] = $r;
                continue;
            }

            // Sign-sensitive screening (Class 4 tiers etc.)
            $closingBalance = ((float) $r['closing_dr']) - ((float) $r['closing_cr']);
            if (!$this->mapping->signMatches($resolved['sign'] ?? null, $closingBalance)) {
                continue;
            }

            $lineCode  = $resolved['code'];
            $byLine[$lineCode] ??= [
                'code'     => $lineCode,
                'label'    => $resolved['label'],
                'group'    => $resolved['group'],
                'section'  => $resolved['section'],
                'side'     => $resolved['side'] ?? null,
                'amount'   => 0.0,
                'accounts' => [],
            ];

            // For balance-sheet lines: use ABSOLUTE value of closing
            // balance (Bilan reports magnitudes, sign comes from which
            // side of the balance sheet the line lives on).
            // For P&L lines: use period movement.
            if ($resolved['group'] === 'p_l') {
                $movementSigned = ((float) $r['movement_dr']) - ((float) $r['movement_cr']);
                // Income lines: credit-normal, so the sign-flipped magnitude.
                $amount = ($resolved['side'] ?? 'expense') === 'income'
                    ? -$movementSigned
                    :  $movementSigned;
            } else {
                $amount = abs($closingBalance);
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

        // Build the structured output, walking the mapping in section order
        $bilanActif   = $this->buildBilanSection([
            'Actif Immobilisé' => OhadaMappingService::BILAN_ACTIF_IMMOBILISE,
            'Actif Circulant'  => OhadaMappingService::BILAN_ACTIF_CIRCULANT,
            'Trésorerie Actif' => OhadaMappingService::BILAN_TRESORERIE_ACTIF,
        ], $byLine);

        $bilanPassif  = $this->buildBilanSection([
            'Capitaux Propres'    => OhadaMappingService::BILAN_CAPITAUX_PROPRES,
            'Dettes Financières'  => OhadaMappingService::BILAN_DETTES_FINANCIERES,
            'Passif Circulant'    => OhadaMappingService::BILAN_PASSIF_CIRCULANT,
            'Trésorerie Passif'   => OhadaMappingService::BILAN_TRESORERIE_PASSIF,
        ], $byLine);

        $compteDeResultat = $this->buildCompteDeResultat($byLine);

        // Bilan totals (Actif vs Passif equilibrium check)
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

    /**
     * Walk a Bilan section group (e.g. all sub-sections of ACTIF) and
     * produce a structured list of lines, each with its accounts and
     * subtotal.
     */
    private function buildBilanSection(array $sections, array $byLine): array
    {
        $output = ['sections' => [], 'lines' => []];
        foreach ($sections as $title => $defs) {
            $sectionLines = [];
            $sectionTotal = 0.0;
            foreach ($defs as $code => $def) {
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

    /**
     * Build the Compte de Résultat with leaf lines + derived subtotals.
     */
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

        // Compute subtotals iteratively (each subtotal can reference
        // earlier subtotals via 'from' / 'add_codes')
        $subtotals = [];
        foreach (OhadaMappingService::COMPTE_DE_RESULTAT_SUBTOTALS as $code => $spec) {
            $val = 0.0;
            if (isset($spec['from']) && isset($subtotals[$spec['from']])) {
                $val = $subtotals[$spec['from']]['amount'];
            }
            foreach (($spec['add'] ?? []) as $addCode) {
                $val += (float) ($leaves[$addCode]['amount'] ?? 0.0);
            }
            foreach (($spec['add_codes'] ?? []) as $addCode) {
                $val += (float) ($subtotals[$addCode]['amount'] ?? 0.0);
            }
            foreach (($spec['sub'] ?? []) as $subCode) {
                if (isset($leaves[$subCode]))    $val -= (float) $leaves[$subCode]['amount'];
                if (isset($subtotals[$subCode])) $val -= (float) $subtotals[$subCode]['amount'];
            }
            $subtotals[$code] = ['code' => $code, 'label' => $spec['label'], 'amount' => $val];
        }

        // Build display order — for the Système Normal layout we walk
        // leaves and inject subtotals at the right boundaries.
        $displayOrder = [
            'TA','RA','RB',
            'XA',                                        // MARGE COMMERCIALE
            'TB','TC','TD','TE','TF','TG','TH','TI',
            'XB',                                        // CHIFFRE D'AFFAIRES
            'RC','RD','RE','RF','RG','RH','RI','RJ',
            'XC',                                        // VALEUR AJOUTÉE
            'RK',
            'XD',                                        // EBE
            'RL',
            'XE',                                        // RÉSULTAT D'EXPLOITATION
            'TQ','RP',
            'XF',                                        // RÉSULTAT FINANCIER
            'XG',                                        // RÉSULTAT ACTIVITÉS ORDINAIRES
            'TT','RS',
            'XH',                                        // RÉSULTAT HAO
            'RW',
            'XI',                                        // RÉSULTAT AVANT IMPÔTS
            'RZ',
            'XJ',                                        // RÉSULTAT NET
        ];

        $rows = [];
        foreach ($displayOrder as $code) {
            if (isset($subtotals[$code])) {
                $rows[] = [
                    'code'     => $code,
                    'label'    => $subtotals[$code]['label'],
                    'amount'   => $subtotals[$code]['amount'],
                    'kind'     => 'subtotal',
                    'accounts' => [],
                ];
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

        return [
            'rows'      => $rows,
            'subtotals' => $subtotals,
            'leaves'    => $leaves,
        ];
    }
}
