<?php
declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\Demo\DemoAccount;
use App\Models\Demo\DemoCustomer;
use App\Models\Demo\DemoInvoice;
use App\Models\Demo\DemoProduct;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Sends a natural-language demo command to Claude, gets back a
 * structured JSON operation that DemoCommandExecutor can apply.
 *
 * Supported operation shapes (Claude returns one of these):
 *
 *   { "action": "add_invoice",
 *     "customer_name": "Mahima Supermarché",
 *     "invoice_date": "2026-05-10",      // YYYY-MM-DD
 *     "amount_ht": 850000,
 *     "has_po": false,
 *     "po_number": null,
 *     "product_name": null,              // optional, picks from catalogue
 *     "qty": 1,
 *     "notes": null }
 *
 *   { "action": "add_payment",
 *     "invoice_number": "INV-2026-00012",
 *     "payment_date": "2026-05-12",
 *     "amount": 850000,
 *     "method": "bank_transfer",
 *     "reference": "PAY-456" }
 *
 *   { "action": "add_journal_entry",
 *     "entry_date": "2026-05-10",
 *     "description": "Rent payment",
 *     "journal_code": "OD",
 *     "reference": null,
 *     "lines": [
 *        { "account_code": "622000", "debit":  6500000, "credit": 0 },
 *        { "account_code": "521000", "debit":  0,       "credit": 6500000 }
 *     ] }
 *
 *   { "action": "add_customer",
 *     "name": "New Customer Ltd",
 *     "category": "Retail",
 *     "payment_terms_days": 30,
 *     "country": "CM",
 *     "contact_email": null }
 *
 *   { "action": "error",
 *     "reason": "Could not parse the command — ..."  }
 *
 * The schema sent to Claude is the *current* sandbox's customer list +
 * product catalogue + chart of accounts so it can pick the closest match
 * by name. This makes "pay the Mahima invoice" work without the visitor
 * needing to know the invoice number — Claude resolves it from "Mahima"
 * → the matching customer → the most recent open invoice.
 */
final class DemoCommandParser
{
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const TIMEOUT_SECONDS   = 30;

    /**
     * Returns the structured operation Claude derived from $commandText.
     * Always returns an array; on parse/transport failure returns an
     * error-shaped op the executor knows how to render to the audit log.
     */
    public function parse(Tenant $tenant, string $commandText): array
    {
        $apiKey = (string) config_env('AI_API_KEY', '');
        $model  = (string) (config('bi.business_insights.model') ?: config_env('AI_MODEL', '') ?: 'claude-haiku-4-5');

        if ($apiKey === '') {
            return $this->errorOp('AI provider not configured — set AI_API_KEY to enable the natural-language console. Use the canned forms in the Data tab instead.');
        }

        $schema = $this->schemaForTenant($tenant);

        try {
            $raw = $this->callAnthropic($apiKey, $model, $commandText, $schema);
            return $this->parseResponse($raw);
        } catch (\Throwable $e) {
            Log::warning('DemoCommandParser failure', [
                'tenant_id' => $tenant->id,
                'command'   => $commandText,
                'error'     => $e->getMessage(),
            ]);
            return $this->errorOp('Could not reach the AI service: ' . $e->getMessage());
        }
    }

    /** Inventory of valid identifiers in the sandbox so Claude grounds its picks. */
    private function schemaForTenant(Tenant $tenant): array
    {
        return [
            'customers' => DemoCustomer::where('tenant_id', $tenant->id)
                ->orderBy('name')
                ->get(['name', 'category', 'payment_terms_days'])
                ->map(fn ($c) => $c->name . ' (' . ($c->category ?? '—') . ', ' . $c->payment_terms_days . 'd)')
                ->toArray(),
            'products' => DemoProduct::where('tenant_id', $tenant->id)
                ->orderBy('name')
                ->get(['name', 'category', 'unit_price'])
                ->map(fn ($p) => $p->name . ' (' . ($p->category ?? '—') . ', ' . (int) $p->unit_price . ' XAF)')
                ->toArray(),
            'accounts' => DemoAccount::where('tenant_id', $tenant->id)
                ->orderBy('code')
                ->get(['code', 'name'])
                ->map(fn ($a) => $a->code . ' · ' . $a->name)
                ->toArray(),
            'recent_invoices' => DemoInvoice::with('customer')
                ->where('tenant_id', $tenant->id)
                ->orderByDesc('invoice_date')
                ->limit(10)
                ->get(['number', 'customer_id', 'invoice_date', 'total', 'status'])
                ->map(fn ($i) => $i->number . ' · ' . ($i->customer?->name ?? '—') . ' · ' . (int) $i->total . ' XAF · ' . $i->status)
                ->toArray(),
        ];
    }

