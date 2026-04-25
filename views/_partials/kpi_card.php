<?php
/**
 * Reusable KPI card.
 *
 * @var string  $label       KPI label
 * @var string  $value       Formatted value (display-ready)
 * @var ?string $sub         Small line under the value (e.g. PY comparison)
 * @var ?float  $deltaPct    Optional delta to show with colored pill
 * @var ?string $deltaLabel  Optional label next to the pill (default "vs PY")
 * @var ?string $hint        Optional low-contrast formula line at the bottom
 * @var ?string $accent      Optional hex border accent ('indigo','emerald','amber','rose')
 */
$label      = $label      ?? '';
$value      = $value      ?? '-';
$sub        = $sub        ?? null;
$deltaPct   = $deltaPct   ?? null;
$deltaLabel = $deltaLabel ?? 'vs PY';
$hint       = $hint       ?? null;
$accent     = $accent     ?? 'indigo';

$accents = [
    'indigo'  => 'from-indigo-500/5 to-transparent',
    'emerald' => 'from-emerald-500/5 to-transparent',
    'amber'   => 'from-amber-500/5 to-transparent',
    'rose'    => 'from-rose-500/5 to-transparent',
    'sky'     => 'from-sky-500/5 to-transparent',
    'violet'  => 'from-violet-500/5 to-transparent',
];
$accentCls = $accents[$accent] ?? $accents['indigo'];
?>
<div class="kpi relative overflow-hidden bg-white border border-slate-200 rounded-xl p-5 shadow-sm transition-transform hover:-translate-y-0.5">
  <div class="absolute inset-0 bg-gradient-to-br <?= $accentCls ?> pointer-events-none"></div>
  <div class="relative">
    <div class="text-[11px] uppercase tracking-wider text-slate-500 font-medium"><?= ea_e($label) ?></div>
    <div class="mt-1 text-2xl font-semibold text-slate-900 truncate"><?= $value /* pre-escaped */ ?></div>
    <?php if ($deltaPct !== null || $sub !== null): ?>
      <div class="mt-2 flex items-center gap-2 flex-wrap">
        <?php if ($deltaPct !== null): ?>
          <?php
            $cls = $deltaPct >= 0 ? 'text-emerald-700 bg-emerald-50 border-emerald-200'
                                  : 'text-rose-700 bg-rose-50 border-rose-200';
            $arrow = $deltaPct >= 0 ? '▲' : '▼';
          ?>
          <span class="inline-flex items-center gap-1 text-xs font-medium px-2 py-0.5 rounded border <?= $cls ?>">
            <?= $arrow ?> <?= number_format(abs($deltaPct), 1) ?>%
          </span>
          <span class="text-xs text-slate-500"><?= ea_e($deltaLabel) ?></span>
        <?php endif; ?>
        <?php if ($sub !== null): ?>
          <span class="text-xs text-slate-500"><?= $sub /* pre-escaped */ ?></span>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($hint !== null): ?>
      <div class="mt-2 text-[11px] text-slate-400"><?= ea_e($hint) ?></div>
    <?php endif; ?>
  </div>
</div>
