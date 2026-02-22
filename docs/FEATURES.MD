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

## Guardrails

- Fail-safe webhook behavior prioritizing consistent transaction state.
- Strict URL and payload validation before persistence.
- Centralized event-type constants and replay bounds.
- ACP-safe error logging with forensic traceability.
- Checkout currency must align with Polar org default presentment currency when presentment currencies are disabled in the Polar org.
