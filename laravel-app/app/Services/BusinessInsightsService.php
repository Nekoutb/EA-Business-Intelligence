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

    /**
     * How many times each Odoo service call is retried on transient
     * failure (connection timeout, 5xx, etc.) before we give up on that
     * block. 3 attempts is enough to ride out most single-packet flake
     * without pinning the daily job for minutes on a dead host.
     */
    private const ODOO_RETRY_ATTEMPTS = 3;

    /**
     * Backoff between retry attempts, in microseconds. We use a fixed
     * 2s rather than exponential — the daily job already runs at 06:00
     * with no other consumers, so we're optimising for "ride out a
     * transient" not "back off from a stressed service".
     */
    private const ODOO_RETRY_BACKOFF_US = 2_000_000;

    public function __construct(private TenantContext $context) {}

    /**
     * Aggregate the headline KPIs for a tenant. Defensive on two axes:
     *
     *   1. If any sub-service throws (Odoo not configured / unreachable),
     *      we retry it ODOO_RETRY_ATTEMPTS times with a fixed backoff.
     *      Most "translanticd.com timeout 153ms" failures are single-
     *      packet flake — three attempts ride them out cleanly.
     *
     *   2. The bucket for a service that fails *every* attempt is left
     *      null, noted in `errors`, and (downstream of this method)
     *      back-filled from the latest OK snapshot in the DB so the
     *      narrative is computed against stale-but-real numbers rather
     *      than zeros that the model would interpret as "business has
     *      flatlined". See generate() for the back-fill logic.
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
            $rev = $this->withRetry('revenue', $errors,
                fn () => (new RevenueService($client, $source))->analyze());
            if ($rev !== null) {
                $out['currency'] = $rev['currency'] ?? null;
                $out['revenue']  = $this->trimRevenue($rev);
            }

            // P&L (gross, ebitda, net, opex, all margin pcts)
            $pl = $this->withRetry('pl', $errors,
                fn () => (new ProfitLossService($client))->analyze());
            if ($pl !== null) {
                $out['pl'] = $this->trimPl($pl);
            }

            // Receivables (DSO, AR, ageing). Depends on revenue YTD —
            // pass safe defaults when revenue itself failed so the call
            // still goes out (Receivables stands on its own for AR/DSO).
            $revYtd  = (float) ($out['revenue']['total'] ?? 0);
            $daysYtd = (int)   ($out['revenue']['days']  ?? 1);
            $rec = $this->withRetry('receivables', $errors,
                fn () => (new ReceivablesService($client))->analyze(
                    $revYtd, max($daysYtd, 1), (string) ($out['currency'] ?? 'USD')
                ));
            if ($rec !== null) {
                $out['receivables'] = $this->trimReceivables($rec);
            }

            // Reconciliation (Expected → Billed → Cash)
            $bill = $this->withRetry('reconciliation', $errors,
                fn () => (new BillingService($client))->reconciliationOnly());
            if ($bill !== null) {
                $out['reconciliation'] = $this->trimReconciliation($bill);
            }
        } catch (\Throwable $e) {
            // OdooFactory itself failed — credentials missing, host
            // unresolvable, etc. Don't retry: this is a configuration
            // problem, not transient flake.
            $errors[] = 'odoo: ' . $e->getMessage();
        }

        // Restore prior tenant context.
        if ($previous) $this->context->set($previous); else $this->context->clear();

        return $out;
    }

    /**
     * Call $producer up to ODOO_RETRY_ATTEMPTS times, sleeping
     * ODOO_RETRY_BACKOFF_US between attempts. Returns the result on
     * success, null on terminal failure (all attempts exhausted) —
     * append all attempt errors to $errors so the operator can see
     * the full retry history in the saved snapshot.
     */
    private function withRetry(string $label, array &$errors, \Closure $producer)
    {
        $lastError = null;
        for ($attempt = 1; $attempt <= self::ODOO_RETRY_ATTEMPTS; $attempt++) {
            try {
                return $producer();
            } catch (\Throwable $e) {
                $lastError = $e;
                if ($attempt < self::ODOO_RETRY_ATTEMPTS) {
                    usleep(self::ODOO_RETRY_BACKOFF_US);
                }
            }
        }
        // All attempts failed — log a single consolidated error.
        $errors[] = $label . ': ' . self::ODOO_RETRY_ATTEMPTS
                  . ' attempts failed — ' . ($lastError?->getMessage() ?? 'unknown');
        return null;
    }

    /**
     * Composite snapshot: take the freshly-attempted $live snapshot,
     * fall back to the corresponding block from $fallback (typically
     * the latest OK row in the DB) for each block that's still null
     * after retries. Returns the merged snapshot AND a list of which
     * blocks were back-filled — that list goes into the prompt so the
     * model sees the data is stale and frames its narrative accordingly.
     *
     * @return array{0: array, 1: array<int, string>}
     */
    private function mergeWithFallback(array $live, ?array $fallback): array
    {
        if (!is_array($fallback)) return [$live, []];

        $backfilled = [];
        foreach (['revenue', 'pl', 'receivables', 'reconciliation'] as $block) {
            if ($live[$block] === null && is_array($fallback[$block] ?? null)) {
                $live[$block]  = $fallback[$block];
                $backfilled[]  = $block;
            }
        }
        // Restore currency if revenue was back-filled and we didn't get
        // a fresh one (revenue carries the currency tag).
        if (in_array('revenue', $backfilled, true) && empty($live['currency'])) {
            $live['currency'] = $fallback['currency'] ?? null;
        }
        return [$live, $backfilled];
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

        // Back-fill missing blocks from the most recent OK snapshot so a
        // single bad fetch doesn't reset the narrative to "everything is
        // zero". Older rows can be hours or days stale — that's fine; we
        // mark the resulting row STATUS_PARTIAL and tell the model the
        // data is stale (via `stale_blocks` in the snapshot). The
        // alternative — generating against zeros — produced the
        // "translanticd.com:1011 connection timeout 153ms" fabrication
        // the operator just saw on /admin/business-insights.
        $fallbackSource = $this->latestOkSnapshotFor($tenant);
        [$snapshot, $staleBlocks] = $this->mergeWithFallback($snapshot, $fallbackSource);
        $snapshot['stale_blocks'] = $staleBlocks;   // [] when fully fresh

        // Compute server-side traffic lights for the 5 headline KPIs,
        // plus the variance table and risk register. These are derived
        // deterministically from the snapshot (see BusinessInsightsScorer
        // for thresholds + rules) and folded back IN to the snapshot so:
        //   1. The LLM sees the same statuses the UI will render and
        //      must justify its narrative against them.
        //   2. Historical rows automatically pick up the latest UI
        //      rendering — only the threshold tunes need a redeploy,
        //      no regeneration required.
        $snapshot['scorecard'] = BusinessInsightsScorer::score($snapshot);

        // Skipped path — we have no usable Odoo data at all, AND no
        // prior snapshot to fall back on. First-run state on a brand
        // new tenant.
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

            // Tag STATUS_PARTIAL whenever at least one block was
            // back-filled from a prior snapshot — preserves visibility
            // for the admin without preventing the narrative from
            // being generated.
            $status = empty($snapshot['stale_blocks'])
                ? BusinessInsight::STATUS_OK
                : BusinessInsight::STATUS_PARTIAL;

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
                'status'        => $status,
                'error_msg'     => $status === BusinessInsight::STATUS_PARTIAL
                    ? 'Stale blocks: ' . implode(', ', $snapshot['stale_blocks'])
                    : null,
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

    /**
     * Load the most recent kpis_snapshot from a STATUS_OK row for this
     * tenant — used to back-fill blocks that today's live fetch couldn't
     * retrieve. Returns null on first run (no prior OK exists).
     *
     * We deliberately accept STATUS_OK rows of any age; the stale_blocks
     * tag on the new row will tell the model + the admin that fallback
     * was used.
     */
    private function latestOkSnapshotFor(Tenant $tenant): ?array
    {
        $row = BusinessInsight::where('tenant_id', $tenant->id)
            ->where('status', BusinessInsight::STATUS_OK)
            ->orderByDesc('generated_at')
            ->first();
        if (!$row || !is_array($row->kpis_snapshot)) return null;
        return $row->kpis_snapshot;
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
You are a senior business advisor producing a **management report** for the owner and their advisor of a small / mid-sized firm running on the Elite Advisors Business Management Suite (BMS).

Your output is rendered in a one-page Executive Summary card at the top of the admin's Business Insights page, followed by structured Financial / Operational / Risk / Outlook sections. It is the headline of a 15-minute morning meeting. The reader is scanning, not studying — lead with the finding, support with figures, end with what to DO.

## Report architecture (Management Reporting standard)

The narrative MUST follow this six-section structure. Omit a section only when the underlying data is null AND no comparison can be drawn — never omit because you have nothing to say (write one direct line instead).

  ## Executive Summary
    Two short paragraphs.
    Paragraph 1: the single most important finding of the day. Lead with the verdict, then the figure (e.g. "Profitability is the issue: EBITDA margin **-3.1%** vs **+8.4%** prior period, driven by opex growth outpacing revenue.").
    Paragraph 2: status of the rest of the business in one breath ("Revenue YoY **+6%**, DSO holding at **34d**, overdue rate **12%** — collection is the soft spot to watch.").

  ## Financial Performance
    Comment line-by-line on the variance table the user message contains (the `scorecard.variance_table` block — Revenue total, RpD, RpO, OpD, RpC, CpO, Gross profit, Opex, EBITDA, Net profit). For each material variance (>5% favourable / unfavourable), state the figure AND the most likely driver inferred from the other KPIs (e.g. "RpO up **8%** while OpD flat — pricing power, not volume").
    Reference the variance table directly. Do not invent numbers that aren't in the table.

  ## Operational Performance
    Comment on the operational drivers: customers, outfits, RpD trend, CpO ratio to RpO (efficiency), discount levels (DpOT, DpC). Highlight any of: CpO / RpO ratio > 35%, customer count change vs prior, OpD trend.
    4–6 lines maximum.

  ## Risk Register
    Quote the risks already computed in `scorecard.risks` — these are the threshold-triggered risks the UI is showing. Do NOT invent new risks. For each risk in the array, write one tight line stating the figure and the implication. Add a single sentence on cumulative exposure if multiple risks trigger at once (e.g. "Two urgent risks compounding — collection pressure on a thin EBITDA cushion").
    If the risks array is empty, write: "No risks above threshold."

  ## Outlook
    Forward-looking in two parts:
    (a) One sentence on trajectory if today's trends continue 30 days.
    (b) One sentence on the single biggest swing factor (the one number that, if it moves, changes the verdict).

  ## Decisions Requested
    Numbered list of 2–4 concrete decisions the owner needs to make this week. Each starts with a verb ("Approve", "Pause", "Re-quote", "Call"). Phrase them so a "yes/no" or "do this by Friday" is the natural response.
    Reference the recommendations field on the risk entries when applicable; add others if the financials warrant it.

## Headline KPIs and traffic lights

The user message contains a precomputed scorecard with five headline KPIs and their server-computed traffic-light statuses (`scorecard.kpis`):

  - revenue_yoy     (higher better — green > +5%, amber -5%..+5%, red < -5%)
  - gross_margin    (higher better — green > 35%, amber 20%..35%, red < 20%)
  - ebitda_margin   (higher better — green > 10%, amber 0%..10%, red < 0%)
  - dso             (lower better  — green < 30d, amber 30d..60d, red > 60d)
  - overdue_rate    (lower better  — green < 10%, amber 10%..30%, red > 30%)

Your `priority` field MUST match the scorecard's `overall` status: scorecard.overall=red → priority="urgent"; amber → "watch"; green → "good". This is deterministic — do not override it from your reading of the narrative.

## Raw KPI taxonomy you will see in the user message

  - revenue.{total, rpd, rpo, opd, rpc, cpo, dp_ot, dp_c, customers, outfits, yoy_pct, prev}
      RpD = revenue per working day · RpO = revenue per outfit · OpD = outfits per day
      RpC = revenue per customer    · CpO = cost per outfit
      DpOT = discount per outfit · DpC = discount per customer
      yoy_pct = current period vs prior period growth %
      prev = the same key set for the prior period (total, rpd, rpo, opd, rpc, cpo)

  - pl.{revenue, cogs, gross_profit, gross_margin_pct, opex, ebitda, ebitda_margin_pct, net_profit, net_margin_pct}

  - receivables.{total_ar, overdue_ar, overdue_rate, dso, avg_age, top5_pct, pct_gt_60, pct_gt_90, over60_amount, over90_amount, open_invoice_count}

  - reconciliation.{expected, billed, collected, billing_gap, collection_gap, billing_realisation_pct, collection_realisation_pct}

  - scorecard.{overall, kpis{...}, variance_table[], risks[]}
      (precomputed — quote these verbatim, never override the status colour)

  - errors          : fetch failure strings; if present, mention "data unavailable" rather than guessing
  - stale_blocks    : blocks back-filled from yesterday; if non-empty, frame those figures as "directional only"

## Return format — strict JSON, no prose outside

{
  "summary": "One-sentence verdict for the index card. State the lead finding plus the headline KPI status (e.g. 'EBITDA margin -3.1% — profitability is the priority; revenue still growing +6% YoY').",
  "priority": "urgent" | "watch" | "good",   // MUST equal scorecard.overall (red→urgent, amber→watch, green→good)
  "insight_md": "Full markdown body following the six-section architecture above. Use ## section headings exactly. Use **bold** for every figure and percentage. End with the numbered Decisions Requested list."
}

## Hard rules — these prevent the most common failure modes

  1. **CITE ONLY DATA THAT IS IN THE SNAPSHOT.**
     The snapshot is the ONLY source of truth. Do NOT invent hostnames, IP addresses, port numbers, latency figures, timestamps, currency codes, customer names, or any other specific that isn't literally in the JSON. If a connection error occurred, quote the `errors` string verbatim — never elaborate.

  2. **Match the priority to scorecard.overall.** No override. Red→urgent, amber→watch, green→good.

  3. If a data block is null AND wasn't back-filled, write "data unavailable — sync needed" in the relevant section. Don't infer values.

  4. If `stale_blocks` is non-empty, open the Executive Summary by naming which blocks are stale and treating their figures as directional only.

  5. **Risk Register reads from scorecard.risks ONLY.** Do not invent thresholds that aren't in that array.

  6. Decisions Requested = 2–4 items, each starts with a verb, each tied to a specific figure from the snapshot.

  7. Currency: use whatever the snapshot's `currency` field says; never invent a different one.

  8. No marketing speak. No "exciting opportunity". No "challenging environment". Direct advisor tone — figures, verdict, next step.
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
                 // Server-computed scorecard. The model MUST quote these
                 // verbatim — the UI shows the same statuses, and any
                 // override would create a visible mismatch between the
                 // KPI band and the narrative.
                 'scorecard'      => $snapshot['scorecard'] ?? null,
                 // Pass both fetch errors AND the list of back-filled
                 // blocks so the model can frame stale data honestly
                 // instead of treating it as today's reading.
                 'errors'         => $snapshot['errors']       ?? [],
                 'stale_blocks'   => $snapshot['stale_blocks'] ?? [],
             ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
             . "\n\nReturn the JSON object described in the system prompt. "
             . "Remember: priority MUST equal scorecard.overall (red→urgent, amber→watch, green→good). "
             . "Risk Register reads ONLY from scorecard.risks. Decisions Requested ends the report with 2–4 verb-led items.";
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
