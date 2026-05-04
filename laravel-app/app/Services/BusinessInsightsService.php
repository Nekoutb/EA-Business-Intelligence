<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\BusinessInsight;
use App\Models\Tenant;
use App\Odoo\OdooFactory;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * Daily business-insight generator.
 *
 * For each tenant: aggregate KPIs from the existing services, hand a
 * compact JSON snapshot to Claude (Haiku by default — cheapest tier;
 * configurable via config('bi.business_insights.model')), parse a
 * structured response, persist a BusinessInsight row.
 *
 * Prompt caching: the system prompt + KPI taxonomy is wrapped in a
 * cached content block. Across a fleet-wide generation pass (~30
 * tenants in 5 minutes) the system block is read from cache after
 * the first call → ~80% input-token cost saving.
 */
final class BusinessInsightsService
{
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const TIMEOUT_SECONDS   = 60;

    public function __construct(private TenantContext $context) {}

    /**
     * Aggregate the headline KPIs for a tenant. Defensive: if any
     * sub-service throws (e.g. Odoo not configured yet), the bucket
     * is set to null and noted in `errors` rather than aborting.
     */
    public function aggregateKpis(Tenant $tenant): array
    {
        // Push the tenant onto the request-scoped TenantContext so
        // services that rely on tenant() work the same as on a normal
        // request. Restore on exit.
        $previous = $this->context->has() ? $this->context->tenant() : null;
        $this->context->set($tenant);

        $errors = [];
        $out = [
            'tenant'        => ['id' => $tenant->id, 'slug' => $tenant->slug, 'name' => $tenant->brand ?: $tenant->name],
            'as_of'         => now()->format('Y-m-d'),
            'currency'      => null,
            'revenue'       => null,
            'pl'            => null,
            'receivables'   => null,
            'reconciliation'=> null,
            'errors'        => &$errors,
        ];

        try {
            $client = OdooFactory::fromConfig();
            $source = (string) config_env('REVENUE_SOURCE', 'invoice');

            // Revenue (RpD, RpO, OpD, RpC, CpO + YoY + customers/outfits)
            try {
                $rev = (new RevenueService($client, $source))->analyze();
                $out['currency'] = $rev['currency'] ?? null;
                $out['revenue']  = $this->trimRevenue($rev);
            } catch (\Throwable $e) { $errors[] = 'revenue: ' . $e->getMessage(); }

            // P&L (gross, ebitda, net, opex, all margin pcts)
            try {
                $pl = (new ProfitLossService($client))->analyze();
                $out['pl'] = $this->trimPl($pl);
            } catch (\Throwable $e) { $errors[] = 'pl: ' . $e->getMessage(); }

            // Receivables (DSO, AR, ageing)
            try {
                $revYtd  = (float) ($out['revenue']['total'] ?? 0);
                $daysYtd = (int)   ($out['revenue']['days']  ?? 1);
                $rec = (new ReceivablesService($client))->analyze(
                    $revYtd, max($daysYtd, 1), (string) ($out['currency'] ?? 'USD')
                );
                $out['receivables'] = $this->trimReceivables($rec);
            } catch (\Throwable $e) { $errors[] = 'receivables: ' . $e->getMessage(); }

            // Reconciliation (Expected → Billed → Cash)
            try {
                $bill = (new BillingService($client))->reconciliationOnly();
                $out['reconciliation'] = $this->trimReconciliation($bill);
            } catch (\Throwable $e) { $errors[] = 'reconciliation: ' . $e->getMessage(); }
        } catch (\Throwable $e) {
            $errors[] = 'odoo: ' . $e->getMessage();
        }

        // Restore prior tenant context.
        if ($previous) $this->context->set($previous); else $this->context->clear();

        return $out;
    }

