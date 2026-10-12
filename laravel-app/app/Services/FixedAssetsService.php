<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Fixed assets — register analytics for decision making.
 *
 * Source: Odoo's `account.asset` (Accounting › Assets, Enterprise). The
 * fields read are resolved against fields_get first, so the same code runs
 * on Odoo 14–16 (asset_type, value_residual only) and 17–19 (book_value,
 * account_asset_id, no asset_type). Read-only.
 *
 * Sections:
 *   kpis           gross, accumulated depreciation, net book value, depreciation
 *                  YTD (GL class 68), active count, value-weighted age, % consumed,
 *                  fully-depreciated-still-in-use, reaching end of life ≤ 12 months
 *   by_category    per fixed-asset account (SYSCOHADA class 2): count, gross,
 *                  accumulated, NBV, % depreciated, average remaining life
 *   forecast       depreciation charge for the next 12 months (draft
 *                  depreciation entries already scheduled in Odoo)
 *   watchlist      replacement planning — assets fully depreciated or ending
 *                  within 12 months, with remaining life
 *   capex          acquisitions per year (gross value added)
 *   recon          register vs general ledger: asset accounts (gross) and
 *                  depreciation accounts (accumulated)
 *   top_assets     largest assets by net book value
 *   quality        register hygiene flags (missing dates/descriptions)
 */
final class FixedAssetsService
{
    public function __construct(private OdooClient $odoo) {}

    public function analyze(?\DateTimeImmutable $today = null): array
    {
        $today    = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0, 0);
        $currency = $this->detectCurrency();
        $notes    = [];

        // 1. Register — only fields that exist on this Odoo version.
        try {
            $avail = array_keys((array) $this->odoo->callModel('account.asset', 'fields_get', ['attributes' => ['type']]));
        } catch (\Throwable $e) {
            return $this->emptyResult($currency, 'The Assets module (account.asset) is not available on this Odoo instance, or the API user cannot read it.');
        }
        $want = ['id', 'name', 'state', 'original_value', 'book_value', 'value_residual', 'salvage_value',
                 'acquisition_date', 'prorata_date', 'method', 'method_number', 'method_period',
                 'account_asset_id', 'account_depreciation_id', 'asset_type', 'disposal_date', 'model_id'];
        $fields = array_values(array_intersect($want, $avail));
        $domain = [['state', 'in', ['open', 'paused', 'close']]];
        if (in_array('asset_type', $avail, true)) $domain[] = ['asset_type', '=', 'purchase'];

        $assets = [];
        $offset = 0;
        do {
            $batch = $this->odoo->searchRead('account.asset', $domain, $fields,
                ['offset' => $offset, 'limit' => 500, 'order' => 'original_value desc, id asc']);
            foreach ($batch as $a) $assets[] = $a;
            $offset += 500;
        } while (count($batch) === 500);

        $hasBook = in_array('book_value', $avail, true);
        $m2o = fn ($v) => is_array($v) ? [(int) $v[0], (string) ($v[1] ?? '')] : [0, ''];

        $k = ['gross_value' => 0.0, 'net_book_value' => 0.0, 'active_asset_count' => 0, 'closed_count' => 0,
              'fully_depreciated_count' => 0, 'fully_depreciated_gross' => 0.0,
              'eol_12m_count' => 0, 'eol_12m_gross' => 0.0];
        $weightedAge = 0.0;
        $byCat = []; $watch = []; $top = []; $capex = []; $quality = ['no_acquisition_date' => 0, 'missing_description' => 0, 'no_asset_account' => 0];
        $regGrossByAcc = []; $regAccumByAcc = [];

