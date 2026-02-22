# X Polar Checkout Backlog

## Active Focus

This file tracks current implementation tasks. Canonical remote tracking remains GitHub issue `#1`.

## User Test Required (Top Priority)

- [ ] **ACP currency setting confirmation**
  - In ACP gateway settings, confirm `Default presentment currency` is set to `EUR`.
  - Save once and verify no validation error is shown.

- [ ] **Manual paid checkout (sandbox)**
  - Complete a real hosted Polar checkout from Nexus invoice flow and confirm IPS transaction reaches paid state.
  - Confirm `t_gw_id` stores the Polar order id (UUIDv4).

- [ ] **Manual refund flow (sandbox)**
  - Partial refund from IPS/Nexus path -> transaction becomes part-refunded.
  - Full remaining refund -> transaction becomes refunded.

## Agent-Executable Open Tasks

- [ ] **Webhook endpoint lifecycle implementation**
  - Integrity panel currently reports endpoint not found:
    - `Webhook endpoint not found on Polar. Re-save gateway settings to create one.`
  - Current code only fetches endpoint when `webhook_endpoint_id` exists; creation/backfill path is incomplete.
  - Implement endpoint discovery/creation + persisted `webhook_endpoint_id`, then validate `syncEvents` path.

- [ ] **B3 completion evidence (real paid-order refund success)**
  - One end-to-end successful refund call is still required against a real paid Polar order id created through Nexus runtime.

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

## Archive

- Completed phase/blocker details moved to `docs/archive/BACKLOG_ARCHIVE.md`.
