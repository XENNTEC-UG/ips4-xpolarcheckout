# Stripe Checkout Runtime Verification Checklist

Use this file for runtime checks that require a live IPS + Stripe test environment.

## Auto-Verification Policy

- Codex runs all checks that are verifiable via Docker, DB, logs, CLI, and API.
- User testing is only requested for browser/UI and full business-flow behavior that requires real checkout/refund/dispute actions.
- Last automated verification run: 2026-02-17.

### A1. Automated Checkout Tax Payload Assertions
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Command:
  - `docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_tax_payload.php`
- What is covered:
  - Tax-enabled session payload includes:
    - `automatic_tax[enabled]=true`
    - `billing_address_collection=required`
    - `customer_update[address]=auto` and `customer_update[name]=auto`
  - Tax-disabled session payload only forces billing when `address_collection` is enabled.
  - Line item tax behavior handling:
    - `exclusive` and `inclusive` pass through correctly
    - invalid values normalize to `exclusive`
    - tax-disabled line items omit `tax_behavior`

### A2. Automated Webhook Replay Task
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Command:
  - `docker compose exec -T php php -r 'require "/var/www/html/init.php"; $task=new \IPS\xpolarcheckout\tasks\webhookReplay; var_export($task->execute()); echo "\n"; var_export(\IPS\Data\Store::i()->xpolarcheckout_webhook_replay_state); echo "\n";'`
- What was verified:
  - Task fetched recent Stripe events and replayed supported event types through local webhook endpoint.
  - Replay completed with HTTP `200` responses and no duplicate transaction mutations (`t_id=29` remained `rfnd`, webhook event count unchanged).
  - Datastore cursor persisted in `xpolarcheckout_webhook_replay_state`.

### A3. Automated Tax Breakdown Snapshot Assertions
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Command:
  - `docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_tax_breakdown_snapshot.php`
- What is covered:
  - Selects a paid Stripe invoice from test mode and invokes snapshot tax-breakdown builder in CLI-safe reflection mode.
  - Verifies snapshot keys exist:
    - `taxability_reason`
    - `taxability_reasons[]`
    - `tax_breakdown[]`
  - For invoices with tax entries, verifies at least one breakdown row with amount/taxability fields.
  - Confirms jurisdiction/rate metadata extraction path is active through tax-rate lookups.

### A4. Automated Stripe-vs-IPS Total Mismatch Assertions
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Command:
  - `docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_total_mismatch_snapshot.php`
- What is covered:
  - Invokes snapshot total comparison helper against a real xpolarcheckout transaction.
  - Verifies matching totals path (`has_total_mismatch=false`, mismatch `0`).
  - Verifies mismatch path (`has_total_mismatch=true`, mismatch fields populated) using controlled +1 minor-unit adjustment.

### A5. Automated ACP Integrity Panel Stats Assertions
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Command:
  - `docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_integrity_panel_stats.php`
- What is covered:
  - Invokes ACP integrity panel stats collector in CLI-safe reflection mode.
  - Verifies required stats keys and expected data types.
  - Confirms panel data source wiring for webhook/replay/mismatch metrics.
  - Invokes integrity replay executor helper and verifies expected replay result message keys.

### A6. Automated Settlement Block Rendering Assertions
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Command:
  - `docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_settlement_rendering.php`
- What is covered:
  - Verifies settlement hooks are registered in `core_hooks`.
  - Verifies hook selectors still align with Nexus invoice/print template anchors.
  - Verifies hook content includes Stripe settlement, tax, invoice, payment intent, and customer link rows.
  - Verifies legacy notes marker blocks are absent and snapshot customer link URLs are populated.

### A7. Automated DE/EU VAT Matrix Sandbox Assertions
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Command:
  - `docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_vat_matrix_sandbox.php`
- What is covered:
  - Executes Stripe Tax calculation matrix for:
    - DE B2C
    - EU B2C (OSS-style address)
    - EU B2B with VAT ID
    - non-EU customer
  - Verifies all scenario responses are valid and include tax breakdown data.
  - Verifies expected zero/non-zero tax mix across scenarios.

