# EA Compliance

A PHP-powered SaaS compliance platform forked from EA Business Intelligence.
Use this as a separate development sandbox for compliance, audit, regulatory,
and governance services without interfering with the BI production app.

Forked from EA Business Intelligence. Ready to extend with compliance modules.
- Total revenue, current year (YTD) vs prior year (same window)
- **Revenue per day** = total revenue / number of days
- **Revenue per outfit** = total revenue / number of outfits (document lines)
- Daily trend chart, CY vs PY

## Requirements
- PHP 8.0+ with `curl` and `dom` extensions (both ship with standard PHP)
- Any web server, or just PHP's built-in dev server

No Composer dependencies are required — a tiny PSR-4 autoloader is built in.

## Quick start

1. **Configure credentials**

   ```bash
   copy .env.example .env      # Windows
   # or: cp .env.example .env  # macOS/Linux
   ```

   Edit `.env` and fill in:

   ```
   ODOO_URL=https://mycompany.odoo.com
   ODOO_DB=mycompany
   ODOO_USERNAME=you@example.com
   ODOO_API_KEY=xxxxxxxxxxxxxxxxxxxxxxxx
   ODOO_PROTOCOL=jsonrpc          # or xmlrpc
   REVENUE_SOURCE=invoice         # invoice | sale | pos

   APP_USER=admin
   APP_PASSWORD=changeme          # <-- change this
   APP_SECRET=some-long-random-string
   ```

   > Generate an API key in Odoo: **Preferences → Account Security → New API Key**.

2. **Run the dev server**

   ```bash
   php -S localhost:8080 -t public
   ```

3. **Open** http://localhost:8080 and sign in with `APP_USER` / `APP_PASSWORD`.

   You can also manage the Odoo connection from the **Settings** page (`/settings`)
   and hit **Test connection** before saving.

## Project structure

```
public/index.php            # front controller / router
src/
  bootstrap.php             # autoloader, session, helpers
  Config.php                # .env loader
  Odoo/
    OdooClient.php          # interface
    JsonRpcClient.php       # JSON-RPC implementation
    XmlRpcClient.php        # XML-RPC implementation (pure PHP, no extension)
    OdooFactory.php         # builds the right client from config
    OdooException.php
  Services/
    RevenueService.php      # CY vs PY, per-day, per-outfit, daily series
  Controllers/
    AuthController.php
    DashboardController.php
    SettingsController.php
views/                      # layout + pages (Tailwind via CDN, Chart.js via CDN)
.env.example
```

## How "revenue" is defined

The `REVENUE_SOURCE` setting chooses which Odoo model is queried. All model and
field names below are verified against **Odoo 18** (current stable) and are
backward-compatible with Odoo 13+.

| Source    | Model            | Date field      | Amount field            | Line model (outfits)                   |
|-----------|------------------|-----------------|-------------------------|----------------------------------------|
| `invoice` | `account.move`   | `invoice_date`  | `amount_total_signed`   | `account.move.line` (product lines only) |
| `sale`    | `sale.order`     | `date_order`    | `amount_total`          | `sale.order.line` (product lines only)   |
| `pos`     | `pos.order`      | `date_order`    | `amount_total`          | `pos.order.line`                         |

Domain filters applied:

- **Invoices**: `move_type in ('out_invoice','out_refund')` AND `state = 'posted'`.
  Refunds are signed negative via `amount_total_signed`, so the total is net
  revenue automatically.
- **Sales**: `state in ('sale','done')`. The `done` value was removed in Odoo 17
  (replaced by the `locked` boolean) — keeping it in the filter is harmless on
  newer versions and still matches on older ones.
- **POS**: `state in ('paid','invoiced','done')`.

**Outfit counting** excludes section/note/tax/cogs/rounding/payment-term lines.
This is important: in **Odoo 16+**, `account.move.line.display_type` became
`'product'` for real lines (it was `False` in ≤v15), so naive filtering on
`display_type = False` would count zero. The service uses a `not in` filter
covering all non-product display types to work correctly from Odoo 13 through 18.

