# X Polar Checkout Archive Backlog

This archive preserves completed migration checkpoints that no longer belong in active backlog tracking.

## Archived Completion Notes

### 2026-02-22 - Completed Core Migration Items

- B1: Standard Webhooks signature verification completed.
  - Signed payload format: `webhook-id.webhook-timestamp.raw-body`.
  - Supports both `whsec_` and SSE tunnel plain-secret formats.
  - Base64 HMAC + `v1,<signature>` parsing shipped.

- B2: Event map completion completed.
  - Transition handlers shipped for:
    - `order.created`
    - `order.paid`
    - `order.updated`
    - `order.refunded`
    - `checkout.updated`
    - `refund.updated`
  - Terminal-state guardrails and idempotency behavior implemented.

- B4: Replay pipeline rewrite completed.
  - Polar `/v1/webhooks/deliveries` source wired with pagination.
  - Event-family filtering (`REQUIRED_WEBHOOK_EVENTS`) + dedupe by event id.
  - Dry-run and live replay both implemented.
  - Replay cursor persistence + runtime guardrails implemented.

- Settlement snapshot normalization completed.
  - Snapshot now includes provider/IPS comparison keys used by integrity mismatch reporting.
  - Snapshot write failures are logged under `xpolarcheckout_snapshot`.

- Infrastructure: Polar CLI Docker service completed.
  - SSE tunnel forwarding operational.
  - Gateway settings auto-sync from `.env` on service start.

## Archive Intent

- Keep active work in `docs/BACKLOG.md`.
- Keep shipped outcomes in `docs/CHANGELOG.md`.
- Keep detailed implementation sequencing in `docs/POLAR_GATEWAY_IMPLEMENTATION_PLAN.md`.

This file is intentionally concise and historical only.
