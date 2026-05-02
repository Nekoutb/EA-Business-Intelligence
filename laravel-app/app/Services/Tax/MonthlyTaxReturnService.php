<?php
declare(strict_types=1);

namespace App\Services\Tax;

use App\Odoo\OdooClient;

/**
 * Monthly Tax Return — DGI Cameroon monthly declaration.
 *
 * Aggregates posted GL movements in the selected month into the categories
 * the DGI form requires. Each category returns a header + total + an array
 * of detail lines (sub-rates / sub-bases) so the view can render a
 * collapsible accordion (header + total visible, details expand on click).
 *
 * Categories covered (per Cameroon Code Général des Impôts):
 *   1. PRÉLÈVEMENT SPÉCIAL SUR LES REVENUS VERSÉS À L'ÉTRANGER (TSR)
 *      — TSR retenu by rate band (1..4)
 *   2. IMPÔT SUR LE REVENU DES PERSONNES PHYSIQUES (IRPP)
 *      — IRPP salaires, RCM, revenus fonciers, BIC/BNC retenu
 *   3. IMPÔT SUR LES SOCIÉTÉS (IS) — acomptes mensuels, retenue à la source
 *   4. TAXE SUR LA VALEUR AJOUTÉE (TVA) — collectée, déductible, à décaisser
 *   5. CENTIMES ADDITIONNELS COMMUNAUX (CAC) — 10% sur impôts directs
 *   6. PRÉCOMPTE SUR LOYERS
 *   7. DROITS D'ENREGISTREMENT ET DE TIMBRE
 *   8. AUTRES TAXES (taxe spéciale produits pétroliers, redevance audiovisuelle, etc.)
 *
 * Sourcing
 *   Where the SYSCOHADA chart of accounts has a clear mapping (TVA → 4431/4452/
 *   4456, retenue IS → 4424, retenue IRPP → 4422, TSR → 4423 etc.), we sum the
 *   posted lines in the window. Where the firm has not configured separate
 *   sub-accounts for each rate band (e.g. TSR 5% / 7.5% / 10% / 15%), the line
 *   resolves to zero and the user can fill in manually before filing.
 *
 * Output shape
 *   [
 *     'currency'   => 'XAF',
 *     'period'     => [year, month, from, to, label, due_date, due_status],
 *     'categories' => [
 *         [
 *           'id'      => 'tsr',
 *           'code'    => 'TSR',
 *           'label'   => "PRÉLÈVEMENT SPÉCIAL SUR LES REVENUS VERSÉS À L'ÉTRANGER",
 *           'total'   => 0.0,
 *           'details' => [
 *               ['label' => 'TSR retenu 1 — base taux 1', 'amount' => 0, 'rate' => '5 %',   'account' => '4423'],
 *               ['label' => 'TSR retenu 2 — base taux 2', 'amount' => 0, 'rate' => '7,5 %', 'account' => '4423'],
 *               ['label' => 'TSR retenu 3 — base taux 3', 'amount' => 0, 'rate' => '10 %',  'account' => '4423'],
 *               ['label' => 'TSR retenu 4 — base taux 4', 'amount' => 0, 'rate' => '15 %',  'account' => '4423'],
 *           ],
 *           'note'    => 'Optional explanatory note',
 *         ],
 *         ...
 *     ],
 *     'total_due'  => 0.0,
 *     'available_months' => [...],
 *     'as_of'      => 'YYYY-MM-DD',
 *   ]
 */
