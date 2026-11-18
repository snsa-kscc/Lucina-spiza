# e-Računi sync monitor — TODO

Open items for `lucina-spiza/eracuni-monitor.php`. Background:
`eracuni-sync-monitor-proposal.md`. Created 18.11.2026.

## Before production

- [ ] **Outage longer than the 48 h window loses orders.** If the API is down
  two nights in a row, an order can leave the window while `unknown` and is
  never checked again. Fix: also select orders whose last outcome is a problem
  (`unknown`, `missing`, `repair_failed`, `repair_unverified`,
  `synced_no_invoice`, `needs_correction`) up to ~14 days back, until they
  come back `synced`.
- [ ] **Long outage goes quiet.** Per-order dedup means day 2 of an outage
  emails nothing for already-`unknown` orders. Fix: when the API call fails,
  add one "API još uvijek nedostupan" line to every run's email, outside the
  per-order dedup.
- [ ] **"All missing" guard.** If the API answers `ok` but none of ≥ 3 orders
  are found, it is more likely wrong credentials / wrong company than real
  drops. Report as suspicious and skip repair. Only matters once repair is on.
- [ ] **Timeout vs. visitor-triggered runs.** API timeout is 30 s + sleeps;
  a run started by a page view can hit PHP `max_execution_time` (30–60 s).
  Fine with the konsoleH cron (CLI, no limit). Fix: lower the timeout to 15 s.
- [ ] **Test reset helper.** `_lucina_eracuni_monitor` is hidden from the
  Custom Fields box (leading `_`); resetting an order for a test needs SQL.
  Optional: an admin-only way to clear it (or a WP-CLI command).
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
