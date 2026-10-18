# e-Računi configuration (done 2026-10-13)

Goal: WooCommerce sells services (Masterclasses) and digital products (PDF
book), but e-Računi issued invoices with **goods** PDV exemptions. Products in
Woo are `virtual + downloadable`; the matching e-Računi catalog item (matched
by SKU) was already `prodaja usluga`.

## Root cause

e-Računi company settings → **Internet trgovine → sinkronizacija → "Vrsta
prodaje po državi isporuke potrošača"** inherited goods-oriented defaults:

- EU consumers → `Oporezive isporuke dobara i usluga u RH`
- EU VAT payers → `Oslobođene isporuke dobara unutar EU`
- Non-EU → `izvoz dobara izvan EU`

Every country has two selects: **eb** = consumer (fizička osoba), **vr** = VAT
payer (pravna osoba).

## Sale-type IDs applied

| ID | Label (short) | Used for |
|---|---|---|
| `0` | Oporezive isporuke dobara i usluga u RH | HR + EU **consumers** |
| `16` | Oslobođene isporuke — obavljene usluge unutar EU (PDV obračunava primatelj) | EU **VAT payers** |
| `120` | Izvoz usluga iz EU krajnjim potrošačima u državi izvan EU | Non-EU **consumers** |
| `17` | Oslobođene isporuke — obavljene usluge osobama bez sjedišta u RH | Non-EU **VAT payers** |

Result: all EU countries set `0/16` (HR stays `0/0`); **216 non-EU countries**
set `120/17`.

## Save endpoint (for future bulk edits)

UI's "U redu" button POSTs to:

```
action=ajaxPostAddOrEditVatTransactionDefinition&isFrame=true
shopId=2381&parentPopUp=3&countryIsoCode=<ISO>&eb=<id>&vr=<id>
```

Opening a dialog first is `action=ajaxGetVatTransactionDefinition` with the
same `shopId`/`countryIsoCode`.

## Quirks

- **Six WooCommerce country codes are rejected** (HTTP 501, no action):
  `BQ`, `JE`, `SS`, `BL`, `SX`, `MF`. e-Računi simply doesn't support them.
- **Serbia (`RS`) and Åland (`AX`)** save fine but never appear in the static
  overview table — verify by reopening their dialogs, not by the list.
- A stale open modal breaks scripted dialog loops — close dialogs before
  iterating; targeting "the newest dialog" is reliable.
- Verification by parsing escaped JS responses is unreliable; render the
  dialog and read the selected values.

## MULTIVAT OSS — intentionally not enabled

The company (R1) is on the **BASIC** package; OSS sale types (`Obavljene
elektroničke/usluge krajnjim potrošačima u EU (OSS)`) are absent from the
selects and require paid **MULTIVAT OSS** activation (~€300,
`helpdesk@e-racuni.com`).

**Decision (user-confirmed):** business is below the €10,000 EU-wide B2C
threshold → EU consumers pay Croatian VAT. Revisit OSS only if the threshold
is exceeded or the company registers voluntarily.

## WooCommerce tax side

- 28 tax-rate rows; **all EU standard rates set to 25% on purpose** (HR VAT
  under the threshold). Non-EU countries have no rows → no Woo VAT for them.
- Masterclasses → Standard class; PDF book → Reduced rate class.
- VAT number reaches orders as `_billing_eu_vat_number` (bridged from
  ThemeHigh's `_wc_billing/thwcfe-block/_billing_eu_vat_number`).
