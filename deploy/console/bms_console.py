#!/usr/bin/env python3
"""BMS console: https://status-bms.cm-ea.com (ticket T9).

One page to watch and drive BMS on server 6, in the spirit of Elimelec's
status page: dev, staging and production side by side, the server's
health, and two buttons -- Rebuild staging from production, and Promote
staging -> prod (with a dry run). Each press streams its output and is
logged with the person who pressed it.

Python standard library only. Runs as root under systemd
(bms-console.service), listening on 127.0.0.1 only; Apache terminates
HTTPS and proxies to it. Root is needed because the buttons run the same
server scripts the GitHub workflows run (MySQL as root, the deploy key).

Security: personal logins (PBKDF2-SHA256), login throttling, session
cookies that are HttpOnly + Secure + SameSite=Strict, and every POST must
come from the console's own origin AND carry the session's CSRF token.
"""
import calendar
import hashlib
import hmac
import html
import json
import os
import re
import secrets
import shutil
import ssl
import subprocess
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

# ------------------------------------------------------------------ settings
PORT = int(os.environ.get("BMS_CONSOLE_PORT", "8077"))
ORIGIN = os.environ.get("BMS_CONSOLE_ORIGIN", "https://status-bms.cm-ea.com")
HOME = os.environ.get("BMS_CONSOLE_HOME", "/opt/bms-console")
STATE = os.environ.get("BMS_CONSOLE_STATE", "/var/lib/bms-console")
USERS_FILE = os.path.join(STATE, "users.json")
JOBS_DIR = os.path.join(STATE, "jobs")
ACTIONS_LOG = os.path.join(STATE, "actions.log")
BIN = os.path.join(HOME, "bin")
REPO = "Nekoutb/EA-Business-Intelligence"
COOKIE = "bms_console"
SESSION_SECONDS = 12 * 3600
PBKDF2_ROUNDS = 310_000

ENVIRONMENTS = [
    # name, url, folder on server 6, branch
    ("Development", "https://dev-bms.cm-ea.com", "/var/www/eams-dev", "dev/*"),
    ("Staging", "https://staging-bms.cm-ea.com", "/var/www/eams-staging", "staging"),
    ("Production", "https://bms.cm-ea.com", "/var/www/eams", "prod"),
]

ACTIONS = {
    "rebuild": ("Rebuild staging from production", ["bash", os.path.join(BIN, "bms-rebuild-staging.sh")]),
    "promote-dry": ("Promote staging -> prod (dry run)", ["bash", os.path.join(BIN, "bms-promote.sh"), "--dry-run"]),
    "promote": ("Promote staging -> prod", ["bash", os.path.join(BIN, "bms-promote.sh")]),
}

# ------------------------------------------------------------------ users
_users_lock = threading.Lock()


def hash_password(password: str) -> str:
    salt = secrets.token_hex(16)
    digest = hashlib.pbkdf2_hmac("sha256", password.encode(), salt.encode(), PBKDF2_ROUNDS).hex()
    return f"pbkdf2_sha256${PBKDF2_ROUNDS}${salt}${digest}"


def easy_password() -> str:
    """A password that survives being read off one screen and typed into
    another: no look-alike characters (0/O, 1/l/I) and no symbols except
    the separators, e.g. 'k7mp-x3qa-9fhw-t2nc' (about 98 bits)."""
    alphabet = "abcdefghjkmnpqrstuvwxyz23456789"
    return "-".join("".join(secrets.choice(alphabet) for _ in range(5)) for _ in range(4))


def check_password(password: str, stored: str) -> bool:
    try:
        _, rounds, salt, digest = stored.split("$")
        test = hashlib.pbkdf2_hmac("sha256", password.encode(), salt.encode(), int(rounds)).hex()
        return hmac.compare_digest(test, digest)
    except Exception:
        return False


def load_users() -> dict:
    with _users_lock:
        try:
            with open(USERS_FILE) as f:
                return json.load(f).get("users", {})
        except FileNotFoundError:
            return {}