### A8. Automated Refund Snapshot Consistency Assertions
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Command:
  - `docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_refund_snapshot_consistency.php`
- What is covered:
  - Verifies refunded transaction snapshot totals/tax values match invoice snapshot values.
  - Validates full + partial refund evidence via Stripe Refunds API on xpolarcheckout payment intents.

### A9. Automated Chargeback Protection Assertions
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Command:
  - `docker compose exec -T php php /tmp/xsc_tests/test_chargeback_protection.php`
  - Source: `ips-dev-source/apps/xpolarcheckout/docs/automation/test_chargeback_protection.php`
- What is covered:
  - `dispute_ban` ACP setting defaults to TRUE when not set.
  - Gateway source contains `dispute_ban` / `fraud_protection` strings; `refund_ban` / `refund_settings` removed.
  - Legacy migration keys array includes `dispute_ban`, not `refund_ban`.
  - Refund data extraction: all 9 fields (charge_id, amount, amount_refunded, currency, refunded, captured_at, latest_refund.id/reason/created/amount).
  - Dispute data extraction: all 10 fields (id, reason, status, amount, currency, created, evidence_due_by, is_charge_refundable, charge_id, payment_intent).
  - Dispute closure: metadata update preserves original data, adds status + closed_at.
  - Dispute closure fallback: creates minimal dispute data when no prior data exists.
  - Dispute closure history: `lost` → STATUS_REFUNDED, `won` → STATUS_PAID.
  - Snapshot enrichment: customer_email, customer_name, customer_address, payment_method_type, card_last4, card_brand, card_fingerprint.
  - Snapshot fallback: unexpanded charge (string ID) yields NULL for all card fields.
  - Webhook source validation: refund handler has no ban logic, dispute handler has ban logic, all three handler catch blocks use `\Throwable`, no `DISPUT` typo.
  - Lang file: `dispute_ban` strings present, `refund_ban` strings absent.

### A10. P2/P3 Dispute Visibility & Radar Risk
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Command:
  - `docker compose exec -T php php /var/www/html/applications/xpolarcheckout/docs/automation/test_dispute_visibility.php`
  - Source: `ips-dev-source/apps/xpolarcheckout/docs/automation/test_dispute_visibility.php`
- What is covered:
  - `extensions.json` exists and registers `StripeDisputeSummary`.
  - `StripeDisputeSummary.php` extends `MemberACPProfile\Block`, has `output()`, queries `nexus_transactions`, reads `xpolarcheckout_dispute`, wrapped in `\Throwable`.
  - `build.xml` contains `StripeDisputeSummary` extension registration.
  - `buildStripeSnapshot` source contains `risk_level`, `risk_score`, `outcome_type`, `outcome_seller_message`.
  - Client settlement hook displays `payment_method_type`, `risk_level`, `risk_score`.
  - Print settlement hook displays `payment_method_type`, `risk_level`, `risk_score`.
  - All 11 new lang strings present in lang.php.
  - Mock Radar data extraction: expanded charge yields correct values, unexpanded charge yields NULLs.

### A12. Webhook Endpoint Sync & Drift Detection
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Command:
  - `docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_webhook_sync.php`
  - Source: `ips-dev-source/apps/xpolarcheckout/docs/automation/test_webhook_sync.php`
- What is covered:
  - `REQUIRED_WEBHOOK_EVENTS` constant exists on gateway class with exactly 6 events.
  - All 6 required event strings present in constant.
  - `testSettings()` uses `REQUIRED_WEBHOOK_EVENTS` (not inline array) and stores `webhook_endpoint_id`.
  - `fetchWebhookEndpoint()` and `syncWebhookEvents()` methods exist on gateway class.
  - Replay task references gateway constant, no local `REPLAY_EVENT_TYPES` constant.
  - Integrity controller has `syncEvents` method with `csrfCheck()`.
  - `collectIntegrityStats` references `webhook_events_missing` and `webhook_events_extra`.
  - All 9 new lang strings present.
  - Mock drift logic: required=[A,B,C] + stripe=[B,C,D] yields missing=[A], extra=[D].