    /**
     * Generate + persist a BusinessInsight for $tenant.
     *
     * Returns the saved row. Always succeeds in returning a row — on
     * LLM failure or Odoo failure the row is saved with status =
     * 'failed' / 'skipped' so the admin can see what happened.
     */
    public function generate(Tenant $tenant, bool $force = false): BusinessInsight
    {
        // Skip if today's row already exists, unless --force.
        if (!$force) {
            $today = now()->startOfDay();
            $existing = BusinessInsight::where('tenant_id', $tenant->id)
                ->where('generated_at', '>=', $today)
                ->orderByDesc('generated_at')
                ->first();
            if ($existing) return $existing;
        }

        $snapshot = $this->aggregateKpis($tenant);

        // Skipped path — we have no usable Odoo data at all.
        $allNull = $snapshot['revenue'] === null
                && $snapshot['pl'] === null
                && $snapshot['receivables'] === null
                && $snapshot['reconciliation'] === null;
        if ($allNull) {
            return BusinessInsight::create([
                'tenant_id'     => $tenant->id,
                'generated_at'  => now(),
                'model'         => '—',
                'kpis_snapshot' => $snapshot,
                'summary'       => 'No Odoo data available yet — sync first.',
                'priority'      => BusinessInsight::PRIORITY_WATCH,
                'status'        => BusinessInsight::STATUS_SKIPPED,
                'error_msg'     => $snapshot['errors'][0] ?? null,
            ]);
        }

        $apiKey = (string) config_env('AI_API_KEY', '');
        $model  = (string) (config('bi.business_insights.model') ?: config_env('AI_MODEL', '') ?: 'claude-haiku-4-5');
        if ($apiKey === '') {
            return BusinessInsight::create([
                'tenant_id'     => $tenant->id,
                'generated_at'  => now(),
                'model'         => $model,
                'kpis_snapshot' => $snapshot,
                'summary'       => 'AI provider not configured — set AI_API_KEY.',
                'priority'      => BusinessInsight::PRIORITY_WATCH,
                'status'        => BusinessInsight::STATUS_FAILED,
                'error_msg'     => 'AI_API_KEY missing',
            ]);
        }

        try {
            [$raw, $usage] = $this->callAnthropic($apiKey, $model, $snapshot);
            $parsed = $this->parseResponse($raw);

            return BusinessInsight::create([
                'tenant_id'     => $tenant->id,
                'generated_at'  => now(),
                'model'         => $model,
                'kpis_snapshot' => $snapshot,
                'insight_md'    => $parsed['insight_md'],
                'summary'       => $parsed['summary'],
                'priority'      => $parsed['priority'],
                'input_tokens'  => $usage['input']  ?? 0,
                'output_tokens' => $usage['output'] ?? 0,
                'status'        => BusinessInsight::STATUS_OK,
            ]);
        } catch (\Throwable $e) {
            Log::error('BusinessInsights generate failed for tenant ' . $tenant->slug . ': ' . $e->getMessage());
            return BusinessInsight::create([
                'tenant_id'     => $tenant->id,
                'generated_at'  => now(),
                'model'         => $model,
                'kpis_snapshot' => $snapshot,
                'summary'       => 'Generation failed — see error_msg.',
                'priority'      => BusinessInsight::PRIORITY_WATCH,
                'status'        => BusinessInsight::STATUS_FAILED,
                'error_msg'     => substr($e->getMessage(), 0, 480),
            ]);
        }
    }

    // ── Trim helpers — keep the prompt small ─────────────────────────

    /** Cherry-pick the headline numbers; drops the daily series + partner blobs. */
    private function trimRevenue(array $rev): array
    {
        $cur = $rev['current']  ?? [];
        $prv = $rev['previous'] ?? [];
        return [
            'currency' => $rev['currency'] ?? null,
            'period'   => $rev['period']   ?? null,
            'days'     => $rev['period']['days'] ?? null,
            'total'    => $cur['total'] ?? 0,
            'rpd'      => $cur['rpd'] ?? 0,
            'rpo'      => $cur['rpo'] ?? 0,
            'opd'      => $cur['opd'] ?? 0,
            'rpc'      => $cur['per_customer'] ?? 0,
            'cpo'      => $cur['cpo'] ?? 0,
            'dp_ot'    => $cur['dp_ot'] ?? 0,
            'dp_c'     => $cur['dp_c'] ?? 0,
            'customers'=> $cur['customers'] ?? 0,
            'outfits'  => $cur['outfits'] ?? 0,
            'yoy_pct'  => $rev['yoy_growth_pct'] ?? null,
            'prev'     => [
                'total' => $prv['total'] ?? 0, 'rpd' => $prv['rpd'] ?? 0,
                'rpo'   => $prv['rpo'] ?? 0,   'opd' => $prv['opd'] ?? 0,
                'rpc'   => $prv['per_customer'] ?? 0, 'cpo' => $prv['cpo'] ?? 0,
            ],
        ];
    }

