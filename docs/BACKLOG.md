# X Polar Checkout Backlog

## Active Focus

This file tracks current implementation tasks. Canonical remote tracking remains GitHub issue `#1`.

## Infrastructure (Done)

- [x] **Polar CLI Docker service** — local webhook forwarding via SSE tunnel.
  - Auto-syncs `webhook_secret`, `access_token`, `environment`, `default_product_id` from `.env` to DB.
  - Docker profile: `polar`. Runbook: `docs/POLAR_CLI_LOCAL_DEBUG.md`.
  - SSE secret is plain hex (no `whsec_` prefix) — signature verification must handle this.

## Phase 2 Blockers

- [x] **B1: Standard Webhooks signature verification**
  - `checkSignature()` now uses signed payload format `webhook-id.webhook-timestamp.raw-body`.
  - Supports both `whsec_` prefixed secrets and raw SSE tunnel secret format.
  - Uses base64 HMAC (`hash_hmac(..., TRUE)` + `base64_encode`) and `v1,<signature>` token parsing.
  - Enforces timestamp skew guard and forensic logging on invalid attempts.
  - File: `app-source/modules/front/webhook/webhook.php`.

- [x] **B2: Event map completion**
  - Implemented full webhook transition handlers for `order.created`, `order.paid`, `order.updated`, `order.refunded`, `checkout.updated`, and `refund.updated`.
  - Added status guardrails for idempotency and no terminal-state regression.
  - Added partial/full refund detection from Polar order/refund fields (`total_amount`, `refunded_amount`, refund `amount` fallback).
  - File: `app-source/modules/front/webhook/webhook.php` (2026-02-22).

- [ ] **B3: Checkout + refund provider paths**
  - Finish checkout session creation payload and redirect handoff.
  - Implement `refund()` using Polar refund API and map outcomes to IPS statuses.
  - In progress:
    - amount conversion now uses currency decimals and checkout `price_currency` uses lowercase enum style.
    - checkout payload fixed to Polar `prices[product_id]` list format and validated against sandbox API (`POST /v1/checkouts/` returns open checkout + URL).
    - refund payload schema validated against sandbox API (`POST /v1/refunds/` accepted fields, returns `Order not found` for unknown order id).
  - Remaining: execute full paid-order refund success test using a real sandbox paid order id (`gw_id`).

- [x] **B4: Replay pipeline rewrite**
  - Replaced placeholder task with Polar webhook delivery replay pipeline using `/v1/webhooks/deliveries`.
  - Filters by `REQUIRED_WEBHOOK_EVENTS`, dedupes by webhook event id, supports dry-run mode, and preserves replay cursor state.
  - Re-forwards payloads to local webhook endpoint with Standard Webhooks headers/signature generation.
  - Runtime guardrails implemented: lookback, overlap, max events per run, max pages, max runtime.
  - Files: `app-source/tasks/webhookReplay.php`, `app-source/sources/XPolarCheckout/XPolarCheckout.php`, `app-source/modules/admin/monitoring/integrity.php`.

## Integration Tasks

- [x] Gateway settings auto-configured from `.env` via polar-cli Docker service (no ACP needed for dev).
- [ ] Validate gateway appears and saves correctly in ACP payment methods.
  - Runtime check confirms gateway class resolves with expected settings from DB; ACP click-through verification still pending.
- [x] Normalize settlement snapshot keys for customer and print hook output.
  - `xpolarcheckout_snapshot` now persists normalized total/tax/subtotal/refund display fields and IPS comparison keys:
    - `amount_total_display`
    - `ips_invoice_total_display`
    - `total_difference_display`
    - `has_total_mismatch`
    - `total_mismatch_display`
  - File: `app-source/modules/front/webhook/webhook.php`.

## Testing Tasks

- [x] Local CLI webhook forwarding verified (polar-cli Docker service, events forward with 200).
- [ ] Signature-validation smoke test.
  - HTTP response checks passed against live endpoint:
    - missing signature -> `403 MISSING_SIGNATURE`
    - invalid signature -> `403 INVALID_SIGNATURE`
    - stale timestamp -> `403 INVALID_SIGNATURE`
  - Remaining: verify forensic row persistence in `xpc_webhook_forensics` on this local install.
- [ ] Manual paid checkout test in Nexus sandbox.
- [ ] Manual partial and full refund verification.
- [x] Replay dry-run validation (task executes and returns structured result).
- [ ] Replay live validation in ACP integrity panel.
- [x] Snapshot normalization lint validation in container runtime (`php -l` on touched files).
- [x] Snapshot comparison runtime probe passed (`applyIpsInvoiceTotalComparison` behavior verified in IPS runtime).

## Documentation Tasks

- [x] Keep `POLAR_GATEWAY_IMPLEMENTATION_PLAN.md` aligned with shipped changes.
- [x] Add dated changelog entries for each merged milestone.
- [x] Mirror important milestone updates into GitHub issue `#1` comments.