### A14. Tax Readiness Status Normalization
- [ ] Test executed
- Outcome: [ ] PASS  [ ] FAIL  [ ] BLOCKED
- Command:
  - `docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_tax_readiness_status.php`
  - Source: `ips-dev-source/apps/xpolarcheckout/docs/automation/test_tax_readiness_status.php`
- What is covered:
  - `fetchTaxReadiness()`, `normalizeTaxReadiness()`, `applyTaxReadinessSnapshotToSettings()` methods exist on gateway class.
  - Normalization contains all 4 canonical states: `collecting`, `not_collecting`, `unknown`, `error`.
  - Mock normalization logic: active+regs=collecting, active+0=not_collecting, pending+regs=not_collecting.
  - NULL input handling returns error state.
  - All 6 snapshot keys present in normalization output.
  - `testSettings()` calls `applyTaxReadinessSnapshotToSettings` and logs to `xpolarcheckout_tax`.
  - Integrity controller has `refreshTaxReadiness` method with `csrfCheck()`.
  - `collectIntegrityStats` references tax readiness fields.
  - All 13 new tax readiness lang strings present.

### A15. Webhook Capture Concurrency Lock Guard
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Command:
  - `docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_webhook_capture_lock.php`
  - Source: `ips-dev-source/apps/xpolarcheckout/docs/automation/test_webhook_capture_lock.php`
- What is covered:
  - `acquireTransactionProcessingLock()` and `releaseTransactionProcessingLock()` helpers exist.
  - Webhook controller contains DB lock SQL calls (`GET_LOCK`/`RELEASE_LOCK`).
  - Lock acquisition and release are wired in both capture paths:
    - `checkout.session.completed`
    - `checkout.session.async_payment_succeeded/failed`
  - Lock log category `xpolarcheckout_webhook_lock` exists for observability.

### A16. Replay Task Runtime Controls
- [ ] Test executed
- Outcome: [ ] PASS  [ ] FAIL  [ ] BLOCKED
- Command:
  - `docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_replay_controls.php`
  - Source: `ips-dev-source/apps/xpolarcheckout/docs/automation/test_replay_controls.php`
- What is covered:
  - Gateway settings form contains replay_lookback, replay_overlap, replay_max_events fields with section header.
  - Replay task reads configurable settings from gateway with fallback to constants.
  - Replay task clamps values within safe bounds (300-86400, 60-1800, 10-500).
  - Dry-run mode: accepts parameter, branches on flag, does not call forwardEventToWebhook, returns structured array.
  - Paginated event fetching: fetchStripeEventsPaginated with MAX_PAGES_PER_RUN, MAX_RUNTIME_SECONDS, has_more, starting_after.
  - Integrity controller has dryRunReplay method with csrfCheck() calling execute(TRUE).
  - Integrity panel includes replay config stats and uses configured lookback for staleness.
  - All 11 new lang strings present.

### A17. Payment Integrity Alerting
- [ ] Test executed
- Outcome: [ ] PASS  [ ] FAIL  [ ] BLOCKED
- Command:
  - `docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_integrity_alerting.php`
  - Source: `ips-dev-source/apps/xpolarcheckout/docs/automation/test_integrity_alerting.php`
- What is covered:
  - Extension class extends AdminNotification with correct static properties ($group, $groupPriority, $itemPriority).
  - runChecksAndSendNotifications() references all 4 alert types (webhook_errors, replay_stale, mismatches, tax_not_collecting).
  - title/subtitle/body use dynamic lang keys per alert type.
  - severity() maps HIGH for webhook_errors/replay_stale, NORMAL for others.
  - All types are DISMISSIBLE_TEMPORARY. link() points to integrity panel.
  - selfDismiss() handles all 4 types with lightweight re-checks.
  - integrityMonitor task exists and calls runChecksAndSendNotifications().
  - extensions.json registers AdminNotifications/PaymentIntegrity.
  - tasks.json registers integrityMonitor with PT5M interval.
  - collectAlertStats() static method on gateway class.
  - All 14 new lang strings present.