def save_users(users: dict) -> None:
    with _users_lock:
        tmp = USERS_FILE + ".tmp"
        with open(tmp, "w") as f:
            json.dump({"users": users}, f, indent=2)
        os.chmod(tmp, 0o600)
        os.replace(tmp, USERS_FILE)


# ------------------------------------------------------------------ sessions + throttling
_sessions: dict = {}          # token -> {"user", "csrf", "expires"}
_failures: dict = {}          # ip -> [timestamps]
_sessions_lock = threading.Lock()


def new_session(user: str) -> str:
    token = secrets.token_urlsafe(32)
    with _sessions_lock:
        _sessions[token] = {"user": user, "csrf": secrets.token_urlsafe(24),
                            "expires": time.time() + SESSION_SECONDS}
    return token


def get_session(token: str):
    with _sessions_lock:
        s = _sessions.get(token or "")
        if not s:
            return None
        if s["expires"] < time.time():
            _sessions.pop(token, None)
            return None
        if s["user"] not in load_users():   # removed while signed in
            _sessions.pop(token, None)
            return None
        return s


def throttled(ip: str) -> bool:
    now = time.time()
    recent = [t for t in _failures.get(ip, []) if now - t < 900]
    _failures[ip] = recent
    return len(recent) >= 8


def note_failure(ip: str) -> None:
    _failures.setdefault(ip, []).append(time.time())


# ------------------------------------------------------------------ jobs (one at a time)
_job_lock = threading.Lock()
_current_job = {"id": None}


def log_action(user: str, action: str, result: str) -> None:
    line = f"{time.strftime('%Y-%m-%d %H:%M:%S', time.gmtime())} UTC\t{user}\t{action}\t{result}\n"
    with open(ACTIONS_LOG, "a") as f:
        f.write(line)


def start_job(action: str, user: str):
    if not _job_lock.acquire(blocking=False):
        return None
    job_id = time.strftime("%Y%m%d-%H%M%S", time.gmtime()) + "-" + action
    out_path = os.path.join(JOBS_DIR, job_id + ".log")
    meta_path = os.path.join(JOBS_DIR, job_id + ".json")
    label, cmd = ACTIONS[action]
    meta = {"id": job_id, "action": action, "label": label, "user": user,
            "started": time.time(), "finished": None, "exit": None}
    with open(meta_path, "w") as f:
        json.dump(meta, f)
    _current_job["id"] = job_id
    log_action(user, label, "started " + job_id)

    def run():
        try:
            env = dict(os.environ, BMS_ACTOR=user, BMS_CONSOLE_JOB=job_id)
            with open(out_path, "wb") as out:
                out.write(f"{label} -- pressed by {user}\n\n".encode())
                out.flush()
                proc = subprocess.Popen(cmd, stdout=out, stderr=subprocess.STDOUT, env=env, cwd="/")
                code = proc.wait()
        except Exception as e:  # pragma: no cover
            with open(out_path, "ab") as out:
                out.write(f"\nConsole error: {e}\n".encode())
            code = -1
        meta["finished"], meta["exit"] = time.time(), code
        with open(meta_path, "w") as f:
            json.dump(meta, f)
        log_action(user, label, f"exit {code} {job_id}")
        _current_job["id"] = None
        _job_lock.release()

    threading.Thread(target=run, daemon=True).start()
    return job_id


def job_meta(job_id: str):
    if not job_id or "/" in job_id or ".." in job_id:
        return None
    try:
        with open(os.path.join(JOBS_DIR, job_id + ".json")) as f:
            return json.load(f)
    except (FileNotFoundError, json.JSONDecodeError):
        return None


def recent_actions(n=15):
    try:
        with open(ACTIONS_LOG) as f:
            lines = f.readlines()[-200:]
    except FileNotFoundError:
        return []
    rows = [l.rstrip("\n").split("\t") for l in lines if "\texit " in l]
    return list(reversed(rows))[:n]


