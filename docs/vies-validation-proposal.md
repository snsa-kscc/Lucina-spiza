# VIES VAT validation — proposal

Status: **proposal — awaiting client decision.** No code written yet.

## Problem

The checkout VAT field is currently validated by **format only** (per-country
regex). Any well-formed but fake number — e.g. `DE123456789` — is accepted,
marks the order `is_vat_exempt`, gets charged 0% VAT, and e-Računi issues a
reverse-charge invoice.

For EU B2B reverse charge, the **seller is expected to verify that the
customer's VAT number was valid at the time of supply**. Format checks alone
are not considered verification. So this is primarily a compliance fix, with
fraud prevention as a side effect.

## What VIES is

European Commission service (`checkVatService`, SOAP) that confirms whether an
EU VAT number exists and is valid for intra-EU trade. Free to query. Returns:

- `valid` / `invalid`
- trader name and address
- an optional **consultation `requestIdentifier`** — stored proof that the
  check was performed, useful if the tax authority ever questions a
  zero-rated invoice

Known caveats:

- **EU only.** UK numbers need a separate HMRC service; non-EU countries have
  no equivalent registry.
- **Unreliable availability.** Member-state backends go down regularly
  (especially weekends). Outages return `SERVICE_UNAVAILABLE` /
  `MS_UNAVAILABLE`, not a clean yes/no.
- Response time typically 0.5–3s.
- Greece uses prefix `EL` (not `GR`) in VIES; Northern Ireland is `XI`.

## Hosting check (done)

PHP **8.2** on the account (`.phpversion`), `SoapClient` enabled — verified via
temporary `phpinfo()` on staging. No hosting changes needed.

## How it fits into the current implementation

The existing flow in `lucina-spiza/checkout-company.php` stays the same shape:

```
format check (existing regex)
  → if EU country: VIES lookup   ← new step
  → set_is_vat_exempt() before totals (existing)
  → persist is_vat_exempt on order (existing)
```

Only `valid` results get exemption. Croatian companies and all consumers are
untouched — they never reach the VIES step. Non-EU companies keep format-only
validation (no registry exists); optionally flagged for manual review.

An `invalid` VIES result surfaces as a checkout error so the customer can fix
the number — it must not silently charge VAT while e-Računi still issues a
business reverse-charge invoice.

The VIES outcome (`valid` / `unverified`, timestamp, consultation ID) is
stored as order meta for audit.

## Decisions the client needs to make

### 1. Outage policy — the main decision

When VIES is down or times out:

| Policy | Behaviour | Trade-off |
|---|---|---|
| Fail closed | Customer is charged VAT | Safe, but loses legit B2B sales during outages |
| Fail open | Exempt anyway | No lost sales, but recreates today's hole during outages |
| Fail to review | Exempt + flag order `vies_unverified` for manual check | No lost sales; needs occasional manual review before fulfilment |

Recommendation: **fail to review** — orders aren't blocked and the exposure
window is limited to actual VIES outages.

### 2. Where the check runs

Recommendation: **inline during checkout** (Store API customer update), with
a short timeout (~4s) and result caching. Errors appear natively in the block
checkout UI.

Alternatives considered: an async "validate" button (better UX but the
exemption still needs to be settled before totals, so an inline re-check is
unavoidable) and post-order async validation (rejected — the invoice is
already sent to e-Računi by then).

### 3. Non-EU companies

VIES cannot verify them. Keep format-only, optionally flagged
`vat_unverified`. If the client wants stronger assurance, manual review of
flagged orders is the practical option.

### 4. Build vs plugin

Recommendation: **extend the existing mu-plugin** rather than install the
official *EU VAT Number* extension. That extension is built around its own
field/classic checkout; adopting it would mean re-wiring the e-Računi
OIB/VAT handoff. Keeping the mu-plugin preserves a single source of truth for
the exemption decision.

## Effort estimate

~150–250 lines of new code + ~30 lines of changes in
`checkout-company.php`. Structure:

| Component | Size | Purpose |
|---|---|---|
| `class-vies-validator.php` | 60–80 lines | SoapClient wrapper, ~4s timeout, normalizes to valid/invalid/unavailable, captures consultation ID |
| Cache | 20–30 lines | WP transient per VAT number — Store API updates fire on every checkout field change, so caching prevents hammering VIES. Cache valid/invalid ~24h; unavailable briefly or not at all |
| Integration in `checkout-company.php` | ~30 lines changed | Insert VIES step between format check and exemption; surface invalid-VAT checkout error |
| Order meta + review flag | 40–60 lines | `vies_valid`/`vies_unverified`, timestamp, consultation ID; admin note/column for review queue |

Would grow it: settings UI for the outage policy (+~100 lines), UK/HMRC
support (+~80 lines), retry/backoff (+~30 lines).

Would shrink it: no cache (−30, not recommended), fail-closed with no review
flag (−40).

## Edge cases worth noting

- VIES valid but member-state name/address mismatch is normal — only the
  `valid` flag is authoritative; trader details are informational.
- A VAT number that was valid at purchase time should remain exempt
  historically; the stored consultation ID is the evidence.
- Customers who mistype a digit get "invalid" — the checkout error message
  should suggest checking the number format/country.
