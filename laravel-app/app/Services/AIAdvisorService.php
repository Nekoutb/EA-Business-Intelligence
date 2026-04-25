<?php
declare(strict_types=1);

namespace App\Services;



/**
 * "Elite Advisors" — turns on-screen KPI snapshots into prioritized,
 * actionable recommendations using a configurable LLM backend.
 *
 * The provider, model and API key are read from Config (persisted in .env by
 * SettingsController) and never exposed on the client. This lets operators
 * swap providers to optimise cost without touching the UI.
 *
 * Supported providers:
 *   - 'anthropic' (default): Claude Messages API (claude-sonnet-4-5 by default)
 *   - 'openai'             : OpenAI Chart Completions (gpt-4o-mini by default)
 */
final class AIAdvisorService
{
    private const DEFAULT_ANTHROPIC_MODEL = 'claude-sonnet-4-20250514';
    private const DEFAULT_OPENAI_MODEL    = 'gpt-4o-mini';
    private const TIMEOUT_SECONDS         = 45;
    private const ANTHROPIC_VERSION       = '2023-06-01';

    /**
     * Generate a structured list of insights for a named page context.
     *
     * @param string $page       Stable page slug: dashboard | revenue | receivables | profit_loss | payables | fixed_assets
     * @param array  $context    Pre-digested numeric snapshot to hand to the model
     * @param array  $opts       Optional override keys: provider, model, temperature
     *
     * @return array{insights:list<array{title:string,severity:string,body:string,action:string}>, model:string, provider:string}
     */
    public function analyze(string $page, array $context, array $opts = []): array
    {
        $provider = strtolower((string) ($opts['provider'] ?? config_env('AI_PROVIDER', 'anthropic')));
        // Normalise common aliases
        if (in_array($provider, ['claude', 'anthropic', 'claude-ai'], true)) {
            $provider = 'anthropic';
        }
        $model    = (string) ($opts['model'] ?? config_env('AI_MODEL', ''));
        $apiKey   = (string) config_env('AI_API_KEY', '');
        if ($apiKey === '') {
            throw new \RuntimeException('AI API key not configured. Add it on the Settings page.');
        }

        if ($model === '') {
            $model = $provider === 'openai' ? self::DEFAULT_OPENAI_MODEL : self::DEFAULT_ANTHROPIC_MODEL;
        }

        $prompt = $this->buildPrompt($page, $context);

        $raw = $provider === 'openai'
            ? $this->callOpenAI($apiKey, $model, $prompt)
            : $this->callAnthropic($apiKey, $model, $prompt);

        $insights = $this->parseInsights($raw);

        return [
            'insights' => $insights,
            'model'    => $model,
            'provider' => $provider,
        ];
    }

    // ---- Providers --------------------------------------------------------

    private function callAnthropic(string $apiKey, string $model, string $prompt): string
    {
        $payload = [
            'model'      => $model,
            'max_tokens' => 1600,
            'temperature' => 0.3,
            'system'     => $this->systemPrompt(),
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];
        $resp = $this->httpPost(
            'https://api.anthropic.com/v1/messages',
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            [
                'x-api-key: ' . $apiKey,
                'anthropic-version: ' . self::ANTHROPIC_VERSION,
                'content-type: application/json',
            ]
        );
        $j = json_decode($resp, true);
        if (!is_array($j)) {
            throw new \RuntimeException('Invalid JSON from Anthropic API.');
        }
        if (isset($j['error'])) {
            $msg = is_array($j['error']) ? ($j['error']['message'] ?? 'Unknown') : (string) $j['error'];
            throw new \RuntimeException('Anthropic API error: ' . $msg);
        }
        // Claude returns content as a list of blocks; concatenate any 'text' blocks.
        $out = '';
        foreach (($j['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $out .= $block['text'] ?? '';
            }
        }
        return $out;
    }

    private function callOpenAI(string $apiKey, string $model, string $prompt): string
    {
        $payload = [
            'model'       => $model,
            'temperature' => 0.3,
            'messages'    => [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user',   'content' => $prompt],
            ],
            'response_format' => ['type' => 'json_object'],
        ];
        $resp = $this->httpPost(
            'https://api.openai.com/v1/chat/completions',
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            [
                'authorization: Bearer ' . $apiKey,
                'content-type: application/json',
            ]
        );
        $j = json_decode($resp, true);
        if (!is_array($j)) {
            throw new \RuntimeException('Invalid JSON from OpenAI API.');
        }
        if (isset($j['error'])) {
            $msg = is_array($j['error']) ? ($j['error']['message'] ?? 'Unknown') : (string) $j['error'];
            throw new \RuntimeException('OpenAI API error: ' . $msg);
        }
        return (string) ($j['choices'][0]['message']['content'] ?? '');
    }

    private function httpPost(string $url, string $body, array $headers): string
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_DNS_CACHE_TIMEOUT => 120,
        ];
        // Resolve CA bundle (same logic as Json2Client for portable PHP)
        $ca = self::resolveCaBundle();
        if ($ca !== '') {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('HTTP error calling AI API: ' . $err);
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ((int) $code >= 400) {
            // Let the JSON parser surface the structured error below.
        }
        return (string) $resp;
    }

