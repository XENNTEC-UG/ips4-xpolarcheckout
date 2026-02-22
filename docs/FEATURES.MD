# X Polar Checkout Features

> Polar payment gateway for IPS Nexus focused on reliable capture, reconciliation, and operational visibility.

## Version Snapshot

| Package version | Long version | Source |
| --- | --- | --- |
| `1.0.1` | `10001` | `app-source/data/versions.json` |

## Product Positioning

`xpolarcheckout` provides a maintainable IPS-native payment layer around Polar-hosted checkout and webhook-driven reconciliation.

## Feature Pillars

| Pillar | What it enables | Business impact |
| --- | --- | --- |
| Hosted checkout orchestration | Redirect-based checkout start from Nexus transactions | Faster customer payment flow with reduced PCI exposure |
| Webhook-driven reconciliation | Event handling that maps provider state to IPS transaction state | Consistent finance records between provider and IPS |
| Idempotent processing | Signature checks, replay protection, event tracking, lock guards | Safer handling of duplicate or delayed deliveries |
| Settlement evidence snapshots | Durable capture of order/refund/tax evidence on transaction metadata | Better support and accounting diagnostics |
| Integrity operations center | ACP integrity panel, replay controls, and forensics visibility | Faster incident detection and response |
| Recovery tooling | Scheduled/manual replay paths plus explicit run telemetry | Lower revenue risk during webhook outages |

## Capability Status

### Implemented
- Gateway app scaffold and Nexus gateway registration path.
- Webhook entrypoint and forensics persistence with 90-day retention cleanup.
- Signature freshness and verification hardening (Standard Webhooks), including hex-secret handling for Polar CLI local tunnels.
- Transaction-level idempotency and processing guardrails.
- ACP integrity module with environment badge (sandbox/production).
- Polar checkout creation API flow with currency-aware minor-unit conversion.
- ACP presentment-currency control that syncs Polar org default presentment currency on save.
- Polar event map: `order.updated`, `order.refunded`, `checkout.updated`, `refund.updated` with safe state transition helpers and terminal-state guardrails.
- Polar refund API integration with sandbox-validated request schema.
- Replay pipeline sourced from Polar `/v1/webhooks/deliveries` API with deduplication, dry-run mode, and persisted replay cursor.
- Configurable replay guardrails: lookback, overlap, and max events with clamped bounds.
- Settlement snapshot schema with display keys, IPS-vs-provider total comparison, and mismatch detection.
- Recovery upgrade package for partial installations.
- Polar CLI Docker service for local webhook forwarding via SSE endpoint with auto-synced gateway settings.

### Post-MVP targets
- Expanded analytics cards in integrity panel.
- Enhanced member profile payment summary blocks.
- Additional alert channels for persistent payment integrity faults.

## Capability Highlights

### Payment flow and gateway behavior
- Creates Polar hosted checkout sessions from Nexus transactions with redirect-based flow.
- Currency-aware minor-unit conversion using IPS `\IPS\nexus\Money::numberOfDecimalsForCurrency()` and `\IPS\Math\Number` (no fixed 2-decimal assumption).
- Checkout payload formatted as `prices[product_id] = [ { ...price override... } ]` per Polar API requirements.
- Coupon name hook for Polar-compatible discount handling.

### Webhook reliability and safety
- Standard Webhooks signature verification with hex-secret normalization for Polar CLI local tunnels (strip `whsec_` → hex-byte decode → base64 fallback → raw fallback).
- Timestamp freshness enforcement (600s default window).
- Event-id-based idempotency — duplicate deliveries return 200 without state mutation.
- Terminal-state guardrails prevent regression of already-paid or already-refunded transactions.
- Invalid gateway reference guard returns `INVALID_GATEWAY_SETTINGS` (HTTP 400) instead of triggering PHP 500.
- Webhook validation failures persisted to `xpc_webhook_forensics` for ACP security audit with 90-day auto-cleanup.

### Settlement and reconciliation
- Normalized settlement snapshot stored in transaction `t_extra` and invoice `i_status_extra`.
- Provider-vs-IPS total comparison with display keys (`amount_total_display`, `ips_invoice_total_display`, `total_difference_display`).
- Mismatch detection flags (`has_total_mismatch`) for quick operational review.
- Subtotal, tax, and refund display fields persisted when available from provider payload.
- Settlement details rendered on client and print invoice views via theme hooks.

### Monitoring and recovery
- ACP integrity panel with environment badge (sandbox/production), replay status, and health indicators.
- Replay pipeline sourced from Polar `/v1/webhooks/deliveries` API with deduplication and persisted cursor.
- Dry-run mode for safe replay testing without state changes.
- Configurable replay guardrails: lookback window, overlap, and max events per run with clamped bounds.
- Integrity monitor task (5-min interval) for automated health checks.
- Admin notification extension surfaces persistent payment integrity problems to ACP users.
- ACP forensics viewer for webhook failure audit trail.

### Local development
- Polar CLI Docker service (`polar` profile) for local webhook forwarding via Polar SSE endpoint.
- Auto-syncs gateway settings (`webhook_secret`, `access_token`, `environment`, `default_product_id`) from `.env` to `nexus_paymethods` on every container start.
- No manual ACP configuration needed for local dev — `.env` is the single source of truth.
- Recovery upgrade package (`upg_10001`) reapplies hooks/modules/tasks for partially installed environments.

## Built-In Guardrails

- Fail-safe webhook behavior prioritizing consistent transaction state.
- Strict URL and payload validation before persistence.
- Centralized event-type constants and replay bounds.
- ACP-safe error logging with forensic traceability.
- Checkout currency must align with Polar org default presentment currency.

## Ideal Use Cases

- IPS stores using Polar as their payment provider for subscriptions or one-time products.
- Teams migrating from Stripe to Polar who want parity in operational visibility and reconciliation tooling.
- Admin teams that need webhook reliability guarantees, replay recovery, and forensic audit trails.
