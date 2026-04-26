<?php

namespace App\Livewire;

use App\Odoo\OdooFactory;
use App\Services\AIAdvisorService;
use App\Services\CustomerInsightsService;
use App\Services\FixedAssetsService;
use App\Services\PayablesService;
use App\Services\ProfitLossService;
use App\Services\ReceivablesService;
use App\Services\RevenueService;
use App\Services\Tax\VATComplianceService;
use App\Services\Tax\VATInvoiceComplianceService;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Right-rail Claude-powered advisor.
 *
 * Rendered once per page via:  @livewire('ai-insights', ['page' => 'revenue'])
 *
 * On mount() we flag ourselves as "not yet generated" and show a skeleton.
 * As soon as Alpine wires in, wire:init fires generate() which:
 *   1) builds a numeric context (KPI snapshot) for this page,
 *   2) hands it to AIAdvisorService (Claude via Anthropic Messages API),
 *   3) renders 3 – 5 actionable insights, colour-coded by severity.
 *
 * Re-generation is triggered by a Livewire event so the "Sync now" button
 * (or the user) can force a fresh pass without paying for identical calls.
 */
class AiInsights extends Component
{
    public string $page      = 'dashboard';
    public bool   $loading   = true;
    public ?string $error    = null;
    /** @var array<int,array{severity:string,title:string,body:string}> */
    public array $insights   = [];
    public bool   $unconfigured = false;

    public function mount(string $page = 'dashboard'): void
    {
        $this->page = $page;
        $key = (string) config_env('AI_API_KEY', '');
        if ($key === '') {
            $this->unconfigured = true;
            $this->loading     = false;
            return;
        }

        // Pre-generate once during the initial HTTP request so the SSR’d view
        // already contains insights even if Livewire JS fails to hydrate.
        $this->generate();
    }

    public function generate(): void
    {
        if ($this->unconfigured) {
            return;
        }
        $this->loading  = true;
        $this->error    = null;
        $this->insights = [];

        try {
            logger()->info('AiInsights generate start', ['page' => $this->page]);
            $context = $this->buildContext($this->page);
            $advisor = new AIAdvisorService();
            $result  = $advisor->analyze($this->page, $context);

            $this->insights = array_map(
                fn($i) => [
                    'severity' => strtolower((string) ($i['severity'] ?? 'info')),
                    'title'    => (string) ($i['title'] ?? 'Insight'),
                    'body'     => (string) ($i['body']  ?? ''),
                ],
                (array) ($result['insights'] ?? [])
            );

            // If no insights were generated but no error occurred, show a message
            if (empty($this->insights)) {
                $this->error = 'No insights were generated. This may be due to empty data or an API issue. Ask your platform administrator to verify the AI provider configuration.';
            }
            logger()->info('AiInsights generate done', ['page' => $this->page, 'count' => count($this->insights), 'error' => $this->error]);
        } catch (\Throwable $e) {
            logger()->error('AiInsights generate failed', [
                'page' => $this->page,
                'error' => $e->getMessage(),
            ]);
            $this->error = $e->getMessage();
        } finally {
            $this->loading = false;
        }
    }

    #[On('bi-cache-cleared')]
    public function regenerate(): void
    {
        $this->generate();
    }

