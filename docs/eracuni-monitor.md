# e-Računi sync monitor

`lucina-spiza/eracuni-monitor.php` — nightly check that every paid Woo order
reached e-Računi, with automatic resync of the ones that didn't.

## Why

e-Računi sometimes answers an order webhook with HTTP 200 and then never
imports it: no narudžba kupca, no račun, nothing in its sync log. Woo sees 200
and never retries, so the order is silently lost from the books. The
Sinkroniziraj button in the order's e-Računi box fixes it, without
duplicates — the monitor does the same automatically.

Never issue a račun for a web order by hand: the shop flow is narudžba kupca →
račun, and manual invoices break numbering and analytics.

## How it works

```
          ┌────────────────────────────────────────────┐
          │ WooCommerce: wc_get_orders()               │
          │ processing/completed, total > 0, and       │
          │  • created 30 min – 48 h ago, or           │
          │  • created 48 h – 14 days ago AND last     │
          │    outcome is a problem (re-check)         │
          └─────────────────────┬──────────────────────┘
                                │ Woo order numbers (460,461,…)
                                ▼
          ┌────────────────────────────────────────────┐
          │ e-Računi API: SalesOrderList {number}      │
          │ 100 numbers per call; retries on network   │
          │ error / 429 / non-JSON 5xx: 15 s, 30 s     │
          └─────────────────────┬──────────────────────┘
                                │
                                ▼
                   ┌─────────────────────────┐   yes
                   │ API error?              ├─────────►  UNKNOWN
                   │ HTTP≠200, status≠ok,    │            (all orders of the run)
                   │ timeout, bad response   │
                   └────────────┬────────────┘
                                │ no
                                ▼
                   ┌─────────────────────────┐   yes
                   │ "All missing" guard:    ├─────────►  UNKNOWN
                   │ API ok, but none of ≥ 3 │            (all orders; "provjeriti
                   │ orders found? (*)       │             API podatke"; no repair)
                   └────────────┬────────────┘
                                │ no
                                ▼
                   ┌─────────────────────────┐   no
                   │ Order number returned?  ├─────────►  MISSING ──► repair
                   └────────────┬────────────┘
                                │ yes
                                ▼
                   ┌─────────────────────────┐   yes
                   │ status =                ├─────────►  NEEDS_CORRECTION
                   │ CorrectionPending?      │
                   └────────────┬────────────┘
                                │ no
                                ▼
                   ┌─────────────────────────┐   no
                   │ salesInvoiceRef(s) set? ├─────────►  SYNCED_NO_INVOICE
                   │ (račun issued)          │
                   └────────────┬────────────┘
                                │ yes
                                ▼
                             SYNCED  ✓
```

(*) Orders already known `missing` / `repair_failed` don't count toward the 3.
Most likely trigger: wrong API user or company in wp-config.