### A13. Discount Coupon Safety + Stale Endpoint-ID Fallback
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Command:
  - `docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_discount_coupon_safety.php`
  - Source: `ips-dev-source/apps/xpolarcheckout/docs/automation/test_discount_coupon_safety.php`
- What is covered:
  - `buildDiscountSafetyFallbackLineItems()` helper exists.
  - `auth()` wraps `createOneTimeStripeCoupon()` in try/catch and logs `xpolarcheckout_coupon`.
  - On coupon creation error, checkout falls back to summary line-item payload (payment continuity).
  - `createOneTimeStripeCoupon()` sanitizes coupon name and caps it to Stripe's 40-char limit.
  - `fetchWebhookEndpoint()` only returns ID lookup when Stripe response contains `id`.
  - `fetchWebhookEndpoint()` preserves legacy URL-match fallback for stale `webhook_endpoint_id`.

### A11. Tax Evidence Capture (VAT ID Collection + Customer Tax Identity)
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Command:
  - `docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_tax_evidence.php`
  - Source: `ips-dev-source/apps/xpolarcheckout/docs/automation/test_tax_evidence.php`
- What is covered:
  - ACP setting `tax_id_collection` exists in gateway `settings()`.
  - `applyCheckoutTaxConfiguration()` applies `tax_id_collection` to session payload.
  - `buildStripeSnapshot()` captures `customer_tax_exempt`, `customer_tax_ids`, `tax_id_collection_enabled`.
  - Client settlement hook displays tax exempt status + customer tax IDs with reverse charge highlight.
  - Print settlement hook displays same rows.
  - All 4 new lang strings present.
  - Mock extraction: reverse-charge session, no-tax-ID fallback, exempt status scenarios.

## Install and Config

### S1. App installed in ACP
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Comment:
  - Verified in DB: `core_applications.app_directory='xpolarcheckout'`, `app_enabled=1`.
  - Source version is `10012` (v1.1.2). Run ACP upgrade if DB shows `10011`.
  - Command:
    - `docker compose exec -T db mysql -uroot -pREDACTED_DB_PASSWORD ips -e "SELECT app_directory,app_enabled,app_long_version FROM core_applications WHERE app_directory='xpolarcheckout';"`

### S2. Gateway appears in Nexus and can be configured
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Comment:
  - Verified Stripe checkout gateway exists in `nexus_paymethods`.
  - Command:
    - `docker compose exec -T db mysql -uroot -pREDACTED_DB_PASSWORD ips -e "SELECT m_id,m_gateway FROM nexus_paymethods WHERE m_gateway='XPolarCheckout';"`

### S3. Webhook URL + secret generated and stored
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Comment:
  - Verified `webhook_url` and `webhook_secret` exist in `nexus_paymethods.m_settings` for `StripeCheckout`.
  - Command:
    - `docker compose exec -T db mysql -uroot -pREDACTED_DB_PASSWORD ips -e "SELECT m_id,m_gateway,JSON_UNQUOTE(JSON_EXTRACT(m_settings,'$.webhook_url')) AS webhook_url,JSON_UNQUOTE(JSON_EXTRACT(m_settings,'$.webhook_secret')) AS webhook_secret FROM nexus_paymethods WHERE m_gateway='XPolarCheckout';"`

### S4. Stripe CLI Docker listener forwards to local webhook
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Comment:
  - Listener is up, forwarding to local IPS endpoint, and recent trigger delivered with HTTP `200`.
  - Listener reports Stripe API version `2026-01-28.clover`.
- Command:
  - `docker compose --profile stripe up -d stripe-cli`
  - `docker compose logs -f stripe-cli`
  - `docker compose exec -T stripe-cli stripe trigger checkout.session.completed --add checkout_session:metadata.transaction=1`
  - `docker compose logs --since 5m stripe-cli`
