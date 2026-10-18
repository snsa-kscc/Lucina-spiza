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

## Still to verify on labouche

Live checkout tests: EU B2B valid VAT → 0 tax; HR company → 25%; non-EU
company → 0; EU consumer → 25%; invalid VAT → taxed; non-EU consumer → no tax
row and e-Računi `120` classification on the invoice.