    /**
     * Locate a valid CA bundle for curl (portable PHP may lack one).
     */
    private static function resolveCaBundle(): string
    {
        static $resolved = null;
        if ($resolved !== null) return $resolved;

        $candidates = [
            function_exists('base_path') ? base_path('cacert.pem')                : null,
            function_exists('base_path') ? base_path('../php-runtime/cacert.pem') : null,
            dirname(__DIR__, 2) . '/php-runtime/cacert.pem',
            dirname(__DIR__, 3) . '/php-runtime/cacert.pem',
        ];
        foreach ($candidates as $c) {
            if ($c && is_file($c)) {
                return $resolved = $c;
            }
        }
        return $resolved = (string) ini_get('curl.cainfo') ?: '';
    }

    // ---- Prompting --------------------------------------------------------

    private function systemPrompt(): string
    {
        return <<<TXT
You are "Elite Advisors", a senior strategy + finance advisor (McKinsey / BCG
caliber) speaking directly to a CFO. Your job: look at the provided KPI
snapshot and produce 4-6 SHARP, ACTIONABLE insights.

Hard constraints:
- Output ONLY valid JSON matching this schema, no prose, no markdown fences:
  {"insights":[{"title":"...","severity":"info|warning|critical|positive","body":"...","action":"..."}]}
- 4 to 6 insights total, ordered by importance (most important first).
- "title": max 8 words, written as a headline.
- "body": 1-2 tight sentences explaining WHAT the data shows and WHY it matters.
- "action": 1 concrete next step the CFO can assign this week.
- "severity" values:
    - "critical" = urgent risk / cash / write-off exposure
    - "warning"  = adverse trend needing attention
    - "info"     = notable pattern, decision-support
    - "positive" = strength to reinforce or replicate
- Use the actual numbers from the snapshot. No hallucinated figures.
- Do NOT mention you are an AI, nor the model powering you.
TXT;
    }

    private function buildPrompt(string $page, array $context): string
    {
        $friendly = [
            'dashboard'    => 'Executive dashboard (7 revenue KPIs + 2 P&L ratios)',
            'revenue'      => 'Revenue deep-dive (7 revenue KPIs + top customers/products)',
            'customers'    => 'Customer insights (growth, loyalty, profitability, momentum)',
            'receivables'  => 'Receivables / AR health (6 KPIs + aging + top debtors)',
            'profit_loss'  => 'Profit & Loss (revenue → net, margins, YoY)',
            'payables'     => 'Payables / AP health (6 KPIs + aging + top suppliers)',
            'fixed_assets' => 'Fixed Assets portfolio (gross, NBV, depreciation, aging)',
        ];
        $label = $friendly[$page] ?? $page;
        $json  = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $extra = '';
        if ($page === 'revenue') {
            $extra = "\nFocus points: compare RpD, RpO, OpD, RpC and CpO versus their budgets, and flag whether each is above/below plan and by how much. Suggest concrete levers tied to product profitability and discount levers (e.g. selectively discount low-margin SKUs to lift RpD without eroding CpO).";
        } elseif ($page === 'customers') {
            $extra = "\nFocus points: comment on customer growth vs prior year, repeat vs one-time mix, concentration risks, and most/least profitable customers. Recommend concrete actions (loyalty programs, re-pricing, targeted discounts) to improve RpD through customer behaviour.";
        }
        return "Page context: {$label}{$extra}\n\nKPI snapshot (JSON):\n{$json}\n\nReturn the JSON object described in the system prompt now.";
    }

    // ---- Response parsing -------------------------------------------------

    /**
     * Extract {insights: [...]} from the model output, tolerating code fences
     * and leading/trailing chatter.
     *
     * @return list<array{title:string,severity:string,body:string,action:string}>
     */
    private function parseInsights(string $raw): array
    {
        $raw = trim($raw);
        // Strip ```json ... ``` fences if any.
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $raw, $m)) {
            $raw = $m[1];
        } elseif (preg_match('/(\{.*\})/s', $raw, $m)) {
            $raw = $m[1];
        }
        $j = json_decode($raw, true);
        if (!is_array($j) || !isset($j['insights']) || !is_array($j['insights'])) {
            throw new \RuntimeException('AI returned an unexpected format. Try again or check your model selection.');
        }
        $allowed = ['info', 'warning', 'critical', 'positive'];
        $out = [];
        foreach ($j['insights'] as $i) {
            if (!is_array($i)) continue;
            $sev = strtolower((string) ($i['severity'] ?? 'info'));
            if (!in_array($sev, $allowed, true)) $sev = 'info';
            $out[] = [
                'title'    => trim((string) ($i['title']  ?? 'Insight')),
                'severity' => $sev,
                'body'     => trim((string) ($i['body']   ?? '')),
                'action'   => trim((string) ($i['action'] ?? '')),
            ];
        }
        return array_slice($out, 0, 6);
    }
}
