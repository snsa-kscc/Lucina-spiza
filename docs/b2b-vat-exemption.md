# EU B2B VAT exemption fix (PR #1, merged 2026-10-13)

## Symptom

Recent EU company orders with valid VAT IDs were charged 25% VAT, while older
test orders were correctly zero-rated:

- #229 AT `ATU12345675` → tax `19.80` (wrong)
- #228 AT `ATU46674503` → tax `29.80` (wrong)
- #196 AT `ATU45212356` → tax `0.00` (correct)
- #198 BG `BG175074752` → tax `0.00` (correct)
- #231 DE consumer → `15.05` (correct, taxable)

## Root cause

`lucina-spiza/checkout-company.php` validated the VAT number and bridged it to
`_billing_eu_vat_number` for e-Računi, but **never called
`WC_Customer::set_is_vat_exempt()`**. On block checkout, WooCommerce computes
cart totals from the Store API request *before* the order hook runs, so
setting exemption at order-creation time is too late.

## Fix (commit `4297f78`, merge `9f9101a`)

In `checkout-company.php`:

1. Hooked the Store API customer-update lifecycle so exemption is applied
   **before totals are calculated**.
2. Exempt iff: company checkbox on + VAT field non-empty + billing country
   **not `HR`** + VAT passes `lucina_spiza_validate_vat_for_country()`.
3. Persisted `is_vat_exempt=yes/no` as order meta during checkout processing.

Rules preserved: HR companies and all consumers stay taxable; invalid VAT ⇒
taxable.

## Verified locally

| Case | Result |
|---|---|
| EU company, valid VAT | exempt |
| HR company | taxable |
| Non-EU company | exempt |
| EU consumer | taxable |
| Invalid EU VAT | taxable |

`php -l` clean (checked under PHP 8.5).

## Deployment to staging

- Repo `snsa-kscc/Lucina-spiza`; local clone `~/Work/luce/Lucina-spiza`.
- PR #1: https://github.com/snsa-kscc/Lucina-spiza/pull/1 (reviewed, merged).
- `git pull` on staging failed (see `remote-access.md`), so `.git` was removed
  and `lucina-spiza/checkout-company.php` was uploaded via SFTP.
- Verified: remote SHA-256 `a06a607bb0604297f1979b34bcdc1a301e22c761ab34372193c6a0feb8f77242`
  matches the repo file.

## Deployment to production (17.11.2026)

Production kept the 28.08 "Initial commit" version of `checkout-company.php`
(`3b7ad51`, SHA-256 `90157315a83d…`) until 17.11.2026 — PR #1 had only ever
gone to staging. All other mu-plugin files already matched `main`.

- Synced to `main` via Actions → Deploy → `production`
  ([run 44192291555](https://github.com/snsa-kscc/Lucina-spiza/actions/runs/44192291555)),
  verified SHA-256 `a06a607bb060…`.
- Then PR #3 (Stripe metadata fatal fix, below) was tested on staging and
  promoted ([run 44197411722](https://github.com/snsa-kscc/Lucina-spiza/actions/runs/44197411722)).
  Repo, staging and production all at `84a5f3291271…`.
- **B2B sales are currently paused** (problems with e-Računi), so the exemption
  logic is live but unused. The live checkout tests below were not run.

### Related: Stripe metadata fatal (PR #3)

The `wc_stripe_payment_metadata` filter in `checkout-company.php` called
`$order->get_billing_company()`, but Stripe's Checkout Sessions AJAX
(`wc_stripe_create_checkout_session`) applies that filter with a `null`
order → fatal ~70× between 18.10 and 17.11.2026, on ordinary consumer
checkouts too. Fixed by returning the metadata unchanged when the order isn't a
`WC_Order`. Staging test orders #267 (card), #268 and #269 (Google Pay): no
new fatal.

## Still to verify on labouche (when B2B resumes)

Live checkout tests: EU B2B valid VAT → 0 tax; HR company → 25%; non-EU
company → 0; EU consumer → 25%; invalid VAT → taxed; non-EU consumer → no tax
row and e-Računi `120` classification on the invoice.