final class MonthlyTaxReturnService
{
    /** Account-prefix map per category. Sums posted balance over month window. */
    private const ACCOUNT_MAP = [
        // TVA (VAT)
        'vat_output'        => ['4431'],   // TVA facturée (credit-positive)
        'vat_input_capex'   => ['4452'],   // TVA déductible / immobilisations
        'vat_input_opex'    => ['4456'],   // TVA déductible / autres B&S
        'vat_credit_carry'  => ['4434'],   // TVA crédit reporté
        // Retenues à la source
        'irpp_salaires'     => ['4421'],   // IRPP retenu sur salaires
        'irpp_rcm'          => ['4424'],   // IRPP / RCM retenu
        'irpp_loyers'       => ['4426'],   // Précompte sur loyers
        'irpp_bic_bnc'      => ['4422'],   // IRPP / BIC-BNC retenu à la source
        'is_retenu'         => ['4441'],   // IS retenu à la source
        'is_acompte'        => ['4442'],   // IS acompte mensuel
        'tsr'               => ['4423'],   // TSR — taxe spéciale revenus versés étranger
        'cac'               => ['4471'],   // CAC — centimes additionnels communaux
        'enregistrement'    => ['4475'],   // Droits d'enregistrement / timbre
        'autres_taxes'      => ['4477'],   // TSPP, redevance audiovisuelle, etc.
        // Sales (taxable base for TVA)
        'sales'             => ['70'],     // Class 70 minus 709
    ];

    public function __construct(private OdooClient $odoo) {}