- Expectation:
  - Listener prints `Ready` and shows a `whsec_...` secret.
  - Forwarded webhook deliveries return HTTP `200` from local endpoint.
  - If forwarding fails with `404`, set `.env` `STRIPE_FORWARD_TO` to `http://host.docker.internal/index.php?app=xpolarcheckout&module=webhook&controller=webhook`.

### S5. Listener secret matches ACP/DB secret
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Comment:
  - Listener `whsec_...` matches `webhook_secret` in `nexus_paymethods`.
- Command:
  - `docker compose logs --tail 40 stripe-cli`
  - `docker compose exec -T db mysql -uroot -pREDACTED_DB_PASSWORD ips -e "SELECT m_gateway,JSON_UNQUOTE(JSON_EXTRACT(m_settings,'$.webhook_secret')) AS webhook_secret FROM nexus_paymethods WHERE m_gateway='XPolarCheckout';"`
- Expectation:
  - `whsec_...` in listener log equals `webhook_secret` in DB/ACP.

## Local Webhook Constraints

- Stripe Dashboard endpoint creation requires a public HTTPS URL.
- `http://localhost/...` and `http://host.docker.internal/...` are invalid in Stripe Dashboard by design.
- For local IPS testing, use `stripe listen --forward-to ...` (in this repo via Docker service `stripe-cli`).
- Do not rely on Dashboard webhook endpoint objects for localhost development.

## Fast Troubleshooting (Known Failure Signatures)

### F1. Stripe Dashboard shows `Invalid URL`
- Symptom:
  - Dashboard webhook creation fails for `http://host.docker.internal/index.php?...`.
- Cause:
  - Non-public HTTP endpoint.
- Fix:
  - Use Docker `stripe-cli` forwarder only for local runtime.
  - Keep Dashboard webhook setup for staging/production public HTTPS endpoints.

### F2. Listener returns `500` for `checkout.session.completed`
- Symptom:
  - `docker compose logs --since 2m stripe-cli` shows `POST ... [500]`.
- Common local cause:
  - Synthetic `stripe trigger checkout.session.completed` payload misses IPS-required metadata (`transaction`).
- Fix:
  - Trigger with metadata:
  - `docker compose exec -T stripe-cli stripe trigger checkout.session.completed --add checkout_session:metadata.transaction=1`
  - Re-check logs for `POST ... [200]`.

### F3. Listener running but webhook auth fails
- Symptom:
  - Signature verification errors in IPS logs or recurring non-200 webhook responses.
- Cause:
  - ACP `Webhook Signing secret` not updated to current listener secret.
- Fix:
  - Get current secret from:
  - `docker compose logs --tail 40 stripe-cli`
  - Paste `whsec_...` into ACP gateway setting `Webhook Signing secret`.

### F4. Checkout shows `Invalid boolean: 1`
- Symptom:
  - IPS checkout step 2 returns Stripe gateway error `Invalid boolean: 1`.
- Cause:
  - Form-encoded Stripe payload received `1/0` for boolean fields.
- Fix:
  - Ensure gateway request uses Stripe-compatible boolean tokens:
    - `automatic_tax[enabled]=true`
    - `invoice_creation[enabled]=true`
  - Source file:
    - `ips-dev-source/apps/xpolarcheckout/app-source/sources/XPolarCheckout/XPolarCheckout.php`

### F5. Settlement block links open Stripe Dashboard (not customer view)
- Symptom:
  - Invoice page shows links that require Stripe Dashboard admin login.
- Cause:
  - Snapshot only had dashboard URLs (`dashboard_*`) and no customer-facing URLs.
- Fix:
  - Ensure webhook snapshot stores:
    - `customer_invoice_url` (`hosted_invoice_url`)
    - `customer_invoice_pdf_url` (`invoice_pdf`)
    - `customer_receipt_url` (`charge.receipt_url`)
  - Settlement UI now renders links inline next to:
    - `Stripe invoice ID`
    - `Stripe payment intent`
  - Run a fresh successful payment after deploying this change so new snapshot fields are populated.

## Proven Working Local Baseline (2026-02-12)

