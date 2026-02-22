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

### Already implemented
- Gateway app scaffold and Nexus gateway registration path.
- Webhook entrypoint and forensics persistence.
- Signature freshness and verification hardening.
- Transaction-level idempotency and processing guardrails.
- ACP integrity module and replay task skeleton.

### In active implementation
- Polar checkout creation API flow.
- Polar event map finalization (`order.*`, `refund.*`, settlement updates).
- Polar refund API integration in gateway `refund()` path.
- Replay source rewrite to Polar delivery/event APIs.

### Post-MVP targets
- Expanded analytics cards in integrity panel.
- Enhanced member profile payment summary blocks.
- Additional alert channels for persistent payment integrity faults.

## Guardrails

- Fail-safe webhook behavior prioritizing consistent transaction state.
- Strict URL and payload validation before persistence.
- Centralized event-type constants and replay bounds.
- ACP-safe error logging with forensic traceability.
