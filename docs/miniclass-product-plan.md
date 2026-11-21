# New product: Miniclass HIBRIDNA KREMA — publish plan

Status: **ready to execute** (agreed 2026-11-21). Needs browser access to
wp-admin (Code Mode sessions have no browser — start Claude Code with
`CLAUDE_CODE_EXPERIMENTAL_CODE_MODE=0 claude --chrome`).

Source: Luce's (Barbioza, `infobarbioza@gmail.com`) Gmail thread
**"black friday"** (thread `1a1c4f11d8b86b81`), messages of 20–21 Nov 2026.

## Decisions

- Staging first (labouche.lucinaspiza.com), quick check, then the same on
  production and **publish immediately**. No extensive testing.
- New category **Miniclass**.
- Home page: the product shows as the **8th tile, after the 7 masterclasses**
  in the existing grid. No new section.
- Uses the **existing gift option** (2nd download = gift PDF), so no code
  change. The old gift-option proposal is dropped.
- Voucher wording stays as Luce made it ("GIFT CARD", not "POKLON BON").
- One photo only.
- e-Računi: nothing to do, it picks up the item automatically.
- No Black Friday discount on this product.

## Assets (from the Gmail thread)

| Asset | Gmail message | Attachment | Upload as |
|---|---|---|---|
| Gift voucher PDF (A6, Canva, QR → Drive) | `1a1f9afba8e5a835` | `HIBRIDNA GIFT CARD.pdf` | `POKLON-HIBRIDNA.pdf` |
| Product photo (900×1200, cream close-up) | `1a1feeefe60ebc5b` | `image0.jpeg` | `MINICLASS-HIBRIDNA-KREMA.jpg` |
| Access link (Drive folder) | `1a1f9afba8e5a835` | — | https://drive.google.com/drive/folders/1gaaZLypmVCEN50c-oXseES_EURYYcFf4 |

Download with the Gmail `download_gmail_attachment` tool and rename before
uploading through the WP media library (files land in
`wp-content/uploads/YYYY/MM/`).

**Check first:** open the Drive folder in the browser and confirm the
Miniclass PDF is in it (it was empty on 20 Nov; Luce said she'd add it on
the 21st). The gift voucher's QR points to the same folder.

## Naming conventions on production (for consistency)

- Gift PDFs: `POKLON-<CODE>.pdf` (`POKLON-SLAG`, `-GANACHE`,
  `-BUTTERCREAM`, `-SB`, `-SG`, `-BG`, `-S-B-G`), all A6 landscape.
- Product cover images: upper-case product name (`SLAG-GANACHE.jpg`,
  `BUNDLE-SVA-3.jpg`).
- Product names `Masterclass ŠLAG`, slugs `masterclass-slag`, SKUs
  `master-slag`.
- Downloads: **1st = access link, 2nd = gift PDF** —
  `lucina-spiza/gift-downloads.php` depends on this order.

## Product settings

| Field | Value |
|---|---|
| Type | Simple, **Virtual** + **Downloadable** |
| Name | `Miniclass HIBRIDNA KREMA` |
| Slug | `miniclass-hibridna-krema` |
| SKU | `mini-hibridna-krema` |
| Regular price | **10.00** (incl. 25% PDV — base €8; enter the same way as the existing products) |
| Sale price | empty (no Black Friday price) |
| Tax status / class | Taxable / Standard (same as the masterclasses) |
| Category | **Miniclass** (new, slug `miniclass`) — untick Masterclass |
| Product image | `MINICLASS-HIBRIDNA-KREMA.jpg`, alt = product name |
| Gallery | none |
| Download 1 | name `Pristup sadržaju` (copy the exact name used on the existing products), URL = Drive folder link above |
| Download 2 | name as on existing products' gift file, file = `POKLON-HIBRIDNA.pdf` |
| Download limit / expiry | same as existing products |

Copy every other setting (download names, limits, tax, sold individually,
etc.) from **Masterclass ŠLAG** (product ID 13) so the new product matches.

### Short description

```html
<p>Hibridna krema je moj recept koji spaja maslac i šlag uz tehniku emulgiranja kakva se koristi za ganache – svilenkasta, sjajna i dovoljno stabilna za ozbiljne torte.</p>
```

