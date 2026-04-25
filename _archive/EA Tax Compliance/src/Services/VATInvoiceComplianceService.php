<?php
declare(strict_types=1);

namespace EA\BI\Services;

use EA\BI\Odoo\OdooClient;

final class VATInvoiceComplianceService
{
    private OdooClient $odoo;

    public function __construct(OdooClient $odoo)
    {
        $this->odoo = $odoo;
    }

    public function analyze(?\DateTimeImmutable $today = null, array $period = []): array
    {
        $today = $today ?? new \DateTimeImmutable('today');
        [$cyFrom, $cyTo, $periodLabel] = ea_resolve_period($today, $period);

        $invoice = $this->fetchRepresentativeInvoice($cyFrom, $cyTo);
        if (!$invoice) {
            return [
                'period' => [
                    'from' => $cyFrom->format('Y-m-d'),
                    'to' => $cyTo->format('Y-m-d'),
                    'label' => $periodLabel,
                ],
                'invoice' => null,
                'fields' => [],
                'note' => 'No posted invoices were found in the selected period.',
            ];
        }

        $companyPartner = $this->fetchCompanyPartner($invoice['company_id'][0] ?? null);
        $customerPartner = $this->getPartnerDetails($invoice['partner_id'][0] ?? null);

        $vatAmount = (float) $invoice['amount_total'] - (float) $invoice['amount_untaxed'];
        $vatRate = (float) $invoice['amount_untaxed'] > 0
            ? ($vatAmount / (float) $invoice['amount_untaxed']) * 100
            : null;

        $fields = [
            $this->makeField('Supplier NIU', $companyPartner['vat'] ?? null, 'Art. 150(1) CGI'),
            $this->makeField('Customer NIU', $customerPartner['vat'] ?? null, 'Art. 150(5) CGI'),
            $this->makeField('Supplier Name', $companyPartner['name'] ?? null, 'Art. 150(5) CGI'),
            $this->makeField('Customer Name', $customerPartner['name'] ?? null, 'Art. 150(5) CGI'),
            $this->makeField('Invoice Number', $invoice['name'] ?? null, 'Art. 150(5) CGI'),
            $this->makeField('Invoice Date', $invoice['invoice_date'] ?? null, 'Art. 150(5) CGI'),
            $this->makeField('Price HT', $invoice['amount_untaxed'] ?? null, 'Art. 135 CGI', 'money'),
            $this->makeField('VAT Amount', $vatAmount, 'Art. 135 CGI', 'money'),
            $this->makeField('VAT Rate', $vatRate, 'Art. 142 CGI', 'percent'),
            $this->makeField('Total TTC', $invoice['amount_total'] ?? null, 'Art. 150(5) CGI', 'money'),
            $this->makeField('Currency', $invoice['currency_id'][1] ?? null, 'Art. 150(5) CGI'),
        ];

        return [
            'period' => [
                'from' => $cyFrom->format('Y-m-d'),
                'to' => $cyTo->format('Y-m-d'),
                'label' => $periodLabel,
            ],
            'invoice' => [
                'id' => $invoice['id'],
                'number' => $invoice['name'],
                'date' => $invoice['invoice_date'],
                'customer' => $customerPartner['name'] ?? ($invoice['partner_id'][1] ?? 'Unknown'),
                'amount_total' => $invoice['amount_total'],
                'amount_untaxed' => $invoice['amount_untaxed'],
                'vat_amount' => $vatAmount,
                'vat_rate' => $vatRate,
            ],
            'fields' => $fields,
        ];
    }

    private function fetchRepresentativeInvoice(\DateTimeImmutable $from, \DateTimeImmutable $to): ?array
    {
        $domain = [
            ['move_type', '=', 'out_invoice'],
            ['state', '=', 'posted'],
            ['invoice_date', '>=', $from->format('Y-m-d')],
            ['invoice_date', '<=', $to->format('Y-m-d')],
        ];

        $fields = [
            'id',
            'name',
            'invoice_date',
            'partner_id',
            'amount_total',
            'amount_untaxed',
            'company_id',
            'currency_id',
            'amount_tax',
        ];

        $options = [
            'order' => 'invoice_date desc',
            'limit' => 1,
        ];

        $records = $this->odoo->searchRead('account.move', $domain, $fields, $options);
        return $records[0] ?? null;
    }

    private function getPartnerDetails(?int $id): array
    {
        if (!$id) {
            return [];
        }

        $partners = $this->odoo->searchRead('res.partner', [['id', '=', $id]], [
            'id',
            'name',
            'vat',
            'street',
            'street2',
            'city',
            'zip',
            'country_id',
            'lang',
        ], ['limit' => 1]);

        return $partners[0] ?? [];
    }

    private function fetchCompanyPartner(?int $companyId): array
    {
        if (!$companyId) {
            return [];
        }

        $companies = $this->odoo->searchRead('res.company', [['id', '=', $companyId]], [
            'id',
            'name',
            'partner_id',
        ], ['limit' => 1]);

        if (empty($companies)) {
            return [];
        }

        $partnerId = $companies[0]['partner_id'][0] ?? null;
        return $this->getPartnerDetails($partnerId);
    }

    private function makeField(string $label, $value, string $reference, string $type = 'text'): array
    {
        return [
            'label' => $label,
            'value' => $this->formatValue($value, $type),
            'status' => $this->evaluateStatus($value, $type),
            'reference' => $reference,
        ];
    }

    private function formatValue($value, string $type = 'text'): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($type) {
            'money' => number_format((float) $value, 0, ',', ' ') . ' XAF',
            'percent' => number_format((float) $value, 2) . ' %',
            default => (string) $value,
        };
    }

    private function evaluateStatus($value, string $type = 'text'): string
    {
        if ($value === null || $value === '') {
            return 'missing';
        }

        if (in_array($type, ['money', 'percent'], true) && (float) $value <= 0) {
            return 'missing';
        }

        return 'pass';
    }
}