# ------------------------------------------------------------------ status (cached 60 s)
_cache = {"at": 0, "data": None}
_cache_lock = threading.Lock()


def _get(url: str, timeout=8):
    req = urllib.request.Request(url, headers={"User-Agent": "bms-console"})
    return urllib.request.urlopen(req, timeout=timeout, context=ssl.create_default_context())


def site_status(url: str) -> str:
    try:
        with _get(url + "/") as r:
            return str(r.status)
    except urllib.error.HTTPError as e:
        return str(e.code)
    except Exception:
        return "down"


def github(path: str):
    try:
        with _get(f"https://api.github.com/repos/{REPO}/{path}") as r:
            return json.load(r)
    except Exception:
        return None


def git(*args) -> str:
    repo = os.path.join(HOME, "repo")
    try:
        return subprocess.run(["git", "-C", repo, *args], capture_output=True, text=True, timeout=30).stdout.strip()
    except Exception:
        return ""


def read(path: str) -> str:
    try:
        with open(path) as f:
            return f.read().strip()
    except Exception:
        return ""


def ago(ts) -> str:
    if not ts:
        return "unknown"
    s = int(time.time() - ts)
    for unit, n in (("day", 86400), ("hour", 3600), ("minute", 60)):
        if s >= n:
            k = s // n
            return f"{k} {unit}{'s' if k > 1 else ''} ago"
    return "just now"


def iso_ts(s: str):
    try:
        return calendar.timegm(time.strptime(s, "%Y-%m-%dT%H:%M:%SZ"))
    except Exception:
        return None


def collect() -> dict:
    with _cache_lock:
        if _cache["data"] and time.time() - _cache["at"] < 60:
            return _cache["data"]
    git("fetch", "--quiet", "--prune", "origin")
    prod_run = github("actions/workflows/deploy.yml/runs?branch=prod&status=success&per_page=1")
    prod_run = (prod_run or {}).get("workflow_runs", [None])[0] if prod_run else None
    envs = []
    for name, url, folder, branch in ENVIRONMENTS:
        if name == "Production":
            sha = (prod_run or {}).get("head_sha", "")
            when = iso_ts((prod_run or {}).get("updated_at", ""))
            head = git("rev-parse", "origin/prod")
        else:
            sha = read(os.path.join(folder, "current", "REVISION"))
            try:   # the "current" link is replaced at every deploy
                when = os.lstat(os.path.join(folder, "current")).st_mtime
            except OSError:
                when = None
            head = git("rev-parse", "origin/staging") if name == "Staging" else ""
        subject = git("log", "-1", "--format=%s", sha) if sha else ""
        envs.append({"name": name, "url": url, "branch": branch, "sha": sha, "subject": subject,
                     "when": when, "http": site_status(url),
                     "behind": bool(head and sha and head != sha)})
    rebuilt = read("/var/www/eams-staging/shared/last-rebuild.txt")
    try:
        load = os.getloadavg()
    except (AttributeError, OSError):   # not on Linux (local testing)
        load = (0.0, 0.0, 0.0)
    mem = {}
    for line in read("/proc/meminfo").splitlines():
        k, _, v = line.partition(":")
        mem[k] = int(v.split()[0]) if v.split() else 0
    du = shutil.disk_usage("/")
    up = float((read("/proc/uptime") or "0").split()[0])
    server = {
        "load": f"{load[0]:.2f} / {load[1]:.2f} / {load[2]:.2f}",
        "mem": f"{(mem.get('MemTotal', 0) - mem.get('MemAvailable', 0)) / 1048576:.1f} of {mem.get('MemTotal', 0) / 1048576:.1f} GB used",
        "disk": f"{du.used / 1e9:.0f} of {du.total / 1e9:.0f} GB used",
        "uptime": f"{int(up // 86400)} days",
    }
    data = {"envs": envs, "rebuilt": rebuilt, "server": server, "at": time.time()}
    with _cache_lock:
        _cache.update(at=time.time(), data=data)
    return data


