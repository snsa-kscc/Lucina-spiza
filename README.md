# Lucina Spiza

A WordPress/WooCommerce webshop where Luce sells online cooking masterclasses.

## Stack

- WordPress
- WooCommerce
- Stripe
- PHP

## What lives here

This repository contains a WordPress must-use plugin that holds site-specific customizations for the checkout flow, gift downloads, and e-invoicing (e-Računi). Keeping them in `mu-plugins` means they stay active and survive theme updates.

## Modules

- `vat.php` — VAT validation helpers
- `checkout-company.php` — Company/billing checkout fields, B2B VAT exemption
- `express-checkout.php` — Express checkout (wallet) tweaks
- `checkout-labels.php` — Checkout label tweaks
- `gift-downloads.php` — Gift download handling
- `eracuni.php` — e-Računi integration
- `eracuni-monitor.php` — Nightly e-Računi sync check and repair
- `email-texts.php` — WooCommerce e-mail copy overrides
- `mail-audit.php` — Mail audit/logging utility

Docs: `docs/` (start with `AGENTS.md`).