    // ---------------------------------------------------------------------
    // Page-specific KPI context — kept compact so the LLM stays cheap & fast.
    // ---------------------------------------------------------------------
    private function buildContext(string $page): array
    {
        $client = OdooFactory::fromConfig();
        $source = (string) config_env('REVENUE_SOURCE', 'invoice');

        return match ($page) {
            'revenue' => (function () use ($client, $source) {
                $svc = new RevenueService($client, $source);
                $analysis = ea_cached('revenue_ytd', fn() => $svc->analyze());
                $context  = $this->strip($analysis);

                $profit = ea_cached('revenue_ytd_profit', fn() => $svc->productProfitability($analysis));
                $context['profitability'] = [
                    'most_profitable'  => array_slice(array_map(fn($row) => [
                        'name'   => $row['name'],
                        'margin' => $row['margin'] ?? null,
                        'margin_pct' => $row['margin_pct'] ?? null,
                    ], (array) ($profit['most_profitable'] ?? [])), 0, 5),
                    'least_profitable' => array_slice(array_map(fn($row) => [
                        'name'   => $row['name'],
                        'margin' => $row['margin'] ?? null,
                        'margin_pct' => $row['margin_pct'] ?? null,
                    ], (array) ($profit['least_profitable'] ?? [])), 0, 5),
                ];

                $topDiscounted = ea_cached('revenue_ytd_disc', fn() => $svc->topDiscountedProducts($analysis));
                $context['top_discounted'] = array_slice(array_map(fn($row) => [
                    'name'             => $row['name'] ?? '',
                    'discount_amount'  => $row['discount_amount'] ?? null,
                    'avg_discount_pct' => $row['avg_discount_pct'] ?? null,
                ], (array) $topDiscounted), 0, 5);

                $context['plan_metrics'] = $this->planMetricsSnapshot($analysis);

                return $context;
            })(),
            'customers' => (function () use ($client, $source) {
                $svc = new RevenueService($client, $source);
                $payload = ea_cached('customers_ytd_snapshot', function () use ($svc) {
                    $analysis = $svc->analyze();
                    $customerInsights = (new CustomerInsightsService())->analyze($analysis);
                    return ['analysis' => $analysis, 'insights' => $customerInsights];
                });

                return [
                    'revenue'   => $this->strip($payload['analysis'] ?? []),
                    'customers' => $payload['insights'] ?? [],
                ];
            })(),
            'receivables' => ea_cached('ar', function () use ($client, $source) {
                $r = (new RevenueService($client, $source))->analyze();
                return (new ReceivablesService($client))->analyze(
                    (float) ($r['current']['total'] ?? 0),
                    (int)   ($r['period']['days']  ?? 1),
                    (string)($r['currency']         ?? 'USD'),
                );
            }),
            'pl' => ea_cached('pl', fn() => (new ProfitLossService($client))->analyze()),
            'payables' => ea_cached('ap', function () use ($client) {
                $pl   = (new ProfitLossService($client))->analyze();
                $cogs = (float) ($pl['current']['cogs'] ?? 0);
                $opex = (float) ($pl['current']['opex'] ?? 0);
                return (new PayablesService($client))->analyze(
                    $cogs + $opex, 365, (string) ($pl['currency'] ?? 'USD')
                );
            }),
            'fixed_assets' => ea_cached('fa',
                fn() => (new FixedAssetsService($client))->analyze()),
            'vat_compliance' => ea_cached('vat_compliance',
                fn() => (new VATComplianceService($client))->analyze()),
            'vat_invoice_compliance' => ea_cached('vat_invoice_compliance_default',
                fn() => (new VATInvoiceComplianceService($client))->analyze()),
            default => (function () use ($client, $source) {
                $rev = ea_cached('revenue_ytd',
                    fn() => (new RevenueService($client, $source))->analyze());
                $pl  = null;
                try { $pl = ea_cached('pl', fn() => (new ProfitLossService($client))->analyze()); }
                catch (\Throwable $e) { $pl = null; }
                return ['revenue' => $this->strip($rev), 'pl' => $pl];
            })(),
        };
    }

    private function strip(array $a): array
    {
        foreach ([
            'series',
            'partner_revenue_cy', 'partner_names_cy', 'partner_orders_cy', 'partner_months_cy',
            'partner_revenue_py', 'partner_names_py', 'partner_orders_py', 'partner_months_py',
            'doc_ids_cy'
        ] as $k) {
            unset($a['current'][$k], $a['previous'][$k], $a[$k]);
        }
        return $a;
    }

    private function planMetricsSnapshot(array $analysis): array
    {
        $current = $analysis['current'] ?? [];
        $previous = $analysis['previous'] ?? [];
        $budget   = $analysis['budget'] ?? [];
        $totals   = $budget['totals'] ?? [];
        $workingDays = (int) ($budget['working_days'] ?? ($current['working_days'] ?? 1));
        $workingDays = max($workingDays, 1);

        $safeDiv = function ($num, $den) {
            if (!is_numeric($num) || !is_numeric($den) || (float) $den === 0.0) {
                return null;
            }
            return (float) $num / (float) $den;
        };

        return [
            'actuals' => [
                'rpd' => $current['rpd'] ?? null,
                'rpo' => $current['rpo'] ?? null,
                'opd' => $current['opd'] ?? null,
                'rpc' => $current['per_customer'] ?? null,
                'cpo' => $current['cpo'] ?? null,
                'dp_ot' => $current['dp_ot'] ?? null,
                'dp_c'  => $current['dp_c'] ?? null,
            ],
            'py' => [
                'rpd' => $previous['rpd'] ?? null,
                'rpo' => $previous['rpo'] ?? null,
                'opd' => $previous['opd'] ?? null,
                'rpc' => $previous['per_customer'] ?? null,
                'cpo' => $previous['cpo'] ?? null,
                'dp_ot' => $previous['dp_ot'] ?? null,
                'dp_c'  => $previous['dp_c'] ?? null,
            ],
            'plan' => [
                'rpd' => $safeDiv($totals['revenue'] ?? null, $workingDays),
                'rpo' => $safeDiv($totals['revenue'] ?? null, $totals['outfits'] ?? null),
                'opd' => $safeDiv($totals['outfits'] ?? null, $workingDays),
                'rpc' => $safeDiv($totals['revenue'] ?? null, $totals['customers'] ?? null),
                'cpo' => $safeDiv($totals['cost_base'] ?? null, $totals['outfits'] ?? null),
                'dp_ot' => $safeDiv($totals['discounts'] ?? null, $totals['outfits'] ?? null),
                'dp_c'  => $safeDiv($totals['discounts'] ?? null, $totals['customers'] ?? null),
            ],
        ];
    }

    public function render()
    {
        return view('livewire.ai-insights');
    }
}
