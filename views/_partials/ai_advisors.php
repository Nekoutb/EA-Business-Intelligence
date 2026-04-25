<?php
/**
 * Elite Advisors — AI-generated actionable insights block.
 * Rendered on every analytics page. The underlying LLM provider/model is
 * deliberately NOT exposed to the client.
 *
 * @var string $page  Page slug passed to /api/insights (dashboard|revenue|...)
 */
$page       = $page ?? 'dashboard';
$aiReady    = (string) \EA\BI\Config::get('AI_API_KEY', '') !== '';
$uniqueId   = 'ai_' . preg_replace('/[^a-z0-9]/i', '_', $page);
?>
<section class="bg-gradient-to-br from-slate-900 via-slate-900 to-indigo-900 rounded-xl p-5 shadow-sm border border-slate-800 text-slate-100 mb-8">
  <div class="flex items-start gap-4 flex-wrap">
    <div class="flex-1 min-w-0">
      <div class="flex items-center gap-2 mb-1">
        <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-indigo-500/20 border border-indigo-400/40 text-indigo-200">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.39 4.84L20 8l-4 3.9.94 5.5L12 14.77l-4.94 2.62L8 11.9 4 8l5.61-1.16L12 2z"/></svg>
        </span>
        <h3 class="text-sm font-semibold text-white">Elite Advisors</h3>
        <span class="text-[10px] uppercase tracking-widest text-indigo-300/80">actionable insights</span>
      </div>
      <p class="text-xs text-slate-300 max-w-2xl">
        Independent, McKinsey-style analysis of this page's KPIs.
        Insights are ranked by business impact and come with a concrete next step you can assign.
      </p>
    </div>
    <div class="flex items-center gap-2">
      <?php if ($aiReady): ?>
        <button id="<?= ea_e($uniqueId) ?>_btn" type="button"
                class="inline-flex items-center gap-2 bg-white text-slate-900 text-sm font-semibold rounded-lg px-3 py-2 hover:bg-indigo-50 shadow">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
          <span id="<?= ea_e($uniqueId) ?>_btn_label">Generate insights</span>
        </button>
      <?php else: ?>
        <a href="/settings#ai" class="inline-flex items-center gap-2 bg-white text-slate-900 text-sm font-semibold rounded-lg px-3 py-2 hover:bg-indigo-50 shadow">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          Configure
        </a>
      <?php endif; ?>
    </div>
  </div>

  <div id="<?= ea_e($uniqueId) ?>_body" class="mt-4"></div>
  <?php if (!$aiReady): ?>
    <div class="mt-4 text-xs text-slate-300/80 border-t border-slate-800 pt-3">
      Add an API key in <a class="underline hover:text-white" href="/settings#ai">Settings → Elite Advisors</a> to enable this section.
    </div>
  <?php endif; ?>
</section>

<?php if ($aiReady): ?>
<script>
  (function () {
    const page = <?= json_encode($page) ?>;
    const btn  = document.getElementById(<?= json_encode($uniqueId . '_btn') ?>);
    const lbl  = document.getElementById(<?= json_encode($uniqueId . '_btn_label') ?>);
    const body = document.getElementById(<?= json_encode($uniqueId . '_body') ?>);
    if (!btn) return;

    const sevStyle = {
      critical: { badge: 'bg-rose-500/20 border border-rose-400/50 text-rose-100',     dot: 'bg-rose-400'     },
      warning:  { badge: 'bg-amber-500/20 border border-amber-400/50 text-amber-100',  dot: 'bg-amber-400'    },
      info:     { badge: 'bg-sky-500/20 border border-sky-400/50 text-sky-100',        dot: 'bg-sky-400'      },
      positive: { badge: 'bg-emerald-500/20 border border-emerald-400/50 text-emerald-100', dot: 'bg-emerald-400' },
    };

    function escapeHTML(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function render(insights) {
      if (!insights.length) {
        body.innerHTML = '<div class="text-sm text-slate-300">No insights returned. Try syncing fresh data and regenerating.</div>';
        return;
      }
      const rows = insights.map((i) => {
        const s = sevStyle[i.severity] || sevStyle.info;
        return `
          <div class="bg-slate-800/60 border border-slate-700 rounded-lg p-4">
            <div class="flex items-start gap-3">
              <span class="mt-1.5 inline-block w-2 h-2 rounded-full ${s.dot}"></span>
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                  <h4 class="text-sm font-semibold text-white">${escapeHTML(i.title)}</h4>
                  <span class="text-[10px] uppercase tracking-widest rounded px-1.5 py-0.5 ${s.badge}">${escapeHTML(i.severity)}</span>
                </div>
                <p class="text-xs text-slate-300 mt-1 leading-relaxed">${escapeHTML(i.body)}</p>
                ${i.action ? `<p class="text-xs text-indigo-200 mt-2"><span class="font-semibold">Action:</span> ${escapeHTML(i.action)}</p>` : ''}
              </div>
            </div>
          </div>`;
      }).join('');
      body.innerHTML = `<div class="grid grid-cols-1 md:grid-cols-2 gap-3">${rows}</div>`;
    }

    async function run() {
      btn.disabled = true;
      const prev = lbl.textContent;
      lbl.textContent = 'Thinking…';
      body.innerHTML = '<div class="text-sm text-slate-300 animate-pulse">Analyzing KPIs and drafting recommendations…</div>';
      try {
        const r = await fetch('/api/insights?page=' + encodeURIComponent(page), { credentials: 'same-origin' });
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || 'Request failed');
        render(j.insights || []);
        lbl.textContent = 'Regenerate';
      } catch (e) {
        body.innerHTML = '<div class="text-sm text-rose-200">Could not generate insights: ' + escapeHTML(e.message) + '</div>';
        lbl.textContent = prev;
      } finally {
        btn.disabled = false;
      }
    }

    btn.addEventListener('click', run);
  })();
</script>
<?php endif; ?>
