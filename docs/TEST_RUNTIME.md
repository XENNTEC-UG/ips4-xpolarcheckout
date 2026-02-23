# X Polar Checkout Runtime Verification

Current runtime baseline is `v1.0.3` / `10003`.

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

## End-to-End Paid Checkout (v1.0.2, 2026-02-23)

- Product: MASSITEM3 (1.00 EUR), Invoice #102, Transaction #79
- Flow: Store → Cart → Checkout → Polar sandbox hosted checkout → test card (4242) → payment success → webhook processing → transaction `okay` (paid)
- 7 webhook events processed: `checkout.created`, `checkout.updated` (x2), `order.updated` (x2), `order.paid`, `order.created`
- `t_gw_id` updated from checkout UUID to order UUID (`637ac7ff-f516-4bf3-ba5b-c4e230b9ced5`)
- Settlement snapshot: subtotal EUR 1.00, tax EUR 0.19, total EUR 1.19, `has_total_mismatch: false`, `total_difference_tax_explained: true`

## Dynamic Product Mapping + Multi-Item Checkout (v1.0.3, 2026-02-23)

### Single-Item (previous session)
- Product: MASSITEM3 (1.00 EUR), Invoice #104, Transaction #81
- Polar checkout displayed "MASSITEM3" instead of "IPS4 NEXUS DEFAULT"
- `xpc_product_map` row created: package 8 → Polar product `3187eb62-19a7-4878-959e-276d38bd91e0`
- Mapping reused on subsequent checkout (no duplicate product creation)

### Multi-Item Consolidation
- Cart: Premium 30D (15 EUR) + Starter 7D (5 EUR) + MASSITEM5 (1 EUR) = 21 EUR
- Invoice #106, Transaction #83
- **Finding**: Polar treats multiple products in `products[]` as radio-button choices, not line items. Fix: consolidate multi-item invoices into the first product with combined total.
- Polar checkout displayed: "Premium 30D" at €21 subtotal (correct combined amount)
- Transaction #83: status `okay` (paid), amount 21.00 EUR
- All 3 purchases created: #239 (Premium 30D), #240 (Starter 7D), #241 (MASSITEM5)
- `xpc_product_map` has 4 rows total: MASSITEM3, Premium 30D, Starter 7D, MASSITEM5
- ACP Product Mappings viewer shows all 4 entries with correct names, Polar IDs, and timestamps

## Checkout Flow Controls + Label Modes (Unreleased, 2026-02-23)

### Hybrid Route Mode
- ACP setting `Checkout flow mode` supports:
  - `Allow Polar for all carts`
  - `Hybrid route: show Polar only for single-item carts`
- In hybrid mode:
  - single-item invoice -> Polar appears in checkout payment methods.
  - multi-item invoice -> Polar is hidden from checkout payment methods.
- Defense-in-depth: if a multi-item payment attempts to force Polar in hybrid mode, gateway returns `xpolarcheckout_checkout_flow_single_item_only_error`.

### Multi-Item Label Modes
- ACP setting `Multi-item receipt label mode` supports:
  - first item (legacy),
  - neutral invoice/count label,
  - explicit item-list label.
- Non-legacy label modes create/reuse synthetic Polar products for display labels (datastore key: `xpolarcheckout_checkout_label_products`).
- If product creation is unavailable (missing `products:write`), checkout falls back safely to mapped/legacy behavior.

## Pending Suite Expansion

- Webhook endpoint lifecycle end-to-end verification in ACP (blocked by current token permissions on `/v1/webhooks/endpoints/*`).
- End-to-end refund validation against paid order (Transaction #79, order `637ac7ff-f516-4bf3-ba5b-c4e230b9ced5`).
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
