{{-- Shared styles for the Logistics Operations pages. --}}
<style>
  .lg-tiles { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; margin:14px 0; }
  .lg-tile { background:#fff; border:1px solid #e8e4de; border-radius:12px; padding:13px 15px 11px; }
  .lg-tile .l { font:600 10px/1.3 var(--font,inherit); letter-spacing:.08em; text-transform:uppercase; color:#7c7466; }
  .lg-tile .v { font-weight:700; font-size:22px; line-height:1.15; margin-top:6px; color:#1e3d44; font-variant-numeric:tabular-nums; }
  .lg-tile .s { font-size:11.5px; color:#7c7466; margin-top:3px; }
  .lg-tile.bad .v { color:#9a3b2e; }
  .lg-tile.good .v { color:#0d6e6e; }
  .lg-card { background:#fff; border:1px solid #e8e4de; border-radius:12px; padding:16px 18px; margin:14px 0; }
  .lg-card h3 { margin:0 0 10px; font-size:11px; letter-spacing:.09em; text-transform:uppercase; color:#7c7466; font-weight:600; }
  .lg-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; align-items:start; }
  @media (max-width:900px) { .lg-grid2 { grid-template-columns:1fr; } }
  .lg-brow { display:grid; grid-template-columns:130px 1fr 110px; gap:10px; align-items:center; padding:3px 0; font-size:12px; }
  .lg-brow .k { color:#5c6a66; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .lg-brow .bar { height:14px; border-radius:4px; background:#f1efe9; overflow:hidden; }
  .lg-brow .bar i { display:block; height:100%; background:#0d6e6e; border-radius:4px; min-width:2px; }
  .lg-brow.warn .bar i { background:#9a3b2e; }
  .lg-brow .n { text-align:right; font-size:11.5px; font-variant-numeric:tabular-nums; color:#5c6a66; white-space:nowrap; }
  table.lg-dt { width:100%; border-collapse:collapse; font-size:12.5px; }
  table.lg-dt th, table.lg-dt td { text-align:left; padding:7px 10px; border-bottom:1px solid #efece6; white-space:nowrap; }
  table.lg-dt th { font-size:10px; letter-spacing:.07em; text-transform:uppercase; color:#7c7466; }
  table.lg-dt th.num, table.lg-dt td.num { text-align:right; font-variant-numeric:tabular-nums; }
  table.lg-dt td.neg { color:#9a3b2e; } table.lg-dt td.pos { color:#0d6e6e; }
  table.sortable th { cursor:pointer; user-select:none; }
  table.sortable th:hover { color:#1e3d44; }
  table.sortable th.sort-asc::after  { content:' \25B4'; }
  table.sortable th.sort-desc::after { content:' \25BE'; }
  .lg-arrow { display:inline-block; width:12px; color:#7c7466; cursor:pointer; }
  .lg-note { border-left:3px solid #9a3b2e; background:#f8e9e5; border-radius:0 10px 10px 0;
    padding:12px 16px; margin:14px 0; font-size:13px; color:#5c4a44; }
  .lg-note.ok { border-color:#0d6e6e; background:#e3efec; color:#2e5651; }
  .lg-scroll { overflow-x:auto; }
</style>