- Stack:
  - `docker compose ps` shows `php`, `nginx`, `db`, `redis`, and `stripe-cli` as up.
- Forward target:
  - `STRIPE_FORWARD_TO=http://host.docker.internal/index.php?app=xpolarcheckout&module=webhook&controller=webhook`
- Listener status:
  - Ready on Stripe API version `2026-01-28.clover` (or newer when Stripe advances latest) with active `whsec_...`.
- Delivery test:
  - `checkout.session.completed` forwarded and returned HTTP `200`.

## Manual-Only Checks (User)

- Real bank-network dispute lifecycle beyond webhook-path simulation (chargeback filing windows, evidence submission outcomes).
- Async payment method UX with actual bank debit or delayed confirmation payment method in target market.

## Payment Flow

### P1. Checkout session redirect starts from IPS invoice
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Comment:
  - User-confirmed successful Stripe checkout flow.
  - Stripe PaymentIntent: `pi_REDACTED_TEST_001` (test mode).

### P2. `checkout.session.completed` marks transaction paid
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Comment:
  - DB verification: `nexus_transactions.t_gw_id='pi_REDACTED_TEST_001'` has `t_status='okay'`.
  - Linked invoice `i_id=10` is `paid` with `EUR 5.00`.

### P3. Async payment success/fail webhook paths behave correctly
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Comment:
  - Code fix applied on 2026-02-16:
    - Corrected async `payment_status` branch to `if/elseif/else` so `paid` no longer triggers false BAD_STATUS exception.
    - Terminal statuses now acknowledge with HTTP `200` to avoid Stripe retry loops.
  - Bug found and fixed on 2026-02-17:
    - Async handler had no try/catch around processing; uncaught `\Error` (null member) caused HTTP 500.
    - `throw new \Exception('UNABLE_TO_LOAD_TRANSACTION')` changed to return `200 TRANSACTION_NOT_FOUND` (matching other handlers).
    - `catch (\Exception)` changed to `catch (\Throwable)` to also catch PHP `\Error` types.
    - `BAD_STATUS` else branch now logs and returns 200 instead of throwing.
  - Validated end-to-end on 2026-02-17 via Stripe CLI trigger:
    - `async_payment_succeeded` (`t_id=22`): `pend` → `gwpd` → `okay`, invoice `paid`. HTTP 200.
    - `async_payment_failed` (`t_id=13`): `pend` → `gwpd` → `fail` with `noteRaw: async_payment_failed`. HTTP 200.
    - Idempotency: re-delivery to terminal transaction returns 200, no state mutation.

## Tax Validation

### T1. Stripe Tax is applied (not just enabled) for real Checkout payments
- [x] Test executed
- Outcome: [ ] PASS  [x] FAIL  [ ] BLOCKED
- Comment:
  - Stripe invoice `in_REDACTED_TEST_001` shows `automatic_tax.enabled=true` and `automatic_tax.status=complete`, but `tax=0`.
  - Line-item `taxability_reason=not_collecting`, meaning no tax is currently collected for that jurisdiction with current Stripe Tax registrations/settings.
  - Action required in Stripe Dashboard:
    - Add/confirm tax registrations for required jurisdictions.
    - Confirm product tax code strategy and default tax behavior in Stripe Tax settings.

### T1a. Stripe Sandbox Tax Registration Setup (Official Dashboard Paths)

- Scope: Sandbox only (test mode).
- Open these official Stripe dashboard paths:
  - Registrations: `https://dashboard.stripe.com/tax/registrations`
  - Settings: `https://dashboard.stripe.com/tax/settings`
  - Transactions (test mode URL pattern shown in Stripe docs): `https://dashboard.stripe.com/test/tax/transactions`
- In dashboard, ensure **Sandbox/Test mode** is active before creating registrations.
- For DE/EU VAT collection:
  - Add your home registration (Germany) in sandbox.
  - Add OSS/IOSS registrations as needed for cross-border EU collection.
  - Keep automatic tax enabled in checkout gateway settings.
