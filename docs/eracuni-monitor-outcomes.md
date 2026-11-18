# e-Računi monitor — outcomes

How `lucina-spiza/eracuni-monitor.php` classifies each order on the nightly run.
See also `eracuni-monitor-todo.md`.

## Check

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
          │ (narudžbe kupca, 100 numbers per call)     │
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
                   │ Order number returned?  ├─────────►  MISSING ──► repair (below)
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

(*) Orders already known `missing` / `repair_failed` don't count toward the 3 —
not finding them again is expected. Most likely trigger: wrong API user or
company (e.g. test company) in wp-config.

## Repair (only with `LUCINA_ERACUNI_MONITOR_REPAIR = true`)

```
  MISSING
     │
     ├── repair off, or already 2 attempts ──────────►  stays MISSING (fix by hand)
     │
     ▼ repair on, < 2 attempts
  order.synchronize  (same as the "Sinkroniziraj" button)
     │
     ├── timeout / HTTP≠200 ─────────────────────────►  REPAIR_FAILED
     │
     ▼ HTTP 200, wait 5 s, SalesOrderList again
     │
     ├── API error ──────────────────────────────────►  REPAIR_UNVERIFIED
     ├── number still absent ────────────────────────►  REPAIR_FAILED
     └── found ──────────────────────────────────────►  REPAIRED
                                                        (detail notes if no račun
                                                         or CorrectionPending)
```

## Outcomes

| Outcome | Meaning | What to do |
|---|---|---|
| `synced` | Narudžba kupca + račun exist | Nothing |
| `synced_no_invoice` | Narudžba exists, no račun issued | Check in e-Računi why the račun wasn't issued |
| `needs_correction` | e-Računi stopped it (`CorrectionPending`), reason in `remarks` | Fix in e-Računi (often VAT rate) |
| `missing` | No narudžba kupca — webhook dropped | Order → e-Računi box → **Sinkroniziraj**. Do not issue račun by hand |
| `unknown` | API call failed, or "all missing" guard tripped | Read the reason; next night re-checks. Check credentials if it repeats |
| `repaired` | Monitor resynced it and it now exists | Nothing (check detail for missing račun) |
| `repair_failed` | Resync errored, or order still absent after it | Sinkroniziraj by hand |
| `repair_unverified` | Resync sent, but the re-check API call failed | Next night confirms |

The last three only appear with repair on.

## When an email goes out

An order is in the email only when its outcome **changed** since the last run
(stored in order meta `_lucina_eracuni_monitor`):

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

Exception: when the check itself fails (API error or "all missing" guard),
every order is in the email every night until the API works again.

Each order is checked on the nights inside the 48 h window (normally two).
After that, it is re-checked every night for up to 14 days only while its
last outcome is a problem — so an order fixed by hand late still comes back
`synced` ("Riješeno"). After 14 days it keeps its last outcome.

Quirk: a `repaired` order that is `synced` the next night counts as a change,
so it is reported a second time as "Riješeno".
