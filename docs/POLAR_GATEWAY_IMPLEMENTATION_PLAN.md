# Polar Gateway Implementation Plan

## 1) Objective

Deliver a production-ready IPS4 Nexus payment gateway app (`xpolarcheckout`) backed by Polar hosted checkout and Polar webhooks, while preserving proven reliability patterns from the existing gateway architecture.

## 2) External References

- Polar overview: `https://polar.sh/docs/introduction`
- Polar PHP SDK: `https://polar.sh/docs/integrate/sdk/php`
- Polar CLI repository: `https://github.com/polarsource/cli`

## 3) Current Baseline (As Of 2026-02-22)

- App installs and enables in ACP.
- Version is `1.0.1` / `10001`.
- Gateway registration recovery path is available through `setup/upg_10001`.
- Webhook signature freshness hardening is implemented.
- Forensics table exists: `xpc_webhook_forensics`.
- Integrity ACP module and replay task scaffolding are present.

## 4) Reuse Strategy

### Keep (High Value, Low Risk)

- IPS app/module structure and routing.
- Transaction and invoice linkage model.
- Forensics storage and ACP visibility patterns.
- Idempotency and duplicate-delivery guards.
- Replay runtime guardrails (lookback/overlap/max events/runtime caps).
- Integrity panel shell, cards, and action workflow.

### Replace (Provider-Specific)

- Checkout session creation call and payload.
- Webhook signature verification implementation details.
- Event-to-status mapping logic.
- Refund provider API call in gateway `refund()`.
- Replay event source and cursor strategy.
- Snapshot extraction keys for settlement display.

## 5) Target Architecture

1. Nexus transaction enters gateway `auth()`.
2. Gateway requests Polar checkout session and receives redirect URL.
3. Customer pays in hosted checkout.
4. Polar webhook delivers authoritative order/refund state changes.
5. Webhook controller validates signature and timestamp window.
6. Mapper updates IPS transaction state and stores normalized snapshot.
7. Integrity panel surfaces health and replay diagnostics.
8. Replay task handles recovery when webhook deliveries were missed.

## 6) Provider Integration Design

### 6.1 Checkout Session

- Inputs:
  - IPS transaction id
  - invoice id
  - amount/currency
  - return/cancel URLs
  - member identity metadata
- Output:
  - provider checkout URL
  - provider checkout id
- Rules:
  - Do not mutate transaction state during session creation.
  - Persist provider checkout id in `t_extra` for traceability.

### 6.2 Webhook Verification

- Validate expected signature header presence.
- Parse and validate timestamp.
- Enforce drift tolerance window (default 600 seconds).
- Verify HMAC/signature against configured secret.
- Reject invalid payloads with forensic log entry.

### 6.3 Event Mapping

Map provider event families into IPS status transitions:

- Order paid -> `STATUS_PAID`
- Order failed/canceled -> `STATUS_REFUSED`
- Partial refund -> `STATUS_PART_REFUNDED`
- Full refund -> `STATUS_REFUNDED`

Invariants:

- Idempotent by provider event id.
- No terminal-state regression.
- Duplicate deliveries return success without double-processing.

### 6.4 Refunds

- `refund( $amount )` must call Polar refund endpoint with provider order reference.
- Convert amount using IPS currency decimal precision.
- Return provider refund id where available.
- Persist refund evidence snapshot in `t_extra`.

### 6.5 Replay

- Pull replay candidates from Polar delivery/event APIs.
- Filter to supported event families before forwarding.
- Preserve cursor and last-run timestamps in settings.
- Dry-run mode produces report without state mutation.

## 7) Data Contract

### 7.1 Transaction Metadata (`t_extra`)

Store normalized keys under a single namespace, for example:

- `xpolarcheckout_provider_event_ids` (recent ids)
- `xpolarcheckout_settlement` (order totals, currency, timestamps)
- `xpolarcheckout_refunds` (refund entries)
- `xpolarcheckout_webhook_meta` (delivery id, received-at)

### 7.2 Invoice Metadata (`i_status_extra`)

Use read-only render fields for customer/print settlement blocks:

- settlement totals (minor/display)
- provider order reference
- checkout confirmation link (validated URL only)
- last reconciliation timestamp

### 7.3 Forensics Table

`xpc_webhook_forensics` remains the audit trail for:

- missing signature header
- invalid signature
- stale timestamp
- malformed payload
- unexpected processing exceptions

## 8) ACP Settings Model

Required settings:

- `access_token`
- `webhook_secret`
- `environment` (sandbox/live)
- `replay_lookback_seconds`
- `replay_overlap_seconds`
- `replay_max_events`

Optional settings:

- `default_product_id`
- `enable_integrity_alerts`
- `signature_tolerance_seconds`

Validation:

- Enforce numeric bounds at form and runtime.
- Never store plaintext secrets in logs.
- Mask secrets in ACP display.

## 9) Security Model

- Verify signatures before any payload processing.
- Reject stale deliveries.
- Validate all externally provided URLs before persistence.
- Use strict allowlist for accepted event types.
- Keep forensics logs for at least 90 days.
- Maintain CSRF checks on ACP actions.

## 10) Implementation Phases

## Phase 2 - Core Provider Paths (Current)

1. Finalize webhook event mapping and status transitions.
2. Complete checkout session creation and redirect flow.
3. Implement refund API path and metadata capture.
4. Wire provider snapshot extraction for settlement display.

