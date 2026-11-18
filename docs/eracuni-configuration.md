# e-Računi configuration

The shop sells services (Masterclasses) and digital products (PDF book). Woo
products are `virtual + downloadable`; the matching e-Računi catalog items
(matched by SKU) are `prodaja usluga`.

## Sale type per country

Postavke tvrtke → **Internet trgovine → sinkronizacija → "Vrsta prodaje po
državi isporuke potrošača"**. Each country has two selects: **eb** = consumer,
**vr** = VAT payer.

| ID | Label (short) | Used for |
|---|---|---|
| `0` | Oporezive isporuke dobara i usluga u RH | **All consumers** (HR, EU, non-EU) |
| `16` | Oslobođene isporuke — usluge unutar EU (PDV obračunava primatelj) | EU VAT payers |
| `17` | Oslobođene isporuke — usluge osobama bez sjedišta u RH | Non-EU VAT payers |

So: HR `0/0`, EU countries `0/16`, non-EU countries `0/17`. (The original
inherited defaults were goods-oriented — `izvoz dobara`, `isporuke dobara
unutar EU` — which is why this was set by hand.)

Shop import parameters (*Preuzeti parametri za uvoz podataka*), same on
production and staging: Blagajna `1`, Skladište `0000 - Preuzeto skladište`,
Vrsta prodaje (PDV) `Oporezive isporuke dobara i usluga u RH`, all import
checkboxes off.

If Woo sends a 0% VAT line on a sale type `0` order, e-Računi warns *"Primljena
stopa PDV-a 0% nije dopuštena … postavljena uobičajena stopa"* and applies 25%
itself (order may end up `CorrectionPending`).

## Bulk edits

The dialog's "U redu" button POSTs:

```
action=ajaxPostAddOrEditVatTransactionDefinition&isFrame=true
shopId=2381&parentPopUp=3&countryIsoCode=<ISO>&eb=<id>&vr=<id>
```

Opening a dialog is `action=ajaxGetVatTransactionDefinition` with the same
`shopId` / `countryIsoCode`.

Quirks:

- Six Woo country codes are rejected (HTTP 501): `BQ`, `JE`, `SS`, `BL`, `SX`,
  `MF` — e-Računi doesn't support them.
- `RS` and `AX` save fine but never show in the overview table — verify by
  reopening their dialogs.
- Close stale dialogs before scripting a loop; verify by rendering the dialog,
  not by parsing the escaped JS response.

## MULTIVAT OSS — intentionally not enabled

The company is on the **BASIC** package; OSS sale types need paid **MULTIVAT
OSS** (~€300, `helpdesk@e-racuni.com`). The business is below the €10,000
EU-wide B2C threshold, so EU consumers pay Croatian VAT. Revisit only if the
threshold is exceeded.

## WooCommerce tax side

- All tax-rate rows (EU and non-EU) are **25% on purpose** — Croatian VAT for
  every consumer.
- Masterclasses → Standard class; PDF book → Reduced rate class (5%).
- The VAT number reaches orders as `_billing_eu_vat_number` (bridged from
  ThemeHigh's `thwcfe-block/_billing_eu_vat_number`).
