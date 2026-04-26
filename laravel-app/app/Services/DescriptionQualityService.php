<?php

namespace App\Services;

use App\Odoo\OdooClient;
use DateTimeImmutable;

/**
 * DescriptionQualityService — narration / description quality audit on
 * the General Ledger.
 *
 * Runs ~17 lightweight checks against YTD posted account.move.line records,
 * each reading the `name` field (the per-line narration) plus account/
 * partner context. Each check returns { passed, count } where:
 *   - passed = true  if 0 lines triggered the check
 *   - passed = false if ≥1 lines triggered it
 *   - count  = number of lines that triggered the check
 *
 * Overall score = (number of passed checks / total checks) × 100.
 *
 * Strategy: ONE Odoo round-trip for lines (capped at 50k newest YTD), one
 * batched res.partner lookup for partner names, one batched account lookup
 * for codes. All check logic runs in PHP — fast (<1s for 50k lines).
 */
class DescriptionQualityService
{
    private const FETCH_LIMIT  = 50000;
    private const REPORT_LIMIT = 5000;   // max rows in the downloadable report

    private ?int $companyId = null;

    public function __construct(private OdooClient $odoo) {}

    /**
     * @param array $exclusions  list of {starts_with, except_starts_with?, label}
     *                            entries; lines on matching accounts are
     *                            skipped from the description audit.
     */
    public function analyze(DateTimeImmutable $today, array $exclusions = []): array
    {
        $errors = [];
        $from = $today->modify('first day of January')->setTime(0, 0, 0);
        $to   = $today->setTime(23, 59, 59);

        $lines = $this->safe(fn() => $this->fetchAndNormalize($from, $to, $exclusions), $errors, 'lines') ?? [];

        $categories = [
            'completeness' => [
                'label'  => 'Completeness',
                'checks' => [
                    $this->checkPerLine('1', 'Missing descriptions',
                        'Blank, null, or whitespace-only narration.',
                        fn($l) => $this->isBlank($l['name']), $lines),
                    $this->checkPerLine('2', 'Default / system placeholders',
                        'Generic system-generated narrations (e.g. "Posting", "Transaction", "Écriture comptable").',
                        fn($l) => $this->isDefaultPlaceholder($l['name']), $lines),
                    $this->checkPerLine('3', 'Short descriptions (< 10 chars)',
                        'Single-character, numeric-only, or fewer than 10 characters — typically incomplete or abbreviated to the point of losing audit value.',
                        fn($l) => $this->isShort($l['name']), $lines),
                ],
            ],
            'identification' => [
                'label'  => 'Identification',
                'checks' => [
                    $this->checkPerLine('4', 'Missing partner tag on 40X / 41X line',
                        'Lines on supplier (40X) or customer (41X) accounts that do not have a partner (customer/vendor) tagged on the journal-entry line.',
                        fn($l) => $this->missingCounterparty($l), $lines),
                    $this->checkPerLine('5', 'Missing document reference (manual entries)',
                        'Manual journal entries (move_type = entry) without an alphanumeric document identifier in their narration or ref field.',
                        fn($l) => $this->missingDocRef($l), $lines),
                    $this->checkPerLine('6', 'Date / period mismatch',
                        'Narration mentions a year that differs from the posting year.',
                        fn($l) => $this->mismatchedYear($l), $lines),
                    $this->check7InconsistentNames($lines),
                ],
            ],
            'content_quality' => [
                'label'  => 'Content quality',
                'checks' => [
                    $this->checkPerLine('8', 'Generic / vague narration',
                        '"Various", "Sundry", "Adjustment", "Misc", "Divers", "Régularisation", etc.',
                        fn($l) => $this->isGeneric($l['name']), $lines),
                    $this->checkPerLine('9', 'Description does not match account nature',
                        'Salary keyword on a fuel account, fuel keyword on a rent account, etc.',
                        fn($l) => $this->accountKeywordMismatch($l), $lines),
                    $this->checkPerLine('11', 'Currency / amount mismatches',
                        'Narration explicitly references a different currency code than the company currency.',
                        fn($l) => $this->currencyMismatch($l), $lines),
                ],
            ],
            'language_format' => [
                'label'  => 'Language & formatting',
                'checks' => [
                    $this->checkPerLine('12', 'Mixed languages without justification',
                        'French and English stop-words mixed within the same narration.',
                        fn($l) => $this->mixedLanguages($l['name']), $lines),
                    $this->checkPerLine('13', 'Excessive abbreviations',
                        'More than 40% of narration words are abbreviated (consonant-only or ≤3 chars).',
                        fn($l) => $this->excessiveAbbreviations($l['name']), $lines),
                    $this->checkPerLine('14/15', 'Encoding / special-character issues',
                        'Question-mark sequences, replacement chars (U+FFFD), or Mojibake patterns.',
                        fn($l) => $this->hasEncodingIssues($l['name']), $lines),
                ],
            ],
            'audit_trail' => [
                'label'  => 'Audit trail',
                'checks' => [
                    $this->checkPerLine('16', 'Reversal without cross-reference',
                        'Narration starting "Reversal/Annulation" without a journal-entry number reference.',
                        fn($l) => $this->reversalWithoutRef($l), $lines),
                    $this->checkPerLine('18', 'Wrong-month narration (copy-paste error)',
                        'Narration mentions a month name that doesn\'t match the posting month.',
                        fn($l) => $this->mismatchedMonth($l), $lines),
                    $this->checkPerLine('19', '"Estimated / provisional" on posted entries',
                        'Final entries flagged "estimated", "provisional", "TBC", "à confirmer", "provisoire".',
                        fn($l) => $this->isProvisional($l['name']), $lines),
                    $this->checkPerLine('20', 'Tax (44X) entries missing tax-period reference',
                        'Lines on 44X tax accounts whose narration does not reference a tax period (month/year or quarter).',
                        fn($l) => $this->missingTaxPeriod($l), $lines),
                ],
            ],
        ];

        // Flatten + score (N/A checks are excluded from both numerator and denominator)
        $allChecks = [];
        foreach ($categories as $cat) {
            foreach ($cat['checks'] as $c) {
                $allChecks[] = $c;
            }
        }
        $applicable = array_values(array_filter($allChecks, fn($c) => empty($c['not_applicable'])));
        $passed     = count(array_filter($applicable, fn($c) => $c['passed']));
        $total      = count($allChecks);
        $appCount   = count($applicable);
        $score      = $appCount > 0 ? round($passed / $appCount * 100, 1) : 100.0;

        // Build the downloadable report BEFORE stripping the internal
        // _flagged_ids accumulators.
        $report = $this->buildReport($lines, $allChecks);

        // Drop _flagged_ids from the public payload — the view only needs counts.
        foreach ($categories as &$cat) {
            foreach ($cat['checks'] as &$c) {
                unset($c['_flagged_ids']);
            }
            unset($c);
        }
        unset($cat);

        return [
            'overall_score'    => $score,
            'checks_passed'    => $passed,
            'checks_total'     => $total,
            'checks_applicable'=> $appCount,
            'lines_reviewed'   => count($lines),
            'lines_capped'     => count($lines) >= self::FETCH_LIMIT,
            'fetch_limit'      => self::FETCH_LIMIT,
            'exclusions'       => $exclusions,
            'categories'       => $categories,
            'report'           => $report,
            'report_capped'    => count($report) >= self::REPORT_LIMIT,
            'report_limit'     => self::REPORT_LIMIT,
            'error'            => empty($errors) ? null : implode(' | ', $errors),
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Data fetch + normalization
    // ─────────────────────────────────────────────────────────────────

    /**
     * Returns rows shaped like:
     *   {id, name, ref, account_code, partner_id, partner_name,
     *    date, year, month, amount, move_id}
     *
     * If $exclusions is non-empty, the corresponding account IDs are first
     * resolved from the chart of accounts, then added as a NOT IN filter
     * so the 50k cap applies to the post-exclusion dataset.
     */
    private function fetchAndNormalize(DateTimeImmutable $from, DateTimeImmutable $to, array $exclusions): array
    {
        $domain = [
            ['parent_state', '=', 'posted'],
            ['company_id',   '=', $this->getCompanyId()],
            ['date',         '>=', $from->format('Y-m-d')],
            ['date',         '<=', $to->format('Y-m-d')],
        ];

        // Resolve excluded account ids once at the chart-of-accounts level
        if (!empty($exclusions)) {
            $excluded = $this->resolveExcludedAccountIds($exclusions);
            if (!empty($excluded)) {
                $domain[] = ['account_id', 'not in', $excluded];
            }
        }

        $rows = $this->odoo->searchRead(
            'account.move.line',
            $domain,
            ['name', 'ref', 'account_id', 'partner_id', 'date', 'debit', 'credit', 'move_id'],
            ['limit' => self::FETCH_LIMIT, 'order' => 'date desc, id desc']
        );

        if (empty($rows)) return [];

        // Resolve account codes
        $accountIds = [];
        foreach ($rows as $r) {
            if (is_array($r['account_id'] ?? null) && !empty($r['account_id'][0])) {
                $accountIds[$r['account_id'][0]] = true;
            }
        }
        $codes = [];
        foreach (array_chunk(array_keys($accountIds), 500) as $chunk) {
            foreach ($this->odoo->read('account.account', $chunk, ['code']) as $a) {
                $codes[$a['id']] = (string)($a['code'] ?? '');
            }
        }

        // Resolve partner names
        $partnerIds = [];
        foreach ($rows as $r) {
            if (is_array($r['partner_id'] ?? null) && !empty($r['partner_id'][0])) {
                $partnerIds[$r['partner_id'][0]] = true;
            }
        }
        $partners = [];
        if (!empty($partnerIds)) {
            foreach (array_chunk(array_keys($partnerIds), 500) as $chunk) {
                foreach ($this->odoo->read('res.partner', $chunk, ['name']) as $p) {
                    $partners[$p['id']] = (string)($p['name'] ?? '');
                }
            }
        }

        // Resolve move types and names (for the "manual entries" filter on
        // check #5 and for nicer report output).
        $moveIds = [];
        foreach ($rows as $r) {
            if (is_array($r['move_id'] ?? null) && !empty($r['move_id'][0])) {
                $moveIds[$r['move_id'][0]] = true;
            }
        }
        $moveInfo = [];
        if (!empty($moveIds)) {
            foreach (array_chunk(array_keys($moveIds), 500) as $chunk) {
                try {
                    foreach ($this->odoo->read('account.move', $chunk, ['move_type', 'name']) as $m) {
                        $moveInfo[$m['id']] = [
                            'type' => (string)($m['move_type'] ?? ''),
                            'name' => (string)($m['name']      ?? ''),
                        ];
                    }
                } catch (\Throwable $e) {
                    // Older Odoo without move_type — leave moveInfo empty;
                    // check #5 will simply find no manual entries to flag.
                }
            }
        }

        $lines = [];
        foreach ($rows as $r) {
            $accId   = is_array($r['account_id']  ?? null) ? ($r['account_id'][0]  ?? null) : null;
            $partId  = is_array($r['partner_id']  ?? null) ? ($r['partner_id'][0]  ?? null) : null;
            $moveId  = is_array($r['move_id']     ?? null) ? ($r['move_id'][0]     ?? null) : null;
            $date    = (string)($r['date'] ?? '');

            $lines[] = [
                'id'           => (int)($r['id'] ?? 0),
                'name'         => (string)($r['name'] ?? ''),
                'ref'          => (string)($r['ref']  ?? ''),
                'account_code' => $accId !== null ? ($codes[$accId] ?? '') : '',
                'partner_id'   => $partId,
                'partner_name' => $partId !== null ? ($partners[$partId] ?? '') : '',
                'date'         => $date,
                'year'         => $date !== '' ? (int)substr($date, 0, 4) : 0,
                'month'        => $date !== '' ? (int)substr($date, 5, 2) : 0,
                'amount'       => abs((float)($r['debit'] ?? 0) - (float)($r['credit'] ?? 0)),
                'move_id'      => $moveId,
                'move_type'    => $moveId !== null ? ($moveInfo[$moveId]['type'] ?? '') : '',
                'move_name'    => $moveId !== null ? ($moveInfo[$moveId]['name'] ?? '') : '',
            ];
        }
        return $lines;
    }

    // ─────────────────────────────────────────────────────────────────
    // Check framework
    // ─────────────────────────────────────────────────────────────────

    private function checkPerLine(string $no, string $name, string $description, callable $predicate, array $lines): array
    {
        $count = 0;
        $flagged = [];
        foreach ($lines as $l) {
            if ($predicate($l)) {
                $count++;
                $flagged[] = (int)$l['id'];
            }
        }
        return [
            'no'           => $no,
            'name'         => $name,
            'description'  => $description,
            'passed'       => $count === 0,
            'count'        => $count,
            '_flagged_ids' => $flagged,   // internal — stripped before view payload
        ];
    }

    /** Cross-line check #7: a partner with many distinct narration variants. */
    private function check7InconsistentNames(array $lines): array
    {
        $byPartner = [];
        foreach ($lines as $l) {
            $p = $l['partner_id'];
            if ($p === null) continue;
            $code = $l['account_code'];
            // Only consider 40X / 41X (suppliers/customers) — that's where narration
            // should consistently identify the partner.
            if (!str_starts_with($code, '40') && !str_starts_with($code, '41')) continue;
            $norm = strtolower(preg_replace('/[^\p{L}0-9]+/u', ' ', trim($l['name'] ?? '')));
            if ($norm === '') continue;
            if (!isset($byPartner[$p])) $byPartner[$p] = ['variants' => [], 'line_ids' => []];
            $byPartner[$p]['variants'][$norm] = true;
            $byPartner[$p]['line_ids'][]      = (int)$l['id'];
        }
        $count = 0;
        $flagged = [];
        foreach ($byPartner as $partner) {
            if (count($partner['variants']) > 3) {
                $count   += count($partner['line_ids']);
                $flagged  = array_merge($flagged, $partner['line_ids']);
            }
        }
        return [
            'no'           => '7',
            'name'         => 'Inconsistent counterparty naming',
            'description'  => 'Same supplier/customer appearing with > 3 distinct narration variants across the GL.',
            'passed'       => $count === 0,
            'count'        => $count,
            '_flagged_ids' => $flagged,
        ];
    }

    /**
     * Build a flat report of every flagged line, capped at REPORT_LIMIT.
     * Each entry includes the full line data plus the list of check
     * numbers/names that flagged it. Used by the CSV download endpoint.
     */
    private function buildReport(array $lines, array $allChecks): array
    {
        $byId = [];
        foreach ($lines as $l) $byId[(int)$l['id']] = $l;

        $flagged = [];
        foreach ($allChecks as $chk) {
            $label = $chk['no'] . '. ' . $chk['name'];
            foreach (($chk['_flagged_ids'] ?? []) as $id) {
                if (!isset($flagged[$id])) {
                    $flagged[$id] = [
                        'line'           => $byId[$id] ?? null,
                        'failed_checks'  => [],
                    ];
                }
                $flagged[$id]['failed_checks'][] = $label;
            }
        }
        // Sort by date descending (newest first)
        $list = array_values($flagged);
        usort($list, function ($a, $b) {
            return strcmp(($b['line']['date'] ?? ''), ($a['line']['date'] ?? ''));
        });
        return array_slice($list, 0, self::REPORT_LIMIT);
    }

    // ─────────────────────────────────────────────────────────────────
    // Predicates
    // ─────────────────────────────────────────────────────────────────

    private function isBlank(string $s): bool
    {
        return trim($s) === '';
    }

    private function isDefaultPlaceholder(string $s): bool
    {
        $t = trim(mb_strtolower($s));
        if ($t === '') return false;

        // Exact / starts-with patterns for system defaults (EN + FR)
        static $exact = [
            'journal entry', 'posting', 'transaction', 'auto', 'n/a', 'na',
            'gl upload', 'import', 'import batch',
            'écriture comptable', 'ecriture comptable', 'saisie',
            'téléchargement', 'telechargement', 'écriture', 'ecriture',
            'opération', 'operation', 'libellé', 'libelle',
        ];
        foreach ($exact as $p) {
            if ($t === $p) return true;
        }
        // "Import batch 12345" style
        if (preg_match('/^(import\s+batch|gl\s+upload|saisie|écriture|ecriture)[\s_-]*\d+/iu', $t)) {
            return true;
        }
        // Pure "/" or "-" or "..."
        if (preg_match('/^[\s\-_\/\.]+$/', $t)) return true;
        return false;
    }

    private function isShort(string $s): bool
    {
        $t = trim($s);
        if ($t === '') return false; // covered by check #1
        if (mb_strlen($t) < 10) return true;
        // Numeric-only of any length is also low-value
        if (preg_match('/^\d+$/', $t)) return true;
        return false;
    }

    /**
     * Check #4 — the journal entry LINE itself must carry a partner_id
     * (the supplier/customer field on the line). We don't inspect the
     * narration text — Odoo's Aged Receivable / Aged Payable reports
     * rely on partner_id, and that's the field that drives our AR/AP
     * sub-ledger reconciliations.
     */
    private function missingCounterparty(array $l): bool
    {
        $code = $l['account_code'];
        if (!(str_starts_with($code, '40') || str_starts_with($code, '41'))) return false;
        return $l['partner_id'] === null;
    }

    /**
     * Check #5 — narrowed to MANUAL journal entries (move_type = 'entry').
     * Invoices, bills, payments, and POS entries have a system-generated
     * ref/name, so their description being short or non-numeric is normal.
     * Manual entries are where humans type narrations and where missing
     * document references are most informative.
     */
    private function missingDocRef(array $l): bool
    {
        if (($l['move_type'] ?? '') !== 'entry') return false;
        $hay = $l['name'] . ' ' . $l['ref'];
        // Need an alphanumeric token of 4+ chars containing at least one digit.
        // Matches "INV/2026/0001", "FA-202503-12", "BNK-20250415-001", "12345", "A1234".
        return !preg_match('/[A-Za-z0-9][A-Za-z0-9\-\/_]{3,}/u', $hay)
            || !preg_match('/\d/', $hay);
    }

    private function mismatchedYear(array $l): bool
    {
        if ($l['year'] === 0) return false;
        $yrs = [];
        if (preg_match_all('/\b(20\d{2})\b/', $l['name'], $m)) {
            $yrs = array_unique($m[1]);
        }
        if (empty($yrs)) return false;                   // no year mentioned, nothing to check
        foreach ($yrs as $y) {
            if ((int)$y === $l['year']) return false;     // at least one matches → ok
        }
        return true;                                      // year mentioned, none matched
    }

    private function isGeneric(string $s): bool
    {
        $t = trim(mb_strtolower($s));
        if ($t === '') return false;

        static $generic = [
            'various', 'sundry', 'sundry expenses', 'sundries', 'misc', 'miscellaneous',
            'adjustment', 'reclassification', 'correction', 'per management',
            'divers', 'autres', 'régularisation', 'regularisation', 'ajustement',
            'reclassement', 'rectification', 'sur instruction', 'à régulariser',
        ];
        foreach ($generic as $g) {
            if ($t === $g) return true;
        }
        // Very short narration that's just one of these keywords
        if (preg_match('/^(divers|various|misc|sundry|autres)\b/iu', $t)) return true;
        return false;
    }

    private function accountKeywordMismatch(array $l): bool
    {
        $code = $l['account_code'];
        if ($code === '') return false;
        $hay  = mb_strtolower($l['name']);
        if (trim($hay) === '') return false;

        // Keyword → required account-code prefix (OHADA chart of accounts).
        // Covers the most common manual-posting categories so that obvious
        // narration↔account-class mismatches are caught (salary on a fuel
        // account, fuel on a rent account, sales narration on a 6X expense
        // account, etc.).
        static $rules = [
            // Personnel costs → 66 (Personnel charges)
            'salary'                => '66', 'salaire'         => '66',
            'salaires'              => '66', 'wages'           => '66',
            'payroll'               => '66', 'paie'            => '66',
            'bonus'                 => '66', 'prime'           => '66',
            'social security'       => '66', 'sécurité sociale'=> '66',
            'cnps'                  => '66', 'cnss'            => '66',
            'allowance'             => '66', 'indemnité'       => '66',

            // Transport / fuel → 624 (Transport)
            'fuel'                  => '624', 'carburant'       => '624',
            'gasoline'              => '624', 'essence'         => '624',
            'diesel'                => '624', 'gazole'          => '624',
            'transport'             => '624', 'transit'         => '624',
            'mileage'               => '624', 'kilométrique'    => '624',

            // Rent → 622 (Rents)
            'rent'                  => '622', 'loyer'           => '622',
            'location bureau'       => '622', 'location'        => '622',
            'leasing immobilier'    => '622',

            // Utilities & telecom → 618 (External services)
            'electricity bill'      => '618', 'électricité'     => '618',
            'electricite'           => '618', 'eneo'            => '618',
            'water bill'            => '618', 'facture eau'     => '618',
            'internet'              => '618', 'téléphone'       => '618',
            'telephone'             => '618', 'communication'   => '618',
            'subscription'          => '618', 'abonnement'      => '618',

            // Office supplies & consumables → 605 / 606
            'office supplies'       => '60', 'fournitures de bureau' => '60',
            'stationery'            => '60', 'papeterie'        => '60',
            'consumable'            => '60',

            // Bank charges, interest, financial → 67 (Financial charges)
            'bank fee'              => '67', 'frais bancaire'   => '67',
            'frais bancaires'       => '67', 'agios'            => '67',
            'interest expense'      => '67', 'intérêt'          => '67',
            'commissions bancaires' => '67',

            // Depreciation / amortisation → 68 (Provisions for depreciation)
            'depreciation'          => '68', 'amortissement'    => '68',
            'dotation'              => '68', 'dotation aux amort' => '68',

            // Sales / revenue → 70 (Sales / Income)
            'sales revenue'         => '70', 'service revenue'  => '70',
            'invoice to customer'   => '70', "chiffre d'affaires"=> '70',
            'prestation de service' => '70', 'vente de marchandises' => '70',

            // Inventory purchases → 60 (Purchases)
            'purchase of goods'     => '60', 'achat de marchandises' => '60',
            'raw materials'         => '60', 'matières premières'   => '60',
            'matieres premieres'    => '60',

            // Tax (TVA / withholding) → 44 (Taxes payable / receivable)
            ' tva '                 => '44', ' vat '             => '44',
            'withholding'           => '44', ' ras '             => '44',
            'irpp'                  => '44', 'taxe sur'          => '44',

            // Insurance → 625
            'insurance premium'     => '625', 'prime d\'assurance' => '625',
            'assurance'             => '625',
        ];
        foreach ($rules as $kw => $expectedPrefix) {
            // For multi-word keywords, do a substring match; for single
            // tokens, the same substring check is fine.
            if (mb_stripos($hay, trim($kw)) !== false
                && !str_starts_with($code, $expectedPrefix)) {
                return true;
            }
        }
        return false;
    }

    private function currencyMismatch(array $l): bool
    {
        // Look for an explicit foreign currency code in narration
        // (assumes company currency is XAF / FCFA — typical for OHADA).
        if (preg_match('/\b(USD|EUR|GBP|CAD|JPY|CHF|CNY)\b/', $l['name'])) {
            return true;
        }
        return false;
    }

    private function mixedLanguages(string $s): bool
    {
        $hay = mb_strtolower($s);
        static $fr = ['de la', 'du ', 'des ', 'pour ', 'sur ', 'avec ', 'sans ', 'écriture', 'paiement', 'achat', 'vente', 'facture'];
        static $en = ['the ', ' and ', ' for ', ' from ', ' to ', 'invoice', 'payment', 'purchase', 'sale', 'reversal'];
        $hasFr = false; $hasEn = false;
        foreach ($fr as $w) { if (mb_strpos($hay, $w) !== false) { $hasFr = true; break; } }
        foreach ($en as $w) { if (mb_strpos($hay, $w) !== false) { $hasEn = true; break; } }
        return $hasFr && $hasEn;
    }

    private function excessiveAbbreviations(string $s): bool
    {
        $t = trim($s);
        if (mb_strlen($t) < 10) return false;             // too short to assess
        $words = preg_split('/[\s\.,;\/\-_]+/u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) < 3) return false;
        $abbr = 0;
        foreach ($words as $w) {
            $wl = mb_strtolower($w);
            // 2-3 chars all consonants → abbreviation
            if (preg_match('/^[bcdfghjklmnpqrstvwxz]{2,3}$/iu', $w)) { $abbr++; continue; }
            // ≤3 chars, all letters, no vowels → abbreviation
            if (mb_strlen($w) <= 3 && preg_match('/^[a-z]+$/iu', $w) && !preg_match('/[aeiouy]/iu', $w)) {
                $abbr++;
            }
        }
        return ($abbr / max(1, count($words))) > 0.4;
    }

    private function hasEncodingIssues(string $s): bool
    {
        // 3+ question marks in a row, or replacement char, or common Mojibake
        if (preg_match('/\?{3,}/', $s)) return true;
        if (mb_strpos($s, "\u{FFFD}") !== false) return true;
        // Doubly-encoded UTF-8 leftovers
        if (preg_match('/Ã[©®¨ª¯«¬]|Â[ ©°§ª\xA0-\xBF]/u', $s)) return true;
        return false;
    }

    private function reversalWithoutRef(array $l): bool
    {
        if (!preg_match('/^\s*(reversal|reverse|annulation|annul|extourne|contre[\s\-]?passation)/iu', $l['name'])) {
            return false;
        }
        // Has it got an alphanumeric reference token (journal-entry pattern)?
        if (preg_match('/[A-Z]{1,5}[\/\-][0-9]{2,4}[\/\-][0-9]+/', $l['name'])) return false;
        if (preg_match('/\b\d{4,}\b/', $l['name'])) return false;
        return true;
    }

    private function mismatchedMonth(array $l): bool
    {
        if ($l['month'] === 0) return false;
        static $en = ['january','february','march','april','may','june','july','august','september','october','november','december'];
        static $fr = ['janvier','février','fevrier','mars','avril','mai','juin','juillet','août','aout','septembre','octobre','novembre','décembre','decembre'];
        // Map month-name (any of the above) → month index 1-12
        static $fr_idx = [
            'janvier'=>1,'février'=>2,'fevrier'=>2,'mars'=>3,'avril'=>4,'mai'=>5,'juin'=>6,
            'juillet'=>7,'août'=>8,'aout'=>8,'septembre'=>9,'octobre'=>10,'novembre'=>11,
            'décembre'=>12,'decembre'=>12,
        ];
        $hay = mb_strtolower($l['name']);
        // English
        foreach ($en as $idx => $name) {
            if (mb_strpos($hay, $name) !== false && ($idx + 1) !== $l['month']) return true;
        }
        // French
        foreach ($fr_idx as $name => $idx) {
            if (mb_strpos($hay, $name) !== false && $idx !== $l['month']) return true;
        }
        return false;
    }

    private function isProvisional(string $s): bool
    {
        return (bool)preg_match('/\b(estimated|provisional|tbc|to be confirmed|provisoire|estim[ée]|à confirmer|a confirmer)\b/iu', $s);
    }

    private function missingTaxPeriod(array $l): bool
    {
        if (!str_starts_with($l['account_code'], '44')) return false;
        $hay = $l['name'];
        // Explicit period reference: month name OR YYYY-MM OR Q1/Q2 ... or "T1..T4" (FR trimestre)
        if (preg_match('/\b(20\d{2}[\-\/]\d{1,2})\b/', $hay)) return false;
        if (preg_match('/\b(Q[1-4]|T[1-4]|trimestre)\b/i', $hay)) return false;
        if (preg_match('/(janvier|février|fevrier|mars|avril|mai|juin|juillet|août|aout|septembre|octobre|novembre|décembre|decembre|january|february|march|april|may|june|july|august|september|october|november|december)/iu', $hay)) return false;
        return true;
    }

    // ─────────────────────────────────────────────────────────────────
    // Plumbing
    // ─────────────────────────────────────────────────────────────────

    /**
     * Resolve the chart-of-accounts IDs that match any of the given
     * exclusion rules. One Odoo round-trip — we fetch the candidate
     * 3X / 4X accounts and filter by code prefix client-side.
     */
    private function resolveExcludedAccountIds(array $exclusions): array
    {
        // Build a domain that's the OR of all rules' starts_with prefixes.
        // We deliberately fetch a wider 'starts_with' than each rule needs
        // (just the first character) — keeps the domain simple, and the
        // client-side filter does the precise matching.
        $broadPrefixes = [];
        foreach ($exclusions as $rule) {
            $sw = (string)($rule['starts_with'] ?? '');
            if ($sw === '') continue;
            $broadPrefixes[$sw[0]] = true;
        }
        if (empty($broadPrefixes)) return [];

        // OR of `=like 'X%'` filters
        $or = [];
        foreach (array_keys($broadPrefixes) as $first) {
            $or[] = ['code', '=like', $first . '%'];
        }
        // Build polish-notation domain: for N filters we need (N-1) leading "|"
        $domain = [];
        for ($i = 0; $i < count($or) - 1; $i++) $domain[] = '|';
        $domain = array_merge($domain, $or);

        $rows = $this->odoo->searchRead(
            'account.account',
            $domain,
            ['code'],
            ['limit' => 5000]
        );

        $excluded = [];
        foreach ($rows as $a) {
            $code = (string)($a['code'] ?? '');
            foreach ($exclusions as $rule) {
                $sw = (string)($rule['starts_with'] ?? '');
                if ($sw === '' || !str_starts_with($code, $sw)) continue;
                $excepted = false;
                foreach (($rule['except_starts_with'] ?? []) as $e) {
                    if (str_starts_with($code, (string)$e)) { $excepted = true; break; }
                }
                if (!$excepted) {
                    $excluded[$a['id']] = true;
                    break;
                }
            }
        }
        return array_keys($excluded);
    }

    private function getCompanyId(): int
    {
        if ($this->companyId !== null) return $this->companyId;
        try {
            $uid  = $this->odoo->authenticate();
            $user = $this->odoo->read('res.users', [$uid], ['company_id']);
            $cid  = $user[0]['company_id'] ?? null;
            $this->companyId = is_array($cid) ? (int)($cid[0] ?? 1) : 1;
        } catch (\Throwable $e) {
            $this->companyId = 1;
        }
        return $this->companyId;
    }

    private function safe(callable $fn, array &$errors, string $tag)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            $errors[] = "{$tag}: " . $e->getMessage();
            return null;
        }
    }
}
