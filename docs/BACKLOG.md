# X Polar Checkout Backlog

## Active Focus

This file tracks current implementation tasks. Canonical remote tracking remains GitHub issue `#1`.

## Infrastructure (Done)

- [x] **Polar CLI Docker service** — local webhook forwarding via SSE tunnel.
  - Auto-syncs `webhook_secret`, `access_token`, `environment`, `default_product_id` from `.env` to DB.
  - Docker profile: `polar`. Runbook: `docs/POLAR_CLI_LOCAL_DEBUG.md`.
  - SSE secret is plain hex (no `whsec_` prefix) — signature verification must handle this.

## Phase 2 Blockers

- [ ] **B1 CRITICAL: Webhook signature verification bugs**
  - `checkSignature()` in `webhook.php` has 4 bugs from the Stripe-to-Polar migration:
    1. Missing `msg_id` in signed payload (Standard Webhooks format: `msg_id.timestamp.body`)
    2. Secret not base64-decoded before HMAC
    3. Hash output in hex instead of base64
    4. Wrong delimiter (comma instead of space) in signed content
  - Must fix before any end-to-end payment testing will pass.
  - Note: SSE tunnel secret is plain hex, not `whsec_` prefixed — handle both formats.

- [x] **B2: Event map completion**
  - Implemented full webhook transition handlers for `order.created`, `order.paid`, `order.updated`, `order.refunded`, `checkout.updated`, and `refund.updated`.
  - Added status guardrails for idempotency and no terminal-state regression.
  - Added partial/full refund detection from Polar order/refund fields (`total_amount`, `refunded_amount`, refund `amount` fallback).
  - File: `app-source/modules/front/webhook/webhook.php` (2026-02-22).

- [ ] **B3: Checkout + refund provider paths**
  - Finish checkout session creation payload and redirect handoff.
  - Implement `refund()` using Polar refund API and map outcomes to IPS statuses.
  - In progress: amount conversion now uses currency decimals and checkout `price_currency` now uses lowercase enum style for Polar API compatibility.

- [ ] **B4: Replay pipeline rewrite**
  - Replace current replay source with Polar delivery/event retrieval.
  - Keep runtime guardrails (lookback, overlap, max events, max runtime).

## Integration Tasks

- [x] Gateway settings auto-configured from `.env` via polar-cli Docker service (no ACP needed for dev).
- [ ] Validate gateway appears and saves correctly in ACP payment methods.
- [ ] Normalize settlement snapshot keys for customer and print hook output.

## Testing Tasks

- [x] Local CLI webhook forwarding verified (polar-cli Docker service, events forward with 200).
- [ ] Signature-validation smoke test (blocked on B1 fix).
- [ ] Manual paid checkout test in Nexus sandbox.
- [ ] Manual partial and full refund verification.
- [ ] Replay dry-run and live replay validation in ACP integrity panel.

## Documentation Tasks

- [ ] Keep `POLAR_GATEWAY_IMPLEMENTATION_PLAN.md` aligned with shipped changes.
- [ ] Add dated changelog entries for each merged milestone.
- [ ] Mirror important milestone updates into GitHub issue `#1` comments.
