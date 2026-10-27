{{-- Expects $cfg: type, labels, datasets (label, data, type?, color?), fmt (xaf|pct|days|int), stacked?, horizontal?; $h height class --}}
<div class="{{ $h ?? 'h-56' }}"><canvas data-ci="{{ json_encode($cfg) }}" aria-label="{{ $cfg['title'] ?? 'chart' }}"></canvas></div>
