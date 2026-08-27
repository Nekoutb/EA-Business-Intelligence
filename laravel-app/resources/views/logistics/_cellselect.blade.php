{{-- Spreadsheet-style cell selection for tables marked class="cell-select".
     Click-drag selects a rectangular range; Ctrl/Cmd+click or +drag adds
     to the selection. A floating bar at the bottom-right shows the count
     and sum of the selected cells. Links, buttons, sort headers and the
     roll-up arrow are left untouched. --}}
<style>
  table.cell-select td { cursor: cell; user-select: none; }
  table.cell-select td.cell-selected { background: rgba(13,110,110,.16); }
  #lgCellBar {
    position: fixed; right: 16px; bottom: 16px; z-index: 60; display: none;
    align-items: center; gap: 10px; background: #1e3d44; color: #fff;
    padding: 9px 14px; border-radius: 10px; box-shadow: 0 6px 18px rgba(0,0,0,.25);
    font: 600 12.5px/1.4 -apple-system, "Segoe UI", sans-serif;
  }
  #lgCellBar button {
    background: rgba(255,255,255,.15); border: 0; color: #fff; cursor: pointer;
    padding: 4px 9px; border-radius: 6px; font-size: 11px; font-weight: 600;
  }
  #lgCellBar button:hover { background: rgba(255,255,255,.28); }
</style>
<div id="lgCellBar"><span id="lgCellBarText"></span><button type="button" id="lgCellBarClear">Clear</button></div>
<script>
(function () {
  var selected = new Set();
  var dragging = false, dragTable = null, anchorRow = -1, anchorCol = -1, baseSet = null;

  function rowIndex(table, tr) { return Array.from(table.rows).indexOf(tr); }
  function colIndex(tr, td)    { return Array.from(tr.children).indexOf(td); }

  function rectCells(table, r1, c1, r2, c2) {
    var rmin = Math.min(r1, r2), rmax = Math.max(r1, r2);
    var cmin = Math.min(c1, c2), cmax = Math.max(c1, c2);
    var cells = [];
    for (var ri = rmin; ri <= rmax; ri++) {
      var tr = table.rows[ri]; if (!tr) continue;
      for (var ci = cmin; ci <= cmax; ci++) {
        var cell = tr.children[ci];
        if (cell && cell.tagName === 'TD') cells.push(cell);
      }
    }
    return cells;
  }

  function applyRect(table, r1, c1, r2, c2, base) {
    var rect = rectCells(table, r1, c1, r2, c2);
    var next = new Set(base);
    rect.forEach(function (c) { next.add(c); });
    selected.forEach(function (c) { if (!next.has(c)) c.classList.remove('cell-selected'); });
    next.forEach(function (c) { if (!selected.has(c)) c.classList.add('cell-selected'); });
    selected = next;
    updateBar();
  }

  function updateBar() {
    var bar = document.getElementById('lgCellBar');
    var text = document.getElementById('lgCellBarText');
    if (selected.size === 0) { bar.style.display = 'none'; return; }
    var total = 0, numeric = 0;
    selected.forEach(function (td) {
      var raw = td.textContent.trim();
      if (!/[0-9]/.test(raw)) return;
      var n = parseFloat(raw.replace(/[^0-9.\-]/g, ''));
      if (!isNaN(n)) { total += n; numeric++; }
    });
    var extra = selected.size - numeric;
    text.textContent = selected.size + ' cell' + (selected.size > 1 ? 's' : '') + ' · Σ '
      + total.toLocaleString('en-US') + (extra > 0 ? ' (' + extra + ' non-numeric skipped)' : '');
    bar.style.display = 'flex';
  }

  document.getElementById('lgCellBarClear').addEventListener('click', function () {
    selected.forEach(function (c) { c.classList.remove('cell-selected'); });
    selected = new Set();
    updateBar();
  });

  document.addEventListener('mousedown', function (e) {
    var table = e.target.closest('table.cell-select');
    if (!table) return;
    if (e.target.closest('a, button, th, .lg-arrow')) return;
    var td = e.target.closest('td');
    if (!td) return;
    e.preventDefault();
    var tr = td.parentElement;
    var r = rowIndex(table, tr), c = colIndex(tr, td);
    dragging = true; dragTable = table; anchorRow = r; anchorCol = c;
    baseSet = (e.ctrlKey || e.metaKey) ? new Set(selected) : new Set();
    if (baseSet.size === 0) { selected.forEach(function (x) { x.classList.remove('cell-selected'); }); selected = new Set(); }
    applyRect(table, r, c, r, c, baseSet);
  });

  document.addEventListener('mousemove', function (e) {
    if (!dragging) return;
    var td = e.target.closest('td');
    if (!td || td.closest('table') !== dragTable) return;
    var tr = td.parentElement;
    var r = rowIndex(dragTable, tr), c = colIndex(tr, td);
    applyRect(dragTable, anchorRow, anchorCol, r, c, baseSet);
  });

  document.addEventListener('mouseup', function () {
    dragging = false; dragTable = null; baseSet = null;
  });
})();
</script>
