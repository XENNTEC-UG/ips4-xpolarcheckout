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
  - Hex-secret compatibility: signatures built with hex-key bytes validate correctly.

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
  - Snapshot includes normalized settlement keys:
    - `amount_total_display`
    - `ips_invoice_total_display`
    - `total_difference_display`
    - `has_total_mismatch`
    - `total_mismatch_display`
  - Snapshot includes core event/provider keys:
    - `event_type`
    - `provider_status`
    - `order_id`
    - `amount_total_minor`
    - `amount_refunded_minor`

## Pending Suite Expansion

- Webhook endpoint lifecycle end-to-end verification in ACP (blocked by current token permissions on `/v1/webhooks/endpoints/*`).
- B3 end-to-end paid checkout + successful refund validation (real sandbox paid order fixture).
- Full checkout/refund runtime matrix with non-EUR test fixture (to confirm mismatch guard UX/messages in storefront checkout flow).

## Automated Checks Already Executed

- Gateway registration runtime check (`XPolarCheckout` appears in gateway map and roots).
- Replay task dry-run + live-run execution checks.
- ACP integrity action checks:
  - `Dry Run` action message confirmed.
  - `Run Webhook Replay Now` action message confirmed.
- Signature response checks (`missing`, `invalid`, `stale`).
- Signature normalization checks:
  - hex-key HMAC accepted (`200 SUCCESS`)
  - incorrect base64-decoded-key HMAC rejected (`403 INVALID_SIGNATURE`)
- Forensics persistence checks:
  - `xpc_webhook_forensics` table exists.
  - rows persisted for `missing_signature`, `invalid_signature`, and `timestamp_too_old`.
- Polar sandbox API contract checks:
  - refund schema accepted (unknown order id returns expected provider error).
  - checkout request accepted after org currency sync (`201` with EUR-only price payload).
  - webhook endpoint APIs currently return `401 Unauthorized` with current token (`GET/POST /v1/webhooks/endpoints/`), while checkout APIs remain authorized.
- ACP currency-setting sync checks:
  - `Default presentment currency` setting added to gateway configuration.
  - `testSettings()` syncs Polar org `default_presentment_currency` and persists `organization_id`.
  - mismatch guard validated: non-matching transaction currency returns `xpolarcheckout_presentment_currency_mismatch`.
- Webhook endpoint lifecycle code checks:
  - `testSettings()` attempts endpoint creation when `webhook_endpoint_id` is empty.
  - `syncWebhookEvents()` issues provider PATCH with required event set.
  - provider errors are now logged/surfaced instead of silent no-op behavior.
