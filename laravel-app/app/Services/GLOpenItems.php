<?php
declare(strict_types=1);

namespace App\Services;

use App\Odoo\OdooClient;

/**
 * Open receivable / payable items rebuilt from the general ledger, shaped
 * like account.move records so ReceivablesService / PayablesService can age
 * them unchanged.
 *
 * Used when a tenant has no open customer invoices / vendor bills but its
 * ledger carries the balances anyway (sales and purchases migrated or
 * posted as journal entries — Elimelec). Per partner, the net balance of the
 * unreconciled lines is the amount owed; it is allocated to the most recent
 * lines first (oldest items assumed settled first, FIFO), so the total ties
 * to the ledger and ageing reflects the newest unpaid documents.
 */
final class GLOpenItems
{
    public function __construct(private OdooClient $odoo) {}

    /**
     * @param 'receivable'|'payable' $side
     * @return array<int, array<string, mixed>> invoice-shaped records
     */
    public function fetch(string $side): array
    {
        $receivable = $side === 'receivable';
        $accounts = $this->odoo->searchRead('account.account', [
            '|', ['account_type', '=', $receivable ? 'asset_receivable' : 'liability_payable'],
                 ['code', '=like', $receivable ? '411%' : '401%'],
        ], ['id', 'reconcile'], []);
        if (!$accounts) return [];
        $reconcilable = [];
        foreach ($accounts as $a) $reconcilable[(int) $a['id']] = !empty($a['reconcile']);

        $lines = [];
        $offset = 0;
        do {
            $batch = $this->odoo->searchRead('account.move.line', [
                ['parent_state', '=', 'posted'],
                ['account_id', 'in', array_keys($reconcilable)],
                ['reconciled', '=', false],
            ], ['id', 'move_name', 'partner_id', 'account_id', 'date', 'date_maturity', 'balance', 'amount_residual'],
               ['offset' => $offset, 'limit' => 2000, 'order' => 'date asc, id asc']);
            foreach ($batch as $l) $lines[] = $l;
            $offset += 2000;
        } while (count($batch) === 2000);

        // Signed so that "owed" is positive: debit for AR, credit for AP.
        $sign = $receivable ? 1.0 : -1.0;
        $byPartner = [];
        foreach ($lines as $l) {
            $aid = is_array($l['account_id'] ?? null) ? (int) $l['account_id'][0] : 0;
            $amt = !empty($reconcilable[$aid]) ? (float) ($l['amount_residual'] ?? 0) : (float) ($l['balance'] ?? 0);
            $amt *= $sign;
            if (abs($amt) < 0.005) continue;
            $pid = is_array($l['partner_id'] ?? null) ? (int) $l['partner_id'][0] : 0;
            $byPartner[$pid]['partner'] = $pid ? $l['partner_id'] : false;
            $byPartner[$pid]['lines'][] = $l + ['_amt' => $amt];
        }

        $records = [];
        foreach ($byPartner as $pid => $g) {
            $net = array_sum(array_column($g['lines'], '_amt'));
            if (abs($net) < 0.5) continue;
            // Allocate the net to the newest lines carrying the same sign.
            $remaining = abs($net);
            foreach (array_reverse($g['lines']) as $l) {
                if ($remaining < 0.5) break;
                if (($l['_amt'] > 0) !== ($net > 0)) continue;
                $take = min(abs($l['_amt']), $remaining);
                $remaining -= $take;
                $signed = $net > 0 ? $take : -$take;
                $records[] = [
                    'id'                     => (int) $l['id'],
                    'name'                   => (string) ($l['move_name'] ?? ''),
                    'partner_id'             => $g['partner'],
                    'invoice_date'           => $l['date'] ?: false,
                    'invoice_date_due'       => ($l['date_maturity'] ?? false) ?: ($l['date'] ?: false),
                    'amount_total_signed'    => $signed,
                    'amount_residual_signed' => $signed,
                    'amount_residual'        => $take,
                    'move_type'              => $receivable ? ($net > 0 ? 'out_invoice' : 'out_refund')
                                                            : ($net > 0 ? 'in_invoice' : 'in_refund'),
                ];
            }
        }
        usort($records, fn ($a, $b) => strcmp((string) $a['invoice_date_due'], (string) $b['invoice_date_due']));
        return $records;
    }
}
