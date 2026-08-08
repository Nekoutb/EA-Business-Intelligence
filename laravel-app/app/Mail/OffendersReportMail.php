<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Receivables Top-Offender Scorecard — emailed every 3 days.
 *
 * Mirrors the scorecard on the Receivables page (same ReceivablesService
 * payload, same columns and ratings) so the email and the screen never
 * disagree. Inline-styled HTML string: mail Blade views would need runtime
 * compilation the web user cannot do on this host, and email clients want
 * inline CSS regardless.
 */
class OffendersReportMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array<int,array<string,mixed>> $partners ReceivablesService top_partners
     * @param array<string,mixed>            $head     headline AR figures
     */
    public function __construct(
        public string $brand,
        public string $asOf,
        public array $partners,
        public array $head,
        public string $currency = 'XAF',
    ) {}

    public function envelope(): Envelope
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->asOf) ?: new \DateTimeImmutable();
        return new Envelope(
            subject: 'Receivables — Top Offenders · ' . $this->brand . ' — ' . $d->format('j F Y'),
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->buildHtml());
    }

    private function n(float $v): string { return number_format($v, 0, '.', ','); }
    private function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

    /** Rating pill colours, matching the page's severity language. */
    private function pill(string $level): array
    {
        return match ($level) {
            'critical' => ['#fdeaea', '#b91c1c'],
            'high'     => ['#fdf1e2', '#b45309'],
            'watch'    => ['#fef9e7', '#8a6d1f'],
            default    => ['#eef2f7', '#475569'],
        };
    }

    private function buildHtml(): string
    {
        $ccy = $this->e($this->currency);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->asOf) ?: new \DateTimeImmutable();
        $human = $d->format('l, j F Y');

        $totalAr = (float) ($this->head['total_ar'] ?? 0);
        $overdue = array_sum(array_map(fn ($p) => max(0.0, (float) ($p['overdue'] ?? 0)), $this->partners));
        $worst   = 0;
        foreach ($this->partners as $p) $worst = max($worst, (int) ($p['max_days_overdue'] ?? 0));
        $critical = count(array_filter($this->partners, fn ($p) => ($p['rating_level'] ?? '') === 'critical'));

        $th  = 'padding:6px 8px;font-size:10px;letter-spacing:.05em;text-transform:uppercase;color:#64748b;border-bottom:1px solid #e2e8f0;text-align:left;';
        $thR = $th . 'text-align:right;';
        $td  = 'padding:7px 8px;font-size:13px;color:#1e293b;border-bottom:1px solid #f1f5f9;';
        $tdR = $td . 'text-align:right;font-variant-numeric:tabular-nums;';

        $html  = '<div style="margin:0;padding:16px;background:#f1f5f9;font-family:Segoe UI,Arial,Helvetica,sans-serif;">';
        $html .= '<div style="max-width:700px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;">';

        $html .= '<div style="background:#0f172a;padding:14px 20px;">'
               . '<table width="100%" cellpadding="0" cellspacing="0"><tr>'
               . '<td style="color:#ffffff;font-size:15px;font-weight:bold;">' . $this->e($this->brand) . ' · Receivables — Top Offenders</td>'
               . '<td style="color:#94a3b8;font-size:12px;text-align:right;">' . $this->e($human) . '</td>'
               . '</tr></table></div>';

        $html .= '<div style="padding:18px 20px 24px;">';

        $html .= '<p style="margin:0 0 16px;font-size:13px;line-height:1.55;color:#334155;">'
               . sprintf(
                   'These %d customers account for %s %s of receivables, of which %s %s is overdue — the oldest by %d days%s.',
                   count($this->partners), $this->n(array_sum(array_map(fn ($p) => (float) ($p['ar'] ?? 0), $this->partners))), $ccy,
                   $this->n($overdue), $ccy, $worst,
                   $critical > 0 ? sprintf(' · %d rated critical', $critical) : ''
               )
               . '</p>';

        // Summary tiles
        $lbl = 'padding:5px 8px;font-size:12px;color:#64748b;';
        $val = 'padding:5px 8px;font-size:13px;font-weight:bold;color:#0f172a;text-align:right;font-variant-numeric:tabular-nums;';
        $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:18px;">'
               . '<tr><td style="' . $lbl . '">Total receivables</td><td style="' . $val . '">' . $this->n($totalAr) . '</td>'
               . '<td style="' . $lbl . 'border-left:1px solid #e2e8f0;">Overdue (these customers)</td><td style="' . $val . 'color:#b91c1c;">' . $this->n($overdue) . '</td></tr>'
               . '<tr><td style="' . $lbl . '">DSO</td><td style="' . $val . '">' . $this->n((float) ($this->head['dso'] ?? 0)) . ' d</td>'
               . '<td style="' . $lbl . 'border-left:1px solid #e2e8f0;">Worst ageing</td><td style="' . $val . 'color:#b91c1c;">' . $worst . ' d</td></tr>'
               . '</table>';

        // Scorecard
        $html .= '<table width="100%" cellpadding="0" cellspacing="0">';
        $html .= '<tr><th style="' . $th . '">Customer</th><th style="' . $thR . '">AR</th><th style="' . $thR . '">Overdue</th>'
               . '<th style="' . $thR . '">Share</th><th style="' . $thR . '">Max overdue</th><th style="' . $th . 'text-align:center;">Rating</th></tr>';

        if (empty($this->partners)) {
            $html .= '<tr><td style="' . $td . 'color:#94a3b8;font-style:italic;" colspan="6">No outstanding receivables.</td></tr>';
        } else {
            foreach ($this->partners as $p) {
                [$bg, $fg] = $this->pill((string) ($p['rating_level'] ?? 'watch'));
                $ov = (float) ($p['overdue'] ?? 0);
                $html .= '<tr>'
                    . '<td style="' . $td . '"><b>' . $this->e($p['name'] ?? '—') . '</b>'
                    . '<div style="font-size:11px;color:#94a3b8;">' . $this->e($p['rating_reason'] ?? '') . '</div></td>'
                    . '<td style="' . $tdR . '">' . $this->n((float) ($p['ar'] ?? 0)) . '</td>'
                    . '<td style="' . $tdR . ($ov > 0 ? 'color:#b91c1c;font-weight:bold;' : 'color:#cbd5e1;') . '">' . ($ov > 0 ? $this->n($ov) : '—') . '</td>'
                    . '<td style="' . $tdR . '">' . number_format((float) ($p['share_pct'] ?? 0), 1) . '%</td>'
                    . '<td style="' . $tdR . 'color:#64748b;">' . (int) ($p['max_days_overdue'] ?? 0) . ' d</td>'
                    . '<td style="' . $td . 'text-align:center;"><span style="background:' . $bg . ';color:' . $fg . ';font-size:10px;font-weight:bold;padding:2px 9px;border-radius:9px;white-space:nowrap;">'
                    . $this->e($p['rating_label'] ?? 'Watch') . '</span></td>'
                    . '</tr>';
            }
        }
        $html .= '</table>';

        $html .= '<p style="margin:22px 0 0;font-size:10px;color:#94a3b8;border-top:1px solid #f1f5f9;padding-top:10px;">'
               . 'Automated every 3 days by ' . $this->e($this->brand) . ' BMS · figures as at ' . $this->e($this->asOf) . ' (' . $ccy . '). '
               . 'AR = open balance on posted customer invoices; Overdue = past due date; Share = % of total receivables; '
               . 'Max overdue = oldest unpaid invoice. Mirrors the Top Offender Scorecard on the Receivables page.</p>';

        $html .= '</div></div></div>';
        return $html;
    }
}