    private function trimPl(array $pl): array
    {
        $c = $pl['current'] ?? [];
        return [
            'revenue'    => $c['revenue']    ?? 0,
            'cogs'       => $c['cogs']       ?? 0,
            'gross_profit' => $c['gross_profit'] ?? 0,
            'gross_margin_pct' => $c['gross_margin_pct'] ?? $c['gross_margin'] ?? 0,
            'opex'       => $c['opex']       ?? 0,
            'ebitda'     => $c['ebitda']     ?? 0,
            'ebitda_margin_pct' => $c['ebitda_margin_pct'] ?? $c['ebitda_margin'] ?? 0,
            'net_profit' => $c['net_profit'] ?? 0,
            'net_margin_pct' => $c['net_margin_pct'] ?? $c['net_margin'] ?? 0,
        ];
    }

    private function trimReceivables(array $rec): array
    {
        return [
            'total_ar'      => $rec['total_ar']      ?? 0,
            'overdue_ar'    => $rec['overdue_ar']    ?? 0,
            'overdue_rate'  => $rec['overdue_rate']  ?? 0,
            'dso'           => $rec['dso']           ?? 0,
            'avg_age'       => $rec['avg_age']       ?? 0,
            'top5_pct'      => $rec['top5_pct']      ?? 0,
            'pct_gt_60'     => $rec['pct_gt_60']     ?? 0,
            'pct_gt_90'     => $rec['pct_gt_90']     ?? 0,
            'over60_amount' => $rec['over60_amount'] ?? 0,
            'over90_amount' => $rec['over90_amount'] ?? 0,
            'open_invoice_count' => $rec['open_invoice_count'] ?? 0,
        ];
    }

    private function trimReconciliation(array $rec): array
    {
        $h = $rec['headline'] ?? [];
        return [
            'expected'                  => $h['expected'] ?? 0,
            'billed'                    => $h['billed']   ?? 0,
            'collected'                 => $h['collected']?? 0,
            'billing_gap'               => $h['billing_gap'] ?? 0,
            'collection_gap'            => $h['collection_gap'] ?? 0,
            'billing_realisation_pct'   => $h['billing_realisation_pct'] ?? null,
            'collection_realisation_pct'=> $h['collection_realisation_pct'] ?? null,
        ];
    }

    // ── Anthropic call ────────────────────────────────────────────────

