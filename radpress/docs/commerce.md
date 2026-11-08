# Lean Commerce — development guide

## Status

The 5.0 development work includes an optional store, Stripe Checkout adapter and
private order administration. Commerce starts disabled. The recorded stable
release is still 4.0.0; these development changes have not been released.

Local fixtures cover stock concurrency, retries, signatures and interrupted
storage. Real Stripe sandbox acceptance, the hosting matrix, complete
accessibility/security review and production release acceptance remain open.

## Owner setup

1. Open **Admin → Commerce** as the owner.
2. Choose one supported store currency, destinations, flat shipping charge and
   an explicit tax rate. Tax uses basis points: `100` means 1%, and applies to
   the items plus shipping. Confirm the policy, including an intentional zero
   rate where appropriate. Local pickup is free when enabled.
3. Select **Test** and configure the merchant's Stripe test secret API key and
   endpoint signing secret. Leave credential fields blank to retain saved
   values. Credentials are encrypted and never displayed.
4. Configure the canonical site URL with HTTPS. Enable cURL and Sodium or
   OpenSSL encryption. Keep private application/data directories outside the
   public root or enforce the documented server deny rules.
5. Register `/commerce/stripe/webhook` in Stripe, subscribing to
   `checkout.session.completed`, `checkout.session.expired`,
   `checkout.session.async_payment_succeeded` and
   `checkout.session.async_payment_failed`.
6. Create published products in the store currency, then enable the storefront.
   Complete the sandbox acceptance below before considering live mode.

Settings changes require the current owner password and MFA when enabled,
CSRF and the displayed settings revision. Editors cannot manage orders or
payment settings. Administrators can inspect/reconcile/fulfil orders; only the
owner manages store setup.

Once commerce is enabled or has recorded orders, product administration also
requires owner/admin access because prices and stock affect transactions.
Editor catalogue access remains available on sites that have not enabled or
used commerce.

Alternatively, configure credentials in the web PHP environment:

- `BATOI_PRESS_STRIPE_SECRET_KEY_TEST` and
  `BATOI_PRESS_STRIPE_WEBHOOK_SECRET_TEST`;
- equivalent names ending in `_LIVE` for live credentials;
- unsuffixed `BATOI_PRESS_STRIPE_SECRET_KEY` and
  `BATOI_PRESS_STRIPE_WEBHOOK_SECRET` for the active mode.

Mode-specific environment values take precedence over saved encrypted values.
Saved test/live credentials remain separate so old test orders can still be
reconciled after switching the active storefront. Do not place private keys in
content, themes, plugin settings, examples or source control.

## Products and money

Products store schema 2 metadata, integer `price_minor`, an explicit currency
exponent and a compatible display `price` string. Money is parsed and calculated
without floats. Product saves require a current revision; a stock reservation
therefore invalidates an older editor form. SKU uniqueness is checked under the
storage lock. Inventory represents available units, excluding active holds.

The catalogue supports the explicitly enumerated zero-, two- and three-decimal
currencies in `Money::CURRENCIES`. The initial Stripe store profile supports the
zero- and two-decimal subset. Merchant-country, payment-method and charge-limit
availability still depends on Stripe; a three-letter code alone is insufficient.
See [Stripe currencies](https://docs.stripe.com/currencies).

Existing catalogues are not rewritten on read. From a development checkout:

```sh
php radpress/docs/examples/migrate-products.php
php radpress/docs/examples/migrate-products.php --apply
```

The first command reports compatible records and exceptions without changing
product content. Apply uses revision checks and per-record recoverable writes;
IDs, slugs and exact decimal values are retained. Unsupported precision,
currency or legacy SKU values require review. Existing schema 2 records are
skipped. Prior metadata/body snapshots remain in `data/versions/products/`.
Back up before applying a migration; a multi-record run can report partial
completion and be resumed.

## Customer journey

Published product pages link to a quantity selector. `/commerce/cart` supports
up to 20 different products and 1–999 units per item, with a zero quantity
removing a line. Guest checkout collects contact/delivery details. Prices,
stock, shipping and tax are recalculated by the server; submitted browser totals
are never authoritative.

Stripe hosts card entry. The adapter currently requests card payments and pins
API version `2026-10-28.endive`. A redirect back to the website does not mark an
order paid. The signed callback retrieves the session from the merchant's
Stripe API and verifies installation, order, session, currency, mode and amount.
The order status page is bound to the originating private browser session.
There is no customer-account/password or public order-number lookup.

## Recovery and operations

Orders live in private per-order files below `data/commerce/`. Customer details
are encrypted. Product stock and order/outbox effects share a journal and stable
locks; a prepared journal completes before ordinary product reads or writes.
Unknown external edits stop recovery for operator review. Never edit stock or
journal JSON manually to force recovery.

Stock holds last 35 minutes; Stripe sessions expire after 30 minutes. Relevant
checkout/payment requests release up to 20 expired holds per invocation; cron is
not required for correctness. Duplicate checkout identities and payment events
do not duplicate allocation or fulfilment. A late payment whose stock was
reallocated becomes `paid / needs_attention`, requiring merchant resolution.

**Admin → Orders** provides reference/status filters, pagination, private details,
revisioned fulfilment and reconciliation with Stripe. Initiate refunds in Stripe
and reconcile them here. Refunds do not automatically restock products. This
version does not initiate refunds, automate tax jurisdictions, provide customer
accounts or process subscriptions.

Paid orders commit a durable private outbox record. Automated merchant/customer
message delivery from this new outbox is not implemented yet; use the Stripe
receipt configuration and Orders during development acceptance. Do not treat a
pending outbox record as a delivered email.

Commerce state is excluded from release ZIPs. Existing runtime-update backups
are **not** complete business-data backups: operators must separately preserve
`data/commerce/`, product content, the host encryption key and configuration
while writes are quiesced. Runtime rollback preserves product files once orders
exist, so restoring application files cannot rewind available stock. An older
runtime may not process current payment callbacks; reconcile externally and
restore a compatible runtime. Restoring a data backup cannot reverse a payment.

Static export remains catalogue-only: exported product pages have no enabled
purchase controls, and cart/order/session data is not exported. A functional
store requires the live PHP endpoints.

## Required sandbox acceptance

- Successful and rejected payment, lost browser return, callback retry and
  provider outage after request acceptance.
- Correct totals/currency/mode and safe behavior for forged/stale callbacks.
- Concurrent last-unit purchases, expired holds, late payment and duplicate
  fulfilment attempts.
- Refund status reconciliation and merchant handling of stock exceptions.
- Private HTTP paths, encrypted-secret restore, actual low disk/interruption,
  accessibility and root/subdirectory hosting.

Run local integrity fixtures with `php radpress/tests/commerce.php`. They use
synthetic orders, an injected Stripe transport and signed fixture events; they
make no real model, email or payment calls. Provider behavior follows the
[Checkout API](https://docs.stripe.com/api/checkout/sessions/create) and
[fulfilment guidance](https://docs.stripe.com/checkout/fulfillment.md?ui=full-page).
