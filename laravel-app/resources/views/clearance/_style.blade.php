<style>
    .clr { --c-red:#B3261E; --c-red-bg:#FBEAE8; --c-amb:#9A6700; --c-amb-bg:#FFF4D6; --c-grn:#2E6B3F; --c-grn-bg:#E8F3EB;
           --c-ink:#14110F; --c-mut:#6B6157; --c-line:#E5DED2; --c-card:#FFFFFF; --c-acc:#3E5A44;
           color: var(--c-ink); font-variant-numeric: tabular-nums; }
    .clr * { box-sizing: border-box; }
    .clr-head { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:14px; }
    .clr-eyebrow { font-size:11px; letter-spacing:.14em; text-transform:uppercase; color:var(--c-mut); font-weight:600; }
    .clr-title { font-size:20px; font-weight:700; margin:2px 0 4px; }
    .clr-period { font-size:12px; color:var(--c-mut); }
    .clr-decide { background:#F5F0E4; border-left:3px solid var(--c-acc); padding:10px 14px; border-radius:4px; font-size:14px; margin:0 0 14px; }
    .clr-decide b { font-weight:700; }
    .clr-warn { background:var(--c-red-bg); color:var(--c-red); border:1px solid #F2C7C2; border-radius:4px; padding:8px 12px; font-size:12.5px; margin-bottom:6px; font-weight:500; }
    .clr-note { font-size:11.5px; color:var(--c-mut); margin-top:6px; }
    .clr-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .clr-btn { display:inline-block; font-size:12px; font-weight:600; padding:7px 12px; border-radius:5px; border:1px solid var(--c-line);
               background:var(--c-card); color:var(--c-ink); text-decoration:none; }
    .clr-btn:hover { border-color:var(--c-acc); }
    .clr-kpis { display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:10px; margin-bottom:14px; }
    .clr-kpi { background:var(--c-card); border:1px solid var(--c-line); border-radius:6px; padding:12px 14px; }
    .clr-kpi .l { font-size:11.5px; color:var(--c-mut); font-weight:600; }
    .clr-kpi .v { font-size:22px; font-weight:700; margin-top:4px; }
    .clr-kpi .s { font-size:11.5px; color:var(--c-mut); margin-top:2px; }
    .clr-kpi.red { border-color:#EBB3AD; background:var(--c-red-bg); } .clr-kpi.red .v { color:var(--c-red); }
    .clr-kpi.amber { border-color:#EED38A; background:var(--c-amb-bg); } .clr-kpi.amber .v { color:var(--c-amb); }
    .clr-kpi.green .v { color:var(--c-grn); }
    .clr-up { color:var(--c-grn); } .clr-down { color:var(--c-red); }
    .clr-charts { display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:10px; margin-bottom:14px; }
    .clr-card { background:var(--c-card); border:1px solid var(--c-line); border-radius:6px; padding:12px 14px; margin-bottom:14px; }
    .clr-card h3 { font-size:13.5px; font-weight:700; margin:0 0 8px; }
    .clr-tablewrap { overflow-x:auto; }
    .clr table { border-collapse:collapse; width:100%; font-size:12.5px; }
    .clr th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:var(--c-mut); font-weight:700;
              border-bottom:1px solid var(--c-line); padding:6px 8px; white-space:nowrap; }
    .clr td { border-bottom:1px solid #F1ECE3; padding:6px 8px; vertical-align:top; }
    .clr td.n, .clr th.n { text-align:right; white-space:nowrap; }
    .clr tr.red td { background:var(--c-red-bg); } .clr tr.red td:first-child { box-shadow: inset 3px 0 0 var(--c-red); }
    .clr tr.amber td { background:var(--c-amb-bg); } .clr tr.amber td:first-child { box-shadow: inset 3px 0 0 var(--c-amb); }
    .clr tr.green td:first-child { box-shadow: inset 3px 0 0 var(--c-grn); }
    .clr tr.total td { font-weight:700; border-top:2px solid var(--c-line); background:#FAF7F0; }
    .clr-empty { font-size:12.5px; color:var(--c-mut); padding:10px 2px; font-style:italic; }
    .clr-ok { color:var(--c-grn); font-weight:700; } .clr-bad { color:var(--c-red); font-weight:700; }
    .clr-foot { font-size:11px; color:var(--c-mut); border-top:1px solid var(--c-line); padding-top:8px; margin-top:6px; }
    .clr-tabs { display:flex; gap:4px; flex-wrap:wrap; margin-bottom:12px; }
    .clr-tabs a { font-size:11.5px; padding:4px 9px; border-radius:4px; text-decoration:none; color:var(--c-mut); border:1px solid transparent; }
    .clr-tabs a.on { color:var(--c-ink); border-color:var(--c-line); background:var(--c-card); font-weight:700; }
</style>