# ------------------------------------------------------------------ HTML
CSS = """
*{box-sizing:border-box}body{margin:0;font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif;color:#1d1d1f;background:#f6f6f3}
header{background:#111;color:#fff;padding:14px 24px;display:flex;justify-content:space-between;align-items:center}
header a{color:#ddd;margin-left:16px;text-decoration:none}header b{font-weight:600}
main{max-width:1080px;margin:0 auto;padding:24px}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.card{background:#fff;border:1px solid #e2e2dc;border-radius:10px;padding:16px}
.card h3{margin:0 0 6px;font-size:16px}.muted{color:#6b6b66;font-size:13px}
.ok{color:#1b7f3b}.bad{color:#b42318}.warn{color:#9a6700}
code{font:13px ui-monospace,Consolas,monospace;background:#f0f0ec;padding:1px 5px;border-radius:4px}
section{margin-top:24px}h2{font-size:17px;margin:0 0 10px}
button{font:inherit;padding:8px 14px;border-radius:8px;border:1px solid #111;background:#111;color:#fff;cursor:pointer}
button.light{background:#fff;color:#111}button:disabled{opacity:.5;cursor:default}
form.inline{display:inline}table{width:100%;border-collapse:collapse;font-size:14px}
td,th{text-align:left;padding:6px 8px;border-bottom:1px solid #ecece7}
pre{background:#111;color:#e8e8e3;padding:16px;border-radius:10px;white-space:pre-wrap;font:13px/1.45 ui-monospace,Consolas,monospace;max-height:70vh;overflow:auto}
input{font:inherit;padding:8px;border:1px solid #ccc;border-radius:8px;width:100%;margin:4px 0 12px}
.login{max-width:340px;margin:80px auto}.err{color:#b42318}
@media(max-width:800px){.grid{grid-template-columns:1fr}}
"""

e = html.escape


def page(title: str, body: str, user=None) -> bytes:
    nav = ""
    if user:
        nav = (f"<div><a href='/'>Overview</a><a href='/people'>People</a>"
               f"<a href='/logout'>Sign out ({e(user)})</a></div>")
    return (f"<!doctype html><html lang='en'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>"
            f"<title>{e(title)}</title><style>{CSS}</style></head><body>"
            f"<header><b>BMS console</b>{nav}</header><main>{body}</main></body></html>").encode()


def csrf_field(s) -> str:
    return f"<input type='hidden' name='csrf' value='{e(s['csrf'])}'>"


