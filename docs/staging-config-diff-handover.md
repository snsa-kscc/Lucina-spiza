# Handover — staging e-Računi/VAT configuration differs from production

Status (18.11.2026): **open**. Production is fine; staging is not. Not a code
problem — the mu-plugin is byte-identical on repo, staging and production
(`checkout-company.php` SHA-256 `84a5f3291271…`, all other files equal, checked
17.11.2026). Siniša changed settings on production earlier and doesn't
remember which ones; staging was never aligned.

Next session runs with Code Mode **off**
(`CLAUDE_CODE_EXPERIMENTAL_CODE_MODE=0 claude --chrome`) so it can use the
browser for WP admin, e-Računi and the e-Računi API docs.

## Goal

Find every setting that differs between production and staging that affects
tax or the e-Računi import, write it down here, and align staging to
production (staging only — **never change production** without approval). Then
staging can be used to test the e-Računi sync monitor
(`docs/eracuni-sync-monitor-proposal.md`).

## Symptom

Test purchases on staging (labouche.lucinaspiza.com), 17.11.2026, after the
Stripe fatal fix (PR #3) was deployed:

| Order | Time (UTC) | Payment | Billing country | Product | Webhooks (all HTTP 200) | Staging e-Računi |
|---|---|---|---|---|---|---|
| #267 | 22:43 | card | ? (not logged) | ? | updated `ae2b8bdb…`, created `211ce234…`, updated `316b38ea…` | invoice OK |
| #268 | 22:45 | Google Pay | **US** | ? | updated `3d425271…`, created `678fab38…` | **no invoice** |
| #269 | 23:00 | Google Pay | **US** | Paket Masterclass GANACHE + BUTTERCREAM (99,00) | updated `03608b7e…`, created `74f5eb96…` | invoice with warning ↓ |

#269 import warning in staging e-Računi:

> WooCommerce upozorenje uvoza Pristup sadržaju Paket Masterclass GANACHE +
> BUTTERCREAM: Primljena stopa PDV-a 0% nije dopuštena za postavljenu vrstu
> prodaje "Oporezive isporuke dobara i usluga u RH", stoga je postavljena
> uobičajena stopa. Molimo provjerite jesu li postavke poreznih stopa
> ispravne.

So WooCommerce on staging **sent the line with 0% VAT**. Production Apple Pay /
Google Pay orders import fine (Siniša, 17.11).

Intended rule: **every consumer worldwide pays 25% HR VAT**, e-Računi sale type
`0` (see `AGENTS.md`, `docs/eracuni-configuration.md`).

## Already verified

- **Normal cart tax is identical** on both sites. Store API anonymous cart
  (`/wp-json/wc/store/v1/cart`, add-item, update-customer) with billing
  HR / DE / US: all 7 Masterclass products → 25% on production *and* staging.
  Staging-only product `knjiga` (`master-knjigitza`, ID 1403) → 5%.
  ⇒ The 0% appears only in the **Google Pay express** path on staging, or is
  set after cart calculation.
- Staging is connected to a **separate e-Računi company**: webhooks go to
  `e-racuni.com/H6d/WebShopSynch-16FC074D001A0E00BFB232BB08CFF787`;
  production uses `H7e/WebShopSynch-B1F52B35DA090E00A422FC0905BF086C`. Test
  orders don't touch the real books.
- e-Računi shop import parameters (screenshot, said to be same on both):
  Blagajna 1, Skladište 0000, Vrsta prodaje (PDV) *Oporezive isporuke dobara i
  usluga u RH*, all import checkboxes off.
- Staging Stripe logged `Webhook validation failed (signature_invalid)` at
  22:45:15 UTC → staging Stripe webhook secret doesn't match. Orders still
  completed; may matter for status updates.
- Express checkout log (`lucina-spiza-express`): both Google Pay orders
  arrived with `country=US`, state/postcode/city filled, company fields
  empty — Stripe test-mode wallet address.
- No new `get_billing_company()` fatal on staging after the fix deploy.

## Where to compare (production vs staging)

Do side by side in two browser tabs; record each difference in the table at
the bottom.

### WooCommerce → Settings → General / Tax
- *Prices entered with tax* (incl./excl.)
- *Calculate tax based on* (billing / shipping / shop base) — **prime
  suspect**: wallet orders for virtual products may carry no or a different
  address.
- *Shipping tax class*, *Rounding*, *Additional tax classes*
- *Display prices in shop / during cart and checkout*
- Standard rates table: rows for `*` (all countries) / US / HR, priority,
  compound, **row names** (e-Računi may map by name/rate)
- Reduced rate table (book)
- Default customer location; selling/shipping locations

### Products
- Tax status + tax class of product 817 (GANACHE + BUTTERCREAM) on both

### Stripe (WooCommerce → Settings → Payments → Stripe)
- Live vs test mode; express checkout (Apple/Google Pay) settings; any
  tax/address options; webhook secret (fix the `signature_invalid`)

### Staging orders in WP admin
- #268 and #269: tax lines, totals, billing/shipping address, `is_vat_exempt`
  meta, customer note/status history. Compare with a production wallet order
  (#441 Apple Pay, #442 Apple Pay, #439 Google Pay).

### e-Računi (production company vs staging company)
- Postavke tvrtke → Internet trgovine → sinkronizacija:
  - *Vrsta prodaje po državi isporuke potrošača*: **US** and a few other
    non-EU countries (eb / vr) — should be `0` / `17`
  - **tax-rate settings** the warning points to (*postavke poreznih stopa*;
    mapping of Woo rates/classes to PDV rates)
  - import parameters (above), API/consumer key
- Dnevnik sinkronizacije for **#268** — is there any entry? Error entry ⇒
  config; no entry ⇒ the silent drop reproduced (valuable for the helpdesk
  ticket 259410 and the monitor).
- Press **Sinkroniziraj** on staging #268 and read the message.

### Plugins / versions
- WooCommerce, Stripe gateway, Eurofaktura, ThemeHigh CFE versions on both
  (Plugins screen). Staging WP was 7.1.4 in webhook headers.

## Tools available

- SFTP via `luce` alias (no shell). Logs: `/public_html[/labouche]/wp-content/uploads/wc-logs/`
  (`webhooks-delivery-*`, `fatal-errors-*`, `lucina-spiza-express-*`,
  `woocommerce-gateway-stripe-*`, `mail-audit-*`).
- Store API probes (anonymous cart) work on both sites without auth.
- e-Računi API: test and production users/tokens exist (Siniša). Test user may
  be able to read the staging company's documents — confirm which company each
  user sees before any call. Never put credentials in the repo or chat.
- Browser (Chrome): WP admin on both sites, e-Računi web app (iframe app; real
  mouse events needed — see `docs/remote-access.md`), konsoleH.

## Differences found

| Area | Setting | Production | Staging | Aligned? |
|---|---|---|---|---|
| | | | | |

## Done when

- Differences listed above and staging aligned.
- New staging Google Pay test order (US address) imports into staging e-Računi
  with 25% and no warning.
- #268 explained (config error vs silent drop) and noted in
  `docs/eracuni-sync-monitor-proposal.md`.
