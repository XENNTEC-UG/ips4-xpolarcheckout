# X Polar Checkout Backlog

## Active Focus

This file tracks current implementation tasks. Canonical remote tracking remains GitHub issue `#1`.

## Phase 2 Blockers

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

- [ ] Finalize ACP settings schema for Polar credentials and endpoint fields.
- [ ] Validate gateway appears and saves correctly in ACP payment methods.
- [ ] Normalize settlement snapshot keys for customer and print hook output.

## Testing Tasks

- [ ] Local CLI webhook forwarding and signature-validation smoke tests.
- [ ] Manual paid checkout test in Nexus sandbox.
- [ ] Manual partial and full refund verification.
- [ ] Replay dry-run and live replay validation in ACP integrity panel.

## Documentation Tasks

- [ ] Keep `POLAR_GATEWAY_IMPLEMENTATION_PLAN.md` aligned with shipped changes.
- [ ] Add dated changelog entries for each merged milestone.
- [ ] Mirror important milestone updates into GitHub issue `#1` comments.