    private function callAnthropic(string $apiKey, string $model, string $command, array $schema): string
    {
        $payload = [
            'model'       => $model,
            'max_tokens'  => 800,
            'temperature' => 0.1,
            'system'      => [
                ['type' => 'text', 'text' => $this->systemPrompt(), 'cache_control' => ['type' => 'ephemeral']],
            ],
            'messages' => [
                ['role' => 'user', 'content' => $this->userPrompt($command, $schema)],
            ],
        ];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $apiKey,
                'anthropic-version: ' . self::ANTHROPIC_VERSION,
                'content-type: application/json',
                'anthropic-beta: prompt-caching-2024-07-31',
            ],
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Anthropic call failed: ' . $err);
        }
        curl_close($ch);

        $j = json_decode((string) $resp, true);
        if (!is_array($j))                  throw new \RuntimeException('Invalid JSON from Anthropic.');
        if (isset($j['error']))             throw new \RuntimeException('Anthropic error: ' . ($j['error']['message'] ?? 'unknown'));

        $text = '';
        foreach (($j['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') $text .= $block['text'] ?? '';
        }
        return $text;
    }

    private function systemPrompt(): string
    {
        return <<<MD
You are the natural-language command parser for a demo of a Business Management Suite running on a Cameroon SME's fictional sandbox dataset. The visitor types free-text instructions like "add invoice for Acme 250k no PO" or "book a journal entry: Dr 622000 6,500,000 / Cr 521000 6,500,000" and you translate those into ONE structured JSON operation.

## Output

Return STRICT JSON (no prose outside), exactly one object, with an "action" field that is one of:

  - "add_invoice"      → create a new invoice (with optional PO)
  - "add_payment"      → record a payment against an existing invoice
  - "add_journal_entry"→ post a balanced double-entry move
  - "add_customer"     → create a new customer master record
  - "error"            → command unparseable or asks for something not supported

## Schemas (return EXACTLY these keys; use null for unknown)

### add_invoice
{
  "action": "add_invoice",
  "customer_name": "Mahima Supermarché",  // must match one in the schema's `customers` list (closest-match by name OK)
  "invoice_date": "YYYY-MM-DD",          // default = today if visitor said "today" or didn't say a date
  "amount_ht": 850000,                   // in XAF, integer. Default 100000 if not stated.
  "has_po": false,                       // true = invoice has a matching purchase order, false = no PO (GL quality flag)
  "po_number": null | "PO-12345",
  "product_name": null | "Champagne Moët & Chandon 75cl",  // optional, must match `products` list
  "qty": 1,
  "notes": null | "..."
}

### add_payment
{
  "action": "add_payment",
  "invoice_number": "INV-2026-00012",    // exact match in `recent_invoices`, or null and use customer lookup
  "customer_name": null | "Mahima Supermarché",  // when invoice_number is null, use this to find most recent open invoice
  "payment_date": "YYYY-MM-DD",
  "amount": 850000,
  "method": "bank_transfer" | "cheque" | "cash" | "mobile_money",
  "reference": null | "PAY-..."
}

### add_journal_entry  (double-entry, MUST balance)
{
  "action": "add_journal_entry",
  "entry_date": "YYYY-MM-DD",
  "description": "Loyer mensuel",
  "journal_code": "OD",   // OD|VTE|ACH|BNK|CSH — default OD
  "reference": null | "...",
  "lines": [
    { "account_code": "622000", "debit": 6500000, "credit": 0 },
    { "account_code": "521000", "debit": 0,       "credit": 6500000 }
  ]
}

The sum of debits MUST equal the sum of credits across `lines`. Account codes MUST match an entry in the schema's `accounts` list.

### add_customer
{
  "action": "add_customer",
  "name": "New Customer Ltd",
  "category": "Hotel & Restaurant" | "Retail" | "Wholesale" | "Corporate" | null,
  "payment_terms_days": 30,
  "country": "CM",
  "contact_email": null | "..."
}

### error
{ "action": "error", "reason": "Short, friendly explanation of why this couldn't be parsed." }

## Hard rules

1. ALWAYS return valid JSON. No prose, no markdown fences, no commentary.
2. Names (customer, product, account) MUST match the sandbox schema in the user message. If "Mahima" matches "Mahima Supermarché" use the full name; if no match exists, return an error.
3. For journal entries: every account_code MUST appear in the `accounts` list. If the visitor uses a code that doesn't exist, return action="error" with a helpful reason.
4. Amounts are in XAF (CFA). Parse "250k" / "250000" / "250 000" / "250,000" the same way. Default currency hint: XAF.
5. Dates: ISO YYYY-MM-DD. "today" = (no specific date given). The executor will substitute current date if you pass null.
6. Numeric precision: amounts as integers when no decimals stated.
7. has_po: phrases like "no PO" / "without a purchase order" / "no purchase order" → false. Otherwise default true.
8. Be tolerant of typos / partial names — pick the closest match in the schema.
9. If the visitor asks for something not in the supported actions (delete, update, query, report), return action="error" with a reason naming what IS supported.
MD;
    }

    private function userPrompt(string $command, array $schema): string
    {
        return "## Sandbox schema\n\n"
            . "Customers:\n"  . implode("\n", array_map(fn ($s) => '  - ' . $s, $schema['customers']))    . "\n\n"
            . "Products:\n"   . implode("\n", array_map(fn ($s) => '  - ' . $s, $schema['products']))     . "\n\n"
            . "Chart of accounts:\n" . implode("\n", array_map(fn ($s) => '  - ' . $s, $schema['accounts'])) . "\n\n"
            . "Recent invoices:\n" . implode("\n", array_map(fn ($s) => '  - ' . $s, $schema['recent_invoices'])) . "\n\n"
            . "## Visitor command\n\n"
            . $command . "\n\n"
            . "Parse this into ONE JSON operation following the system prompt. Return only the JSON.";
    }

    private function parseResponse(string $raw): array
    {
        $raw = trim($raw);
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw);
            $raw = trim($raw);
        }
        $j = json_decode($raw, true);
        if (!is_array($j) || !isset($j['action'])) {
            return $this->errorOp('Claude returned a response I could not parse as a structured operation.');
        }
        return $j;
    }

    private function errorOp(string $reason): array
    {
        return ['action' => 'error', 'reason' => $reason];
    }
}
