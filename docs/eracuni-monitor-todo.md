# e-Računi sync monitor — TODO

Open items for `lucina-spiza/eracuni-monitor.php`. Background:
`eracuni-sync-monitor-proposal.md`. Created 18.11.2026.

## Before production

- [x] **Outage longer than the 48 h window loses orders.** Done: orders up to
  14 days old whose last outcome is a problem (`unknown`, `missing`,
  `repair_failed`, `repair_unverified`, `synced_no_invoice`,
  `needs_correction`) are re-checked every night until they come back
  `synced`. Also covers an order synced by hand after its last check (stale
  `missing`) and orders placed in the 30 min before the run (only one check).
  Not yet tested on staging.
- [ ] **Long outage goes quiet.** Per-order dedup means day 2 of an outage
  emails nothing for already-`unknown` orders. Fix: when the API call fails,
  add one "API još uvijek nedostupan" line to every run's email, outside the
  per-order dedup.
- [x] **"All missing" guard.** Done: if the API answers `ok` but finds none of
  ≥ 3 orders (not counting ones already known `missing` / `repair_failed`),
  the whole run is `unknown` with a "provjeriti API podatke" reason, so
  nothing is reported as missing or resynced. Matters most right after the
  production credentials go in. Not yet tested on staging.
- [ ] **Timeout vs. visitor-triggered runs.** API timeout is 30 s + sleeps;
  a run started by a page view can hit PHP `max_execution_time` (30–60 s).
  Fine with the konsoleH cron (CLI, no limit). Fix: lower the timeout to 15 s.
- [ ] **Test reset helper.** `_lucina_eracuni_monitor` is hidden from the
  Custom Fields box (leading `_`); resetting an order for a test needs SQL.
  Optional: an admin-only way to clear it (or a WP-CLI command).
- [ ] **Test re-check and guard on staging.** Re-check: set an older
  order's `_lucina_eracuni_monitor` outcome to `missing` (SQL) and confirm it
  is picked up. Guard: stub `SalesOrderList` to return `[]` for ≥ 3 orders.
- [ ] **Larger number list.** `SalesOrderList` with comma-separated numbers was
  only tried with 3. Before go-live, try 20–50 real numbers (read-only).
- [ ] **Weekly status email (proposal, not decided).** Monday: counts for the
  last 7 days + still-open problems; doubles as a heartbeat so a dead monitor
  is noticed. Check the weekday in Europe/Zagreb. Relies on the 14-day
  re-check so fixed orders don't show as open.
- [ ] **Remove the staging test run time** `LUCINA_ERACUNI_MONITOR_AT = '01:55'`
  from `/public_html/labouche/wp-config.php` when testing is done.

## Go-live

- [ ] Merge PR #4; promote to production (Actions → Deploy → `production`).
- [ ] Production API user (read-only if roles allow); its four
  `LUCINA_ERACUNI_API_*` constants, `LUCINA_ERACUNI_MONITOR_EMAILS =
  'sinisa@dvasadva.com,infobarbioza@gmail.com'`, `REPAIR = false`, no
  `WP_ENVIRONMENT_TYPE`, in `/public_html/wp-config.php` — **needs approval**.
- [ ] konsoleH cron (single slot): edit the job's path from
  `/usr/www/users/tessry/labouche/wp-cron.php` to
  `/usr/www/users/tessry/wp-cron.php`.
- [ ] Next morning: check Scheduled Actions, WC log, and that the alert lands in
  Luce's inbox, not spam.
- [ ] After 3–4 clean days: `LUCINA_ERACUNI_MONITOR_REPAIR = true`.

## Related, outside the module

- [ ] DKIM + DMARC for `lucinaspiza.com` (alerts and customer mail go to spam)
  — GitHub issue #5.
- [ ] Staging #268/#269 `CorrectionPending` — staging tax/e-Računi config
  difference, see `staging-config-diff-handover.md`.
- [ ] CI lints only on push to `main` / manual deploy; add a `pull_request`
  lint trigger.
- [ ] Rotate the test API key (it appeared in a chat screenshot) before or at
  go-live.
