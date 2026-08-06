<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;
use DateTimeImmutable;

/**
 * Treasury · Collections by Revenue Stream (TSD) — CASH BASIS.
 *
 * Collections are counted when the money is APPLIED, not when the invoice
 * was issued: the engine reads Odoo's settlement records
 * (account.partial.reconcile — every payment/credit applied to a customer
 * invoice, with its own settlement date), so an invoice issued in April and
 * paid in July counts in July. ALL customer invoices participate, whatever
 * their issue date (including prior years).
 *
 * Each settlement is disclosed in BOTH units:
 *   - TTC: the actual amount applied against the invoice (cash incl. taxes)
 *   - HT : the net-of-tax component, pro-rated by that invoice's own
 *          HT / TTC ratio (an invoice of 10M TTC carrying 8M HT that gets
 *          fully paid shows 10M collected TTC and 8M collected HT).
 *
 * Stream attribution routes each invoice's HT through its class-70 lines
 * using the SAME matcher as Product Revenue TSD (assigned + redistribution
 * shares); the invoice's non-70 remainder is disclosed as "Other income" so
 * every settled franc is accounted for — nothing silently dropped.
 */
final class CollectionsByStreamService
{
    public function __construct(
        private OdooClient $odoo,
        private GLRevenueService $gl,
    ) {}

