# X Polar Checkout Runtime Verification

Current runtime baseline is Phase 2 in-progress (`v1.0.1` / `10001`).

## Environment Preconditions

1. App `xpolarcheckout` is installed and enabled.
2. Gateway exists in ACP payment methods.
3. Gateway settings contain valid Polar credentials and webhook secret.
4. Local webhook forwarding is active (see `docs/POLAR_CLI_LOCAL_DEBUG.md`).

## Smoke Checks

1. Install/registration integrity
  - App enables cleanly.
  - Gateway appears in ACP add/select flow.
  - No schema errors for `xpc_webhook_forensics`.

2. Signature validation
  - Missing signature headers -> HTTP `403` + forensic row.
  - Invalid signature -> HTTP `403` + forensic row.
  - Stale timestamp (>300s) -> HTTP `403` + forensic row.

3. Event-map transitions (Phase 2 B2)
  - `order.created` -> transaction moves to `gateway pending` (unless terminal).
  - `order.paid` -> capture pipeline runs and transaction moves to paid flow.
  - `order.updated` with `status=paid` -> treated as paid path.
  - `order.updated` with `status=partially_refunded` -> `part refunded`.
  - `order.updated` with `status=refunded` -> `refunded`.
  - `checkout.updated` with `status=failed|expired` -> `refused` when non-terminal.
  - `refund.updated` with `status=succeeded` -> partial/full refund based on amounts.

4. Idempotency
  - Re-delivering the same `webhook-id` returns HTTP `200` and does not duplicate transitions.

5. Snapshot persistence
  - `xpolarcheckout_snapshot` is written on transaction and invoice extra data.
  - Snapshot includes `event_type`, `provider_status`, `order_id`, `amount_total_minor`, `amount_refunded_minor`.

## Pending Suite Expansion

- B3 sandbox payload validation for checkout/refund provider calls.
- B4 replay task implementation and runtime replay verification.