- Post-setup validation:
  - Run a new test checkout from an EU billing country.
  - Expect Stripe invoice line items to show non-zero tax where applicable.
  - Expect IPS invoice notes Stripe summary block to show non-zero `Tax` and updated `Total charged`.

### T2. Tax mode is configurable with safe defaults
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Comment:
  - Stripe tax is enabled by default for new/unset settings.
  - Admin can disable Stripe automatic tax entirely (`tax_enable=false`).
  - When enabled, tax behavior is selectable (`exclusive` or `inclusive`) and defaults to `exclusive`.
  - Checkout payload still forces billing address collection and uses Stripe-compatible boolean tokens (`'true'`) for form-encoded request fields.

### T3. Stripe settlement snapshot is written into IPS transaction/invoice
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- What was verified:
  - Webhook code path now persists Stripe snapshot to:
    - `nexus_transactions.t_extra -> xpolarcheckout_snapshot`
    - `nexus_invoices.i_status_extra -> xpolarcheckout_snapshot`
  - Verified with real paid invoice `i_id=11`:
    - `currency=EUR`, `amount_total_minor=595`, `amount_tax_minor=95`.
  - Runtime mirror is synced and PHP lint is clean.
- Command set for next payment verification:
  - `docker compose logs --since 5m stripe-cli`
  - `docker compose exec -T db mysql -uroot -pREDACTED_DB_PASSWORD ips -e "SELECT t_id,t_status,JSON_UNQUOTE(JSON_EXTRACT(t_extra,'$.xpolarcheckout_snapshot.payment_intent_id')) AS pi,JSON_UNQUOTE(JSON_EXTRACT(t_extra,'$.xpolarcheckout_snapshot.invoice_id')) AS in_id,JSON_UNQUOTE(JSON_EXTRACT(t_extra,'$.xpolarcheckout_snapshot.amount_tax_minor')) AS tax_minor,JSON_UNQUOTE(JSON_EXTRACT(t_extra,'$.xpolarcheckout_snapshot.amount_total_minor')) AS total_minor FROM nexus_transactions WHERE t_id=(SELECT MAX(t_id) FROM nexus_transactions);"`
  - `docker compose exec -T db mysql -uroot -pREDACTED_DB_PASSWORD ips -e "SELECT i_id,i_status,JSON_UNQUOTE(JSON_EXTRACT(i_status_extra,'$.xpolarcheckout_snapshot.amount_tax_minor')) AS tax_minor,JSON_UNQUOTE(JSON_EXTRACT(i_status_extra,'$.xpolarcheckout_snapshot.amount_total_minor')) AS total_minor FROM nexus_invoices WHERE i_id=(SELECT MAX(t_invoice) FROM nexus_transactions);"`
- Expected:
  - `stripe-cli` delivery is HTTP `200`.
  - Latest transaction has `xpolarcheckout_snapshot` with PI/invoice IDs and totals.
  - Latest invoice has snapshot in `i_status_extra`.

### T4. Stripe settlement block renders cleanly on invoice/print pages (no notes pollution)
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- What was verified:
  - Automated hook/template anchoring check passed (`A6`).
  - `theme_sc_clients_settle` and `theme_sc_print_settle` are registered and include required settlement rows.
  - Legacy notes marker block is absent from invoices with snapshot metadata.

### T5. Settlement block exposes customer-facing Stripe links inline
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- What was verified:
  - Settlement hook source includes inline labels for:
    - `View invoice`
    - `PDF`
    - `View receipt`
  - Snapshot for invoice `i_id=38` contains:
    - `customer_invoice_url`
    - `customer_invoice_pdf_url`
    - `customer_receipt_url`
  - Validation executed via `A6`.

## Refund and Dispute Flow