        foreach ($assets as $a) {
            $state = (string) ($a['state'] ?? '');
            $gross = (float) ($a['original_value'] ?? 0);
            // Book value = what remains on the balance sheet (depreciable
            // residual + salvage). Older versions only have value_residual.
            $nbv = $hasBook ? (float) ($a['book_value'] ?? 0)
                            : (float) ($a['value_residual'] ?? 0) + (float) ($a['salvage_value'] ?? 0);
            if ($state === 'close') { $k['closed_count']++; continue; }   // disposed: off the books
            $k['active_asset_count']++;
            $k['gross_value']    += $gross;
            $k['net_book_value'] += $nbv;
            $accum = $gross - $nbv;

            [$accId, $accName] = $m2o($a['account_asset_id'] ?? null);
            [$depId, $depName] = $m2o($a['account_depreciation_id'] ?? null);
            if (!$accId) $quality['no_asset_account']++;
            $cat = $accName !== '' ? $accName : (string) ($a['asset_type'] ?? 'Unclassified');
            if ($accId) $regGrossByAcc[$accId] = ($regGrossByAcc[$accId] ?? 0.0) + $gross;
            if ($depId) $regAccumByAcc[$depId] = ['name' => $depName, 'v' => ($regAccumByAcc[$depId]['v'] ?? 0.0) + $accum];

            $acq = (string) (($a['acquisition_date'] ?? false) ?: (($a['prorata_date'] ?? false) ?: ''));
            if ($acq === '') $quality['no_acquisition_date']++;
            $name = (string) ($a['name'] ?? ('#' . $a['id']));
            if (preg_match('/manquant|missing|inconnu|unknown|\?\?/i', $name)) $quality['missing_description']++;

            // Life: total months vs months elapsed since the depreciation start.
            $life = (int) ($a['method_number'] ?? 0) * max(1, (int) ($a['method_period'] ?? 1));
            $start = (string) (($a['prorata_date'] ?? '') ?: $acq);
            $elapsed = null; $remaining = null;
            if ($start !== '' && $life > 0) {
                $s = new \DateTimeImmutable(substr($start, 0, 10));
                $elapsed = ((int) $today->format('Y') - (int) $s->format('Y')) * 12 + ((int) $today->format('n') - (int) $s->format('n'));
                $remaining = max(0, $life - $elapsed);
            }
            if ($acq !== '') {
                $ageDays = max(0, (int) (new \DateTimeImmutable(substr($acq, 0, 10)))->diff($today)->days);
                $weightedAge += $ageDays * $gross;
                $y = substr($acq, 0, 4);
                $capex[$y] = ['gross' => ($capex[$y]['gross'] ?? 0.0) + $gross, 'count' => ($capex[$y]['count'] ?? 0) + 1];
            }

            $fully = $gross > 0 && $nbv <= 0.5;
            if ($fully) { $k['fully_depreciated_count']++; $k['fully_depreciated_gross'] += $gross; }
            elseif ($remaining !== null && $remaining <= 12) { $k['eol_12m_count']++; $k['eol_12m_gross'] += $gross; }

            $c = $byCat[$cat] ?? ['category' => $cat, 'count' => 0, 'gross' => 0.0, 'accum' => 0.0, 'nbv' => 0.0, 'rem_w' => 0.0, 'rem_g' => 0.0, 'fully' => 0];
            $c['count']++; $c['gross'] += $gross; $c['accum'] += $accum; $c['nbv'] += $nbv;
            if ($remaining !== null) { $c['rem_w'] += $remaining * $gross; $c['rem_g'] += $gross; }
            if ($fully) $c['fully']++;
            $byCat[$cat] = $c;

            $row = ['name' => $name, 'category' => $cat, 'acquired' => $acq, 'gross' => $gross, 'accum' => $accum,
                    'nbv' => $nbv, 'depr_pct' => $gross > 0 ? 100 * $accum / $gross : 0.0,
                    'life_months' => $life ?: null, 'remaining_months' => $remaining, 'state' => $state];
            $top[] = $row;
            if ($fully || ($remaining !== null && $remaining <= 12)) $watch[] = $row + ['status' => $fully ? 'Fully depreciated' : 'Ends within 12 months'];
        }