    /**
     * @param string|null $month  YYYY-MM. Defaults to current calendar month.
     */
    public function analyze(?string $month = null): array
    {
        $today = new \DateTimeImmutable('today');
        $month = $month && preg_match('/^\d{4}-\d{2}$/', $month) ? $month : $today->format('Y-m');

        [$year, $m] = array_map('intval', explode('-', $month));
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m));
        $to   = $from->modify('last day of this month');
        $dueDate = $from->modify('+1 month')->modify('+14 days'); // 15th of M+1
        $dueStatus = match (true) {
            $today < $from           => 'future',
            $today <= $dueDate       => 'pending',
            default                  => 'past_due',
        };

        // Resolve all account ids in one Odoo call, grouped by prefix.
        $idsByPrefix = $this->resolveAccountIdsByPrefix();

        // Category builders return the canonical structure consumed by the view.
        $categories = [
            $this->buildTsrCategory($idsByPrefix, $from, $to),
            $this->buildIrppCategory($idsByPrefix, $from, $to),
            $this->buildIsCategory($idsByPrefix, $from, $to),
            $this->buildVatCategory($idsByPrefix, $from, $to),
            $this->buildCacCategory($idsByPrefix, $from, $to),
            $this->buildPrecompteLoyersCategory($idsByPrefix, $from, $to),
            $this->buildEnregistrementCategory($idsByPrefix, $from, $to),
            $this->buildAutresTaxesCategory($idsByPrefix, $from, $to),
        ];

        $totalDue = 0.0;
        foreach ($categories as $c) {
            $totalDue += (float) ($c['total'] ?? 0);
        }

        return [
            'currency' => $this->detectCurrency(),
            'period'   => [
                'year'       => $year,
                'month'      => $m,
                'from'       => $from->format('Y-m-d'),
                'to'         => $to->format('Y-m-d'),
                'label'      => $from->format('F Y'),
                'due_date'   => $dueDate->format('Y-m-d'),
                'due_status' => $dueStatus,
            ],
            'categories'       => $categories,
            'total_due'        => round($totalDue, 2),
            'available_months' => $this->buildMonthOptions($today),
            'as_of'            => $today->format('Y-m-d'),
        ];
    }

    /* ──────────────────────────────────────────────────────────────────
       Category builders — each returns [id, code, label, total, details, note?]
       ──────────────────────────────────────────────────────────────── */

    private function buildTsrCategory(array $idsByPrefix, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        // TSR pool — all posted lines on accounts beginning with 4423.
        $pool = $idsByPrefix['4423'] ?? [];
        $total = abs($this->sumBalanceWindow($pool, $from, $to));

        // Rate-band breakdown: most charts of accounts do not have separate
        // sub-accounts per rate. We expose four rows so the user can review;
        // amounts are evenly attributed when no sub-account split exists.
        // If the firm has 4423.1, 4423.2, 4423.3, 4423.4 we resolve each.
        $details = [];
        $rates = ['1' => '5 %', '2' => '7,5 %', '3' => '10 %', '4' => '15 %'];
        $hasSplit = false;
        foreach ($rates as $band => $rate) {
            $bandIds = $this->idsForExactPrefix($pool, '4423', '.' . $band);
            if ($bandIds !== []) {
                $hasSplit = true;
                $amt = abs($this->sumBalanceWindow($bandIds, $from, $to));
            } else {
                $amt = 0.0;
            }
            $details[] = [
                'label'   => "TSR retenu {$band}",
                'amount'  => round($amt, 2),
                'rate'    => $rate,
                'account' => '4423.' . $band,
            ];
        }
        // If no split sub-accounts exist, attribute the pool to band 1 as the
        // declared total and leave 2–4 at zero — the principal can re-allocate
        // before filing if the firm received income across multiple bands.
        if (!$hasSplit && $total > 0) {
            $details[0]['amount'] = round($total, 2);
            $details[0]['note']   = 'No sub-account split detected — total attributed to band 1';
        }

        return [
            'id'      => 'tsr',
            'code'    => 'TSR',
            'label'   => "PRÉLÈVEMENT SPÉCIAL SUR LES REVENUS VERSÉS À L'ÉTRANGER",
            'total'   => round($total, 2),
            'details' => $details,
            'note'    => 'TSR — retenue à la source sur prestations versées à des personnes non domiciliées (CGI art. 225). Taux 5 %, 7,5 %, 10 %, 15 % selon nature.',
        ];
    }

    private function buildIrppCategory(array $idsByPrefix, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $details = [];
        $defs = [
            ['key' => '4421', 'label' => 'IRPP — retenu sur salaires (traitements et émoluments)'],
            ['key' => '4424', 'label' => 'IRPP — retenu sur revenus de capitaux mobiliers (RCM)'],
            ['key' => '4426', 'label' => 'IRPP — précompte sur loyers'],
            ['key' => '4422', 'label' => 'IRPP — retenu sur BIC / BNC (prestataires)'],
        ];
        $total = 0.0;
        foreach ($defs as $d) {
            $ids = $idsByPrefix[$d['key']] ?? [];
            $amt = abs($this->sumBalanceWindow($ids, $from, $to));
            $total += $amt;
            $details[] = [
                'label'   => $d['label'],
                'amount'  => round($amt, 2),
                'account' => $d['key'],
            ];
        }
        return [
            'id'      => 'irpp',
            'code'    => 'IRPP',
            'label'   => 'IMPÔT SUR LE REVENU DES PERSONNES PHYSIQUES (RETENUES À LA SOURCE)',
            'total'   => round($total, 2),
            'details' => $details,
            'note'    => 'Sommes retenues à la source au cours du mois et reversées au Trésor avant le 15 du mois suivant.',
        ];
    }

    private function buildIsCategory(array $idsByPrefix, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $idsRetenu = $idsByPrefix['4441'] ?? [];
        $idsAcompte = $idsByPrefix['4442'] ?? [];
        $retenu = abs($this->sumBalanceWindow($idsRetenu, $from, $to));
        $acompte = abs($this->sumBalanceWindow($idsAcompte, $from, $to));
        $total = $retenu + $acompte;
        return [
            'id'      => 'is',
            'code'    => 'IS',
            'label'   => 'IMPÔT SUR LES SOCIÉTÉS',
            'total'   => round($total, 2),
            'details' => [
                ['label' => 'IS — retenue à la source',  'amount' => round($retenu, 2),  'account' => '4441'],
                ['label' => 'IS — acompte mensuel',      'amount' => round($acompte, 2), 'account' => '4442'],
            ],
            'note'    => 'IS retenu sur paiements aux prestataires (CGI art. 21) + acompte mensuel calculé sur le chiffre d\'affaires (taux conventionnel).',
        ];
    }

    private function buildVatCategory(array $idsByPrefix, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $output     = abs($this->sumBalanceWindow($idsByPrefix['4431'] ?? [], $from, $to));
        $inputCapex = $this->sumBalanceWindow($idsByPrefix['4452'] ?? [], $from, $to);
        $inputOpex  = $this->sumBalanceWindow($idsByPrefix['4456'] ?? [], $from, $to);
        $creditCarry = abs($this->sumBalanceWindow($idsByPrefix['4434'] ?? [], $from, $to));
        $netDue     = $output - ($inputCapex + $inputOpex);

        // Sales taxable base — class 70 except 709
        $salesIds = $this->classSalesAccountIds();
        $salesBase = abs($this->sumBalanceWindow($salesIds, $from, $to));

        return [
            'id'      => 'vat',
            'code'    => 'TVA',
            'label'   => 'TAXE SUR LA VALEUR AJOUTÉE',
            'total'   => round(max($netDue, 0), 2),    // only positive net is owed
            'details' => [
                ['label' => 'TVA collectée (output)',                 'amount' => round($output, 2),     'account' => '4431'],
                ['label' => 'TVA déductible — immobilisations',      'amount' => round($inputCapex, 2), 'account' => '4452', 'sign' => 'deduction'],
                ['label' => 'TVA déductible — autres B&S',            'amount' => round($inputOpex, 2),  'account' => '4456', 'sign' => 'deduction'],
                ['label' => 'TVA crédit reporté du mois précédent',  'amount' => round($creditCarry, 2),'account' => '4434', 'sign' => 'deduction'],
                ['label' => 'TVA nette à décaisser (4433)',           'amount' => round($netDue, 2),     'account' => '4433', 'sign' => 'subtotal'],
                ['label' => 'Base imposable — chiffre d\'affaires',   'amount' => round($salesBase, 2),  'account' => '70',   'sign' => 'memo'],
            ],
            'note'    => 'Net VAT due = TVA collectée − (TVA déductible immobilisations + autres B&S + crédit reporté). Si négatif, crédit reportable.',
        ];
    }

    private function buildCacCategory(array $idsByPrefix, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $ids = $idsByPrefix['4471'] ?? [];
        $total = abs($this->sumBalanceWindow($ids, $from, $to));
        return [
            'id'      => 'cac',
            'code'    => 'CAC',
            'label'   => 'CENTIMES ADDITIONNELS COMMUNAUX',
            'total'   => round($total, 2),
            'details' => [
                ['label' => 'CAC sur impôts et taxes — 10 %', 'amount' => round($total, 2), 'account' => '4471', 'rate' => '10 %'],
            ],
            'note'    => 'CAC = 10 % des impôts directs (IRPP, IS, IS retenu, etc.) reversé aux communes (CGI art. 9).',
        ];
    }

    private function buildPrecompteLoyersCategory(array $idsByPrefix, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $ids = $idsByPrefix['4426'] ?? [];
        $total = abs($this->sumBalanceWindow($ids, $from, $to));
        return [
            'id'      => 'precompte_loyers',
            'code'    => 'PRECOMPTE',
            'label'   => 'PRÉCOMPTE SUR LOYERS',
            'total'   => round($total, 2),
            'details' => [
                ['label' => 'Précompte sur loyers — 10 % du loyer brut', 'amount' => round($total, 2), 'account' => '4426', 'rate' => '10 %'],
            ],
            'note'    => 'Précompte mensuel retenu par le locataire-personne morale sur loyer brut versé au bailleur (CGI art. 87).',
        ];
    }

    private function buildEnregistrementCategory(array $idsByPrefix, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $ids = $idsByPrefix['4475'] ?? [];
        $total = abs($this->sumBalanceWindow($ids, $from, $to));
        return [
            'id'      => 'enregistrement',
            'code'    => 'ENREG',
            'label'   => "DROITS D'ENREGISTREMENT ET DE TIMBRE",
            'total'   => round($total, 2),
            'details' => [
                ['label' => "Droits d'enregistrement payés dans le mois", 'amount' => round($total, 2), 'account' => '4475'],
            ],
            'note'    => 'Sommes acquittées au titre des droits d\'enregistrement et de timbre (baux, mutations, marchés, etc.).',
        ];
    }

    private function buildAutresTaxesCategory(array $idsByPrefix, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $ids = $idsByPrefix['4477'] ?? [];
        $total = abs($this->sumBalanceWindow($ids, $from, $to));
        return [
            'id'      => 'autres_taxes',
            'code'    => 'AUTRES',
            'label'   => 'AUTRES TAXES',
            'total'   => round($total, 2),
            'details' => [
                ['label' => 'Taxe spéciale sur les produits pétroliers (TSPP)', 'amount' => 0, 'account' => '4477'],
                ['label' => 'Redevance audiovisuelle',                          'amount' => 0, 'account' => '4477'],
                ['label' => 'Taxe sur les jeux et divertissements',              'amount' => 0, 'account' => '4477'],
                ['label' => 'Autres taxes ad valorem',                           'amount' => round($total, 2), 'account' => '4477', 'note' => 'pool total — split via sub-accounts to break out'],
            ],
            'note'    => 'TSPP, redevance audiovisuelle, taxes spéciales et droits divers reversés mensuellement.',
        ];
    }

    /* ──────────────────────────────────────────────────────────────────
       Helpers
       ──────────────────────────────────────────────────────────────── */

    /** Build the YYYY-MM dropdown — last 12 months, newest first. */
    private function buildMonthOptions(\DateTimeImmutable $today): array
    {
        $opts = [];
        for ($i = 0; $i < 12; $i++) {
            $d = $today->modify("first day of -{$i} month");
            $opts[] = ['value' => $d->format('Y-m'), 'label' => $d->format('F Y')];
        }
        return $opts;
    }

    /**
     * Resolve all category-relevant account ids in one Odoo call.
     * Returns a map: prefix-as-string => [account_id, ...].
     *
     * Important: prefixes are kept as a flat *list of strings* throughout.
     * If they were used as array *keys*, PHP would coerce purely-numeric
     * strings ('4421', '4423', …) into ints, and a subsequent
     * `str_starts_with($code, $intPrefix)` would throw a TypeError because
     * needle must be a string. We therefore never store prefixes as keys
     * before iterating; only after all string-typed work is done do we
     * use them as keys (PHP's int-coercion of the lookup is symmetric, so
     * `$byPrefix['4431']` correctly reaches the same bucket).
     */
    private function resolveAccountIdsByPrefix(): array
    {
        // Collect all distinct prefixes as a flat string list (excluding the
        // 'sales' synthetic key which uses class 70 lookup instead).
        $prefixes = [];
        foreach (self::ACCOUNT_MAP as $key => $list) {
            if ($key === 'sales') continue;
            foreach ($list as $p) {
                $p = (string) $p;
                if (!in_array($p, $prefixes, true)) {
                    $prefixes[] = $p;
                }
            }
        }
        if ($prefixes === []) return [];

        // Build OR domain across all prefixes.
        $domain = [];
        for ($i = 1; $i < count($prefixes); $i++) $domain[] = '|';
        foreach ($prefixes as $p) $domain[] = ['code', '=like', $p . '%'];

        $rows = $this->odoo->searchRead('account.account', $domain, ['id', 'code'], []);

        // Initialise empty buckets — keys here will be int-coerced by PHP for
        // purely-numeric strings, but reads on the same string keys later
        // coerce identically, so lookups still hit the right bucket.
        $byPrefix = [];
        foreach ($prefixes as $p) $byPrefix[$p] = [];

        foreach ($rows as $r) {
            $code = (string) ($r['code'] ?? '');
            if ($code === '') continue;
            // Match the LONGEST prefix this code belongs to (so 4452 doesn't
            // also get attributed to a hypothetical '44' prefix).
            $matched = null;
            foreach ($prefixes as $p) {
                // $p is guaranteed to be a string by the construction above.
                if (str_starts_with($code, $p)) {
                    if ($matched === null || strlen($p) > strlen($matched)) $matched = $p;
                }
            }
            if ($matched !== null) $byPrefix[$matched][] = (int) $r['id'];
        }
        return $byPrefix;
    }

    /**
     * Filter a pool of account ids down to those whose code matches a sub-prefix.
     * Used by TSR to find rate-band sub-accounts (e.g. 4423.1, 4423.2).
     */
    private function idsForExactPrefix(array $pool, string $base, string $sub): array
    {
        if ($pool === []) return [];
        $rows = $this->odoo->searchRead('account.account',
            [['id', 'in', $pool], ['code', '=like', $base . $sub . '%']],
            ['id'], []
        );
        return array_map(fn ($r) => (int) $r['id'], $rows);
    }

    /** Class-70 sales accounts excluding 709 (rebates). One Odoo call. */
    private function classSalesAccountIds(): array
    {
        $rows = $this->odoo->searchRead('account.account',
            [['code', '=like', '70%']],
            ['id', 'code'], []
        );
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