- **Revenue per day** = total / (days in the YTD window, same length PY)
- **Revenue per outfit** = total / number of product lines in the window

## Odoo version compatibility

| Odoo version | Status       | Recommended protocol |
|--------------|--------------|----------------------|
| **19** (current) | ✅ Fully supported | `json2` (new JSON-2 API) |
| 18           | ✅ Verified  | `jsonrpc`            |
| 17           | ✅ Verified  | `jsonrpc`            |
| 16           | ✅ Verified  | `jsonrpc`            |
| 15 and earlier | ✅ Compatible | `xmlrpc` or `jsonrpc` |

### Odoo 19 notes

Odoo 19 shipped a new external API called **JSON-2** at `POST /json/2/<model>/<method>`,
with cleaner semantics:

- **Bearer-token auth** — the API key is carried in `Authorization: bearer <key>`
  on every request; there is no `authenticate()` round-trip returning a uid.
- **Database header** — the DB is sent as `X-Odoo-Database: <db>` instead of as a
  request body field.
- **Meaningful HTTP status codes** — 401 for invalid key, 403 for access denied,
  404 for missing model, 500 for server errors (XML-RPC / JSON-RPC always returned 200).
- **Named arguments only** — no positional args; every parameter is a named JSON body field.

The legacy **XML-RPC** and **JSON-RPC** endpoints are marked deprecated in Odoo 19 but
continue to work. Per Odoo's migration guide they will be **removed in Odoo 22 (fall 2028)**.

Set `ODOO_PROTOCOL=json2` in your `.env` (or pick "JSON-2" in `/settings`) to use
the v19-native transport. All three transports (`json2`, `jsonrpc`, `xmlrpc`) share
the same high-level helpers (`searchRead`, `searchCount`, `read`), so BI features
and the data-source configuration behave identically regardless of protocol.

> **API key lifetime (Odoo 19):** keys now have a maximum lifetime of 3 months and
> must be rotated. Create a dedicated bot user with the minimum access rights to
> avoid interactive-key rotation pain.

### Model/field compatibility across v13 → v19

All revenue queries use fields that are stable across v13-v19:
`move_type`, `state`, `invoice_date`, `amount_total_signed` on `account.move`;
`date_order`, `amount_total` on `sale.order` / `pos.order`; `display_type` on
lines. The one non-obvious quirk — `account.move.line.display_type` changed from
`False` (≤v15) to `'product'` (v16+) for real product lines — is handled by a
`not in` filter that works on every supported version.

## API

`GET /api/revenue` returns the full analysis as JSON — useful if you want to embed
the KPIs in another app:

```json
{
  "source": "invoice",
  "currency": "USD",
  "period":   { "from": "2026-01-01", "to": "2026-04-22", "days": 112 },
  "current":  { "total": 0, "outfits": 0, "per_day": 0, "per_outfit": 0, "series": {} },
  "previous": { "total": 0, "outfits": 0, "per_day": 0, "per_outfit": 0, "series": {} },
  "delta":    { "total_pct": null, "per_day_pct": null, "per_outfit_pct": null }
}
```

## Security notes

- `.env` is git-ignored — never commit credentials.
- Use an Odoo **API key** rather than a password, and create a **read-only** user
  whose access rights are limited to the models you need (`account.move`,
  `account.move.line`, `sale.order`, `sale.order.line`, `res.company`,
  `res.currency`, `res.users`).
- Change `APP_USER` / `APP_PASSWORD` before exposing this app.
- For production, serve behind HTTPS and a real web server (nginx / Apache).

## Roadmap (next BI modules to add)

- Margin & profitability (revenue − COGS)
- Top products / categories / customers
- Inventory turnover & stock-outs
- Cash-flow and AR aging