def overview(s) -> bytes:
    d = collect()
    cards = []
    for env in d["envs"]:
        http_ok = env["http"] == "200"
        state = f"<span class='{'ok' if http_ok else 'bad'}'>{'answers' if http_ok else 'HTTP ' + e(env['http'])}</span>"
        behind = "<div class='warn'>Not on the head of its branch</div>" if env["behind"] else ""
        sha = f"<code>{e(env['sha'][:12])}</code> {e(env['subject'][:60])}" if env["sha"] else "<span class='muted'>unknown</span>"
        cards.append(
            f"<div class='card'><h3>{e(env['name'])}</h3>"
            f"<div><a href='{e(env['url'])}' target='_blank' rel='noopener'>{e(env['url'][8:])}</a> &middot; {state}</div>"
            f"<div class='muted'>branch <code>{e(env['branch'])}</code> &middot; deployed {e(ago(env['when']))}</div>"
            f"<div style='margin-top:8px'>{sha}</div>{behind}</div>")
    busy = _current_job["id"]
    dis = " disabled" if busy else ""
    running = (f"<p class='warn'>Running now: <a href='/jobs/{e(busy)}'>{e(busy)}</a>. One action at a time.</p>" if busy else "")
    actions = (
        f"<section><h2>Actions</h2>{running}"
        f"<form class='inline' method='post' action='/actions/promote-dry'>{csrf_field(s)}<button class='light'{dis}>Promote: dry run</button></form> "
        f"<form class='inline' method='post' action='/actions/promote' onsubmit=\"return confirm('Promote what staging runs to the LIVE site?')\">{csrf_field(s)}<button{dis}>Promote staging &rarr; prod</button></form> "
        f"<form class='inline' method='post' action='/actions/rebuild' onsubmit=\"return confirm('Replace staging data with a fresh copy of production? Anything entered on staging is lost.')\">{csrf_field(s)}<button class='light'{dis}>Rebuild staging from production</button></form>"
        f"<p class='muted'>Promote checks that staging runs the head of <code>staging</code>, that the tests passed on it and that it is a fast-forward, then moves <code>prod</code>; GitHub then deploys the live site. "
        f"Dev and staging deploy by themselves when their branch is pushed. Last staging rebuild: {e(d['rebuilt'] or 'unknown')}.</p></section>")
    srv = d["server"]
    server = (f"<section><h2>Server 6</h2><div class='card'>Load {e(srv['load'])} &middot; Memory {e(srv['mem'])} &middot; "
              f"Disk {e(srv['disk'])} &middot; Up {e(srv['uptime'])}</div></section>")
    def result(r):
        words = r[3].split()
        label = e(" ".join(words[:2]))
        return f"<a href='/jobs/{e(words[-1])}'>{label}</a>" if len(words) > 2 and words[-1] != "-" else label
    rows = "".join(f"<tr><td>{e(r[0])}</td><td>{e(r[1])}</td><td>{e(r[2])}</td><td>{result(r)}</td></tr>"
                   for r in recent_actions() if len(r) >= 4)
    history = (f"<section><h2>History</h2><div class='card'><table><tr><th>When</th><th>Who</th><th>What</th><th>Result</th></tr>"
               f"{rows or '<tr><td colspan=4 class=muted>Nothing yet</td></tr>'}</table></div></section>")
    body = (f"<div class='grid'>{''.join(cards)}</div>{actions}{server}{history}"
            f"<p class='muted'>Status refreshed {e(ago(d['at']))}.</p>")
    return page("BMS console", body, s["user"])


def job_page(s, job_id: str) -> bytes:
    m = job_meta(job_id)
    if not m:
        return page("Not found", "<p>No such job.</p>", s["user"])
    body = (f"<h2>{e(m['label'])}</h2><p class='muted'>Pressed by {e(m['user'])} &middot; <span id='st'>running…</span></p>"
            f"<pre id='out'></pre><p><a href='/'>Back to overview</a></p>"
            "<script>let off=0;const o=document.getElementById('out'),st=document.getElementById('st');"
            f"async function tick(){{const r=await fetch('/jobs/{e(job_id)}/text?from='+off);const j=await r.json();"
            "o.textContent+=j.text;off=j.next;o.scrollTop=o.scrollHeight;"
            "if(j.done){st.textContent=j.exit===0?'finished: success':'finished: exit '+j.exit;st.className=j.exit===0?'ok':'bad';}"
            "else setTimeout(tick,1500);}tick();</script>")
    return page(m["label"], body, s["user"])