(Condensed from Luce's intro — existing products use one sentence here.
Alternative: use her first two sentences verbatim.)

### Description

Luce's text, formatted like the existing products. Note: her "pucа" had a
Cyrillic "а" — fixed to Latin below.

```html
<p><strong>Miniclass HIBRIDNA KREMA</strong></p>
<p>Tražiš kremu koja je ukusna, glatka i dovoljno stabilna za ozbiljne torte? Hibridna krema je moj recept koji spaja maslac i šlag uz tehniku emulgiranja kakva se koristi za ganache. Rezultat je svilenkasta, sjajna krema s kremoznošću buttercreama. Kad se stisne, postaje čvrsta gotovo kao fondant. Na nju bez straha možeš staviti sve ukrase: fondant, wafer, cvijeće, figurice, drip ili ukrase od želatine.</p>
<p><strong>Što dobivaš:</strong></p>
<ul>
<li>Točne gramature za punu dozu i upute kako napraviti pola doze</li>
<li>Detaljan postupak korak po korak, s fotografijama svake faze</li>
<li>Popis sastojaka koje koristim i provjerene preporuke marki koje daju najbolji rezultat</li>
<li>Trik kojim neutraliziraš žute tonove i dobiješ gotovo bijelu kremu</li>
<li>Savjete kako se riješiti mjehurića zraka za savršeno glatku površinu</li>
<li>Upute za čuvanje na sobnoj temperaturi, u hladnjaku i u zamrzivaču te za vraćanje kreme u ispravnu teksturu</li>
</ul>
<p><strong>Zašto ćeš je voljeti:</strong></p>
<ul>
<li>Jednostavna priprema s malo sastojaka</li>
<li>Ne puca, ne curi i ne "znoji" se kao klasične kreme od šlaga</li>
<li>Pogodna za premazivanje, punjenje i ukrašavanje</li>
<li>Može se pripremiti unaprijed</li>
</ul>
<p>Hibridna krema je za sve koji rade torte, od hobista do profesionalaca koji žele pouzdanu kremu za svaku priliku.</p>
<p>Recept dolazi u digitalnom obliku (PDF) i šalje se odmah nakon kupnje.</p>
```

## Home page: 8th tile

The home page (Elementor) has one **Shortcode** widget (`data-id="40a2a36"`)
rendering a 4-column WooCommerce grid of the 7 masterclasses, currently
**newest first** (IDs 818 → 13).

Two things to check in Elementor before publishing:

1. **Filter.** If the shortcode filters `category="masterclass"`, the new
   product won't appear. Change it to `category="masterclass,miniclass"`
   (or whatever filter keeps exactly these 8).
2. **Order.** With newest-first ordering the Miniclass would show as the
   **1st** tile, not the 8th. Fix one of these ways, simplest first:
   - give the products a **menu order** (Masterclasses 1–7 in today's
     order, Miniclass 8) and set the shortcode to
     `orderby="menu_order" order="ASC"`; or
   - order by category/price only if it produces exactly the current
     order + Miniclass last (unlikely: prices aren't in display order).

   Record the shortcode before and after editing, so it can be reverted.

Also check that the shop/category pages and the "related products" area
look right with the new category.

## Steps

### Staging (labouche.lucinaspiza.com)

1. Check the Drive folder has the PDF.
2. Upload `POKLON-HIBRIDNA.pdf` and `MINICLASS-HIBRIDNA-KREMA.jpg` to the media library.
3. Create category **Miniclass**.
4. Create the product (settings above), publish.
5. Fix the home page shortcode (filter + order) → Miniclass is the 8th tile.
6. Quick check: product page, home page grid on desktop + mobile, add to
   cart → checkout shows €10.00 with €2.00 PDV. Optionally one test order
   with "gift" ticked to see the voucher link in the email.

### Production (lucinaspiza.com) — approved to go live right away

7. Repeat steps 2–5 on production, publishing immediately.
8. Check the live home page and product page; confirm Black Friday sale
   prices on the masterclasses are untouched (they're scheduled from 22 Nov).
9. Tell Luce it's live (she announces Sunday 22 Nov afternoon).