### R1. Stripe refund webhook updates IPS transaction status
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Comment:
  - Verified on 2026-02-16 against live Stripe test PaymentIntent `pi_REDACTED_TEST_002` (`t_id=29`):
    - Partial refund `amount=100` cents -> webhook delivered `charge.refunded` -> `nexus_transactions.t_status=prfd`.
    - Remaining refund `amount=495` cents -> webhook delivered `charge.refunded` -> `nexus_transactions.t_status=rfnd`.
  - Evidence:
    - Stripe listener logs: both `charge.refunded` deliveries returned HTTP `200`.
    - DB verification command:
      - `docker compose exec -T db mysql -uroot -pREDACTED_DB_PASSWORD ips -e "SELECT t_id,t_status,t_gw_id FROM nexus_transactions WHERE t_id=29;"`

### R2. Dispute created/closed events update IPS status correctly
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Comment:
  - Initially verified on 2026-02-16 using signed webhook simulation (`t_id=28`).
  - Enhanced validation on 2026-02-17 using signed webhook payloads against `t_id=22` (`pi_REDACTED_TEST_003`):
    - `charge.dispute.created` → `t_status=dspd`, invoice `i_id=31` → `canc`. HTTP 200.
    - `charge.dispute.closed (won)` → `t_status=okay`, invoice → `paid`. HTTP 200.
    - `charge.dispute.created` (2nd) → `t_status=dspd`, invoice → `canc`. HTTP 200.
    - `charge.dispute.closed (lost)` → `t_status=rfnd`, invoice stays `canc`. HTTP 200.
    - Graceful handling: Stripe CLI synthetic dispute (non-matching PI) → `200 TRANSACTION_NOT_FOUND`.
  - Full lifecycle verified: created → closed(won) → created → closed(lost).

## Security (added 2026-02-14 code audit)

### SEC1. Webhook rejects requests with invalid signature
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Fix applied: `checkSignature()` now returns 403 `INVALID_SIGNATURE` on HMAC mismatch.
- What to verify:
  - Send POST with wrong `Stripe-Signature` value — expect 403 response.
  - Send POST with valid signature — expect 200 and normal processing.
- Verification result:
  - `SEC1 code=403 body=INVALID_SIGNATURE`

### SEC2. Webhook rejects requests with missing Stripe-Signature header
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Fix applied: early-exit with 403 `MISSING_SIGNATURE` at top of `manage()`.
- What to verify:
  - POST without `Stripe-Signature` header returns 403 with no PHP error.
- Verification result:
  - `SEC2 code=403 body=MISSING_SIGNATURE`

### SEC3. Webhook handles malformed JSON body gracefully
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Fix applied: `json_decode` result validated with `is_array` + `isset` check; returns 400 `INVALID_PAYLOAD`.
- What to verify:
  - POST with invalid JSON body (e.g. `{broken`) returns 400 with no stack trace.
- Verification result:
  - `SEC3 code=400 body=INVALID_PAYLOAD`

### SEC4. Webhook handles unknown transaction IDs gracefully
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Fix applied: `charge.refunded`/`charge.dispute.*` handlers now handle both `UnderflowException` and `OutOfRangeException` for transaction lookup and return `TRANSACTION_NOT_FOUND` with HTTP `200`.
- What to verify:
  - Webhook with non-existent transaction/payment_intent returns 200 `TRANSACTION_NOT_FOUND` without stack trace.
- Verification result:
  - `SEC4 code=200 body=TRANSACTION_NOT_FOUND`

### SEC5. Webhook duplicate delivery is idempotent
- [x] Test executed
- Outcome: [x] PASS  [ ] FAIL  [ ] BLOCKED
- Fix applied:
  - Webhook stores processed Stripe event IDs in `nexus_transactions.t_extra.xpolarcheckout_webhook_events` and exits early on duplicates.
  - Covers `charge.refunded`, `charge.dispute.created`, `charge.dispute.closed`, `checkout.session.completed`, and async checkout events.
- What to verify:
  - Send the exact same signed webhook payload twice for an event that mutates transaction status.
  - First request applies state transition; second request returns `200` with no additional status history duplication.
- Verification result:
  - Replayed already-processed event id `evt_REDACTED_TEST_001` against `t_id=29`.
  - `nexus_transactions.t_extra.xpolarcheckout_webhook_events` count stayed `2` before/after replay.
