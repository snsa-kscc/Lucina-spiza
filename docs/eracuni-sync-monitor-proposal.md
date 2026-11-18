# e-Računi sync monitor — proposal

Status: **approved by Luce** (02.11.2026, confirmed 10.11.2026). No code written yet.
Helpdesk ticket 259410 sent 02.11; e-Računi's answer (03.11) was wrong and our
correction is unanswered — see [History](#history).

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
`order.synchronize` with the same payload. Used on #439 and #442 on 02.11.2026
(3 and 1 days late): both got narudžba kupca + račun, **no duplicates** — the
original webhooks were never processed late.

## Agreed solution

Agreed with Luce by email (10.11.2026): once a day a job goes through recent
orders, asks e-Računi what state each one is in, **repairs** what the plugin
failed to sync, and emails what it fixed or could not fix. Silent when all is
fine.

New mu-plugin module `lucina-spiza/eracuni-monitor.php`, loaded from
`lucina-spiza.php`.

1. **Schedule** — recurring Action Scheduler action, daily at **04:00
   Europe/Zagreb** (`as_schedule_cron_action`; Action Scheduler evaluates cron
   in UTC, so convert explicitly so DST doesn't shift it to 05:00).
2. **Select** — orders with status `completed`/`processing`, created in the
   last **48 h**, older than **30 min** (webhooks are async; they arrive ~1–2
   min after payment). 48 h instead of "yesterday" so a failed run is covered
   by the next one. Typically 0–5 orders/day → 0–5 API calls.
3. **Check** — `order.refresh` per order, apply the rule above. If the API
   call itself fails (timeout/no response — happened 06.10, 12.10, 25.10),
   report as *unknown* and retry next run; never resync on an unknown.
4. **Repair** — for orders confirmed missing: `order.synchronize` (same call
   as the Sinkroniziraj button), then `order.refresh` again to verify a
   `documentID` now exists. One attempt per order per run; after **2 failed
   runs** stop retrying and only alert. Justification for enabling this in v1:
   the helpdesk will not answer the duplicate question, and the manual syncs of
   #439/#442 produced no duplicates.
5. **Alert** — one email (`wp_mail`) per run, only when something happened:
   *fixed* (order, document numbers created), *could not fix* (error from
   e-Računi), *unknown* (API unreachable), and *synced but no račun* (secondary
   signal). Admin edit links per order. Log every run to WC logs, source
   `lucina-spiza-eracuni-monitor`. Order meta `_lucina_eracuni_monitor`
   (attempts, last result) so an order is reported once per outcome.
   Recipients: **infobarbioza@gmail.com** (Luce) + **sinisa@dvasadva.com**.
6. **Wake-up** — WP-Cron only runs on page visits, so a konsoleH cron job
   triggers it (see below). Extra triggers are harmless — Action Scheduler
   runs the action once per day.

Kill switch: constant `LUCINA_ERACUNI_MONITOR_REPAIR` (default `true`);
`false` = detect and alert only.

### konsoleH cron (checked 17.11.2026)

konsoleH access enabled by Luce; Cron job Manager is at
`https://konsoleh.your-server.de/webhostings/A0388192326/cronjobs` (server
`9eos.your-vhost.de`).

- **0 of 1 slots used** — the plan allows exactly **one** cron job, and none
  exists. (Earlier note that "one job already exists" was wrong.)
- Jobs take an **absolute script path**, not a URL. The interpreter can be
  chosen, or the script must be executable (≥ 705).
- Runtime: interval preset, crontab freetext, or manual. Intervals under 2 h
  must be **exclusive execution** (checkbox, on by default). Minimum interval is
  1 min.
- Output goes by mail to `webmaster@9eos.your-vhost.de` unless the
  *Mail notification to* field / `MAILTO` line in Advanced View says
  otherwise. Set `MAILTO=""` — our own alert email covers failures.
- Server timezone is not shown. **It doesn't matter:** the cron only wakes
  WP-Cron; the 04:00 Zagreb time is set in Action Scheduler.
- Server paths: SFTP `/public_html` = `/usr/www/users/tessry` (seen in
  WC fatal logs). Production: `/usr/www/users/tessry/wp-cron.php`, staging:
  `/usr/www/users/tessry/labouche/wp-cron.php`.

Plan for the single slot: **PHP interpreter →
`/usr/www/users/tessry/wp-cron.php`, every 15 min, exclusive.** This also
makes all other WP-Cron / Action Scheduler work (Woo emails, Stripe, scheduled
sales) run on time at night instead of waiting for a visitor. Production only:
staging stays on visit-triggered WP-Cron, and the monitor is tested there by
running the action manually (Tools → Scheduled Actions → Run).

Not needed: `DISABLE_WP_CRON` (visits may keep triggering it; harmless).

### Budget

Quoted to Luce 10.11: **3 h total for the monitor and the Black Friday
campaign** (23.–29.11, set + revert prices). Monitor share ≈ 2 h: module ~1.25
h, staging test ~0.5 h, konsoleH cron + production promotion ~0.25 h.

### Testing

Staging (labouche) — **check first** whether staging's Eurofaktura plugin is
connected to e-Računi at all. There is only one shop in e-Računi
(`https://lucinaspiza.com`); a connected staging must not push test orders into
the real books. Test the detection and repair logic with a filter that stubs
the `order.refresh` / `order.synchronize` responses (synced / dropped /
repaired / repair fails / API error). On production, run a few days with
`LUCINA_ERACUNI_MONITOR_REPAIR = false` (alerts only), then enable repair.

## Implementation plan (17.11.2026)

### Eurofaktura plugin internals (read from production, v1.1.9)

- `includes/class-eurofaktura-http-client.php` —
  `Eurofaktura_http_client::instance()->post_request($action, $contents, $timeout = 8)`.
  Guzzle, action in header `X-Eurofaktura-Webhook-Action`. Returns the
  response, the **error response** on 4xx/5xx, or **`null`** on
  connect/timeout (logged via `eurofaktura_add_log_error`). Not `wp_remote_*`,
  so our `http_request_args` filter in `eracuni.php` doesn't touch it.
- `includes/eurofaktura-order-functions.php:24` —
  `eurofaktura_map_order_for_request($order)` builds the payload.
- `includes/admin/order-edit/class-eurofaktura-admin-order-meta-box.php`
  - `:174` `order.refresh` → 200 + `data.metaData[]` (`documentID`, `title`,
    `actionsLine1Message`, `actionsLine1[]`); else `message`.
  - `:293` `order.synchronize` → 200 with `status` (`orderSynchronizeWarning`
    = warning, otherwise success) and `message`.
- Plugin is connected when `get_option('eurofaktura_connected')`.

### e-Računi API (docs read 18.11.2026; no live call yet)

Docs: `https://e-racuni.hr/rhr22/ApiDocumentation-Method-<Method>` and
`…/ApiDocumentation-Datatypes-<Type>` (needs a GUI login; a logged-out browser
shows the demo company "TEST AGENCIJA").

- **Transport:** HTTPS POST, JSON body
  `{"username","secretKey","token","method","parameters":{…}}`. The test
  console also shows a CLI form
  `curl https://e-racuni.com/WebServicesHR/API-CLI/<Method> -u USER:SECRET_TOKEN`.
  The endpoint host/path **can differ per organization** — copy it from the
  logged-in console (CURL tab), don't assume `eurofaktura.com/WebServices/API`.
- **Auth:** API user (created in Postavke tvrtke → API web servisi) +
  secretKey + organization token. API user has exactly the GUI user's
  privileges → create a **read-only** user for the monitor if roles allow.
- **Rate limit:** ~1 req/s per IP, burst of 4, **no concurrent requests per
  API user**. A daily run with 2 list calls is far below this.
- **`SalesOrderList`** (narudžbe kupca): filters `dateFrom/dateTo`,
  `orderReceivalDateFrom/To`, `issuedTimestampFrom/To`, `orderReference`,
  `number`, `status`, `methodOfPayment`, `remarks`, `buyer`… Max **500** rows.
  Returns `SalesOrder[]`: `documentID`, `number`, `status`, `date`,
  `orderReference`, `remarks`, `buyerEMail`, `documentAmount`,
  `salesInvoiceRef` / `salesInvoicesRef` (račun(i) issued from it),
  `issuedTimestamp`, `Items`, `PaymentRecords`.
- **`SalesInvoiceList`** (računi): filters `dateFrom/dateTo` (date types can't
  be mixed), `issuedTimestampFrom/To`, `salesOrderNumber`, `orderReference`,
  `number`, `status`… Max **500**. Returns `SalesInvoice[]` incl.
  `salesOrderRef` / `salesOrdersRef`, `orderReference`, `number`.
#### Live test (18.11.2026, test API user → test company)

The test API user (`BARBI`) is in company **[205.490027] buoyfriend d.o.o. 1**,
which is the company the **staging** shop syncs into (it holds staging orders
220–269). It is not the production books → safe for read calls and for
staging tests of the monitor with real data. Endpoint that worked:
`POST https://e-racuni.com/WebServicesHR/API` (JSON; server tag `H6i`).

- **Woo order number = `SalesOrder.number`** (e.g. Woo #267 → `"number":"267"`).
  `orderReference` is empty for web-shop imports; `reference` is `"99"`.
  Manually created orders use the `2026-00001` format, so they never collide.
- **Exact lookup:** `SalesOrderList` with `{"number":"267,268,9999"}` returns
  only the ones that exist — absent number ⇒ no narudžba kupca ⇒ `missing`.
  One call per run for all orders in the window; no date filters needed.
- **Invoice link:** `salesInvoiceRef` / `salesInvoicesRef` = račun number
  (e.g. `44/01/261`), `null` if no račun.
- **New failure mode seen:** imports with warnings get `status:
  "CorrectionPending"`, no račun, and the reason in `remarks` (VAT rate not
  allowed for the sale type, imported total ≠ shop total). Staging #268/#269
  are exactly this (see `staging-config-diff-handover.md`). The monitor should
  report these as `needs_correction` with the `remarks` text — **not** resync
  (resync re-imports the same data).
- **Errors:** wrong secret → HTTP **500** with
  `{"response":{"status":"error","description":"Uneseno korisničko ime ili zaporka nisu valjani."}}`.
  Success → `{"response":{"status":"ok","result":[…]}}`. Anything other than
  `status == "ok"` ⇒ `unknown`.
- Check rule (replaces the `order.refresh` rule for the monitor):

  | e-Računi state | Class | Action |
  |---|---|---|
  | number absent | `missing` | resync (when repair on) |
  | `CorrectionPending` | `needs_correction` | alert with `remarks` |
  | present, `salesInvoiceRef` empty, other status | `synced_no_invoice` | alert |
  | present, `salesInvoiceRef` set | `synced` | silent |
  | API error / timeout | `unknown` | retry next run |

Intended use (unchanged):

- **Check** via the e-Računi API instead of `order.refresh`: one list call per
  run (sales orders + invoices for the window), matched to Woo order numbers.
  Independent of the plugin/webhook path that is failing; gives real račun
  numbers instead of the observed `60:` prefix; makes a backfill cheap.
- **Repair stays on the plugin's `order.synchronize`** (the Sinkroniziraj
  path, proven on #439/#442). Do **not** create documents directly through the
  API — that bypasses the shop flow (narudžba kupca → račun) and breaks
  numbering/analytics.
- To find out: endpoint (same for test/prod?), auth format, method
  names to list sales orders and invoices by date, which field carries the
  Woo order number, rate limits. Validate response shape against #441 (synced
  normally) and #439 (synced manually 02.11).
- **Credentials exist for both a test and a production API user** (Siniša,
  17.11). Find out whether the test user points at a separate test
  company/sandbox — it must not be able to read or write the real books.
- Where they live — PHP constants, the WP equivalent of `.env`:
  - production `/public_html/wp-config.php` → production user/token;
  - staging `/public_html/labouche/wp-config.php` → **test** user/token;
  - optionally both in a secrets file outside the web root (SFTP
    `/lucina-secrets.php`), `require`d from wp-config — verify the path
    resolves on Hetzner first.
  - Constants: `LUCINA_ERACUNI_API_USER`, `LUCINA_ERACUNI_API_TOKEN` (+ endpoint
    if test and prod differ), `LUCINA_ERACUNI_MONITOR_REPAIR` (default
    `false`). Module no-ops if not defined. Not `.htaccess SetEnv`: the
    konsoleH cron runs PHP CLI, which never reads `.htaccess`.
  - **Never** in the repo, CI or chat. The deploy workflow only uploads the
    mu-plugin, so wp-config is edited by hand over SFTP; production wp-config
    = production change → Siniša's approval (or he uploads it).
- Environment guard: use the presence of the credentials (+
  `wp_get_environment_type()`) rather than a hardcoded host check.
- **Repair caveat:** `order.synchronize` goes through the *plugin's*
  connection, not the API user. On staging, check what Eurofaktura is
  connected to before allowing repair there; if it is the real shop, keep
  `REPAIR = false` on staging and test repair with stubs only.

### Module `lucina-spiza/eracuni-monitor.php`

Register it in the module list in `lucina-spiza.php`.

- **Guards:** no-op unless the `LUCINA_ERACUNI_API_*` constants are defined
  (staging has the test user → test company, so it can't reach the real
  books). Repair additionally needs `LUCINA_ERACUNI_MONITOR_REPAIR === true`
  and a connected Eurofaktura plugin.
- **Recipients:** `LUCINA_ERACUNI_MONITOR_EMAILS` (comma-separated) in each
  site's wp-config — staging: Siniša; production: Siniša + Luce. Not set ⇒
  no email, warning in the log.
- **Environment:** `WP_ENVIRONMENT_TYPE` (`wp_get_environment_type()`,
  default `production`). Staging defines `staging`; production leaves it
  unset. Non-production alert subjects start with `[STAGING]`.
- **Schedule:** single Action Scheduler action at the next 04:00
  Europe/Zagreb (compute with `DateTimeZone('Europe/Zagreb')`); each run
  schedules the next; on init re-create if none pending. Avoids UTC/DST issues
  of cron expressions.
- **Select:** `processing`/`completed`, created 48 h … 30 min ago.
  Skip orders with total 0 (proposed — confirm).
- **Classify:** one `SalesOrderList` by `number` (see table above) →
  `synced`, `synced_no_invoice`, `needs_correction`, `missing`, `unknown`.
  API failure / non-200 / `status != ok` → `unknown`, never `missing`.
- **Repair** (`missing` only, when `LUCINA_ERACUNI_MONITOR_REPAIR` is true):
  `order.synchronize` with a longer timeout (~30 s), then re-check. Order meta
  `_lucina_eracuni_monitor` = attempts + last outcome; give up after 2 runs.
- **Alert:** plain-text `wp_mail` in Croatian to `LUCINA_ERACUNI_MONITOR_EMAILS`,
  only on a new outcome per order; admin edit links.
- **Log:** `wc_get_logger()`, source `lucina-spiza-eracuni-monitor`.
- **Test hook:** filter `lucina_eracuni_monitor_response` to stub API responses
  (synced / dropped / repair OK / repair fails / API down).

### Rollout

1. Branch + PR; CI runs `php -l` (PHP 8.2).
2. Merge → staging deploy. Test with stubs; run the action manually from
   Tools → Scheduled Actions; check classification, email, dedup, log.
3. Production: Actions → Deploy → `production`, `REPAIR = false` (alerts
   only). konsoleH cron: interpreter PHP (8.2), script
   `/usr/www/users/tessry/wp-cron.php`, freetext `*/15 * * * *`, exclusive on,
   Advanced View `MAILTO=""`. Next day confirm the 04:00 run in Scheduled
   Actions + WC log.
4. After 3–4 clean days enable repair (one-line commit, `[ci prod]` or
   workflow dispatch).
5. Optional one-off backfill, report-only, orders since 01.10.2026.

Black Friday price change (23.–29.11: 55→39,99, 99→69,99, 149→99,99) is part
of the same 3 h budget but needs no code — tracked separately.

### Decisions (Siniša, 18.11.2026)

1. Alerts-only first, then repair — **yes**.
2. Skip €0 orders — **yes**.
3. Alert email in Croatian — **yes**.
4. Backfill to 01.10 — **no** (not selected).

## Handover (17.11.2026)

Next session runs with Code Mode **off** (`CLAUDE_CODE_EXPERIMENTAL_CODE_MODE=0
claude --chrome`) so it can use the browser for the e-Računi API docs and
konsoleH.

Start here:

1. Read this file and `AGENTS.md`.
2. With Siniša, open the e-Računi API documentation and fill in the
   *e-Računi API* section above (endpoint, auth, list methods, order-number
   field). Make a read-only test call for #441 and #439.
3. Get answers to *Decisions pending*.
4. Implement the module on a branch, open a PR, then follow *Rollout*.

State at handover:

- Doc commits on `main`: `bc7c442` (approval, konsoleH findings) + this one.
  No module code yet; no branch.
- konsoleH: logged in by Siniša in his browser; cron slot empty (0 of 1).
- Eurofaktura plugin copy for reading (not part of the repo):
  `/tmp/claude-1000/-home-snsa-Work-Lucina-spiza/79202c6f-5c87-462b-a391-69db6a0057db/scratchpad/eurofaktura/`
  — may be gone after reboot; re-download with
  `sftp luce` → `get -r /public_html/wp-content/plugins/eurofaktura`.
- Remote SSHFS mount `remote/` was not mounted this session; SFTP via the
  `luce` alias works.
- Email context: Gmail threads "e-racuni ne sinkronizira neke narudzbe",
  "[e-racuni.hr ticket 259410]", "black friday", "Your Hetzner Verification
  Code" (sinisa@dvasadva.com).

## History

- **02.11.2026** — Siniša told Luce about #439/#442. Luce: send the helpdesk
  email with her in CC (written record in case Porezna asks why some invoices
  aren't issued automatically), and go ahead with monitoring.
- **02.11** — accounting told **not to issue invoices for web orders by hand**:
  the shop creates narudžba kupca first and the račun from it; manual invoices
  break the sequence and analytics.
- **02.11** — ticket sent to `helpdesk@e-racuni.hr` (ticket **259410**), Luce
  in CC. #439 and #442 synced manually via Sinkroniziraj at end of day.
- **03.11** — helpdesk (Nejra) replied that both orders had synced fine, based
  on log entries that came from the manual sync. Siniša corrected this the same
  day with timestamps and repeated the three questions. **No answer since**;
  not expected.
- **10.11** — approach above proposed to Luce and accepted; alerts to her
  address.
- **12.11** — Hetzner admin login now requires an email OTP sent to Luce.
- **17.11** — konsoleH access enabled; Cron job Manager checked (above).

## Still open

- ~~`checkout-company.php` Stripe metadata fatal~~ — fixed in PR #3, on
  production since 17.11.2026 (see `b2b-vat-exemption.md`). Check the
  18.11 production fatal log stays clean.
- Staging test orders #268, #269 (Google Pay, billing US) in the **staging**
  e-Računi company (`H6d/WebShopSynch-16FC074D…`, separate from production
  `H7e/…`): webhooks 200; #269 imported with *"Primljena stopa PDV-a 0% nije
  dopuštena … postavljena uobičajena stopa"*, #268 got no invoice. Production
  wallet orders are fine — this is a **staging configuration difference**
  (Woo and/or e-Računi staging set up differently from production; not code).
  Find and align the difference before using staging to test the monitor.

## Progress (18.11.2026)

- Branch `feat/eracuni-sync-monitor`: module `lucina-spiza/eracuni-monitor.php`
  written and registered; not deployed, no PR yet.
- Staging `/public_html/labouche/wp-config.php`: `LUCINA_ERACUNI_API_URL`,
  `_USER`, `_SECRET`, `_TOKEN` (test user) and `LUCINA_ERACUNI_MONITOR_REPAIR
  = false`, `LUCINA_ERACUNI_MONITOR_EMAILS = sinisa@dvasadva.com` and
  `WP_ENVIRONMENT_TYPE = staging` added in the custom-values block; uploaded over SFTP, verified by
  sha256, site 200. (Upload also normalized the file from CRLF to LF.)
- Local copy of the test credentials: `.env.local` (git-ignored).
- Production wp-config untouched. For go-live it needs the production API
  user's four `LUCINA_ERACUNI_API_*` constants, `LUCINA_ERACUNI_MONITOR_EMAILS =
  'sinisa@dvasadva.com,infobarbioza@gmail.com'`, `REPAIR = false`, and **no**
  `WP_ENVIRONMENT_TYPE` (needs Siniša's approval).
