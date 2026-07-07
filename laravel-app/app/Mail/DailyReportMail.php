<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Daily prior-day activity report emailed to the client owner at 08:00:
 * one-line narrative, summary totals, then three tables — sales (cash vs
 * credit, by customer), inventory movements at standard cost, and operating
 * expenses (day + cumulative month-to-date).
 *
 * The body is built as an inline-styled HTML string (no Blade view): mail
 * views would need runtime compilation, which the web user on this host
 * cannot do (storage/framework/views is not writable) — and email clients
 * need inline styles anyway.
 *
 * Data arrays come straight from ReportsService::dailySales / dailyInventory
 * / dailyExpenses.
 */
class DailyReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $brand,
        public string $date,        // Y-m-d (the reported day)
        public array  $sales,
        public array  $inventory,
        public array  $expenses,
        public string $currency = 'XAF',
    ) {}

    public function envelope(): Envelope
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->date) ?: new \DateTimeImmutable($this->date);
        return new Envelope(
            subject: 'Daily Report · ' . $this->brand . ' — ' . $d->format('l, j F Y'),
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->buildHtml());
    }

    // ── HTML assembly ────────────────────────────────────────────────────

    private function n(float $v): string
    {
        return number_format($v, 0, '.', ',');
    }

    private function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    private function narrative(): string
    {
        $s = $this->sales; $i = $this->inventory; $x = $this->expenses;
        $inV = (float) ($i['inward']['value'] ?? 0); $outV = (float) ($i['outward']['value'] ?? 0);
        if ((int) ($s['invoice_count'] ?? 0) > 0) {
            return sprintf(
                'Good morning — yesterday %s sold %s %s across %d invoice%s (%s cash · %s credit), stock moved in %s / out %s, and %s in expenses was recorded (%s so far this month).',
                $this->e($this->brand), $this->n((float) ($s['total'] ?? 0)), $this->currency,
                (int) $s['invoice_count'], ((int) $s['invoice_count'] === 1 ? '' : 's'),
                $this->n((float) ($s['cash_total'] ?? 0)), $this->n((float) ($s['credit_total'] ?? 0)),
                $this->n($inV), $this->n($outV),
                $this->n((float) ($x['day_total'] ?? 0)), $this->n((float) ($x['mtd_total'] ?? 0))
            );
        }
        return sprintf(
            'Good morning — no sales were recorded yesterday at %s; stock moved in %s / out %s, and %s in expenses was recorded (%s so far this month).',
            $this->e($this->brand), $this->n($inV), $this->n($outV),
            $this->n((float) ($x['day_total'] ?? 0)), $this->n((float) ($x['mtd_total'] ?? 0))
        );
    }

    private function buildHtml(): string
    {
        $s = $this->sales; $i = $this->inventory; $x = $this->expenses;
        $ccy = $this->e($this->currency);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->date) ?: new \DateTimeImmutable($this->date);
        $human = $d->format('l, j F Y');

        // Shared inline styles (email-safe: tables + inline CSS only).
        $th  = 'padding:6px 8px;font-size:10px;letter-spacing:.05em;text-transform:uppercase;color:#64748b;border-bottom:1px solid #e2e8f0;text-align:left;';
        $thR = $th . 'text-align:right;';
        $td  = 'padding:6px 8px;font-size:13px;color:#1e293b;border-bottom:1px solid #f1f5f9;';
        $tdR = $td . 'text-align:right;font-variant-numeric:tabular-nums;';
        $tot = 'padding:7px 8px;font-size:13px;font-weight:bold;color:#0f172a;border-top:2px solid #cbd5e1;';
        $totR = $tot . 'text-align:right;';
        $h2  = 'margin:22px 0 8px;font-size:14px;color:#0f172a;';
        $sub = 'font-size:11px;color:#94a3b8;font-weight:normal;';

        $html  = '<div style="margin:0;padding:16px;background:#f1f5f9;font-family:Segoe UI,Arial,Helvetica,sans-serif;">';
        $html .= '<div style="max-width:660px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;">';

        // Header
        $html .= '<div style="background:#0f172a;padding:14px 20px;">'
               . '<table width="100%" cellpadding="0" cellspacing="0"><tr>'
               . '<td style="color:#ffffff;font-size:15px;font-weight:bold;">' . $this->e($this->brand) . ' · Daily Business Report</td>'
               . '<td style="color:#94a3b8;font-size:12px;text-align:right;">' . $this->e($human) . '</td>'
               . '</tr></table></div>';

        $html .= '<div style="padding:18px 20px 24px;">';

        // One-line narrative
        $html .= '<p style="margin:0 0 16px;font-size:13px;line-height:1.55;color:#334155;">' . $this->narrative() . '</p>';

        // ── Summary (totals first) ──
        $rows = [
            ['Sales total',   $this->n((float) ($s['total'] ?? 0)),        'Stock in (@ std cost)',  $this->n((float) ($i['inward']['value'] ?? 0))],
            ['Invoices',      $this->n((float) ($s['invoice_count'] ?? 0)),'Stock out (@ std cost)', $this->n((float) ($i['outward']['value'] ?? 0))],
            ['Outfits sold',  $this->n((float) ($s['outfits'] ?? 0)),      'On-hand (end of day)',   $this->n((float) ($i['on_hand_value'] ?? 0))],
            ['Cash sales',    $this->n((float) ($s['cash_total'] ?? 0)),   'Expenses (day)',         $this->n((float) ($x['day_total'] ?? 0))],
            ['Credit sales',  $this->n((float) ($s['credit_total'] ?? 0)), 'Expenses (' . $this->e($x['month_label'] ?? 'MTD') . ', cum.)', $this->n((float) ($x['mtd_total'] ?? 0))],
        ];
        $lbl = 'padding:5px 8px;font-size:12px;color:#64748b;';
        $val = 'padding:5px 8px;font-size:13px;font-weight:bold;color:#0f172a;text-align:right;font-variant-numeric:tabular-nums;';
        $html .= '<h2 style="' . $h2 . 'margin-top:0;">Summary <span style="' . $sub . '">(' . $ccy . ')</span></h2>';
        $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">';
        foreach ($rows as $r) {
            $html .= '<tr><td style="' . $lbl . '">' . $r[0] . '</td><td style="' . $val . '">' . $r[1] . '</td>'
                   . '<td style="' . $lbl . 'border-left:1px solid #e2e8f0;">' . $r[2] . '</td><td style="' . $val . '">' . $r[3] . '</td></tr>';
        }
        $html .= '</table>';

        // ── Sales table ──
        $html .= '<h2 style="' . $h2 . '">Sales — by customer <span style="' . $sub . '">(' . $ccy . ')</span></h2>';
        if ((int) ($s['invoice_count'] ?? 0) === 0) {
            $html .= '<p style="margin:4px 0 0;font-size:12px;color:#94a3b8;font-style:italic;">No sales recorded on this day.</p>';
        } else {
            $html .= '<table width="100%" cellpadding="0" cellspacing="0">';
            $html .= '<tr><th style="' . $th . '">Customer</th><th style="' . $th . '">Invoice</th><th style="' . $thR . '">Amount</th><th style="' . $th . 'text-align:center;">Type</th><th style="' . $thR . '">Outstanding</th></tr>';
            foreach (($s['rows'] ?? []) as $r) {
                $isCash = ($r['type'] ?? '') === 'cash';
                $badge = $isCash
                    ? '<span style="background:#d1fae5;color:#047857;font-size:10px;font-weight:bold;padding:2px 8px;border-radius:9px;">Cash</span>'
                    : '<span style="background:#fef3c7;color:#b45309;font-size:10px;font-weight:bold;padding:2px 8px;border-radius:9px;">Credit</span>';
                $open = (float) ($r['open'] ?? 0);
                $html .= '<tr><td style="' . $td . '">' . $this->e($r['customer'] ?? '—') . '</td>'
                       . '<td style="' . $td . 'color:#64748b;">' . $this->e($r['invoice'] ?? '') . '</td>'
                       . '<td style="' . $tdR . '">' . $this->n((float) ($r['amount'] ?? 0)) . '</td>'
                       . '<td style="' . $td . 'text-align:center;">' . $badge . '</td>'
                       . '<td style="' . $tdR . ($open > 0 ? 'color:#b45309;' : 'color:#cbd5e1;') . '">' . ($open > 0 ? $this->n($open) : '—') . '</td></tr>';
            }
            $html .= '<tr><td style="' . $tot . '" colspan="2">Total (' . $this->n((float) ($s['cash_total'] ?? 0)) . ' cash · ' . $this->n((float) ($s['credit_total'] ?? 0)) . ' credit)</td>'
                   . '<td style="' . $totR . '">' . $this->n((float) ($s['total'] ?? 0)) . '</td><td style="' . $tot . '"></td>'
                   . '<td style="' . $totR . 'color:#b45309;">' . $this->n(array_sum(array_map(static fn ($r) => max(0.0, (float) ($r['open'] ?? 0)), (array) ($s['rows'] ?? [])))) . '</td></tr>';
            $html .= '</table>';
        }

        // ── Inventory tables ──
        $html .= '<h2 style="' . $h2 . '">Inventory movements <span style="' . $sub . '">(at standard cost, ' . $ccy . ')</span></h2>';
        $inwL = (array) ($i['inward']['lines'] ?? []); $outL = (array) ($i['outward']['lines'] ?? []);
        if (empty($inwL) && empty($outL)) {
            $html .= '<p style="margin:4px 0 0;font-size:12px;color:#94a3b8;font-style:italic;">No stock movements on this day.</p>';
        } else {
            foreach ([['Inward (received)', $inwL, (float) ($i['inward']['qty'] ?? 0), (float) ($i['inward']['value'] ?? 0), '#047857'],
                      ['Outward (issued / sold)', $outL, (float) ($i['outward']['qty'] ?? 0), (float) ($i['outward']['value'] ?? 0), '#b91c1c']] as [$ttl, $lines, $q, $v, $color]) {
                $html .= '<div style="margin:8px 0 2px;font-size:12px;font-weight:bold;color:' . $color . ';">' . $ttl . '</div>';
                if (empty($lines)) {
                    $html .= '<p style="margin:2px 0 8px;font-size:12px;color:#94a3b8;font-style:italic;">none</p>';
                    continue;
                }
                $html .= '<table width="100%" cellpadding="0" cellspacing="0">';
                $html .= '<tr><th style="' . $th . '">Product</th><th style="' . $thR . '">Qty</th><th style="' . $thR . '">Unit cost</th><th style="' . $thR . '">Value</th></tr>';
                foreach ($lines as $l) {
                    $html .= '<tr><td style="' . $td . '">' . $this->e($l['name'] ?? '?') . '</td>'
                           . '<td style="' . $tdR . '">' . $this->n((float) ($l['qty'] ?? 0)) . '</td>'
                           . '<td style="' . $tdR . '">' . $this->n((float) ($l['cost'] ?? 0)) . '</td>'
                           . '<td style="' . $tdR . '">' . $this->n((float) ($l['value'] ?? 0)) . '</td></tr>';
                }
                $html .= '<tr><td style="' . $tot . '">Total</td><td style="' . $totR . '">' . $this->n($q) . '</td><td style="' . $tot . '"></td><td style="' . $totR . '">' . $this->n($v) . '</td></tr>';
                $html .= '</table>';
            }
        }

        // ── Expenses table ──
        $html .= '<h2 style="' . $h2 . '">Expenses <span style="' . $sub . '">(excl. inventory movements, ' . $ccy . ')</span></h2>';
        $xl = (array) ($x['lines'] ?? []);
        $html .= '<table width="100%" cellpadding="0" cellspacing="0">';
        $html .= '<tr><th style="' . $th . '">Account</th><th style="' . $thR . '">Amount</th></tr>';
        if (empty($xl)) {
            $html .= '<tr><td style="' . $td . 'color:#94a3b8;font-style:italic;" colspan="2">No expenses recorded on this day.</td></tr>';
        } else {
            foreach ($xl as $l) {
                $html .= '<tr><td style="' . $td . '">' . $this->e($l['account'] ?? '?') . '</td><td style="' . $tdR . '">' . $this->n((float) ($l['amount'] ?? 0)) . '</td></tr>';
            }
        }
        $html .= '<tr><td style="' . $tot . '">Total for the day</td><td style="' . $totR . '">' . $this->n((float) ($x['day_total'] ?? 0)) . '</td></tr>';
        $html .= '<tr><td style="' . $tot . 'border-top:0;padding-top:0;">Cumulative — ' . $this->e($x['month_label'] ?? 'this month') . ' (MTD)</td><td style="' . $totR . 'border-top:0;padding-top:0;">' . $this->n((float) ($x['mtd_total'] ?? 0)) . '</td></tr>';
        $html .= '</table>';

        // Footer
        $html .= '<p style="margin:22px 0 0;font-size:10px;color:#94a3b8;border-top:1px solid #f1f5f9;padding-top:10px;">'
               . 'Automated daily report · sent at 08:00 (Douala) by ' . $this->e($this->brand) . ' BMS. '
               . 'Sales = posted customer invoices dated ' . $this->e($this->date) . ' (Cash = settled, Credit = on account). '
               . 'Stock at standard cost. Expenses = class-6 GL postings excluding purchases/stock variation.</p>';

        $html .= '</div></div></div>';
        return $html;
    }
}