Matching works because the Woo order number **is** the e-Računi sales order
number (Woo #267 → `"number": "267"`). One `SalesOrderList` call with a comma
list returns only the numbers that exist. The račun is read from the sales
order's `salesInvoiceRef` / `salesInvoicesRef`; no separate invoice lookup.

### Repair (`LUCINA_ERACUNI_MONITOR_REPAIR = true`)

```
  MISSING
     ├── already 2 attempts ─────────────────────────►  stays MISSING (fix by hand)
     ▼
  order.synchronize via the Eurofaktura plugin (= Sinkroniziraj button)
     ├── timeout / HTTP≠200 ─────────────────────────►  REPAIR_FAILED
     ▼ HTTP 200, wait 5 s, SalesOrderList again
     ├── API error ──────────────────────────────────►  REPAIR_UNVERIFIED
     ├── number still absent ────────────────────────►  REPAIR_FAILED
     └── found ──────────────────────────────────────►  REPAIRED
```

Repair goes through the plugin's own connection
(`Eurofaktura_http_client::instance()->post_request('order.synchronize',
eurofaktura_map_order_for_request($order))`), not the API user — so the API
user can be read-only. Documents are never created directly through the API.
`needs_correction` is never resynced (it would re-import the same data).

## Outcomes

| Outcome | Meaning | What to do |
|---|---|---|
| `synced` | Narudžba kupca + račun exist | Nothing |
| `synced_no_invoice` | Narudžba exists, no račun | Check in e-Računi why the račun wasn't issued |
| `needs_correction` | e-Računi stopped it (`CorrectionPending`), reason in `remarks` | Fix in e-Računi (often VAT rate) |
| `missing` | No narudžba kupca — webhook dropped | Order → e-Računi box → **Sinkroniziraj** |
| `unknown` | API failed or "all missing" guard tripped | Read the reason; next night re-checks |
| `repaired` | Monitor resynced it, it now exists | Nothing (check detail for missing račun) |
| `repair_failed` | Resync errored, or still absent after it | Sinkroniziraj by hand |
| `repair_unverified` | Resync sent, re-check API call failed | Next night confirms |

Each order's last outcome is kept in hidden order meta
`_lucina_eracuni_monitor` (`outcome`, `repairs`, `checked`).

## Email

One plain-text email in Croatian per run, only when there is something to say.
An order is included when its outcome **changed** since the last run:

```
  previous ──► now                    email?
  ─────────────────────────────────────────────
  (none)   ──► synced                 no
  (none)   ──► any problem            yes
  problem  ──► same problem           no   (already reported)
  problem  ──► other problem          yes
  problem  ──► synced                 yes  "Riješeno"
  synced   ──► synced                 no
```

Exception: while the check itself fails (API error or guard), every order is in
the email every night, so an outage never goes quiet.

Sent from the WooCommerce sender address (WordPress' default
`wordpress@<host>` lands in spam). Non-production subjects start with
`[STAGING]`. No email = everything synced (or the monitor isn't running —
check Scheduled Actions if in doubt).

## Schedule

One Action Scheduler single action at the next 04:00 Europe/Zagreb (computed
in that timezone, so DST doesn't shift it). Each run queues the next one, even
if it crashed; `action_scheduler_init` re-creates it if missing and moves it
when the run time is changed in wp-config.

WP-Cron only fires on page visits, so the hosting's single konsoleH cron job
runs production `wp-cron.php` every 15 min (PHP CLI, `flock`, `MAILTO=""`):

```
*/15 * * * * flock -n -E 0 <lock> bash -c '/usr/bin/php85 /usr/www/users/tessry/wp-cron.php'
```

SFTP `/public_html` = server path `/usr/www/users/tessry`. Staging has no cron
(its runs fire on visits only).

## Configuration (wp-config.php only, never the repo)

| Constant | |
|---|---|
| `LUCINA_ERACUNI_API_URL` | `https://e-racuni.com/WebServicesHR/API` (copy from the API console's CURL tab) |
| `LUCINA_ERACUNI_API_USER` / `_SECRET` / `_TOKEN` | API user (Postavke tvrtke → API web servisi), its secret key, organization token |
| `LUCINA_ERACUNI_MONITOR_REPAIR` | `true` = resync missing orders (default off) |
| `LUCINA_ERACUNI_MONITOR_EMAILS` | comma-separated recipients |
| `LUCINA_ERACUNI_MONITOR_AT` | optional run time `HH:MM`, default `04:00` |
| `WP_ENVIRONMENT_TYPE` | `staging` on staging only |

The module does nothing unless all four API constants are defined. Repair also
needs the Eurofaktura plugin connected.

Production: production company, repair on, alerts to Siniša + Luce.
Staging: test API user → the separate staging e-Računi company, repair off,
alerts to Siniša.

## e-Računi API notes

- `POST` JSON `{"username","secretKey","token","method","parameters":{…}}`.
  Success: `{"response":{"status":"ok","result":[…]}}`. Bad credentials: HTTP
  500 with JSON `status: "error"` and a `description` — not retried.
- Rate limit ~1 req/s per IP, burst 4, no concurrent requests per user.
- `SalesOrderList` / `SalesInvoiceList` return max 500 rows.
- Docs: `https://e-racuni.hr/rhr22/ApiDocumentation-Method-<Method>` (needs a
  GUI login).
- Short 502 outages happen — hence the retries.

## Logs and debugging

- WooCommerce → Status → Logs, source `lucina-spiza-eracuni-monitor`: one line
  per order per run, plus retries.
- Tools → Scheduled Actions, hook `lucina_eracuni_monitor_run`: past runs and
  the next pending one; "Run" triggers it by hand.
- Filter `lucina_eracuni_monitor_response` stubs API responses for tests.
