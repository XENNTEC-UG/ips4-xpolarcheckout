# X Polar Checkout Flow

This document tracks runtime flows and entry points for IPS app `xpolarcheckout`.

**Related docs:**
- [README.md](README.md) - entrypoint + source paths
- [GitHub Issues](https://github.com/XENNTEC-UG/ips4-xpolarcheckout/issues) — open work items
- [TEST_RUNTIME.md](TEST_RUNTIME.md) - runtime checks
- [GitHub Releases](https://github.com/XENNTEC-UG/ips4-xpolarcheckout/releases) - version history

## 1. Purpose

Adds a Polar Checkout payment gateway implementation for IPS Nexus with hosted checkout, webhook reconciliation, and operational tooling.

## 2. Main Entry Points

- Gateway class:
  - `app-source/sources/XPolarCheckout/XPolarCheckout.php`
- Invoice view helper:
  - `app-source/sources/Invoice/ViewHelper.php`
- Webhook controller:
  - `app-source/modules/front/webhook/webhook.php`
- ACP integrity panel:
  - `app-source/modules/admin/monitoring/integrity.php`
- ACP forensics viewer:
  - `app-source/modules/admin/monitoring/forensics.php`
- ACP product mappings:
  - `app-source/modules/admin/monitoring/products.php`
- Webhook replay task:
  - `app-source/tasks/xpcWebhookReplay.php`
- Integrity monitor task:
  - `app-source/tasks/xpcIntegrityMonitor.php`
- Hook registration:
  - `app-source/data/hooks.json`
  - `app-source/hooks/code_GatewayModel.php`
  - `app-source/hooks/theme_sc_clients_settle.php`
  - `app-source/hooks/theme_sc_print_settle.php`
  - `app-source/hooks/code_memberProfileTab.php`
  - `app-source/hooks/invoiceViewHook.php`
  - `app-source/hooks/couponNameHook.php`
- App metadata:
  - `app-source/data/application.json`
  - `app-source/data/modules.json`
  - `app-source/data/tasks.json`

## 3. Runtime Flow

### Checkout/Auth

1. `XPolarCheckout::auth()` creates a Polar checkout session via Polar API.
2. Session metadata includes IPS transaction/invoice/member references.
3. Browser is redirected to Polar-hosted checkout page.
4. For multi-item invoices, items are consolidated into a single Polar line item with configurable label mode.
5. Optional IPS coupon forwarding creates one-time Polar discounts attached to the checkout.
6. Amounts are converted to minor units using IPS currency decimal precision (no fixed 2-decimal assumption).

### Webhook Processing

Webhook endpoint:
- `index.php?app=xpolarcheckout&module=webhook&controller=webhook`

Handled events in `modules/front/webhook/webhook.php`:
- `order.paid` — capture payment, persist settlement snapshot, trigger Polar invoice generation
- `order.created` — gateway pending state (unless terminal)
- `order.updated` — paid/refunded/partially_refunded state transitions, invoice URL fetch
- `order.refunded` — full refund status update
- `refund.created` — acknowledged without state change (no-op)
- `refund.updated` — partial/full refund based on amounts
- `checkout.updated` — failed/expired checkout → STATUS_REFUSED

Security layers:
- Standard Webhooks signature verification (`webhook-id`, `webhook-timestamp`, `webhook-signature` headers)
- HMAC-SHA256 with hex-secret normalization for Polar CLI tunnels (strip `whsec_` → hex decode → base64 fallback)
- Timestamp freshness enforcement (600s window)
- Event-id-based idempotency via `t_extra.xpolarcheckout_webhook_events` (last 50 event IDs)
- Terminal-state guardrails prevent regression of paid/refunded transactions
- Webhook validation failures persisted to `xpc_webhook_forensics` for ACP audit

### Settlement Snapshot Persistence

On `order.paid`, the webhook persists a snapshot to:
- `nexus_transactions.t_extra['xpolarcheckout_snapshot']`
- `nexus_invoices.i_status_extra['xpolarcheckout_snapshot']`

Snapshot includes: totals (subtotal/tax/total), discount details (amount/name/code), net amount, refund amounts, billing info (name/reason/email), customer tax IDs, Polar invoice number, provider status, and IPS-vs-provider mismatch detection.

### Settlement Display (Hooks)

- `invoiceViewHook` — two-column invoice view with Polar charge summary + payment references (Polar order ID, checkout ID, invoice/PDF/receipt links, billing details)
- `theme_sc_clients_settle` — settlement block on customer invoice page with provider total vs IPS total
- `theme_sc_print_settle` — settlement block on printable invoice with charge summary and payment references

### Invoice View Enhancements (Global)

The `invoiceViewHook` also applies global enhancements to all invoices (not just Polar):
- Products subtotal row inserted before coupon items
- Coupon items display tag icon and green pricing
- Duplicate coupon section hidden via CSS
- Idempotency guard prevents double-enhancement when both Stripe and Polar hooks are active

### Webhook Replay (Outage Recovery)

- Task: `xpcWebhookReplay` (every 15 minutes)
- Fetches failed webhook deliveries from Polar `/v1/webhooks/deliveries` API
- Filters to required event types and deduplicates by event ID
- Forwards payloads with valid Standard Webhooks headers/signature
- Relies on existing idempotency guards to prevent duplicate state transitions
- State cursor persisted in datastore key `xpolarcheckout_webhook_replay_state`
- Manual trigger and dry-run available via ACP integrity panel

### ACP Integrity Panel

- Module: `app=xpolarcheckout&module=monitoring&controller=integrity`
- Displays: environment badge (sandbox/production), webhook config status, replay task health, error count (24h), mismatch count (30d/all-time), webhook endpoint events drift
- Actions: manual replay trigger, dry-run replay, acknowledge/delete errors, sync webhook events

### Dynamic Product Mapping

- Products are created on-demand in Polar when IPS packages are first purchased via this gateway
- `xpc_product_map` table tracks IPS package ID → Polar product ID mappings
- ACP product mappings viewer at `app=xpolarcheckout&module=monitoring&controller=products`
- Bulk "Sync All Names" action patches Polar product names to match current IPS package names

## 4. Known Implementation Notes

- Gateway is injected via hook into `\IPS\nexus\Gateway::gateways()`.
- Polar API base URLs: production `https://api.polar.sh/v1`, sandbox `https://sandbox-api.polar.sh/v1`.
- Polar API trailing slash behavior varies by endpoint — creation endpoints use trailing slashes (`/products/`, `/checkouts/`), while list/read endpoints use no trailing slash.
- Default presentment currency is synced to Polar org via API on gateway settings save.
- Checkout currency must match the configured presentment currency or auth() returns an error.
- Replay task uses Polar webhook deliveries API (not events API like Stripe) — fetches failed deliveries for replay.
- Forensics entries auto-pruned after 90 days by the integrity monitor task (daily check).