Exit criteria:

- Manual paid flow works end-to-end.
- Manual partial and full refunds work.
- Duplicate webhook delivery is idempotent.

## Phase 3 - Replay and Integrity

1. Replace replay source with Polar delivery/event APIs.
2. Keep dry-run and production replay modes.
3. Surface replay health in ACP integrity panel.
4. Add alert thresholds for stale replay, failures, mismatches.

Exit criteria:

- Replay recovers missed events safely.
- Integrity panel reflects replay state accurately.

## Phase 4 - Hardening and QA

1. Expand runtime manual test matrix.
2. Validate failure modes: bad signatures, stale payloads, malformed events.
3. Validate state transitions against all supported event families.
4. Validate ACP permissions and audit logs.

Exit criteria:

- No critical/high defects in webhook processing.
- Test checklist fully green in `docs/TEST_RUNTIME.md`.

## Phase 5 - Release Readiness

1. Final doc pass (`README`, `FLOW`, `FEATURES`, `CHANGELOG`, `TEST_RUNTIME`).
2. Build release package from ACP Developer Center.
3. Execute import/export sync checks in root stack.
4. Publish rollout instructions and rollback path.

Exit criteria:

- App package installs cleanly on fresh test site.
- Gateway appears and saves in ACP payment methods.
- End-to-end payment and refund smoke tests pass.

## 11) Local Debug With Polar CLI

Suggested local workflow:

1. Install CLI from official repository and authenticate.
2. Start local webhook forwarder to IPS endpoint.
3. Use CLI trigger commands for paid and refund scenarios.
4. Inspect ACP forensics + integrity panel after each run.

Expected command categories:

- login/auth
- webhook listen/forward
- event trigger/replay
- environment selection (sandbox/live)

Use the exact syntax from the CLI docs version in use.

## 12) Test Plan (Minimum)

### 12.1 Checkout

- Gateway visible in ACP and can be configured.
- Checkout session created and customer redirect works.
- Successful payment marks IPS transaction paid.

### 12.2 Refunds

- Partial refund updates IPS to part-refunded.
- Full remaining refund updates IPS to refunded.
- Refund metadata persists and renders.

### 12.3 Webhooks

- Missing signature rejected and logged.
- Invalid signature rejected and logged.
- Stale timestamp rejected and logged.
- Duplicate event delivery is no-op success.

### 12.4 Replay

- Dry run produces event count and filtering summary.
- Live replay processes missed events once.
- Replay bounds prevent runaway execution.

## 13) Rollout and Rollback

Rollout:

1. Deploy app package.
2. Enable app and add gateway in ACP.
3. Configure credentials and webhook secret.
4. Run paid + refund smoke tests.

Rollback:

1. Disable gateway for new checkouts.
2. Keep webhook endpoint active for late events until queue drains.
3. Export forensic/report data for postmortem.
4. Revert to prior stable package if required.

## 14) Risks and Mitigations

- Risk: incomplete event mapping causes status drift.
  - Mitigation: explicit mapping table and replay-safe idempotency tests.

- Risk: signature mismatch due to environment confusion.
  - Mitigation: separate sandbox/live settings and visible environment badge in ACP.

- Risk: replay flood during outages.
  - Mitigation: strict runtime caps and chunked processing with cursor persistence.

- Risk: silent failures in webhook parsing.
  - Mitigation: mandatory forensic inserts for every reject path.

## 15) Tracking Rules

- This plan is the local source of execution detail.
- `docs/BACKLOG.md` tracks active tasks.
- `docs/CHANGELOG.md` tracks completed milestones.
- GitHub issue `#1` receives milestone comments after each meaningful merge.

## 16) Immediate Next Actions

1. Done: implement and verify full event mapping in webhook controller.
2. Complete checkout session creation with Polar payload and redirect.
3. Complete refund path and metadata capture.
4. Done: update `TEST_RUNTIME.md` with executable Phase 2 smoke tests.
5. Done: post progress summary comments to issue `#1`.

## 17) Progress Log

### 2026-02-22 - Phase 2 B2 Completed

- Webhook event map implemented for:
  - `order.created`
  - `order.paid`
  - `order.updated`
  - `order.refunded`
  - `checkout.updated`
  - `refund.updated`
- Transition helpers added for:
  - gateway pending state
  - paid capture state
  - checkout failed/expired refusal
  - partial/full refund classification
- Snapshot extraction aligned to Polar order/refund fields:
  - `total_amount`
  - `refunded_amount`
  - succeeded refund `amount` fallback
- File changed:
  - `app-source/modules/front/webhook/webhook.php`
- Validation:
  - `php -l` passed on webhook controller.
  - full `app-source` lint pass in container passed.

### 2026-02-22 - Phase 2 B3 Partial Hardening

- Checkout payload compatibility hardening:
  - `price_currency` switched to lowercase enum format in checkout payload.
- Amount precision hardening:
  - replaced fixed-2-decimal minor-unit math with IPS currency-decimal aware conversion (`numberOfDecimalsForCurrency` + `Math\Number`).
- File changed:
  - `app-source/sources/XPolarCheckout/XPolarCheckout.php`
- Validation:
  - `php -l` passed on gateway source.
  - full `app-source` lint pass in container passed.

### Remaining Phase 2 Work

- B3: checkout/refund provider-path sandbox validation and payload hardening.
- B4: replay pipeline rewrite from placeholder to Polar delivery/event API workflow.
