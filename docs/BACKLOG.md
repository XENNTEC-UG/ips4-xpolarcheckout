# X Polar Checkout Backlog

## Active Focus

This file tracks current implementation tasks. Canonical remote tracking remains GitHub issue `#1`.

## User Test Required

- [ ] **Polar token scope update**
  - Current configured token can create checkouts but cannot manage webhook endpoints (`401 Unauthorized`) or products.
  - Generate/update a Polar organization access token that includes `webhooks:read`, `webhooks:write`, `products:read`, and `products:write` scopes.
  - Re-save the gateway in ACP and verify `webhook_endpoint_id` is persisted.
  - Without `products:write`, dynamic product mapping falls back to default product silently.

- [x] **Manual paid checkout (sandbox)** — verified 2026-02-23
  - Invoice #102, Transaction #79 reached `okay` (paid) via Polar sandbox hosted checkout.
  - 7 webhook events processed. `t_gw_id` updated to Polar order UUID `637ac7ff-...`.
  - Settlement snapshot captured with `has_total_mismatch: false`.

- [ ] **Manual refund flow (sandbox)**
  - Partial refund from IPS/Nexus path -> transaction becomes part-refunded.
  - Full remaining refund -> transaction becomes refunded.

## Multi-Cart Strategy Tracking

- [x] **Implemented interim controls (ACP)**
  - `Checkout flow mode`:
    - allow Polar for all carts
    - hybrid: single-item carts only
  - `Multi-item receipt label mode`:
    - first item (legacy)
    - invoice + item count
    - explicit item-list label

- [x] **Implemented consolidated multi-item fallback**
  - Multi-item invoices are sent as one payable Polar line (combined total), while IPS/Nexus remains itemized source-of-truth.

- [ ] **External dependency (Polar platform)**
  - Await native additive multi-line cart/invoice support from Polar.
  - Once available, replace consolidation fallback with true provider-side line-item checkout and invoice parity.

## Agent-Executable Open Tasks

- [ ] **B3 completion evidence (real paid-order refund success)**
  - One end-to-end successful refund call is still required against a real paid Polar order id created through Nexus runtime. Transaction #79 (order `637ac7ff-f516-4bf3-ba5b-c4e230b9ced5`) is now available for this test.

## Agent Validation Completed (Latest)

- [x] MCP retest pass completed: MCP tools are operational in current session (Context7, DuckDuckGo, UniFi checks).
- [x] ACP click-through verification passed via MCP browser:
  - `Payment Methods` page shows `X Polar Checkout` option in create flow.
  - Existing `Polar Checkout` gateway edit/save succeeds (`Saved` toast).
  - Integrity actions `Dry Run` and `Run Webhook Replay Now` execute successfully.
- [x] Gateway registration runtime check passed (`\IPS\nexus\Gateway::gateways()` + roots resolve `XPolarCheckout`).
- [x] Replay task runtime checks passed:
  - dry run returns structured result (`count`).
  - live run executes and updates replay state.
- [x] Signature smoke responses validated:
  - missing signature -> `403 MISSING_SIGNATURE`
  - invalid signature -> `403 INVALID_SIGNATURE`
  - stale timestamp -> `403 INVALID_SIGNATURE`
- [x] Signature key-normalization hardening shipped and validated:
  - Hex secrets are now normalized to raw bytes before base64 fallback in:
    - `app-source/modules/front/webhook/webhook.php`
    - `app-source/tasks/webhookReplay.php`
  - Verification proof (2026-02-22):
    - HMAC using hex-key bytes -> `200 SUCCESS`
    - HMAC using incorrect base64-decoded key path -> `403 INVALID_SIGNATURE`
- [x] Webhook hardening fix shipped:
  - invalid/missing gateway method on transaction now fails cleanly as `INVALID_GATEWAY_SETTINGS` instead of 500.
  - File: `app-source/modules/front/webhook/webhook.php`.
- [x] Forensics table verification completed locally:
  - Re-applied app schema via `installDatabaseSchema()` in runtime.
  - Confirmed `xpc_webhook_forensics` now exists and persists signature failures.
  - Observed rows for `missing_signature`, `invalid_signature`, and `timestamp_too_old`.
- [x] Polar sandbox API contract checks (latest run):
  - refund payload schema (valid UUIDv4 + reason enum) -> provider returns expected `Order not found` for unknown order.
- [x] ACP presentment-currency setting shipped and validated:
  - added `Default presentment currency` setting to gateway ACP form.
  - save path now syncs Polar org `default_presentment_currency` via API and stores normalized `organization_id`/currency metadata.
  - gateway validity now returns `xpolarcheckout_presentment_currency_mismatch` when transaction currency differs from configured presentment currency.
  - checkout payload now includes explicit top-level `currency`.
  - local runtime validation confirms EUR checkout payload succeeds after sync.
- [x] Webhook endpoint lifecycle implementation completed in gateway class:
  - `testSettings()` now attempts webhook endpoint creation when `webhook_endpoint_id` is empty and stores returned endpoint id.
  - `syncWebhookEvents()` now performs real provider PATCH to `/v1/webhooks/endpoints/{id}` with `REQUIRED_WEBHOOK_EVENTS`.
  - `fetchWebhookEndpoint()` now supports fallback endpoint discovery by matching configured `webhook_url` against provider endpoint list when id is missing (backfill-compatible).
  - endpoint API errors now surface via structured runtime exceptions and log category `xpolarcheckout_webhook_endpoint`.
- [x] Runtime API verification (2026-02-22):
  - `POST /v1/checkouts/` from runtime token: **201 Created**.
  - `GET /v1/webhooks/endpoints/` from same token: **401 Unauthorized**.
  - `testSettings()` now logs endpoint creation failures instead of failing silently when provider rejects endpoint operations.

## Archive

- Completed phase/blocker details moved to `docs/archive/BACKLOG_ARCHIVE.md`.