    /**
     * @param array{type?:string, year?:int, month?:int} $period Master-filter
     *        selection. Column group 1 = the selected window (that month's
     *        settlements when Month·July is picked; current month on the
     *        default view); group 2 = YTD of the selected year. Each group
     *        carries HT and TTC.
     */
    public function analyze(?DateTimeImmutable $today = null, array $period = ['type' => 'ytd']): array
    {
        $today = $today ?? new DateTimeImmutable('today');
        [$selStart, $selEnd, , $ntype] = ea_resolve_period($today, $period);

        $year    = (int) $selStart->format('Y');
        $isCurYr = $year === (int) $today->format('Y');
        $to      = $selEnd->format('Y-m-d');
        $yFrom   = sprintf('%04d-01-01', $year);
        $months  = max(1, (int) $selEnd->format('n'));

        // Selected column group: a specific month bucket, or the current
        // month on the default YTD view; a past full year collapses to YTD.
        if ($ntype === 'month') {
            $selMonth = (int) $selStart->format('n');
            $selLabel = $selStart->format('M Y');
        } elseif ($isCurYr) {
            $selMonth = (int) $today->format('n');
            $selLabel = 'MTD';
        } else {
            $selMonth = null;
            $selLabel = 'Year ' . $year;
        }
        $ytdLabel = $isCurYr ? 'YTD' : 'YTD ' . $year;

        // ── Class-70 accounts through the page's own matcher. ─────────────
        $accIndex = [];
        foreach ($this->odoo->searchRead('account.account', [['code', '=like', '70%']], ['id', 'code', 'name'], []) as $a) {
            $id = (int) ($a['id'] ?? 0);
            if ($id > 0) {
                $accIndex[$id] = ['name' => (string) ($a['name'] ?? ''), 'code' => (string) ($a['code'] ?? '')];
            }
        }
        $match         = $this->gl->matchAccountsToProducts(['accounts' => $accIndex, 'by_account_month' => []]);
        $assigned      = (array) ($match['assigned'] ?? []);
        $redistributed = (array) ($match['redistributed'] ?? []);

        // ── Settlements applied in the year (payment-dated). ──────────────
        $settles = [];   // [ [line_id, month, amount_ttc], ... ]
        $lineIds = [];
        $offset  = 0;
        while (true) {
            $page = $this->odoo->searchRead('account.partial.reconcile',
                [['max_date', '>=', $yFrom], ['max_date', '<=', $to],
                 ['debit_move_id.move_id.move_type', '=', 'out_invoice']],
                ['amount', 'max_date', 'debit_move_id'],
                ['limit' => 1000, 'offset' => $offset]);
            foreach ($page as $r) {
                $lid = is_array($r['debit_move_id'] ?? null) ? (int) $r['debit_move_id'][0] : (int) ($r['debit_move_id'] ?? 0);
                $mo  = (int) substr((string) ($r['max_date'] ?? '0000-00'), 5, 2);
                $amt = (float) ($r['amount'] ?? 0);
                if ($lid > 0 && $mo >= 1 && $mo <= 12 && $amt != 0.0) {
                    $settles[] = [$lid, $mo, $amt];
                    $lineIds[$lid] = true;
                }
            }
            if (count($page) < 1000) break;
            $offset += 1000;
        }

        // ── Receivable line → invoice map. ────────────────────────────────
        $lineToMove = [];
        foreach (array_chunk(array_keys($lineIds), 800) as $chunk) {
            foreach ($this->odoo->searchRead('account.move.line', [['id', 'in', $chunk]], ['id', 'move_id'], ['limit' => 1000]) as $l) {
                $mid = is_array($l['move_id'] ?? null) ? (int) $l['move_id'][0] : (int) ($l['move_id'] ?? 0);
                if ($mid > 0) $lineToMove[(int) $l['id']] = $mid;
            }
        }
        $moveIds = array_values(array_unique(array_values($lineToMove)));

        // ── Invoice HT/TTC + class-70 mix (any issue date, incl. prior years).
        $moveInfo = [];
        foreach (array_chunk($moveIds, 800) as $chunk) {
            foreach ($this->odoo->searchRead('account.move', [['id', 'in', $chunk]], ['id', 'amount_untaxed_signed', 'amount_total_signed'], ['limit' => 1000]) as $m) {
                $moveInfo[(int) $m['id']] = [
                    'ht'  => abs((float) ($m['amount_untaxed_signed'] ?? 0)),
                    'ttc' => abs((float) ($m['amount_total_signed'] ?? 0)),
                    'mix' => [],
                ];
            }
        }
        foreach (array_chunk($moveIds, 600) as $chunk) {
            $offset = 0;
            while (true) {
                $lines = $this->odoo->searchRead('account.move.line',
                    [['move_id', 'in', $chunk], ['account_id', 'in', array_keys($accIndex)]],
                    ['move_id', 'account_id', 'price_subtotal'],
                    ['limit' => 1000, 'offset' => $offset]);
                foreach ($lines as $l) {
                    $mid = is_array($l['move_id'] ?? null) ? (int) $l['move_id'][0] : (int) ($l['move_id'] ?? 0);
                    $aid = is_array($l['account_id'] ?? null) ? (int) $l['account_id'][0] : (int) ($l['account_id'] ?? 0);
                    if (isset($moveInfo[$mid]) && $aid > 0) {
                        $moveInfo[$mid]['mix'][$aid] = ($moveInfo[$mid]['mix'][$aid] ?? 0.0) + (float) ($l['price_subtotal'] ?? 0);
                    }
                }
                if (count($lines) < 1000) break;
                $offset += 1000;
            }
        }

        // ── Stream skeleton from the registry. ────────────────────────────
        $products = (array) config('budget_products.products', []);
        $cats     = (array) config('budget_products.categories', []);
        $mkMonths = fn () => array_fill(1, $months, 0.0);
        $streams  = [];
        foreach ($products as $p) {
            if (($p['revenue_source'] ?? null) !== 'gl_706_named') continue;
            $streams[(string) $p['key']] = [
                'key' => (string) $p['key'], 'label' => (string) ($p['label'] ?? $p['key']),
                'category' => (string) ($p['category'] ?? 'other_cargo'),
                'color' => (string) ($p['color'] ?? '#64748b'),
                'ht' => $mkMonths(), 'ttc' => $mkMonths(),
            ];
        }
        $unmatched = ['ht' => $mkMonths(), 'ttc' => $mkMonths(), 'accounts' => []];
        $other     = ['ht' => $mkMonths(), 'ttc' => $mkMonths()];

        $routeShares = function (int $aid) use ($assigned, $redistributed): array {
            $pk = $assigned[$aid] ?? null;
            if ($pk !== null) return [(string) $pk => 1.0];
            $targets = (array) ($redistributed[$aid]['targets'] ?? []);
            return $targets !== [] ? array_map('floatval', $targets) : [];
        };

        // ── Fold every settlement into month × stream × (HT, TTC). ───────
        $count = 0;
        foreach ($settles as [$lid, $mo, $ttcAmt]) {
            if ($mo > $months) continue;
            $mid = $lineToMove[$lid] ?? 0;
            $inf = $moveInfo[$mid] ?? null;
            if ($inf === null || $inf['ttc'] <= 0.0) continue;
            $count++;

            $htAmt = $ttcAmt * ($inf['ht'] / $inf['ttc']);
            $invHt = $inf['ht'] > 0.0 ? $inf['ht'] : 1.0;

            $covered = 0.0;
            foreach ($inf['mix'] as $aid => $sub) {
                $frac = $sub / $invHt;              // this account's share of the invoice HT
                if ($frac == 0.0) continue;
                $shares = $routeShares((int) $aid);
                if ($shares === []) {
                    $unmatched['ht'][$mo]  += $htAmt * $frac;
                    $unmatched['ttc'][$mo] += $ttcAmt * $frac;
                    $name = trim(($accIndex[$aid]['code'] ?? '') . ' ' . ($accIndex[$aid]['name'] ?? ''));
                    if ($name !== '' && !in_array($name, $unmatched['accounts'], true)) $unmatched['accounts'][] = $name;
                } else {
                    foreach ($shares as $pk => $share) {
                        if (!isset($streams[$pk])) continue;
                        $streams[$pk]['ht'][$mo]  += $htAmt * $frac * $share;
                        $streams[$pk]['ttc'][$mo] += $ttcAmt * $frac * $share;
                    }
                }
                $covered += $frac;
            }
            // The invoice's non-70 remainder (other income lines, rounding).
            $rest = max(0.0, 1.0 - $covered);
            if ($rest > 0.0001) {
                $other['ht'][$mo]  += $htAmt * $rest;
                $other['ttc'][$mo] += $ttcAmt * $rest;
            }
        }

        // ── Windows: selected month bucket + YTD sums. ────────────────────
        $win = function (array $byMonth) use ($selMonth): array {
            return [
                'sel' => $selMonth !== null ? (float) ($byMonth[$selMonth] ?? 0.0) : array_sum($byMonth),
                'ytd' => array_sum($byMonth),
            ];
        };

        $orderOf = [];
        foreach ($cats as $ck => $c) $orderOf[$ck] = (int) ($c['order'] ?? 99);
        $rows = [];
        foreach ($streams as $s) {
            $ht = $win($s['ht']); $ttc = $win($s['ttc']);
            $rows[] = [
                'key' => $s['key'], 'label' => $s['label'], 'category' => $s['category'], 'color' => $s['color'],
                'sel_ht' => $ht['sel'], 'sel_ttc' => $ttc['sel'], 'ytd_ht' => $ht['ytd'], 'ytd_ttc' => $ttc['ytd'],
            ];
        }
        usort($rows, fn ($a, $b) => [($orderOf[$a['category']] ?? 99), $a['label']] <=> [($orderOf[$b['category']] ?? 99), $b['label']]);

        $catTotals = [];
        foreach ($rows as $r) {
            $catTotals[$r['category']] ??= ['sel_ht' => 0.0, 'sel_ttc' => 0.0, 'ytd_ht' => 0.0, 'ytd_ttc' => 0.0];
            foreach (['sel_ht', 'sel_ttc', 'ytd_ht', 'ytd_ttc'] as $k) $catTotals[$r['category']][$k] += $r[$k];
        }

        $unW = ['sel_ht' => $win($unmatched['ht'])['sel'], 'sel_ttc' => $win($unmatched['ttc'])['sel'],
                'ytd_ht' => $win($unmatched['ht'])['ytd'], 'ytd_ttc' => $win($unmatched['ttc'])['ytd'],
                'accounts' => $unmatched['accounts']];
        $otW = ['sel_ht' => $win($other['ht'])['sel'], 'sel_ttc' => $win($other['ttc'])['sel'],
                'ytd_ht' => $win($other['ht'])['ytd'], 'ytd_ttc' => $win($other['ttc'])['ytd']];

        $totals = ['sel_ht' => $unW['sel_ht'] + $otW['sel_ht'], 'sel_ttc' => $unW['sel_ttc'] + $otW['sel_ttc'],
                   'ytd_ht' => $unW['ytd_ht'] + $otW['ytd_ht'], 'ytd_ttc' => $unW['ytd_ttc'] + $otW['ytd_ttc']];
        foreach ($rows as $r) {
            foreach (['sel_ht', 'sel_ttc', 'ytd_ht', 'ytd_ttc'] as $k) $totals[$k] += $r[$k];
        }

        // ── Monthly chart series (TTC — the actual cash), labels Jan..M. ──
        $labels = [];
        for ($m = 1; $m <= $months; $m++) {
            $labels[] = DateTimeImmutable::createFromFormat('!Y-n-j', "{$year}-{$m}-1")->format('M');
        }
        $chart = [];
        foreach ($streams as $s) {
            $data = array_values($s['ttc']);
            if (array_sum($data) == 0.0) continue;
            $chart[] = ['key' => $s['key'], 'label' => $s['label'], 'color' => $s['color'], 'data' => $data];
        }
        if (array_sum($unmatched['ttc']) != 0.0) {
            $chart[] = ['key' => '_unmatched', 'label' => 'Unmatched 70x', 'color' => '#b45309', 'data' => array_values($unmatched['ttc'])];
        }

        $columns = $selMonth !== null
            ? [['k' => 'sel_ht', 'label' => $selLabel . ' · HT'], ['k' => 'sel_ttc', 'label' => $selLabel . ' · TTC'],
               ['k' => 'ytd_ht', 'label' => $ytdLabel . ' · HT'], ['k' => 'ytd_ttc', 'label' => $ytdLabel . ' · TTC']]
            : [['k' => 'ytd_ht', 'label' => $ytdLabel . ' · HT'], ['k' => 'ytd_ttc', 'label' => $ytdLabel . ' · TTC']];

        return [
            'as_of'      => $to,
            'basis'      => 'cash',
            'columns'    => $columns,
            'labels'     => ['sel' => $selLabel, 'ytd' => $ytdLabel],
            'monthly'    => ['labels' => $labels, 'series' => $chart],
            'streams'    => $rows,
            'categories' => $cats,
            'cat_totals' => $catTotals,
            'unmatched'  => $unW,
            'other'      => $otW,
            'totals'     => $totals,
            'meta'       => ['settlements' => $count, 'invoices' => count($moveIds)],
        ];
    }
}
