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
    private const FETCH_LIMIT = 50000;

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
                    $this->checkPerLine('3', 'Short descriptions (< 5 chars)',
                        'Single-character, numeric-only, or fewer than 5 characters.',
                        fn($l) => $this->isShort($l['name']), $lines),
                ],
            ],
            'identification' => [
                'label'  => 'Identification',
                'checks' => [
                    $this->checkPerLine('4', 'Missing counterparty name (40X / 41X)',
                        'Lines on supplier (40X) or customer (41X) accounts whose narration does not include the partner name.',
                        fn($l) => $this->missingCounterparty($l), $lines),
                    $this->checkPerLine('5', 'Missing document reference',
                        'Narration contains no alphanumeric document identifier (e.g. invoice no, voucher).',
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

        // Flatten + score
        $allChecks = [];
        foreach ($categories as $cat) {
            foreach ($cat['checks'] as $c) {
                $allChecks[] = $c;
            }
        }
        $passed = count(array_filter($allChecks, fn($c) => $c['passed']));
        $total  = count($allChecks);
        $score  = $total > 0 ? round($passed / $total * 100, 1) : 100.0;

        return [
            'overall_score'   => $score,
            'checks_passed'   => $passed,
            'checks_total'    => $total,
            'lines_reviewed'  => count($lines),
            'lines_capped'    => count($lines) >= self::FETCH_LIMIT,
            'fetch_limit'     => self::FETCH_LIMIT,
            'exclusions'      => $exclusions,
            'categories'      => $categories,
            'error'           => empty($errors) ? null : implode(' | ', $errors),
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
        foreach ($lines as $l) {
            if ($predicate($l)) $count++;
        }
        return [
            'no'          => $no,
            'name'        => $name,
            'description' => $description,
            'passed'      => $count === 0,
            'count'       => $count,
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
            $byPartner[$p][$norm] = ($byPartner[$p][$norm] ?? 0) + 1;
        }
        $offendingLines = 0;
        foreach ($byPartner as $p => $variants) {
            // > 3 distinct narration variants for the same partner = noisy
            if (count($variants) > 3) {
                $offendingLines += array_sum($variants);
            }
        }
        return [
            'no'          => '7',
            'name'        => 'Inconsistent counterparty naming',
            'description' => 'Same supplier/customer appearing with > 3 distinct narration variants across the GL.',
            'passed'      => $offendingLines === 0,
            'count'       => $offendingLines,
        ];
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
        if (mb_strlen($t) < 5) return true;
        // Numeric-only of any length is also low-value
        if (preg_match('/^\d+$/', $t)) return true;
        return false;
    }

    private function missingCounterparty(array $l): bool
    {
        $code = $l['account_code'];
        if (!(str_starts_with($code, '40') || str_starts_with($code, '41'))) return false;
        $partnerName = trim($l['partner_name']);
        if ($partnerName === '') return true;            // posting on AP/AR with no partner = orphan
        // Does the narration mention the partner name? (case-insensitive, first 4+ chars)
        $key = mb_strtolower(mb_substr($partnerName, 0, 4));
        if (mb_strlen($key) < 3) return false;            // too short to test
        return mb_stripos($l['name'], $key) === false;
    }

    private function missingDocRef(array $l): bool
    {
        $hay = $l['name'] . ' ' . $l['ref'];
        // Looking for an alphanumeric token of 4+ chars containing at least one digit.
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

        // Keyword → required account-code prefix
        static $rules = [
            'salary'        => '66',
            'salaire'       => '66',
            'salaires'      => '66',
            'payroll'       => '66',
            'fuel'          => '624',
            'carburant'     => '624',
            'rent'          => '622',
            'loyer'         => '622',
            'depreciation'  => '68',
            'amortissement' => '68',
        ];
        foreach ($rules as $kw => $expectedPrefix) {
            if (mb_stripos($hay, $kw) !== false && !str_starts_with($code, $expectedPrefix)) {
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