        $k['accumulated_depr']       = $k['gross_value'] - $k['net_book_value'];
        $k['depreciation_ratio_pct'] = $k['gross_value'] > 0 ? 100 * $k['accumulated_depr'] / $k['gross_value'] : 0.0;
        $k['avg_asset_age_years']    = $k['gross_value'] > 0 ? ($weightedAge / $k['gross_value']) / 365.25 : 0.0;

        // 2. Depreciation YTD from the GL (class 68) and same window last year.
        $k['depreciation_ytd'] = $this->glSum('68%', $today->format('Y') . '-01-01', $today->format('Y-m-d'), $notes);
        $k['depreciation_py_same'] = $this->glSum('68%', ((int) $today->format('Y') - 1) . '-01-01', $today->modify('-1 year')->format('Y-m-d'), $notes);

        // 3. Next-12-month depreciation schedule (draft entries Odoo has already
        //    generated for each running asset).
        $forecast = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $today->modify('first day of this month')->modify("+{$i} months");
            $forecast[$m->format('Y-m')] = ['label' => $m->format('M Y'), 'amount' => 0.0];
        }
        try {
            $moveFields = array_keys((array) $this->odoo->callModel('account.move', 'fields_get', ['attributes' => ['type']]));
            $amtField = in_array('depreciation_value', $moveFields, true) ? 'depreciation_value' : 'amount_total';
            $end = $today->modify('first day of this month')->modify('+12 months')->modify('-1 day');
            foreach ((array) $this->odoo->callModel('account.move', 'read_group', [
                'domain'  => [['asset_id', '!=', false], ['state', '=', 'draft'],
                              ['date', '>=', $today->modify('first day of this month')->format('Y-m-d')], ['date', '<=', $end->format('Y-m-d')]],
                'fields'  => [$amtField . ':sum'], 'groupby' => ['date:month'], 'lazy' => false,
            ]) as $g) {
                $f = $g['__range']['date:month']['from'] ?? $g['__range']['date']['from'] ?? null;
                if (is_string($f) && isset($forecast[substr($f, 0, 7)])) $forecast[substr($f, 0, 7)]['amount'] += (float) ($g[$amtField] ?? 0);
            }
        } catch (\Throwable $e) {
            $notes[] = 'Depreciation schedule unavailable (' . $e->getMessage() . ').';
        }

        // 4. Register vs general ledger.
        $recon = [];
        try {
            $ids = array_unique(array_merge(array_keys($regGrossByAcc), array_keys($regAccumByAcc)));
            $bal = $ids ? $this->balancesByAccount($ids, $today->format('Y-m-d')) : [];
            foreach ($regGrossByAcc as $aid => $v) {
                $gl = $bal[$aid]['balance'] ?? 0.0;
                $recon[] = ['type' => 'Gross value', 'account' => $bal[$aid]['name'] ?? ('#' . $aid), 'register' => $v, 'ledger' => $gl, 'difference' => $v - $gl];
            }
            foreach ($regAccumByAcc as $aid => $r) {
                $gl = -($bal[$aid]['balance'] ?? 0.0);   // credit balance
                $recon[] = ['type' => 'Accumulated depreciation', 'account' => $bal[$aid]['name'] ?? $r['name'], 'register' => $r['v'], 'ledger' => $gl, 'difference' => $r['v'] - $gl];
            }
        } catch (\Throwable $e) {
            $notes[] = 'Ledger reconciliation unavailable.';
        }

        foreach ($byCat as &$c) {
            $c['depr_pct'] = $c['gross'] > 0 ? 100 * $c['accum'] / $c['gross'] : 0.0;
            $c['avg_remaining_months'] = $c['rem_g'] > 0 ? $c['rem_w'] / $c['rem_g'] : null;
            $c['share_nbv'] = $k['net_book_value'] > 0 ? 100 * $c['nbv'] / $k['net_book_value'] : 0.0;
            unset($c['rem_w'], $c['rem_g']);
        }
        unset($c);
        usort($byCat, fn ($a, $b) => $b['gross'] <=> $a['gross']);
        usort($top, fn ($a, $b) => $b['nbv'] <=> $a['nbv']);
        usort($watch, fn ($a, $b) => [$a['remaining_months'] ?? 0, -$a['gross']] <=> [$b['remaining_months'] ?? 0, -$b['gross']]);
        ksort($capex);

        return [
            'currency'    => $currency,
            'as_of'       => $today->format('Y-m-d'),
            'module'      => 'present',
            'message'     => $assets ? null : 'The Assets module is installed but the register is empty.',
            'kpis'        => $k,
            'by_category' => array_values($byCat),
            'forecast'    => array_values($forecast),
            'watchlist'   => $watch,
            'capex'       => $capex,
            'recon'       => $recon,
            'top_assets'  => array_slice($top, 0, 15),
            'quality'     => $quality,
            'asset_count' => $k['active_asset_count'],
            'notes'       => $notes,
        ];
    }

    private function glSum(string $like, string $from, string $to, array &$notes): float
    {
        try {
            $ids = array_map(fn ($r) => (int) $r['id'], $this->odoo->searchRead('account.account', [['code', '=like', $like]], ['id'], []));
            if (!$ids) return 0.0;
            $rows = $this->odoo->callModel('account.move.line', 'read_group', [
                'domain'  => [['parent_state', '=', 'posted'], ['date', '>=', $from], ['date', '<=', $to], ['account_id', 'in', $ids]],
                'fields'  => ['balance:sum'], 'groupby' => [], 'lazy' => false,
            ]);
            return (float) ($rows[0]['balance'] ?? 0);
        } catch (\Throwable $e) {
            $notes[] = 'GL depreciation charge unavailable.';
            return 0.0;
        }
    }

    /** @return array<int, array{name:string,balance:float}> posted balance to date */
    private function balancesByAccount(array $ids, string $to): array
    {
        $out = [];
        foreach ((array) $this->odoo->callModel('account.move.line', 'read_group', [
            'domain'  => [['parent_state', '=', 'posted'], ['date', '<=', $to], ['account_id', 'in', array_values($ids)]],
            'fields'  => ['balance:sum'], 'groupby' => ['account_id'], 'lazy' => false,
        ]) as $g) {
            if (!is_array($g['account_id'] ?? null)) continue;
            $out[(int) $g['account_id'][0]] = ['name' => (string) $g['account_id'][1], 'balance' => (float) ($g['balance'] ?? 0)];
        }
        return $out;
    }

    private function emptyResult(string $currency, string $message): array
    {
        return [
            'currency' => $currency, 'as_of' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'module' => 'absent', 'message' => $message,
            'kpis' => ['gross_value' => 0.0, 'net_book_value' => 0.0, 'accumulated_depr' => 0.0, 'depreciation_ytd' => 0.0,
                       'depreciation_py_same' => 0.0, 'active_asset_count' => 0, 'closed_count' => 0, 'avg_asset_age_years' => 0.0,
                       'depreciation_ratio_pct' => 0.0, 'fully_depreciated_count' => 0, 'fully_depreciated_gross' => 0.0,
                       'eol_12m_count' => 0, 'eol_12m_gross' => 0.0],
            'by_category' => [], 'forecast' => [], 'watchlist' => [], 'capex' => [], 'recon' => [],
            'top_assets' => [], 'quality' => [], 'asset_count' => 0, 'notes' => [],
        ];
    }

    private function detectCurrency(): string
    {
        try {
            $uid = $this->odoo->authenticate();
            $user = $this->odoo->read('res.users', [$uid], ['company_id']);
            $cid = isset($user[0]['company_id'][0]) ? (int) $user[0]['company_id'][0] : 1;
            $co  = $this->odoo->read('res.company', [$cid], ['currency_id']);
            $crid = isset($co[0]['currency_id'][0]) ? (int) $co[0]['currency_id'][0] : 0;
            if ($crid > 0) {
                $c = $this->odoo->read('res.currency', [$crid], ['name']);
                if (isset($c[0]['name'])) return (string) $c[0]['name'];
            }
        } catch (\Throwable $e) { /* fall through */ }
        return 'XAF';
    }
}
