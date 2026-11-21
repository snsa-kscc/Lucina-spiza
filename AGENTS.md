# Lucina Spiza — WooCommerce ↔ e-Računi

Workspace for the lucinaspiza.com WooCommerce store and its e-Računi
(Eurofaktura) invoicing integration.

## Layout

This repo (`github.com/snsa-kscc/Lucina-spiza`) is a WordPress **mu-plugin**:
`lucina-spiza.php` loads the modules in `lucina-spiza/` (checkout company/VAT
handling, express checkout, e-Računi bridging and sync monitor, email texts).
`docs/` explains what was set up and why — read before touching tax logic.
The hosting account is reached over SFTP only (`docs/remote-access.md`).

## Environments

|            | Site                     | Remote path             |
| ---------- | ------------------------ | ----------------------- |
| Production | lucinaspiza.com          | `/public_html`          |
| Staging    | labouche.lucinaspiza.com | `/public_html/labouche` |

The mu-plugin deploys to `wp-content/mu-plugins/` on each. Stack observed on
staging: WP 7.1.3, WooCommerce 11.0.0, Eurofaktura plugin 1.1.9, ThemeHigh
Checkout Field Editor Pro.

## Golden rules

- **Never modify production** (`/public_html` outside `labouche/`) without
  explicit approval.
- The `luce` SSH host is **SFTP-only** — `exec request failed` on shell/exec.
  Deployment = GitHub Actions: merge to `main` deploys staging; production is
  promoted with Actions → Deploy → Run workflow (target `production`). See
  `docs/deployment.md`.
- **Do not run `git` on the server** — the SFTP backend can't handle it. Code
  goes through CI; wp-config edits follow `docs/remote-access.md`.
- Never edit the commercial Eurofaktura plugin — all custom code lives in the
  mu-plugin repo.
- EU B2C is charged **25% Croatian VAT on purpose**: the business is below the
  €10,000 EU-wide threshold, so no OSS. Do not "correct" EU rates to
  destination-country rates and do not buy MULTIVAT OSS yet.

## VAT / PDV decision matrix (current intended state)

**All consumers worldwide pay 25% Croatian VAT** (accountant's instruction).
B2B sales are currently paused, so the company rows are dormant.

| Customer                  | Woo tax              | `is_vat_exempt` | e-Računi sale type                      |
| ------------------------- | -------------------- | --------------- | --------------------------------------- |
| HR consumer               | 25%                  | no              | `0` — oporeziva isporuka u RH           |
| HR company                | 25%                  | no              | `0` — oporeziva isporuka u RH           |
| EU consumer               | 25% (HR VAT, < €10k) | no              | `0` — oporeziva isporuka u RH           |
| EU company, valid VAT     | 0%                   | yes             | `16` — usluge unutar EU, reverse charge |
| Non-EU consumer           | 25% (HR VAT)         | no              | `0` — oporeziva isporuka u RH           |
| Non-EU company, valid VAT | 0%                   | yes             | `17` — usluge osobama bez sjedišta u RH |
| Invalid VAT ID            | taxed                | no              | consumer row                            |

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

- `docs/eracuni-configuration.md` — e-Računi sale types per country, import
  parameters, bulk-edit endpoint, why no OSS, Woo tax side.
- `docs/eracuni-monitor.md` — nightly e-Računi sync check + auto-resync
  (live on production): flow, outcomes, email, schedule, config.
- `docs/b2b-vat-exemption.md` — how company orders get 0% VAT (B2B paused).
- `docs/vies-validation-proposal.md` — proposed VIES lookup before granting
  B2B exemption; pending client decision, no code.
- `docs/miniclass-product-plan.md` — publish plan for the new Miniclass
  HIBRIDNA KREMA product (staging → production, home page 8th tile).
- `docs/deployment.md` — CI deploy to staging/production, secrets.
- `docs/remote-access.md` — SFTP-only access, safe wp-config edits.
