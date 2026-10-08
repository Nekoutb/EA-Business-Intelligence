<style>
    :root {
        --bg-page:#FBF8F1; --bg-surface:#F5F0E4; --ink-200:#D8D0C4; --ink-300:#B8AFA3;
        --fg-primary:#14110F; --fg-secondary:#4A4239; --fg-muted:#948A7E;
        --accent:#B07F3B; --accent-hover:#8E6228; --bad:#B26A53; --good:#3E5A44;
    }
    * { box-sizing: border-box; margin: 0; }
    body {
        font-family: 'Hanken Grotesk', -apple-system, 'Segoe UI', sans-serif;
        background: var(--bg-page); color: var(--fg-primary);
        min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px;
    }
    .card {
        width: 100%; max-width: 420px; background: var(--bg-surface);
        border: 1px solid var(--ink-200); border-radius: 8px; padding: 32px;
        box-shadow: 0 12px 32px rgba(31,27,23,.08), 0 4px 8px rgba(31,27,23,.04);
    }
    .eyebrow { font-size: 11px; letter-spacing: .18em; text-transform: uppercase; color: var(--accent); font-weight: 600; margin-bottom: 8px; }
    h1 { font-size: 22px; font-weight: 700; letter-spacing: -.01em; margin-bottom: 8px; }
    .sub { font-size: 14px; color: var(--fg-secondary); line-height: 1.55; margin-bottom: 20px; }
    label { display: block; font-size: 13px; font-weight: 600; color: var(--fg-secondary); margin: 16px 0 6px; }
    input[type=password], input[type=email] {
        width: 100%; padding: 10px 12px; font: inherit; font-size: 15px;
        border: 1px solid var(--ink-300); border-radius: 4px; background: #fff; color: var(--fg-primary);
    }
    input:focus { outline: 2px solid var(--accent); outline-offset: 1px; border-color: var(--accent); }
    .hint { font-size: 12px; color: var(--fg-muted); margin-top: 6px; }
    .err { font-size: 13px; color: var(--bad); margin-top: 6px; font-weight: 500; }
    .note { font-size: 13px; line-height: 1.5; padding: 10px 12px; border-radius: 4px; margin-bottom: 8px; }
    .note--ok  { background: #ECF1EC; color: var(--good); border: 1px solid #C9D6CB; }
    .note--err { background: #F8ECE7; color: var(--bad); border: 1px solid #E8C9BD; }
    button, .btn {
        display: block; width: 100%; margin-top: 24px; padding: 12px; font: inherit; font-size: 15px; font-weight: 600;
        background: var(--accent); color: #fff; border: 0; border-radius: 4px; cursor: pointer; text-align: center; text-decoration: none;
    }
    button:hover, .btn:hover { background: var(--accent-hover); }
    .foot { margin-top: 16px; text-align: center; font-size: 13px; }
    .foot a { color: var(--fg-muted); }
</style>
