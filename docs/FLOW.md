# Stripe Checkout App Flow

This document tracks architecture and runtime flow for IPS app `xpolarcheckout`.

**Related docs:**
- [README.md](README.md) - entrypoint + install workflow
- [BACKLOG.md](BACKLOG.md) - active tasks
- [BACKLOG_ARCHIVE.md](BACKLOG_ARCHIVE.md) - completed backlog history
- [TEST_RUNTIME.md](TEST_RUNTIME.md) - runtime checks
- [CHANGELOG.md](CHANGELOG.md) - completed work history
- [../../../../AI_TOOLS.md](../../../../AI_TOOLS.md) - tools and session workflow

## 1. Purpose

Adds a Stripe Checkout payment gateway implementation for IPS Nexus.

## 2. Main Entry Points

- Gateway class:
  - `ips-dev-source/apps/xpolarcheckout/app-source/sources/XPolarCheckout/XPolarCheckout.php`
- Webhook controller:
  - `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php`
- ACP integrity panel:
  - `ips-dev-source/apps/xpolarcheckout/app-source/modules/admin/monitoring/integrity.php`
- Webhook replay task:
  - `ips-dev-source/apps/xpolarcheckout/app-source/tasks/webhookReplay.php`
- Hook registration:
  - `ips-dev-source/apps/xpolarcheckout/app-source/data/hooks.json`
  - `ips-dev-source/apps/xpolarcheckout/app-source/hooks/code_GatewayModel.php`
  - `ips-dev-source/apps/xpolarcheckout/app-source/hooks/code_loadJs.php`
  - `ips-dev-source/apps/xpolarcheckout/app-source/hooks/theme_sc_clients_settle.php`
  - `ips-dev-source/apps/xpolarcheckout/app-source/hooks/theme_sc_print_settle.php`
- App metadata:
  - `ips-dev-source/apps/xpolarcheckout/app-source/data/application.json`
  - `ips-dev-source/apps/xpolarcheckout/app-source/data/modules.json`
  - `ips-dev-source/apps/xpolarcheckout/app-source/data/tasks.json`
  - `ips-dev-source/apps/xpolarcheckout/app-source/data/build.xml`

## 3. Runtime Flow

### Checkout/Auth

1. `XPolarCheckout::auth()` creates Stripe Checkout Session via Stripe API.
2. Session metadata includes IPS transaction/invoice/member references.
3. Browser is redirected to Stripe-hosted checkout via JS `stripe.redirectToCheckout()`.
4. Line items are built per-Nexus-invoice-item with fallback to a single summary line (`buildStripeLineItems()`).
5. Amounts are converted to Stripe minor units using `moneyToStripeMinorUnit()` (no float math).

### Webhook Processing

Webhook endpoint:
- `index.php?app=xpolarcheckout&module=webhook&controller=webhook`

Handled events in `modules/front/webhook/webhook.php`:
- `checkout.session.completed` — capture payment, persist Stripe snapshot
- `checkout.session.async_payment_succeeded` — capture deferred payment
- `checkout.session.async_payment_failed` — mark refused
- `charge.refunded` — partial/full refund status update
- `charge.dispute.created` — mark disputed, revoke benefits, admin notification
- `charge.dispute.closed` — resolve dispute (lost=refunded, won=paid)

Security layers:
- `Stripe-Signature` header required (403 if missing)
- `checkSignature()` validates HMAC-SHA256 with defensive parsing and multi-v1 support
- Webhook event idempotency via `t_extra.xpolarcheckout_webhook_events` (keeps last 50 event IDs)
- Gateway null-guard for charge/dispute events (log + return 200 if gateway not found)
- Transaction lookup exceptions (`UnderflowException`/`OutOfRangeException`) return 200 `TRANSACTION_NOT_FOUND`

### Stripe Snapshot Persistence

On `checkout.session.completed`, the webhook persists a snapshot to:
- `nexus_transactions.t_extra['xpolarcheckout_snapshot']`
- `nexus_invoices.i_status_extra['xpolarcheckout_snapshot']`

Snapshot includes: Stripe totals, tax breakdown, taxability reasons, customer-facing URLs (validated via `normalizePublicUrl()`), dashboard URLs, and IPS-vs-Stripe total mismatch detection.

### Settlement Display (Theme Hooks)

- `theme_sc_clients_settle` — injects Stripe settlement block after `.cNexusInvoiceView` on customer invoice page
- `theme_sc_print_settle` — injects Stripe settlement block after `.ipsPrint > table` on printable invoice

### Webhook Replay (Outage Recovery)

- Task: `webhookReplay` (every 15 minutes)
- Fetches recent Stripe events via Events API
- Forwards supported event types to local webhook with valid HMAC signature
- Relies on existing idempotency guards to prevent duplicate state transitions
- State cursor persisted in datastore key `xpolarcheckout_webhook_replay_state`
- Manual trigger available via ACP integrity panel

### ACP Integrity Panel

- Module: `app=xpolarcheckout&module=monitoring&controller=integrity`
- Displays: webhook config status, replay task health/cursor, recent webhook errors (24h), Stripe-vs-IPS mismatch counts and detail rows
- Manual replay action with CSRF protection and ACP audit logging

## 4. Known Implementation Notes

- No custom DB tables/settings are defined in `data/settings.json` (empty).
- Gateway is injected via hook into `\IPS\nexus\Gateway::gateways()`.
- Stripe JS is conditionally loaded via `code_loadJs` hook.
- Stripe API version pinned to `2026-01-28.clover` across all code paths.
- JS output in `auth()` is encoded with `JSON_HEX_*` flags for defense-in-depth.
- Replay task uses IPS HTTP framework (`\IPS\Http\Url::external()->request()->post()`); TLS bypass is `IN_DEV`-only.
- URL values persisted into snapshot payloads are sanitized via `normalizePublicUrl()` (http/https scheme check, host validation).

