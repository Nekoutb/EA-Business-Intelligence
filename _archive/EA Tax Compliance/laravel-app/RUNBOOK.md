# EA Business Intelligence — Laravel App Runbook

Modern SaaS BI console built on **Laravel 11 + Livewire 3 + Tailwind v3 + Chart.js**.
Every data page carries a sticky **Elite Advisor** panel on the right that calls
Claude (Anthropic) for actionable commentary on the numbers you're looking at.

---

## 1. Start the dev server

From the project root (`EA Business Intelligence/`):

```powershell
.\php-runtime\php.exe -c .\php-runtime\php.ini -S localhost:8000 -t .\laravel-app\public
```

Then open **http://localhost:8000** — you'll be redirected to the login page.

Default credentials (change in `laravel-app/.env`):
- username: `admin`
- password: `changeme`

### Stop the server

```powershell
Get-Process php | Stop-Process -Force
```

---

## 2. Buy a Claude API key (unlocks the right-rail AI)

1. Visit **https://console.anthropic.com** → sign up.
2. **Billing → Add credits** (minimum $5 prepaid — no subscription).
3. **API Keys → Create Key** → copy the `sk-ant-api03-…` string.
4. In the app: **Settings → Elite Advisor → API Key** → paste → Save.
5. Reload any data page — the right rail will generate 3-5 insights in ~5s.

**Model recommendation:** `claude-sonnet-4-5` — best quality-per-dollar for KPI narration.
Typical usage cost: **$0.01-0.05 per page load** (5-min cache means repeats are free).

---

## 3. Project layout

```
laravel-app/
├── app/
│   ├── Http/Controllers/      # One controller per page (Dashboard, Revenue, …)
│   ├── Http/Middleware/       # RequireAppLogin (session gate)
│   ├── Livewire/              # AiInsights — the right-rail Claude panel
│   ├── Odoo/                  # JSON-2 / JSON-RPC / XML-RPC clients
│   ├── Services/              # RevenueService, ProfitLossService, …
│   └── Support/helpers.php    # config_env(), ea_cached(), ea_resolve_period()
├── config/bi.php              # App-specific config (Odoo, AI, auth, brand)
├── resources/views/
│   ├── layouts/app.blade.php  # Master layout (sidebar + content + AI rail)
│   ├── components/            # <x-kpi/> and <x-period-filter/>
│   ├── livewire/              # Livewire component templates
│   ├── dashboard.blade.php    # Executive dashboard
│   ├── revenue.blade.php, receivables.blade.php, …
│   └── auth/login.blade.php
└── routes/web.php             # All routes, middleware-grouped
```

---

## 4. Common tasks

### Regenerate composer autoload (after new classes)
```powershell
.\php-runtime\php.exe -c .\php-runtime\php.ini .\composer.phar dump-autoload -o
```

### Clear Laravel caches
```powershell
.\php-runtime\php.exe -c .\php-runtime\php.ini .\laravel-app\artisan config:clear
.\php-runtime\php.exe -c .\php-runtime\php.ini .\laravel-app\artisan view:clear
```

### View logs
```powershell
Get-Content .\laravel-app\storage\logs\laravel.log -Tail 50 -Wait
```

### Force-refresh Odoo data (bypass 5-min cache)
Click **Sync now** in any page header, or visit **/sync** via POST.

---

## 5. Production hardening (when you're ready)

- **Install Node 20+** and run `npm install && npm run build` inside `laravel-app/`
  to compile Tailwind instead of using the CDN.
- Set `APP_ENV=production`, `APP_DEBUG=false` in `.env`.
- Swap `hash_equals` auth in `AuthController` for `password_hash()` / `password_verify()`
  with a hashed password stored in `.env`.
- Serve behind Nginx / Apache pointing its `DocumentRoot` at `laravel-app/public/`.
- Consider Laravel's built-in `queue:work` for async AI insight generation.

---

## 6. Troubleshooting

| Symptom | Fix |
|---|---|
| **500 on Dashboard/Revenue** | PHP `max_execution_time` too low. Already set to 180 in `php-runtime/php.ini`. |
| **"HTTP error calling Odoo: SSL connection timeout"** | Network slow — the JSON-2 client uses a 30s connect / 90s total timeout. Retry. |
| **"API key is valid but no user with login X"** | Your `ODOO_USERNAME` doesn't match the owner of the API key. |
| **AI panel stuck on "Claude is analysing…"** | Empty or wrong API key. Check Settings → Elite Advisor → API Key. |
| **Blank pages / CSS missing** | Tailwind CDN not loading — check internet / ad-blockers. |
| **`Class not found` after editing helpers** | Run `composer dump-autoload -o`. |

---

## 7. What's different from the previous plain-PHP app?

| Plain PHP (preserved as fallback in `src/`) | Laravel (`laravel-app/`) |
|---|---|
| Custom router in `public/index.php` | Laravel routing (`routes/web.php`) + middleware |
| Flat `views/*.php` includes | Blade templates with layout inheritance + components |
| Polling the AI endpoint via JS fetch | Reactive Livewire component, `wire:init` for lazy load |
| `Config::get('X')` reads `.env` | `config('bi.…')` + session overrides (live edit) |
| Session cache via `$_SESSION` | Laravel `session()` helper, same semantics |
| Manual Tailwind CDN | Tailwind CDN now, Vite+npm when you're ready |

Your Odoo client classes (`Json2Client`, `JsonRpcClient`, `XmlRpcClient`) were
ported **as-is** with only the namespace changed from `EA\BI\Odoo` to `App\Odoo` —
so all protocol fixes (JSON-2, array-vs-object handling, SSL timeouts) carry over.