    /** Returns [raw_text, usage{input,output}]. */
    private function callAnthropic(string $apiKey, string $model, array $snapshot): array
    {
        $payload = [
            'model'       => $model,
            'max_tokens'  => 2200,
            'temperature' => 0.3,
            // System block uses prompt caching — Anthropic ephemeral
            // cache is 5 min, so a fleet-wide pass amortises this
            // across all but the first tenant.
            'system' => [
                ['type' => 'text', 'text' => $this->systemPrompt(),
                 'cache_control' => ['type' => 'ephemeral']],
            ],
            'messages' => [
                ['role' => 'user', 'content' => $this->userPrompt($snapshot)],
            ],
        ];
        $resp = $this->httpPost(
            'https://api.anthropic.com/v1/messages',
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            [
                'x-api-key: ' . $apiKey,
                'anthropic-version: ' . self::ANTHROPIC_VERSION,
                'content-type: application/json',
                'anthropic-beta: prompt-caching-2024-07-31',
            ]
        );
        $j = json_decode($resp, true);
        if (!is_array($j))   throw new \RuntimeException('Invalid JSON from Anthropic API.');
        if (isset($j['error'])) {
            $msg = is_array($j['error']) ? ($j['error']['message'] ?? 'Unknown') : (string) $j['error'];
            throw new \RuntimeException('Anthropic API: ' . $msg);
        }
        $text = '';
        foreach (($j['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') $text .= $block['text'] ?? '';
        }
        $usage = [
            'input'  => (int) ($j['usage']['input_tokens']  ?? 0),
            'output' => (int) ($j['usage']['output_tokens'] ?? 0),
        ];
        return [$text, $usage];
    }

    private function systemPrompt(): string
    {
        return <<<MD
You are a senior business advisor reviewing the daily KPI snapshot of a small / mid-sized firm running on the Elite Advisors Business Management Suite (BMS).

Your audience is the firm's owner and their advisor. They will read this in a 15-minute morning meeting. Keep it concrete, prioritized, and actionable.

KPI taxonomy you will see in the user message:

  - revenue.{total, rpd, rpo, opd, rpc, cpo, dp_ot, dp_c, customers, outfits, yoy_pct}
      RpD = revenue per working day · RpO = revenue per outfit (unit) · OpD = outfits per day
      RpC = revenue per customer    · CpO = cost per outfit
      DpOT = discount per outfit · DpC = discount per customer
      yoy_pct = year-over-year growth (current period vs prior period)

  - pl.{revenue, cogs, gross_profit, gross_margin_pct, opex, ebitda, ebitda_margin_pct, net_profit, net_margin_pct}

  - receivables.{total_ar, overdue_ar, overdue_rate, dso, avg_age, top5_pct, pct_gt_60, pct_gt_90, over60_amount, over90_amount, open_invoice_count}

  - reconciliation.{expected, billed, collected, billing_gap, collection_gap, billing_realisation_pct, collection_realisation_pct}

Return STRICT JSON with this shape (no prose outside the JSON):

{
  "summary": "1–2 sentence headline. State the single most important finding.",
  "priority": "urgent" | "watch" | "good",
    // urgent = something is bleeding (negative EBITDA, AR > 90d > 10% of total, DSO > 60, overdue rate > 30%)
    // watch  = trending wrong but not yet critical
    // good   = healthy, just routine commentary
  "insight_md": "Full markdown body. Use these section headings (omit ones that don't apply): ## Headline · ## What's working · ## What needs attention · ## Recommended actions (numbered list). Use **bold** for figures, percentages, and concrete numbers. Reference the KPI keys when stating findings (e.g. 'CpO is 43% of RpO, 8 pts above the 35% target')."
}

Rules:
  - Be specific. Always cite the actual numbers from the snapshot.
  - Always explicitly flag risk thresholds: CpO > 35% of RpO, DSO > 30d, overdue rate > 20%, EBITDA margin < 0%, AR > 90d > 10%.
  - Recommend at most 4 actions, ordered by impact.
  - If a major data block is missing or all zeros, note "data unavailable — sync needed" in the relevant section instead of hallucinating numbers.
  - Currency: format amounts as the snapshot provides; never invent a different currency.
  - No marketing speak. No bullet point apologetics. Direct, advisor tone.
MD;
    }

    private function userPrompt(array $snapshot): string
    {
        return "KPI snapshot for "
             . ($snapshot['tenant']['name'] ?? 'workspace')
             . " (slug: " . ($snapshot['tenant']['slug'] ?? '?')
             . ", as_of: " . ($snapshot['as_of'] ?? '?') . ")\n\n"
             . json_encode([
                 'currency'       => $snapshot['currency'],
                 'revenue'        => $snapshot['revenue'],
                 'pl'             => $snapshot['pl'],
                 'receivables'    => $snapshot['receivables'],
                 'reconciliation' => $snapshot['reconciliation'],
                 'errors'         => $snapshot['errors'],
             ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
             . "\n\nReturn the JSON object described in the system prompt.";
    }

    private function parseResponse(string $raw): array
    {
        // Strip code fences if Claude wrapped the JSON.
        $raw = trim($raw);
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw);
            $raw = trim($raw);
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            throw new \RuntimeException('Could not parse Claude response as JSON.');
        }
        $priority = strtolower((string) ($j['priority'] ?? 'watch'));
        if (!in_array($priority, ['urgent', 'watch', 'good'], true)) $priority = 'watch';

        return [
            'summary'    => (string) ($j['summary']    ?? '— summary missing —'),
            'priority'   => $priority,
            'insight_md' => (string) ($j['insight_md'] ?? ''),
        ];
    }

    // ── HTTP — copy of the AIAdvisorService pattern (no SDK dependency) ──

    private function httpPost(string $url, string $body, array $headers): string
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_POST              => true,
            CURLOPT_POSTFIELDS        => $body,
            CURLOPT_HTTPHEADER        => $headers,
            CURLOPT_TIMEOUT           => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT    => 15,
            CURLOPT_IPRESOLVE         => CURL_IPRESOLVE_V4,
            CURLOPT_DNS_CACHE_TIMEOUT => 120,
        ];
        $ca = self::resolveCaBundle();
        if ($ca !== '') $opts[CURLOPT_CAINFO] = $ca;
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('HTTP error calling Anthropic: ' . $err);
        }
        curl_close($ch);
        return (string) $resp;
    }

    private static function resolveCaBundle(): string
    {
        static $resolved = null;
        if ($resolved !== null) return $resolved;
        $candidates = [
            ini_get('curl.cainfo'),
            ini_get('openssl.cafile'),
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/usr/local/etc/openssl/cert.pem',
        ];
        foreach ($candidates as $p) {
            if (is_string($p) && $p !== '' && is_file($p)) return $resolved = $p;
        }
        return $resolved = '';
    }
}
