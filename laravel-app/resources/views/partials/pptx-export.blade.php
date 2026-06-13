{{--
    Reusable "Export to PowerPoint" control.

    Usage (one line per report page):
        @include('partials.pptx-export', [
            'title'    => 'Executive Overview',
            'subtitle' => ($periodLabel ?? 'YTD') . ' · ' . ($ccy ?? 'XAF'),
        ])

    How it works — 100% client-side, zero server/Composer dependency:
      • PptxGenJS is lazy-loaded from CDN only on first click (keeps page
        load fast; the ~1 MB lib never costs anything until used).
      • The builder AUTO-COLLECTS from whatever page it's on:
          – charts  : every ECharts instance + every <canvas> (Chart.js),
                      captured as a crisp PNG
          – KPIs    : the v5 .strip cells, plus [data-pptx-kpi] opt-ins
          – summary : .summary band or [data-pptx-summary]
          – notes   : each card's "Reading:" footer (.v5-card__f / [data-pptx-note])
          – tables  : opt-in only, via table[data-pptx-table]
      • Output is a branded deck: cover → Key Metrics → one slide per chart.

    Params:
      $title    (string)  report title shown on the cover + filename
      $subtitle (string)  optional — period / currency line
--}}
<button type="button" class="ea-pptx-btn"
        data-pptx-title="{{ $title ?? 'Report' }}"
        data-pptx-subtitle="{{ $subtitle ?? '' }}"
        data-pptx-brand="{{ function_exists('tenant_brand_name') ? tenant_brand_name() : config('bi.brand', 'Elite Advisors') }}"
        data-pptx-workspace="{{ function_exists('tenant') ? optional(tenant())->name : '' }}"
        onclick="eaPptxExport(this)"
        aria-label="Export this report to PowerPoint">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h5a2 2 0 1 1 0 4H8v-4zm0 0v6"/>
    </svg>
    PowerPoint
</button>

