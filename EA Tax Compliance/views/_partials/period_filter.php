<?php
/**
 * Shared period selector bar.
 *
 * @var string $action     Form target URL, e.g. '/revenue' or '/profit-loss'
 * @var array  $period     Current period state: ['type' => str, 'year' => ?, 'month' => ?]
 * @var string $labelShown Optional "Showing: ..." text
 */
$action     = $action     ?? '/';
$period     = $period     ?? ['type' => 'ytd'];
$labelShown = $labelShown ?? '';

$ptype  = strtolower((string) ($period['type']  ?? 'ytd'));
$pyear  = (int) ($period['year']  ?? (int) date('Y'));
$pmonth = (int) ($period['month'] ?? (int) date('n'));
$cls    = function (string $val) use ($ptype): string {
    $base = 'px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors';
    return $val === $ptype
        ? $base . ' bg-indigo-600 border-indigo-600 text-white'
        : $base . ' bg-white border-slate-300 text-slate-700 hover:border-indigo-400 hover:text-indigo-600';
};
$months   = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];
$thisYear = (int) date('Y');
?>
<div class="bg-white border border-slate-200 rounded-xl p-4 mb-6 shadow-sm">
  <div class="flex flex-wrap items-center gap-2">
    <span class="text-xs uppercase tracking-wider text-slate-500 mr-2 font-medium">Period</span>
    <a href="<?= ea_e($action) ?>?period=ytd" class="<?= $cls('ytd') ?>">Year-to-date</a>
    <a href="<?= ea_e($action) ?>?period=mtd" class="<?= $cls('mtd') ?>">Month-to-date</a>
    <a href="<?= ea_e($action) ?>?period=yoy" class="<?= $cls('yoy') ?>">Year-on-Year</a>

    <form method="get" action="<?= ea_e($action) ?>" class="inline-flex items-center gap-2 ml-1">
      <input type="hidden" name="period" value="month">
      <span class="<?= $cls('month') ?> !py-1">Specific month</span>
      <select name="month" class="text-xs border border-slate-300 rounded-lg px-2 py-1.5 bg-white">
        <?php foreach ($months as $mi => $mn): ?>
          <option value="<?= $mi ?>" <?= ($ptype==='month' && $pmonth===$mi)?'selected':'' ?>><?= $mn ?></option>
        <?php endforeach; ?>
      </select>
      <select name="year" class="text-xs border border-slate-300 rounded-lg px-2 py-1.5 bg-white">
        <?php for ($yy=$thisYear; $yy>=$thisYear-5; $yy--): ?>
          <option value="<?= $yy ?>" <?= ($ptype==='month' && $pyear===$yy)?'selected':'' ?>><?= $yy ?></option>
        <?php endfor; ?>
      </select>
      <button type="submit" class="text-xs bg-slate-900 text-white px-3 py-1.5 rounded-lg hover:bg-slate-800">Apply</button>
    </form>

    <?php if ($labelShown !== ''): ?>
      <span class="text-xs text-slate-500 ml-auto">
        Showing <span class="font-medium text-slate-700"><?= ea_e($labelShown) ?></span>
      </span>
    <?php endif; ?>
  </div>
</div>
