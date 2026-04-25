<?php
declare(strict_types=1);

namespace EA\BI\Controllers;

use EA\BI\Config;
use EA\BI\Odoo\OdooFactory;
use EA\BI\Services\AIAdvisorService;
use EA\BI\Services\FixedAssetsService;
use EA\BI\Services\PayablesService;
use EA\BI\Services\ProfitLossService;
use EA\BI\Services\ReceivablesService;
use EA\BI\Services\RevenueService;

/**
 * Exposes the /api/insights endpoint consumed by the "Elite Advisors" card.
 *
 * The caller passes a page slug (dashboard | revenue | ...). The server
 * rebuilds a compact, model-friendly KPI context from that page's cached
 * analyses, hands it to AIAdvisorService, and returns structured JSON.
 *
 * Insights are cached in the session per (page + context hash) to avoid
 * paying for identical calls; "Sync now" invalidates the cache.
 */
final class AIController
{
    public function insights(): void
    {
        ea_require_login();
        header('Content-Type: application/json');

        try {
            $page = (string) ($_GET['page'] ?? 'dashboard');
            $context = $this->buildContext($page);

            $hash = md5(json_encode($context));
            $key  = 'ai_' . $page . '_' . $hash;

            $result = ea_cached($key, function () use ($page, $context) {
                return (new AIAdvisorService())->analyze($page, $context);
            });

            // Never echo the model/provider fields to the client. These are
            // backend-only, so operators can swap providers without the UI
            // shifting.
            echo json_encode([
                'ok'       => true,
                'insights' => $result['insights'],
                'page'     => $page,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Produce a concise numeric snapshot for the requested page. We only
     * forward KPI-level aggregates to the LLM — no PII / customer lists
     * beyond top-N names the dashboards already display.
     */
    private function buildContext(string $page): array
    {
        $client = OdooFactory::fromConfig();
        $source = (string) Config::get('REVENUE_SOURCE', 'invoice');

        switch ($page) {
            case 'revenue':
                $a = ea_cached('revenue_ytd', fn() => (new RevenueService($client, $source))->analyze());
                return $this->stripSeries($a);

            case 'receivables':
                $ar = ea_cached('ar', function () use ($client, $source) {
                    $r = (new RevenueService($client, $source))->analyze();
                    return (new ReceivablesService($client))->analyze(
                        (float) ($r['current']['total'] ?? 0.0),
                        (int)   ($r['period']['days']   ?? 1),
                        (string)($r['currency']          ?? 'USD'),
                    );
                });
                return $ar;

            case 'profit_loss':
                return ea_cached('pl_' . md5(json_encode(['type'=>'ytd','year'=>null,'month'=>null])),
                    fn() => (new ProfitLossService($client))->analyze());

            case 'payables':
                return ea_cached('ap', function () use ($client, $source) {
                    $pl = (new ProfitLossService($client))->analyze();
                    $cogs = (float) ($pl['current']['cogs'] ?? 0);
                    $opex = (float) ($pl['current']['opex'] ?? 0);
                    return (new PayablesService($client))->analyze($cogs + $opex, 365, (string) ($pl['currency'] ?? 'USD'));
                });

            case 'fixed_assets':
                return ea_cached('fa', fn() => (new FixedAssetsService($client))->analyze());

            case 'dashboard':
            default:
                $rev = ea_cached('revenue_ytd', fn() => (new RevenueService($client, $source))->analyze());
                $pl  = null;
                try { $pl = ea_cached('pl', fn() => (new ProfitLossService($client))->analyze()); }
                catch (\Throwable $e) { $pl = null; }
                return [
                    'revenue' => $this->stripSeries($rev),
                    'pl'      => $pl,
                ];
        }
    }

    /**
     * Drop the large per-day series arrays; the LLM gets totals & deltas.
     */
    private function stripSeries(array $a): array
    {
        if (isset($a['current']['series']))  unset($a['current']['series']);
        if (isset($a['previous']['series'])) unset($a['previous']['series']);
        if (isset($a['partner_revenue_cy'])) unset($a['partner_revenue_cy']);
        if (isset($a['partner_names_cy']))   unset($a['partner_names_cy']);
        if (isset($a['doc_ids_cy']))         unset($a['doc_ids_cy']);
        return $a;
    }
}
