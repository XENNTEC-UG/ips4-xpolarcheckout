# X Polar Checkout Features

`xpolarcheckout` connects IPS Commerce transactions to Polar hosted checkout. Checkout initiation creates the provider session and redirects the buyer. Signed webhooks are authoritative for payment capture, transaction reconciliation, settlement snapshots, and refund state updates.

## Current Version

| Version | Long Version | Source |
| --- | --- | --- |
| `1.0.20` | `10020` | `app-source/data/application.json` |

## Requirements

- IPS4 with Commerce (Nexus)
- PHP 8.1 or higher
- Polar organization access token, product ID, and Standard Webhooks secret

## Checkout and Gateway Behavior

- Registers `IPS\xpolarcheckout\XPolarCheckout` in the Nexus gateway map.
- Creates Polar hosted checkout sessions and redirects the buyer to the returned URL.
- Sets the Nexus transaction to gateway pending before session creation so its ID is available for metadata and the return URL.
- Stores the Polar checkout ID in `nexus_transactions.t_gw_id`, then replaces it with the Polar order ID when an order webhook supplies one.
- Converts amounts to minor units using the IPS currency decimal definition.
- Rejects checkout when the transaction currency differs from the configured presentment currency.
- Maps IPS packages to Polar products on demand through `xpc_product_map`, with the configured default product as fallback.
- Consolidates multi-item invoices into one Polar product because the checkout `products` collection represents choices rather than additive lines.
- Supports `first_item`, `invoice_count`, and `item_list` labels for consolidated checkouts.
- Can hide Polar for multi-item invoices through `checkValidity()`, with a second guard in `auth()`.
- Detects negative invoice items, creates a one-time Polar discount when the arithmetic matches the transaction total, and falls back to charging the transaction total when discount creation or validation fails.
- Allows Polar discount codes only when enabled, and disables them when an IPS discount is forwarded.
- Implements gateway refunds through the Polar refunds API and records refund response evidence in transaction metadata.

## Webhook Reconciliation

- Accepts `order.created`, `order.paid`, `order.updated`, `order.refunded`, `refund.created`, `refund.updated`, and `checkout.updated`.
- Resolves the Nexus transaction from Polar metadata before changing payment state.
- Validates Standard Webhooks headers with HMAC-SHA256 and a 600 second timestamp window.
- Normalizes `whsec_` secrets and supports hex, base64, and raw secret material.
- Records validation and processing failures in `xpc_webhook_forensics`.
- Uses the event ID list in `t_extra.xpolarcheckout_webhook_events` and a transaction lock to prevent duplicate concurrent processing.
- Runs `checkFraudRulesAndCapture()` for `order.paid`, paid `order.updated`, and succeeded `checkout.updated` events. The checkout redirect does not mark the invoice paid.
- Prevents terminal payment states from regressing to pending or refused.
- Applies partial or full refund states from succeeded refund events and order refund amounts.
- Stores normalized settlement snapshots on both the transaction and invoice.
- Requests Polar invoice generation after `order.paid`, then stores the customer invoice URL after an `order.updated` event reports generation complete.

## Settlement Data and Display

- Stores provider totals, tax, discounts, net amount, refunded amounts, currency, provider status, billing details, customer details, and provider references when present in webhook data.
- Compares the provider total with the IPS invoice total and stores mismatch fields for monitoring and display.
- Preserves a previously fetched customer invoice URL when a later snapshot lacks it.
- Adds a Polar charge summary and payment references to the front invoice view.
- Replaces the client invoice total block when a Polar snapshot exists.
- Adds settlement tables to printable invoices.
- Applies products subtotal and coupon presentation changes to invoice views through `Invoice\ViewHelper`.

## Product Mapping

- Creates a Polar product when a package has no row in `xpc_product_map`.
- Reuses the stored mapping for later checkout sessions.
- Synchronizes a mapped Polar product name when the IPS package name changes.
- Provides an ACP mapping table and a bulk name synchronization action.
- Caches synthetic label products for consolidated checkout labels in the IPS datastore, capped at 200 entries.

## Monitoring and Recovery

- Provides ACP integrity, forensics, and product mapping controllers.
- Reports webhook configuration, recent log errors, settlement mismatches, replay recency, and provider endpoint event drift in the integrity controller.
- Supports manual live replay and dry-run replay from the integrity controller.
- Runs `xpcWebhookReplay` every 15 minutes. It fetches failed Polar webhook deliveries, filters supported event types, deduplicates by event ID, and forwards signed payloads to the local webhook controller.
- Bounds replay by lookback, overlap, event count, page count, and runtime, then stores replay state in the IPS datastore.
- Runs `xpcIntegrityMonitor` every 5 minutes for local integrity notifications and daily pruning of forensic rows older than 90 days.
- Raises optional ACP notifications for recent webhook errors, stale replay execution, and settlement mismatches.
- Adds a Polar payment summary block to ACP member profiles with transaction count, refund count, last recorded history activity, and a link to the integrity controller.

## Local Development

- The main repository includes a `polar-cli` Docker service for forwarding Polar SSE webhook events to the local IPS endpoint.
- The main repository service can synchronize local gateway settings from environment variables.
- Upgrade package `upg_10001` reapplies hooks, modules, and tasks for partial installations.
