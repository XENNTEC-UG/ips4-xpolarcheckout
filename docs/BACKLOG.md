# X Polar Checkout Backlog

## Active Focus

This file tracks current implementation tasks. Canonical remote tracking remains GitHub issue `#1`.

## User Test Required (Top Priority)

- [ ] **ACP gateway click-through verification**
  - In ACP, open Nexus payment methods and verify `X Polar Checkout` appears, can be selected, and settings save/reload correctly.
  - Verify integrity panel actions in ACP:
    - `Run Webhook Replay Now`
    - `Dry Run`
    - webhook endpoint sync action (if shown)

- [ ] **Manual paid checkout (sandbox)**
  - Complete a real hosted Polar checkout from Nexus invoice flow and confirm IPS transaction reaches paid state.
  - Confirm `t_gw_id` stores the Polar order id (UUIDv4).

- [ ] **Manual refund flow (sandbox)**
  - Partial refund from IPS/Nexus path -> transaction becomes part-refunded.
  - Full remaining refund -> transaction becomes refunded.

## Agent-Executable Open Tasks

- [ ] **MCP retest pass (infrastructure)**
  - Docker MCP gateway calls currently fail with `Transport closed` in this session.
  - Re-run MCP-based verification once gateway connectivity is restored.

- [ ] **Forensics table verification (`xpc_webhook_forensics`)**
  - Current local DB check reports table is missing (`COUNT(*) = 0` in `information_schema.tables`).
  - Re-run schema/app upgrade path so webhook forensic rows can persist.
  - Then re-run signature failure checks and verify forensic rows are written.

- [ ] **B3 completion evidence (real paid-order refund success)**
  - Sandbox API contract is validated, but still need one end-to-end successful refund call against a real paid Polar order id created through Nexus runtime.

## Agent Validation Completed (Latest)

- [x] Gateway registration runtime check passed (`\IPS\nexus\Gateway::gateways()` + roots resolve `XPolarCheckout`).
- [x] Replay task runtime checks passed:
  - dry run returns structured result (`count`).
  - live run executes and updates replay state.
- [x] Signature smoke responses validated:
  - missing signature -> `403 MISSING_SIGNATURE`
  - invalid signature -> `403 INVALID_SIGNATURE`
  - stale timestamp -> `403 INVALID_SIGNATURE`
- [x] Webhook hardening fix shipped:
  - invalid/missing gateway method on transaction now fails cleanly as `INVALID_GATEWAY_SETTINGS` instead of 500.
  - File: `app-source/modules/front/webhook/webhook.php`.
- [x] Polar sandbox API contract checks passed:
  - checkout payload -> returns `status=open` with checkout URL.
  - refund payload schema (valid UUIDv4 + reason enum) -> provider returns expected `Order not found` for unknown order.

## Archive

- Completed phase/blocker details moved to `docs/archive/BACKLOG_ARCHIVE.md`.
