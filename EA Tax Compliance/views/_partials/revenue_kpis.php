<?php
/**
 * The 7 revenue KPIs + trend chart, shared between Dashboard and Revenue pages.
 *
 * @var array       $analysis   RevenueService::analyze() output
 * @var string      $chartId    Unique canvas id (so two pages can coexist safely)
 */
$chartId   = $chartId   ?? 'trendChart';
$hideChart = $hideChart ?? false;
$c   = $analysis['current'];
$p   = $analysis['previous'];
$d   = $analysis['delta'];
$cur = $analysis['currency'];
$days = $analysis['period']['days'];
$yoy  = $analysis['yoy_growth_pct'];

$moneyHtml = fn(float $v): string => ea_e(ea_money($v, $cur));
$subPy = fn(float $py, string $prefix = 'PY '): string => $prefix . '<span class="font-medium text-slate-600">' . $moneyHtml($py) . '</span>';
?>

<div class="text-xs text-slate-500 mb-4 flex flex-wrap items-center gap-x-3 gap-y-1">
  <span>Source: <span class="font-mono text-slate-700"><?= ea_e($analysis['source']) ?></span></span>
  <span>·</span>
  <span>Window: <?= ea_e($analysis['period']['from']) ?> → <?= ea_e($analysis['period']['to']) ?></span>
  <span>·</span>
  <span><?= (int) $days ?> day<?= $days === 1 ? '' : 's' ?></span>
  <span>·</span>
  <span>Currency: <span class="font-medium text-slate-700"><?= ea_e($cur) ?></span></span>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
  <?php
    // 1. Total Revenue (YTD)
    ea_render_partial('kpi_card', [
      'label'    => 'Total Revenue (YTD)',
      'value'    => $moneyHtml($c['total']),
      'deltaPct' => $d['total_pct'],
      'sub'      => $subPy($p['total']),
      'accent'   => 'indigo',
    ]);

    // 2. YoY Growth Rate
    $yoyDisplay = $yoy === null ? '<span class="text-slate-400 text-lg">n/a</span>'
                                : number_format($yoy, 1) . '%';
    ea_render_partial('kpi_card', [
      'label'    => 'YoY Growth Rate',
      'value'    => $yoyDisplay,
      'sub'      => 'Headline momentum vs prior year',
      'hint'     => '= (CY − PY) / |PY|',
      'accent'   => ($yoy !== null && $yoy >= 0) ? 'emerald' : 'rose',
    ]);

    // 3. Revenue per Day
    ea_render_partial('kpi_card', [
      'label'    => 'Revenue per Day',
      'value'    => $moneyHtml($c['per_day']),
      'deltaPct' => $d['per_day_pct'],
      'sub'      => $subPy($p['per_day']),
      'hint'     => '= Total / ' . (int) $days . ' days',
      'accent'   => 'sky',
    ]);

    // 4. Outfits Sold
    ea_render_partial('kpi_card', [
      'label'    => 'Outfits Sold',
      'value'    => number_format((int) $c['outfits']),
      'deltaPct' => $d['outfits_pct'],
      'sub'      => 'PY <span class="font-medium text-slate-600">' . number_format((int) $p['outfits']) . '</span>',
      'hint'     => 'Count of product lines on documents',
      'accent'   => 'violet',
    ]);
  ?>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
  <?php
    // 5. Average Revenue per Outfit (AOV)
    ea_render_partial('kpi_card', [
      'label'    => 'Avg Revenue per Outfit (AOV)',
      'value'    => $moneyHtml($c['per_outfit']),
      'deltaPct' => $d['per_outfit_pct'],
      'sub'      => $subPy($p['per_outfit']),
      'hint'     => '= Total / Outfits sold',
      'accent'   => 'amber',
    ]);

    // 6. Unique Customers
    ea_render_partial('kpi_card', [
      'label'    => 'Unique Customers',
      'value'    => number_format((int) $c['customers']),
      'deltaPct' => $d['customers_pct'],
      'sub'      => 'PY <span class="font-medium text-slate-600">' . number_format((int) $p['customers']) . '</span>',
      'hint'     => 'Distinct partners billed in window',
      'accent'   => 'sky',
    ]);

    // 7. Revenue per Customer
    ea_render_partial('kpi_card', [
      'label'    => 'Revenue per Customer',
      'value'    => $moneyHtml($c['per_customer']),
      'deltaPct' => $d['per_customer_pct'],
      'sub'      => $subPy($p['per_customer']),
      'hint'     => '= Total / Unique customers',
      'accent'   => 'violet',
    ]);
  ?>
</div>

<?php if (!$hideChart): ?>
<div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm mb-8">
  <div class="flex items-center justify-between mb-3">
    <div>
      <h2 class="text-sm font-semibold text-slate-900">Daily Revenue Trend</h2>
      <p class="text-xs text-slate-500">Current year vs prior year (same calendar window)</p>
    </div>
  </div>
  <canvas id="<?= ea_e($chartId) ?>" height="110"></canvas>
</div>

<script>
  (function () {
    const cy = <?= json_encode($c['series']) ?>;
    const py = <?= json_encode($p['series']) ?>;
    const from = new Date('<?= ea_e($analysis['period']['from']) ?>');
    const to   = new Date('<?= ea_e($analysis['period']['to']) ?>');
    const labels = [], cyData = [], pyData = [];
    for (let d = new Date(from); d <= to; d.setDate(d.getDate() + 1)) {
      const iso = d.toISOString().slice(0, 10);
      const pyIso = (d.getFullYear() - 1) + iso.slice(4);
      labels.push(iso);
      cyData.push(+(cy[iso] || 0));
      pyData.push(+(py[pyIso] || 0));
    }
    const ctx = document.getElementById(<?= json_encode($chartId) ?>);
    new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [
          { label: 'Current Year', data: cyData, borderColor: '#4f46e5', backgroundColor: 'rgba(79,70,229,0.1)', tension: 0.25, fill: true, pointRadius: 0, borderWidth: 2 },
          { label: 'Prior Year',   data: pyData, borderColor: '#94a3b8', borderDash: [4,4], tension: 0.25, fill: false, pointRadius: 0, borderWidth: 2 },
        ],
      },
      options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 12 } },
          tooltip: {
            callbacks: {
              label: (ctx) => ctx.dataset.label + ': <?= addslashes($cur) ?> ' + ctx.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}),
            }
          }
        },
        scales: {
          x: { ticks: { maxTicksLimit: 12, color: '#64748b' }, grid: { display: false } },
          y: { ticks: { color: '#64748b', callback: (v) => v.toLocaleString() }, grid: { color: '#e2e8f0' } }
        }
      }
    });
  })();
</script>
<?php endif; ?>
