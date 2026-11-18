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
- [x] **Long outage goes quiet.** Done: while the check fails (API error or
      "all missing" guard), every order is in that night's email, outside the
      per-order dedup.
- [x] **"All missing" guard.** Done: if the API answers `ok` but finds none of
      ≥ 3 orders (not counting ones already known `missing` / `repair_failed`),
      the whole run is `unknown` with a "provjeriti API podatke" reason, so
      nothing is reported as missing or resynced. Matters most right after the
      production credentials go in.
- [x] **Remove the staging test run time** `LUCINA_ERACUNI_MONITOR_AT` test line (was `'12:56'`)
      from `/public_html/labouche/wp-config.php` — staging back to 04:00.

Dropped (18.11.2026, keep it simple): API timeout change (konsoleH cron has no
time limit), test reset helper and staging tests of re-check/guard, larger
number-list test, weekly status email.

## Go-live

- [ ] Merge PR #4; promote to production (Actions → Deploy → `production`).
- [ ] Production API user (read-only if roles allow — repair resyncs through
      the Eurofaktura plugin's own connection, not this user). Block prepared,
      **commented out**, in `/public_html/wp-config.php` (18.11.2026): fill in
      URL/USER/SECRET/TOKEN, then delete the `ACTIVATE` and `END` lines.
      `REPAIR = true` from the start (decided 18.11.2026), emails
      `sinisa@dvasadva.com,infobarbioza@gmail.com`, no `WP_ENVIRONMENT_TYPE`.
      Backup of the previous file: `/wp-config.prod.bak-20261118` (account
      root, outside the web root).
- [x] konsoleH cron (single slot): path switched from
      `/usr/www/users/tessry/labouche/wp-cron.php` to
      `/usr/www/users/tessry/wp-cron.php` (every 15 min, `php85`, `flock`).
      Staging now has no cron — its runs fire only on page visits.
- [ ] Next morning: check Scheduled Actions, WC log, and that the alert lands in
      Luce's inbox, not spam.

## Related, outside the module

- [ ] DKIM + DMARC for `lucinaspiza.com` (alerts and customer mail go to spam)
      — GitHub issue #5.
- [ ] Staging #268/#269 `CorrectionPending` — staging tax/e-Računi config
      difference, see `staging-config-diff-handover.md`.
