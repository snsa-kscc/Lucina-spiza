# Lucina Špica — WooCommerce ↔ e-Računi

Workspace for the lucinaspiza.com WooCommerce store and its e-Računi
(Eurofaktura) invoicing integration.

## Layout

- `Lucina-spiza/` — clone of `github.com/snsa-kscc/Lucina-spiza`, a WordPress
  **mu-plugin** with custom checkout logic (company invoice checkbox, EU VAT/OIB
  validation, e-Računi bridging).
- `remote/` — user's SSHFS mount of the hosting account (`luce:`). Treat as
  read-mostly; see `docs/remote-access.md`.
- `racuni.CSV` — e-Računi invoice export, kept for reference.
- `docs/` — what was set up and why. Read before touching tax logic.

## Environments

| | Site | Remote path |
|---|---|---|
| Production | lucinaspiza.com | `/public_html` |
| Staging | labouche.lucinaspiza.com | `/public_html/labouche` |

The mu-plugin deploys to `wp-content/mu-plugins/` on each. Stack observed on
staging: WP 7.1.3, WooCommerce 11.0.0, Eurofaktura plugin 1.1.9, ThemeHigh
Checkout Field Editor Pro.

## Golden rules

- **Never modify production** (`/public_html` outside `labouche/`) without
  explicit approval.
- The `luce` SSH host is **SFTP-only** — `exec request failed` on shell/exec.
  Deployment = SFTP `put` or file ops over SSHFS.
- **Do not run `git` over SSHFS** on remote checkouts — ownership (remote UID
  1786) and FETCH_HEAD writes fail. The staging mu-plugins dir is no longer a
  git repo anyway; upload changed files and verify with `sha256sum`.
- Never edit the commercial Eurofaktura plugin — all custom code lives in the
  mu-plugin repo.
- EU B2C is charged **25% Croatian VAT on purpose**: the business is below the
  €10,000 EU-wide threshold, so no OSS. Do not "correct" EU rates to
  destination-country rates and do not buy MULTIVAT OSS yet.

## VAT / PDV decision matrix (current intended state)

| Customer | Woo tax | `is_vat_exempt` | e-Računi sale type |
|---|---|---|---|
| HR consumer | 25% | no | `0` — oporeziva isporuka u RH |
| HR company | 25% | no | `0` — oporeziva isporuka u RH |
| EU consumer | 25% (HR VAT, < €10k) | no | `0` — oporeziva isporuka u RH |
| EU company, valid VAT | 0% | yes | `16` — usluge unutar EU, reverse charge |
| Non-EU consumer | none | no | `120` — izvoz usluga |
| Non-EU company, valid VAT | 0% | yes | `17` — usluge osobama bez sjedišta u RH |
| Invalid VAT ID | taxed | no | consumer row |

Croatian companies are always domestic-taxable, even with an OIB — the code
exempts only non-`HR` billing countries.

## Key code

- `lucina-spiza/checkout-company.php` — block-checkout company/VAT handling;
  sets `WC_Customer::set_is_vat_exempt()` via Store API hooks before totals,
  persists `is_vat_exempt` order meta.
- `lucina-spiza/vat.php` — per-country VAT regexes + Croatian OIB checksum.
- `lucina-spiza/eracuni.php` — passes `_billing_eu_vat_number` to Eurofaktura.
- ThemeHigh field keys (do not rename): `thwcfe-block/is_company_invoice`,
  `thwcfe-block/_billing_eu_vat_number`.

## Docs

- `docs/eracuni-configuration.md` — e-Računi country mappings, save endpoint,
  BASIC-package limits, Woo tax table.
- `docs/b2b-vat-exemption.md` — the EU B2B over-charging bug, PR #1 fix,
  staging deployment.
- `docs/remote-access.md` — SSH/SFTP/SSHFS access model and every pitfall hit.
- `docs/vies-validation-proposal.md` — proposed VIES lookup before granting
  B2B exemption; pending client decision. No code written yet.
