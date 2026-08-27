{{-- Shared behavior for the Logistics report tables:
     - .lg-roll-row / .lg-roll-detail: click a row to expand its paired
       detail row (data-target = detail row id).
     - table.sortable: click any non-empty header to sort by that column
       (numeric columns sort largest-first on the initial click; text
       columns sort A-Z). Roll-row/roll-detail pairs move together so an
       expandable table stays intact after sorting. --}}
<script>
(function () {
  document.querySelectorAll('.lg-roll-row').forEach(function (row) {
    row.addEventListener('click', function () {
      var t = document.getElementById(row.dataset.target);
      if (!t) return;
      var open = t.style.display !== 'none';
      t.style.display = open ? 'none' : 'table-row';
      var arrow = row.querySelector('.lg-arrow');
      if (arrow) arrow.textContent = open ? '▸' : '▾';
    });
  });

  function sortTable(table, idx, th) {
    var rows = Array.from(table.rows).slice(1); // skip header row
    var groups = [];
    for (var i = 0; i < rows.length; i++) {
      var r = rows[i];
      if (r.classList.contains('lg-roll-detail')) continue;
      var group = [r];
      var next = rows[i + 1];
      if (r.dataset && r.dataset.target && next && next.id === r.dataset.target) {
        group.push(next); i++;
      }
      groups.push(group);
    }
    var numeric = th.classList.contains('num');
    var dir = th.dataset.dir === (numeric ? 'desc' : 'asc') ? (numeric ? 'asc' : 'desc') : (numeric ? 'desc' : 'asc');
    Array.from(table.rows[0].cells).forEach(function (h) { h.classList.remove('sort-asc', 'sort-desc'); h.dataset.dir = ''; });
    th.dataset.dir = dir;
    th.classList.add(dir === 'desc' ? 'sort-desc' : 'sort-asc');

    groups.sort(function (a, b) {
      var av = (a[0].children[idx] ? a[0].children[idx].textContent : '').trim();
      var bv = (b[0].children[idx] ? b[0].children[idx].textContent : '').trim();
      if (numeric) {
        av = parseFloat(av.replace(/[^0-9.\-]/g, '')) || 0;
        bv = parseFloat(bv.replace(/[^0-9.\-]/g, '')) || 0;
        return dir === 'desc' ? bv - av : av - bv;
      }
      return dir === 'desc' ? bv.localeCompare(av) : av.localeCompare(bv);
    });
    groups.forEach(function (g) { g.forEach(function (r) { table.appendChild(r); }); });
  }

  document.querySelectorAll('table.sortable').forEach(function (table) {
    var headerRow = table.rows[0];
    if (!headerRow) return;
    Array.from(headerRow.cells).forEach(function (th, idx) {
      if (!th.textContent.trim()) return; // skip blank/arrow columns
      th.addEventListener('click', function () { sortTable(table, idx, th); });
    });
  });
})();
</script>
