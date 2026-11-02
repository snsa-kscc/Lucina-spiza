# e-Računi sync monitor — proposal

Status: **proposal — awaiting client (Luce) approval.** No code written yet.
Helpdesk ticket about the root cause drafted, not yet sent (see below).

## Problem

e-Računi intermittently accepts an order webhook and then never processes it.
WooCommerce sees HTTP 200, treats the delivery as successful and never
retries, so the order silently gets **no customer order and no invoice** in
e-Računi. Nobody notices until someone looks.

### Incidents

| Order | WC ID | Paid | Product | Payment | Webhooks (UTC) | Delivery IDs (created / updated) |
|---|---|---|---|---|---|---|
| #439 | 1815 | 30.10.2026 09:06 | `master-slag` | Google Pay | 08:08:31–32, both 200 | `aee189338a8a8607724cfd136d729284` / `bc65cd7c2a3996465f8c7a1ce096ed09` |
| #442 | 1818 | 01.11.2026 22:36 | `masterclass-slag-buttercream-ganach` | Apple Pay | 21:37:10, both 200 | `6320411191e308dfa4b4722ef5639565` / `b49bb4b85d153c4ae914f5c72ae95de8` |

2 of 5 orders between 29.10 and 01.11 were dropped.

### Evidence that it is e-Računi's side, not our data

- Both webhooks (`order.created` ID 3, `order.updated` ID 4) were delivered
  to `e-racuni.com/H7e/WebShopSynch-B1F52B35DA090E00A422FC0905BF086C` and
  answered `200`, empty body, `x-erapp: H7e` — identical to orders that synced
  (`wc-logs/webhooks-delivery-*.log`).
- e-Računi's **Dnevnik sinkronizacije** (Postavke tvrtke → Internet trgovine –
  sinkronizacija) has **no entry at all** for the dropped deliveries — not
  even an error. 30.10 is absent; 01.11 lists only #440 and #441.
- When e-Računi rejects data it logs *Sinkronizacija s greškama* with a
  reason. It does that on every order for the first `order.updated` (which
  arrives before `order.created`: "Narudžba kupca N ne postoji") — that error
  is normal noise, not this bug.
- Same SKU + same wallet synced fine: #441 (`masterclass-slag-buttercream-ganach`,
  Apple Pay) two hours before #442; `master-slag` synced in #432. All SKUs exist
  in the e-Računi catalog. No Eurofaktura plugin errors, no fatal at order time.

Unrelated but recurring: `checkout-company.php:96` fatal
(`get_billing_company()` on null from the Stripe checkout-sessions AJAX
handler) on 29.10 and 01.11. Not the cause; worth a separate fix.

## How "synced" can be detected

The Eurofaktura plugin stores **no order meta**. Its order meta box asks
e-Računi live: `Eurofaktura_http_client::instance()->post_request('order.refresh', eurofaktura_map_order_for_request($order))`
→ JSON `data.metaData[]`, one entry per document, each with `documentID`.

Observed responses:

| Order | `metaData` |
|---|---|
| synced (#438, #441) | `Narudžba kupca #441 [221:…]`, `Račun #435/01/261 [60:…]`, Otpremnica, OK odobrenje, Zalihe |
| dropped (#439, #442) | only `Narudžba kupca` with `documentID` **empty** |

Rule: **missing = no `metaData` entry with a non-empty `documentID`.**
Secondary signal: order synced but no `60:` (Račun) document → invoice not
issued (flag separately). Prefixes `221` = narudžba kupca, `60` = račun are
observed, not documented — match defensively.

Manual recovery = the meta box **Sinkroniziraj** button, which posts
`order.synchronize` with the same payload.

## Proposed solution

New mu-plugin module `lucina-spiza/eracuni-monitor.php`, loaded from
`lucina-spiza.php`. No new infrastructure.

1. **Schedule** — recurring Action Scheduler action, daily at **04:00
   Europe/Zagreb** (`as_schedule_cron_action`; Action Scheduler evaluates cron
   in UTC, so convert explicitly so DST doesn't shift it to 05:00).
2. **Select** — orders with status `completed`/`processing`, created in the
   last **48 h**, older than **30 min** (webhooks are async; they arrive ~1–2
   min after payment). Typically 0–5 orders/day → 0–5 API calls.
3. **Check** — `order.refresh` per order, apply the rule above. If the API
   call itself fails (timeout/no response — happened 06.10, 12.10, 25.10),
   report as *unknown*, not as missing.
4. **Alert** — one email (`wp_mail`) listing missing orders with admin edit
   links; nothing sent when all is fine. Log every run to WC logs, source
   `lucina-spiza-eracuni-monitor`. Remember alerted order IDs (order meta
   `_lucina_eracuni_alerted`) so an order is reported once.
5. **Wake-up** — WP-Cron only runs on page visits, so something must hit it
   at 04:00. The host is SFTP-only (no shell crontab), but it is Hetzner web
   hosting (`/usr/www/users/tessry`) and **konsoleH cron jobs** are available
   — preferred option: daily 04:00, `php /usr/www/users/tessry/wp-cron.php`
   (or URL `https://lucinaspiza.com/wp-cron.php`). Check whether konsoleH cron
   times are local or UTC.
   The plan has a limited number of cron slots and **one cron job already
   exists** — find out what it runs before touching it. If it already calls
   `wp-cron.php` often enough (≤ hourly), no change is needed. Never replace
   an unrelated job; fallback is an external pinger (cron-job.org /
   Cloudflare Cron Trigger). Extra triggers are harmless — the job runs
   once/day.

Not in scope for v1: **auto-resync** (calling `order.synchronize` from the
job). Enable only after e-Računi confirms a manual sync can't create a
duplicate if the original request is processed late.

### Open decisions for Luce

- Alert recipients: Siniša only, or Siniša + Luce?
- Approve the work (estimate **~3 h**: module ~1.5 h, staging test ~1 h,
  pinger + production promotion ~0.5 h).
- Later: auto-resync yes/no (after helpdesk answer).

### Testing

Staging (labouche) — **check first** whether staging's Eurofaktura plugin is
connected to e-Računi at all. There is only one shop in e-Računi
(`https://lucinaspiza.com`); a connected staging must not push test orders into
the real books. If staging is not connected, test the detection logic with a
filter that stubs the `order.refresh` response (synced / dropped / API error),
then do a dry run on production that only logs (no email) for a few days before
enabling the email.

## Pending actions (not done)

- Helpdesk email drafted in Gmail (sinisa@dvasadva.com) to
  `helpdesk@e-racuni.com` with both incidents and delivery IDs. Earlier ticket
  went to `helpdesk@e-racuni.hr` — consider using that address.
- Email to Luce drafted (infobarbioza@gmail.com) asking how to proceed.
- #439 and #442 still unsynced — press **Sinkroniziraj** after the helpdesk
  answer (or Luce issues invoices manually).