@once
@push('styles')
<style>
  .ea-pptx-btn{
    display:inline-flex; align-items:center; gap:5px;
    background:var(--surface,#fff); color:var(--ea-primary,#0d6e6e);
    border:1px solid var(--ea-primary,#0d6e6e); border-radius:var(--r,6px);
    padding:4px 10px; font:600 11px var(--font,'Segoe UI',sans-serif);
    cursor:pointer; transition:background .12s;
  }
  .ea-pptx-btn:hover{ background:rgba(13,110,110,.08); }
  .ea-pptx-btn[disabled]{ opacity:.6; cursor:progress; }
  .ea-pptx-btn svg{ width:13px; height:13px; flex-shrink:0; }
</style>
@endpush
@push('scripts')
<script>
/* ── PowerPoint export — shared across all report pages ────────────── */
(function () {
  if (window.eaPptxExport) return;   // @once guard belt-and-suspenders

  var PPTX_CDN = 'https://cdn.jsdelivr.net/npm/pptxgenjs@3.12.0/dist/pptxgen.bundle.js';

  function loadLib() {
    return new Promise(function (resolve, reject) {
      if (window.PptxGenJS) return resolve();
      var s = document.createElement('script');
      s.src = PPTX_CDN;
      s.onload = function () { resolve(); };
      s.onerror = function () { reject(new Error('Could not load the PowerPoint library (network/CDN).')); };
      document.head.appendChild(s);
    });
  }

  function txt(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
  function slug(s) { return (s || 'report').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 50); }
  function dateStr() { var d = new Date(); return d.getFullYear() + ('0' + (d.getMonth() + 1)).slice(-2) + ('0' + d.getDate()).slice(-2); }

  /* Pull a chart container's title + insight note from its enclosing card. */
  function cardOf(el) { return el.closest('.v5-card, .ea-card, .section-card, [data-pptx-card]') || el.parentElement || el; }
  function titleFor(el, fallback) {
    var c = cardOf(el);
    return txt(c.querySelector('.v5-card__t, [data-pptx-chart-title], h3, header')) || fallback;
  }
  function noteFor(el) {
    var c = cardOf(el);
    var n = txt(c.querySelector('.v5-card__f, [data-pptx-note]'));
    return n.replace(/^Reading:\s*/i, '');
  }

  function collect(btn) {
    var cfg = {
      title:     btn.getAttribute('data-pptx-title') || 'Report',
      subtitle:  btn.getAttribute('data-pptx-subtitle') || '',
      brand:     btn.getAttribute('data-pptx-brand') || '',
      workspace: btn.getAttribute('data-pptx-workspace') || '',
      summary:   '', kpis: [], charts: [], tables: [],
    };

    var sm = document.querySelector('[data-pptx-summary], .summary');
    if (sm) cfg.summary = txt(sm).replace(/^In 5 sec\s*/i, '');

    // KPIs — v5 strip cells + generic opt-in tiles.
    document.querySelectorAll('.strip__c').forEach(function (c) {
      var label = txt(c.querySelector('.strip__l'));
      var value = txt(c.querySelector('.strip__v'));
      if (label || value) cfg.kpis.push({ label: label, value: value, meta: txt(c.querySelector('.strip__m')) });
    });
    document.querySelectorAll('[data-pptx-kpi]').forEach(function (c) {
      cfg.kpis.push({
        label: txt(c.querySelector('[data-pptx-kpi-label]')) || c.getAttribute('data-pptx-kpi') || '',
        value: txt(c.querySelector('[data-pptx-kpi-value]')),
        meta:  txt(c.querySelector('[data-pptx-kpi-meta]')),
      });
    });

    // Charts — ECharts instances first (high quality via getDataURL).
    if (window.echarts) {
      document.querySelectorAll('.v5-card__b, [data-pptx-chart], .ea-chart').forEach(function (el) {
        var inst = window.echarts.getInstanceByDom(el);
        if (!inst) return;
        try {
          var w = el.clientWidth || 640, h = el.clientHeight || 360;
          cfg.charts.push({
            title: titleFor(el, cfg.title),
            note:  noteFor(el),
            ratio: (w / h) || 1.8,
            img:   inst.getDataURL({ type: 'png', pixelRatio: 2, backgroundColor: '#ffffff' }),
          });
        } catch (e) { /* chart not ready — skip */ }
      });
    }
    // Chart.js / raw canvases (opt-in so we don't grab sparklines etc.).
    document.querySelectorAll('canvas[data-pptx-chart]').forEach(function (cv) {
      try {
        var tmp = document.createElement('canvas');
        tmp.width = cv.width; tmp.height = cv.height;
        var ctx = tmp.getContext('2d');
        ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, tmp.width, tmp.height);
        ctx.drawImage(cv, 0, 0);
        cfg.charts.push({
          title: titleFor(cv, cfg.title), note: noteFor(cv),
          ratio: (cv.width / cv.height) || 1.8, img: tmp.toDataURL('image/png'),
        });
      } catch (e) { /* tainted/empty canvas — skip */ }
    });

    // Tables — opt-in only.
    document.querySelectorAll('table[data-pptx-table]').forEach(function (tb) {
      var head = [], rows = [];
      tb.querySelectorAll('thead th').forEach(function (th) { head.push(txt(th)); });
      tb.querySelectorAll('tbody tr').forEach(function (tr) {
        var r = []; tr.querySelectorAll('td,th').forEach(function (td) { r.push(txt(td)); });
        if (r.length) rows.push(r);
      });
      if (rows.length) cfg.tables.push({ title: titleFor(tb, cfg.title), head: head, rows: rows.slice(0, 30) });
    });

    return cfg;
  }

  async function build(cfg) {
    var P = new PptxGenJS();
    P.defineLayout({ name: 'EAWIDE', width: 13.33, height: 7.5 });
    P.layout = 'EAWIDE';
    var W = 13.33, H = 7.5;
    var TEAL = '0D6E6E', DARK = '111827', GREY = '6B7280', LINE = 'E5E7EB', FONT = 'Segoe UI';
    var stamp = new Date().toLocaleString();

    function footer(s) {
      var left = cfg.brand + (cfg.workspace ? '  ·  ' + cfg.workspace : '');
      s.addText(left, { x: 0.4, y: H - 0.42, w: 8.5, h: 0.25, fontSize: 8, color: GREY, fontFace: FONT });
      s.addText('Elite Advisors BMS' + (cfg.subtitle ? '  ·  ' + cfg.subtitle : ''),
        { x: W - 5.3, y: H - 0.42, w: 4.9, h: 0.25, fontSize: 8, color: GREY, fontFace: FONT, align: 'right' });
    }

    // 1 · Cover
    var c = P.addSlide(); c.background = { color: 'FFFFFF' };
    c.addShape(P.ShapeType.rect, { x: 0, y: 0, w: W, h: 1.7, fill: { color: TEAL } });
    c.addText((cfg.brand || '').toUpperCase(), { x: 0.6, y: 0.5, w: W - 1.2, h: 0.6, fontSize: 14, bold: true, color: 'FFFFFF', fontFace: FONT, charSpacing: 2 });
    c.addText(cfg.title || 'Report', { x: 0.6, y: 2.5, w: W - 1.2, h: 1.0, fontSize: 40, bold: true, color: DARK, fontFace: FONT });
    if (cfg.subtitle) c.addText(cfg.subtitle, { x: 0.6, y: 3.55, w: W - 1.2, h: 0.5, fontSize: 16, color: TEAL, bold: true, fontFace: FONT });
    c.addText('Generated ' + stamp, { x: 0.6, y: 4.15, w: W - 1.2, h: 0.4, fontSize: 12, color: GREY, fontFace: FONT });
    if (cfg.summary) c.addText(cfg.summary, { x: 0.6, y: 5.0, w: W - 1.2, h: 1.7, fontSize: 11, color: DARK, fontFace: FONT, valign: 'top', lineSpacingMultiple: 1.2 });
    c.addText('Elite Advisors — Business Management Suite', { x: 0.6, y: H - 0.5, w: W - 1.2, h: 0.3, fontSize: 9, color: GREY, fontFace: FONT });

    // 2 · Key Metrics
    if (cfg.kpis.length) {
      var s = P.addSlide(); s.background = { color: 'FFFFFF' };
      s.addText('Key Metrics', { x: 0.4, y: 0.3, w: W - 0.8, h: 0.5, fontSize: 22, bold: true, color: DARK, fontFace: FONT });
      if (cfg.subtitle) s.addText(cfg.subtitle, { x: 0.4, y: 0.85, w: W - 0.8, h: 0.3, fontSize: 11, color: GREY, fontFace: FONT });
      var cols = 3, mx = 0.4, gap = 0.3, top = 1.5, ch = 1.45;
      var cw = (W - 2 * mx - (cols - 1) * gap) / cols;
      cfg.kpis.slice(0, 12).forEach(function (k, i) {
        var col = i % cols, row = Math.floor(i / cols);
        var x = mx + col * (cw + gap), y = top + row * (ch + 0.3);
        s.addShape(P.ShapeType.roundRect, { x: x, y: y, w: cw, h: ch, fill: { color: 'FFFFFF' }, line: { color: LINE, width: 1 }, rectRadius: 0.06 });
        s.addShape(P.ShapeType.rect, { x: x, y: y, w: 0.07, h: ch, fill: { color: TEAL } });
        s.addText((k.label || '').toUpperCase(), { x: x + 0.22, y: y + 0.16, w: cw - 0.34, h: 0.3, fontSize: 9, bold: true, color: GREY, fontFace: FONT, charSpacing: 1 });
        s.addText(k.value || '—', { x: x + 0.22, y: y + 0.5, w: cw - 0.34, h: 0.5, fontSize: 20, bold: true, color: DARK, fontFace: FONT });
        if (k.meta) s.addText(k.meta, { x: x + 0.22, y: y + 1.02, w: cw - 0.34, h: 0.3, fontSize: 9, color: GREY, fontFace: FONT });
      });
      footer(s);
    }

    // 3 · One slide per chart
    cfg.charts.forEach(function (chart) {
      var s = P.addSlide(); s.background = { color: 'FFFFFF' };
      s.addText(chart.title || 'Chart', { x: 0.4, y: 0.3, w: W - 0.8, h: 0.5, fontSize: 20, bold: true, color: DARK, fontFace: FONT });
      var ax = 0.6, ay = 1.2, aw = W - 1.2, ah = H - 2.5;
      var ratio = chart.ratio || 1.8;
      var iw = aw, ih = aw / ratio;
      if (ih > ah) { ih = ah; iw = ah * ratio; }
      s.addImage({ data: chart.img, x: ax + (aw - iw) / 2, y: ay, w: iw, h: ih });
      if (chart.note) s.addText(chart.note, { x: 0.6, y: H - 1.0, w: W - 1.2, h: 0.5, fontSize: 10, italic: true, color: GREY, fontFace: FONT, valign: 'top' });
      footer(s);
    });

    // 4 · Tables (opt-in)
    cfg.tables.forEach(function (tbl) {
      var s = P.addSlide(); s.background = { color: 'FFFFFF' };
      s.addText(tbl.title || 'Table', { x: 0.4, y: 0.3, w: W - 0.8, h: 0.5, fontSize: 20, bold: true, color: DARK, fontFace: FONT });
      var rows = [];
      if (tbl.head.length) rows.push(tbl.head.map(function (h) { return { text: h, options: { bold: true, color: 'FFFFFF', fill: { color: TEAL } } }; }));
      tbl.rows.forEach(function (r) { rows.push(r.map(function (cell) { return { text: cell }; })); });
      s.addTable(rows, { x: 0.4, y: 1.2, w: W - 0.8, fontSize: 9, fontFace: FONT, color: DARK, border: { type: 'solid', color: LINE, pt: 0.5 }, autoPage: true });
      footer(s);
    });

    await P.writeFile({ fileName: slug(cfg.title) + '_' + dateStr() + '.pptx' });
  }

  window.eaPptxExport = async function (btn) {
    var original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = 'Building…';
    try {
      await loadLib();
      var cfg = collect(btn);
      if (!cfg.charts.length && !cfg.kpis.length && !cfg.tables.length) {
        throw new Error('Nothing on this page to export yet — try once the data has loaded.');
      }
      await build(cfg);
    } catch (e) {
      alert('PowerPoint export failed: ' + (e && e.message ? e.message : e));
    } finally {
      btn.disabled = false;
      btn.innerHTML = original;
    }
  };
})();
</script>
@endpush
@endonce