def people_page(s, msg="") -> bytes:
    users = load_users()
    me_admin = users.get(s["user"], {}).get("admin", False)
    rows = ""
    for name, u in sorted(users.items()):
        ctl = ""
        if me_admin and name != s["user"]:
            ctl = (f"<form class='inline' method='post' action='/people/remove'>{csrf_field(s)}"
                   f"<input type='hidden' name='name' value='{e(name)}'><button class='light'>Remove</button></form>")
        rows += f"<tr><td>{e(name)}</td><td>{'administrator' if u.get('admin') else 'member'}</td><td>{ctl}</td></tr>"
    add = ""
    if me_admin:
        add = (f"<h2 style='margin-top:20px'>Add someone</h2><form method='post' action='/people/add' class='card' style='max-width:420px'>{csrf_field(s)}"
               "<label>Login name<input name='name' required pattern='[a-z0-9._-]{2,32}'></label>"
               "<label>Password (12 characters at least)<input name='password' type='password' required minlength='12'></label>"
               "<label><input type='checkbox' name='admin' style='width:auto'> Administrator</label><br><button>Add</button></form>")
    pw = (f"<h2 style='margin-top:20px'>Change my password</h2><form method='post' action='/people/password' class='card' style='max-width:420px'>{csrf_field(s)}"
          "<label>New password (12 characters at least)<input name='password' type='password' required minlength='12'></label><button>Change</button></form>")
    body = (f"<h2>People</h2>{'<p class=ok>' + e(msg) + '</p>' if msg else ''}<div class='card'><table>"
            f"<tr><th>Login</th><th>Role</th><th></th></tr>{rows}</table></div>{add}{pw}")
    return page("People", body, s["user"])


def login_page(err="") -> bytes:
    body = ("<div class='login card'><h2>Sign in</h2>"
            f"{'<p class=err>' + e(err) + '</p>' if err else ''}"
            "<form method='post' action='/login'><label>Login<input name='name' autocomplete='username' required></label>"
            "<label>Password<input name='password' type='password' autocomplete='current-password' required></label>"
            "<button>Sign in</button></form></div>")
    return page("Sign in", body)


