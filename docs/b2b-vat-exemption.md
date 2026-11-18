# EU B2B VAT exemption

How company orders get 0% VAT. B2B sales are currently paused, so this logic
is live but unused.

## Rule

An order is VAT-exempt iff: company checkbox on + VAT field non-empty +
billing country **not `HR`** + the VAT number passes
`lucina_spiza_validate_vat_for_country()` (format only — see
`vies-validation-proposal.md`). Croatian companies and all consumers stay
taxable; an invalid VAT number ⇒ taxable.

## How it works

`lucina-spiza/checkout-company.php`:

1. On block checkout, WooCommerce calculates totals from the Store API
   customer update **before** any order hook runs. So exemption is set with
   `WC_Customer::set_is_vat_exempt()` in the Store API customer-update
   lifecycle — setting it at order creation is too late (that was the original
   bug: valid EU companies were charged 25%).
2. `is_vat_exempt = yes/no` is stored as order meta.
3. The VAT number is bridged to `_billing_eu_vat_number`, which the
   Eurofaktura plugin sends to e-Računi.

The `wc_stripe_payment_metadata` filter in the same file must tolerate a
`null` order: Stripe's Checkout Sessions AJAX applies it without one.

## Expected results

| Case | Result |
|---|---|
| EU company, valid VAT | exempt, e-Računi sale type `16` |
| Non-EU company, valid VAT | exempt, sale type `17` |
| HR company | taxable 25% |
| Any consumer | taxable 25% |
| Invalid VAT | taxable |

Verified in code; live checkout tests on staging are still to be run when B2B
resumes.
