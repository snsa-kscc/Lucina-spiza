# Hide the gift option for products that can't be gifts — implementation plan

Status: **proposal, waiting for the client (Luce) to approve.** No code written yet.

## Background

The gift checkbox (ThemeHigh additional field `is_gift`) appears on every
checkout. `lucina-spiza/gift-downloads.php` saves it as `_is_gift` order
meta and picks which download the buyer gets:

- gift → 2nd downloadable file (the voucher PDF)
- not a gift → 1st downloadable file (the access link)

The products we have now each have two files: link first, gift PDF second.
The **new product is downloadable and virtual, with one file only (the
link)**, and can't be bought as a gift.

## What happens today with the new product

If someone ticks "gift", the download filter can't find a 2nd file, so it
falls back to the files the product has: the buyer still gets the link.
**Nothing breaks.** But the checkbox still shows when the new product is
the only thing in the cart, so a buyer could tick it and expect a voucher
that never arrives.

## Goal

- Cart has **no product that can be a gift** → hide the gift checkbox and
  always save `_is_gift = no`.
- Cart has **at least one product that can be a gift** (including mixed carts)
  → no change from today.

## Rule: which products can be gifts

> A product can be a gift if it has **2 or more downloadable files**.

This matches the file setup we already use, so there's no tag, category or
extra setting to maintain: the file list decides. For variable products the
variation's own downloads are checked.

The file order must stay the same: link 1st, gift PDF 2nd. If the new product
ever gets a 2nd file, it becomes a gift product automatically.

## Implementation

All in the mu-plugin; the commercial plugins aren't touched.

### 1. Helper (`gift-downloads.php`)

```php
function lucina_spiza_product_is_giftable( $product ) {
	return $product instanceof WC_Product
		&& $product->is_downloadable()
		&& count( $product->get_downloads() ) >= 2;
}
```

Plus one function each to check the cart (`WC()->cart`) and the order
(`$order->get_items()`): true if any line item's product can be a gift.

### 2. Front end: hide the checkbox

- On the checkout page, `gift-downloads.php` enqueues a new
  `assets/checkout-gift.js` and adds an inline
  `window.lucinaSpizaGift = { giftable: true|false }` worked out from the
  cart.
- When `giftable` is false, the script hides the checkbox's wrapper with the
  existing `checkout-field-hidden` class. A `MutationObserver` puts the
  class back after the block checkout re-renders, the same way
  `checkout-company.js` does.
- If the box was ticked before it was hidden, the script unticks it first
  through React's native setter + `change` event, so the block checkout's
  state doesn't keep sending `true`.

The block checkout doesn't let customers remove items, so the value worked
out at page load stays correct. If that ever changes, the next step up is to
expose the flag through the Store API cart (`woocommerce_store_api_register_endpoint_data`)
and read it from `wp.data`'s `wc/store/cart`.

### 3. Server-side check

In the existing `woocommerce_store_api_checkout_update_order_from_request`
handler: if no item in the order can be a gift, save `_is_gift = no`
whatever the request says. This covers old cached pages, express checkout
and edited requests; hiding the box alone wouldn't.

The `woocommerce_get_item_downloads` filter stays as it is: for a product
with one file it already returns that file.

## Behaviour after the change

| Cart | Checkbox | `_is_gift` | Downloads |
|---|---|---|---|
| Only the new product | hidden | always `no` | link |
| Only existing products | shown | as ticked | link or gift PDF |
| Mixed, gift ticked | shown | `yes` | existing → gift PDF, new → link |
| Mixed, gift not ticked | shown | `no` | link for both |

## Open points before coding

1. **Checkbox DOM id / wrapper class.** `is_gift` lives in ThemeHigh's
   "additional info" section, not billing, so its id probably isn't
   `billing-thwcfe-block-…`. Check it on staging (labouche) first.
2. **Section heading.** If `is_gift` is the only field in that section,
   hide the section title too, so the checkout doesn't show an empty heading.
3. Should a hidden checkbox also change any email text? Right now no email
   text depends on `_is_gift` (`email-texts.php` doesn't read it).

## Testing (staging)

Once merged to `main` it deploys to labouche. Test with:

1. Only the new product → no checkbox; order meta `_is_gift = no`; email and
   order page show the link.
2. Only an existing product, gift ticked → voucher PDF; not ticked → link.
3. Mixed cart, gift ticked → existing product gets the PDF, new product the link.
4. Tick the gift box, then reload with only the new product in the cart → box
   hidden, order saved as not a gift.

Then promote to production with Actions → Deploy → `production`.

## Effort

About 60–80 lines: helper and server-side check in `gift-downloads.php`
(~30), new `checkout-gift.js` (~40), enqueue and inline flag (~10).