# ------------------------------------------------------------------ HTTP
class Handler(BaseHTTPRequestHandler):
    server_version = "bms-console"

    def log_message(self, fmt, *args):  # quieter journal: no query strings
        pass

    def ip(self) -> str:
        # Behind Cloudflare and Apache: Cloudflare's header names the real
        # visitor; X-Forwarded-For's first entry could be set by anyone.
        return (self.headers.get("CF-Connecting-IP", "").strip()
                or self.headers.get("X-Forwarded-For", "").split(",")[-1].strip()
                or self.client_address[0])

    def session(self):
        for part in self.headers.get("Cookie", "").split(";"):
            k, _, v = part.strip().partition("=")
            if k == COOKIE:
                return get_session(v), v
        return None, None

    def send(self, code: int, body: bytes, ctype="text/html; charset=utf-8", extra=None):
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Frame-Options", "DENY")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("Referrer-Policy", "same-origin")
        for k, v in (extra or []):
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(body)

    def redirect(self, to: str, extra=None):
        self.send(303, b"", extra=[("Location", to)] + (extra or []))

    def form(self) -> dict:
        n = min(int(self.headers.get("Content-Length", "0") or 0), 16384)
        return {k: v[0] for k, v in urllib.parse.parse_qs(self.rfile.read(n).decode()).items()}

    # ---- GET
    def do_GET(self):
        path = urllib.parse.urlparse(self.path)
        s, token = self.session()
        if path.path == "/login":
            return self.send(200, login_page())
        if path.path == "/logout":
            if token:
                with _sessions_lock:
                    _sessions.pop(token, None)
            return self.redirect("/login", [("Set-Cookie", f"{COOKIE}=; Max-Age=0; Path=/; Secure; HttpOnly; SameSite=Strict")])
        if not s:
            return self.redirect("/login")
        if path.path == "/":
            return self.send(200, overview(s))
        if path.path == "/people":
            return self.send(200, people_page(s))
        if path.path.startswith("/jobs/"):
            parts = path.path.split("/")
            job_id = parts[2] if len(parts) > 2 else ""
            if len(parts) == 4 and parts[3] == "text":
                m = job_meta(job_id)
                if not m:
                    return self.send(404, b"{}", "application/json")
                start = int(urllib.parse.parse_qs(path.query).get("from", ["0"])[0] or 0)
                with open(os.path.join(JOBS_DIR, job_id + ".log"), "rb") as f:
                    f.seek(start)
                    chunk = f.read(256 * 1024)
                out = {"text": chunk.decode("utf-8", "replace"), "next": start + len(chunk),
                       "done": m["finished"] is not None, "exit": m["exit"]}
                return self.send(200, json.dumps(out).encode(), "application/json")
            return self.send(200, job_page(s, job_id))
        return self.send(404, page("Not found", "<p>Not found.</p>", s["user"]))

    # ---- POST
    def do_POST(self):
        path = urllib.parse.urlparse(self.path).path
        origin = self.headers.get("Origin", "")
        if origin != ORIGIN:
            return self.send(403, b"Refused: this request did not come from the console itself.", "text/plain")
        f = self.form()
        if path == "/login":
            ip = self.ip()
            if throttled(ip):
                return self.send(429, login_page("Too many attempts. Wait 15 minutes."))
            users = load_users()
            # Logins are lower case; forgive "Praise" and stray spaces from
            # copying. (Generated passwords never contain spaces.)
            name = f.get("name", "").strip().lower()
            u = users.get(name)
            if not u or not check_password(f.get("password", "").strip(), u.get("hash", "")):
                note_failure(ip)
                return self.send(401, login_page("Wrong login or password."))
            token = new_session(name)
            return self.redirect("/", [("Set-Cookie", f"{COOKIE}={token}; Max-Age={SESSION_SECONDS}; Path=/; Secure; HttpOnly; SameSite=Strict")])
        s, _ = self.session()
        if not s:
            return self.redirect("/login")
        if not hmac.compare_digest(f.get("csrf", ""), s["csrf"]):
            return self.send(403, b"Refused: stale form. Reload the page and try again.", "text/plain")
        if path.startswith("/actions/"):
            action = path.split("/")[2]
            if action not in ACTIONS:
                return self.send(404, b"Unknown action.", "text/plain")
            job_id = start_job(action, s["user"])
            if not job_id:
                return self.send(409, page("Busy", "<p>Another action is running. <a href='/'>Back</a></p>", s["user"]))
            return self.redirect(f"/jobs/{job_id}")
        users = load_users()
        is_admin = users.get(s["user"], {}).get("admin", False)
        if path == "/people/password":
            pw = f.get("password", "")
            if len(pw) < 12:
                return self.send(400, people_page(s, ""))
            users[s["user"]]["hash"] = hash_password(pw)
            save_users(users)
            log_action(s["user"], "changed own password", "exit 0 -")
            return self.send(200, people_page(s, "Password changed."))
        if not is_admin:
            return self.send(403, b"Administrators only.", "text/plain")
        if path == "/people/add":
            name, pw = f.get("name", "").strip().lower(), f.get("password", "")
            if not re.fullmatch(r"[a-z0-9._-]{2,32}", name) or len(pw) < 12 or name in users:
                return self.send(400, people_page(s, ""))
            users[name] = {"hash": hash_password(pw), "admin": f.get("admin") == "on"}
            save_users(users)
            log_action(s["user"], f"added {name}", "exit 0 -")
            return self.send(200, people_page(s, f"{name} added."))
        if path == "/people/remove":
            name = f.get("name", "")
            if name == s["user"] or name not in users:
                return self.send(400, people_page(s, ""))
            if users[name].get("admin") and sum(1 for u in users.values() if u.get("admin")) <= 1:
                return self.send(400, people_page(s, "The last administrator cannot be removed."))
            users.pop(name)
            save_users(users)
            log_action(s["user"], f"removed {name}", "exit 0 -")
            return self.send(200, people_page(s, f"{name} removed."))
        return self.send(404, b"Not found.", "text/plain")


def main():
    os.makedirs(JOBS_DIR, exist_ok=True)
    os.chmod(STATE, 0o700)
    httpd = ThreadingHTTPServer(("127.0.0.1", PORT), Handler)
    print(f"BMS console on 127.0.0.1:{PORT} for {ORIGIN}", flush=True)
    httpd.serve_forever()


if __name__ == "__main__":
    main()
