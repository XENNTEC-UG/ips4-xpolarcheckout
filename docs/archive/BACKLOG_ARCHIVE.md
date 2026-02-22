# Stripe Checkout Dev Status - Completed Archive

This file contains completed backlog items moved from [BACKLOG.md](BACKLOG.md).

---

## Chargeback Protection Suite (completed 2026-02-20)

- [x] **B1 — PaymentIntent metadata** — Added `ips_transaction_id`, `ips_invoice_id`, `ips_member_id`, `customer_email`, `customer_ip`, `product_description` to PI metadata.
- [x] **B2 — Receipt email** — Set `payment_intent_data[receipt_email]`. Stripe auto-emails receipt to customer.
- [x] **B3 — Statement descriptor suffix** — Uses `INV-{id}` format, truncated to 22 chars.
- [x] **B4 — TOS consent collection** — ACP toggle `tos_consent_enabled`. Stripe shows TOS checkbox when enabled.
- [x] **B5 — Phone number collection** — Optional field, feeds Radar when provided.
- [x] **B6 — Auto-populate dispute evidence** — Draft-only evidence on `charge.dispute.created`. Admin reviews in Stripe Dashboard.
- [x] **B7 — Capture customer IP in metadata** — Uses `\IPS\Request::i()->ipAddress()`.
- [x] **B8 — Request 3D Secure** — ACP toggle `threeds_enabled`. Uses `any` (frictionless preferred). Liability shift on success.
- ~~**B9 — Session expiration**~~ — Removed. Stripe's default 24h matches IPS4 invoice timeout.
- [x] **B10 — Customer metadata enrichment** — `ips_member_id` and `account_created` on Stripe Customer.
- [x] **B11 — Custom checkout text** — ACP TextArea `custom_checkout_text`. Plain text above Pay button. Max 1200 chars.

*Archived 2026-02-20.*

---

## Tax & Registration Improvements (completed 2026-02-18)

- [x] **[IMPROVEMENT] Tax-aware mismatch detection** — `applyIpsInvoiceTotalComparison()` now compares the Stripe-vs-IPS difference against `amount_tax_minor`. When the difference equals the tax amount, `total_difference_tax_explained` is set TRUE and `has_total_mismatch` stays FALSE. Settlement UI shows informational "Tax collected (Stripe)" row instead of orange warning. Unexplained differences still trigger warnings. New snapshot fields: `total_difference_minor`, `total_difference_display`, `total_difference_tax_explained`. Files: `webhook.php`, `theme_sc_clients_settle.php`, `theme_sc_print_settle.php`, `lang.php`.
- [x] **[IMPROVEMENT] Registration type display in Tax Readiness** — `normalizeTaxReadiness()` summary builder now includes registration type labels: `eu_oss_union`/`eu_oss_non_union` → "(EU OSS)", `ioss` → "(IOSS)". Result: "2 (DE, DE (EU OSS))" instead of "2 (DE, DE)". File: `XPolarCheckout.php`.

---

## Browser-Test Bug Fixes (completed 2026-02-18)

- [x] **[CRITICAL] Front-end invoice detail page crash — `ParseError: unexpected token "@"`** — Replaced `$taxId@last` foreach iterator in both `theme_sc_clients_settle.php` and `theme_sc_print_settle.php` hook content strings. The `@last` syntax is not supported inside hook `hookData()` content arrays. Client hook now uses `<div>`-wrapped entries; print hook uses `<br>`-separated entries. Verified: `/clients/orders/31/` loads correctly showing Stripe settlement data.
- [x] **[BUG] ACP dispute summary block missing from member profile** — Two issues: (1) missing language key `memberACPProfileTitle_xpolarcheckout_StripeDisputeSummary` in `dev/lang.php` (needed for block title), (2) no code hook to inject the block into the Member View tab's `leftColumnBlocks()`. Added lang key and created new `code_memberProfileTab` hook on `\IPS\core\extensions\core\MemberACPProfileTabs\Main`. Verified: block renders in left column showing chargebacks, refunds, ban status, and integrity panel link.

*Both fixes browser-verified on 2026-02-18.*

---

## Code Review Fixes (completed 2026-02-18)

- [x] **[A] Fix webhook idempotency TOCTOU on `charge.*` handlers** — all three `charge.*` handlers now use `acquireTransactionProcessingLock`/`releaseTransactionProcessingLock` with try/finally, matching the existing `checkout.session.completed` pattern.
- [x] **[A] Use transaction-bound webhook secret for `charge.*` verification** — all three `charge.*` handlers now load the transaction first and verify the signature against `$transaction->method->settings['webhook_secret']`. The shared gateway resolution block was removed as dead code.
- [x] **[B] Fix gateway class detection in ACP member dispute summary block** — fixed `instanceof` check from source-tree path to IPS4 runtime class `\IPS\xpolarcheckout\XPolarCheckout`.
- [x] **[B] Align dispute evidence deadline key name** — changed key lookup from `evidence_due` to `evidence_due_by`. Formatted raw unix timestamp using `\IPS\DateTime::ts()->localeDate()` for locale-aware display.

*All four items independently confirmed by two separate code reviews (Codex + Opus manual analysis). Archived 2026-02-18.*

---

## Replay hardening + cleanup (completed 2026-02-18)

- [x] Capped `replay_max_events` ACP form max and runtime clamp from `500` to `100` (Stripe API limit).
- [x] Added `types[]` server-side filter to Stripe `/v1/events` fetch — replay pagination now only counts relevant events, eliminating event-type starvation under high unrelated traffic.
- [x] P2 build.xml/upgrade wiring closed — ACP Developer Center auto-generates `build.xml` and `setup/upg_*` manifests from `data/*.json` on build. No manual manifest needed.
- [x] Added missing `menu__xpolarcheckout_monitoring_integrity` lang key (fixed truncated ACP sidebar label).

## P2: Payment Integrity Alerting (completed 2026-02-18)

- [x] Created `AdminNotifications/PaymentIntegrity` extension class using IPS4's native admin notification system.
- [x] 4 automated alert types: webhook errors (HIGH), replay stale (HIGH), mismatches (NORMAL), tax not collecting (NORMAL).
- [x] Alerts auto-send and auto-clear based on threshold conditions. All dismissible (temporary 24h).
- [x] Each alert links to the integrity panel for detailed investigation.
- [x] `selfDismiss()` implements lightweight per-type re-checks so alerts clear when conditions resolve.
- [x] Extracted `collectAlertStats()` static method on gateway class — shared between integrity panel and monitor task. Local DB queries only, no Stripe API calls.
- [x] Created `integrityMonitor` task (5-minute interval) that calls `runChecksAndSendNotifications()`.
- [x] Registered extension in `extensions.json` and task in `tasks.json`.
- [x] Admin opt-in/out via ACP Notification Settings ("Stripe Payment Integrity" group under commerce).
- [x] Added 14 new lang strings (settings title, 4 titles, 4 subtitles, 4 bodies, task label).
- [x] Added automation test A17 (extension structure, alert types, severity/dismissible mapping, task wiring, JSON registration, lang strings).
- [x] Design note: endpoint drift alerting excluded from automated checks (requires Stripe API call). Drift remains visible on integrity panel.

*Archived 2026-02-18.*

---

## P1: Replay Task Runtime Controls in ACP (completed 2026-02-18)

- [x] Added ACP gateway settings for replay lookback, overlap, and max events per run with min/max guardrails.
- [x] Replay task reads configurable values from gateway settings with fallback to class constants.
- [x] Replay task clamps all values within safe bounds (lookback 300-86400, overlap 60-1800, max events 10-500).
- [x] Implemented paginated Stripe event fetching (`has_more` + `starting_after`) with hard guardrails (max 10 pages, max 120s runtime per run).
- [x] Added dry-run mode (`execute(TRUE)`) — fetches and filters events without forwarding or saving state; returns structured array.
- [x] Added "Dry Run" button in ACP integrity panel with CSRF protection and ACP audit logging.
- [x] Integrity panel replay section now shows configured lookback, overlap, and max events values.
- [x] Integrity panel staleness threshold uses configured lookback instead of hardcoded 3600.
- [x] Added 11 new lang strings for replay controls and dry-run.
- [x] Added automation test A16 (replay controls: form fields, settings reading, clamping, dry-run, pagination, integrity panel).
- [x] Fixed settings key naming mismatch (`replay_lookback_seconds` → `replay_lookback`) to match IPS4 gateway form prefix stripping.
- [x] Verification: 15/15 automation scripts PASS (A1-A14, A16), A15 targeted guard PASS.

*Archived 2026-02-18.*

---

## Operational Constraints (Accepted)

1. **Stripe Tax registration dependency** — accepted external dependency. Stripe can report `not_collecting` until dashboard registrations are complete. Mitigation is tracked as ACP `Tax Readiness` visibility (implemented).
2. **Full dispute bank-network lifecycle** — accepted operational process constraint. Real dispute evidence submission and resolution windows are bank-driven and not testable in dev.

*Archived 2026-02-18.*

---

## P1: ACP Tax Readiness Indicator (completed 2026-02-18)

- [x] Added `fetchTaxReadiness()` static helper: fetches `GET /v1/tax/settings` + `GET /v1/tax/registrations` with error handling and logging.
- [x] Added `normalizeTaxReadiness()` static helper: normalizes Stripe response into canonical states (`collecting`, `not_collecting`, `unknown`, `error`) with registration count and summary.
- [x] Added `applyTaxReadinessSnapshotToSettings()` reusable helper to refresh and merge snapshot into settings.
- [x] Integrated best-effort tax readiness refresh into `testSettings()` on gateway save (never blocks save).
- [x] Added read-only tax readiness summary to ACP gateway settings form (status, registrations, last checked, warning).
- [x] Extended integrity panel `collectIntegrityStats()` with tax readiness snapshot from stored settings.
- [x] New "Tax Readiness" status card in integrity grid (green/yellow/red).
- [x] New "Tax Readiness" detail section: status, last checked, registrations, error, warning text, refresh button.
- [x] New `refreshTaxReadiness()` ACP action with CSRF protection, gateway settings persistence, and ACP audit logging.
- [x] Added 13 new lang strings for tax readiness feature.
- [x] Extended integrity panel stats test with tax readiness keys.
- [x] Added automation test A14 (38 assertions: methods, mock normalization, integration, lang strings).
- [x] Verification: 14/14 automation scripts PASS (A1-A14), browser-verified live Stripe API refresh.

*Archived 2026-02-18.*

---

## Code Review Checkpoint (2026-02-17) — Completed

### Finished / Verified
- [x] P0 webhook sync/drift implementation is present and wired end-to-end (`REQUIRED_WEBHOOK_EVENTS`, endpoint ID persistence, drift-aware integrity panel, sync action).
- [x] Runtime validation completed: PHP lint clean and automation suite passing through A13.
- [x] Core payment-state behavior remained stable (refund/dispute/async + snapshot persistence).

### Resolved During Review
- [x] Coupon safety hardening:
  - `createOneTimeStripeCoupon()` sanitizes and caps coupon name to Stripe 40-char max.
  - `auth()` uses best-effort coupon creation and falls back to summary line-items on Stripe coupon API failure.
- [x] Webhook endpoint stale-ID resilience:
  - `fetchWebhookEndpoint()` now falls back to URL matching when ID lookup returns error/no `id`.

### Decision
- [x] Keep best-effort Stripe coupon behavior as default:
  - preserve payment continuity even if Stripe coupon metadata fails.
  - Stripe-side discount labeling is secondary to successful checkout.

### Operational Constraints Decision
- [x] Stripe Tax registration dependency: accepted external constraint.
- [x] Full dispute bank-network lifecycle: accepted operational process constraint.

---

## Priority Backlog (2026-02-14) — Completed

### P0 - Setup
- [x] Confirm app is installed/upgraded in ACP on the local site.
- [x] Confirm Stripe gateway appears under Nexus payment methods.
- [x] Confirm webhook URL and secret are generated/stored after saving gateway settings.
- Verified in `TEST_RUNTIME.md` (`S1`/`S2`/`S3`) and rechecked via DB queries on 2026-02-14.

### P0 - Critical Bugs (found 2026-02-14 code audit)
- [x] **CRITICAL: Webhook signature bypass** — fixed: failed signature now returns 403 `INVALID_SIGNATURE` and handlers abort processing immediately (`if ( !$this->checkSignature(...) ) { return; }`). File: `webhook.php`.
- [x] **Missing `$_SERVER['HTTP_STRIPE_SIGNATURE']` isset check** — fixed: early-exit with 403 if header missing. File: `webhook.php` top of `manage()`.
- [x] **No try-catch on webhook JSON body parse** — fixed: `json_decode` result validated with `is_array` + `isset` check; returns 400 on invalid payload. File: `webhook.php` top of `manage()`.

### P1 - Safety / Reliability
- [x] Align Stripe API version pins to `2026-01-28.clover` across code paths.
- [x] Review webhook signature verification behavior and failure handling. (Fixed — see P0 Critical above.)
- [x] Review webhook event handling for idempotency and duplicate deliveries. (Fixed: webhook now stores processed Stripe `event.id` values in transaction `t_extra.xpolarcheckout_webhook_events` and short-circuits duplicate deliveries across refund/dispute/checkout handlers; keeps last 50 event IDs per transaction.)
- [x] Harden async webhook state handling (`checkout.session.async_payment_*`). (Fixed: corrected `if/elseif` branch for `payment_status` so `paid` no longer falls into `BAD_STATUS` error path; non-pending terminal statuses now return 200 to avoid retry loops.)
- [x] Review refund flow behavior for partial and full refunds. (Verified 2026-02-16 in Stripe test mode: partial refund transitioned `t_status` to `prfd`, full remaining refund transitioned to `rfnd`; webhook deliveries returned HTTP `200`.)
- [x] Add automated test coverage for tax-enabled Checkout payload shape (`automatic_tax`, `tax_behavior=exclusive`, billing collection). (Added script: `docs/automation/test_tax_payload.php`; run via `docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_tax_payload.php`.)
- [x] Add webhook outage recovery/replay flow for downtime windows. (Implemented task `tasks/webhookReplay.php` scheduled every 15 minutes via `data/tasks.json`; pulls Stripe Events API window, replays supported event types into local webhook with valid signature, and relies on event-id idempotency to avoid duplicates.)
- [x] **Transaction lookup error handling** — fixed: refund/dispute handlers now treat both `UnderflowException` and `OutOfRangeException` as `TRANSACTION_NOT_FOUND` (HTTP `200`), preventing webhook `500`s on unknown gateway IDs. File: `webhook.php`.

### P1 - Code Quality
- [x] **Gateway lookup inefficiency** — fixed: gateway resolved once at top of `manage()` with `break`; three duplicate loops removed. File: `webhook.php`.
- [x] **Amount calculation precision** — fixed: replaced `* 100` with `moneyToStripeMinorUnit()` using `\IPS\Math\Number` multiplication and currency decimals from `\IPS\nexus\Money::numberOfDecimalsForCurrency()`. File: `XPolarCheckout.php`.

### P1 - Product Display in Stripe Checkout
- [x] **Product name missing on Stripe Checkout page** — fixed: Checkout line item names now come from real Nexus invoice item names; generic invoice title is only used as fallback when an item name is empty.
- [x] **Multi-item cart shows single generic line item** — fixed: `auth()` now builds Stripe `line_items[]` from each Nexus invoice item (`name`, `quantity`, `unit_amount`), with a single-line fallback only when no valid invoice rows exist.

### P2 - Maintainability
- [x] Add code comments for gateway-webhook event mapping. (Added docblock event table + inline labels per handler.)
- [x] Document Stripe API version usage consistency across code paths.
- [x] Add tax operations runbook for Stripe dashboard setup (registrations, tax codes, customer location completeness).
- [x] Validate Stripe settlement block rendering on both customer invoice view and printable invoice. (Automated check confirms settlement hooks are registered, selectors still match Nexus template anchors, required Stripe rows are present in hook output, and legacy notes markers are absent.)
- [x] Add ACP payment integrity panel. (Added ACP module `monitoring/integrity` with webhook config status, replay cursor/run health, recent webhook error visibility, and Stripe-vs-IPS mismatch summary tables.)

### P3 - Tax Accuracy (DE/EU Focus)
- [x] Persist and surface Stripe `taxability_reason` and tax breakdown (jurisdiction/rate) per paid transaction. (Snapshot now stores `taxability_reason`, `taxability_reasons[]`, and `tax_breakdown[]` with tax rate metadata (name/percentage/jurisdiction), rendered in both invoice and print settlement blocks.)
- [x] Add Stripe-vs-IPS total mismatch indicator for paid invoices (read-only warning, no mutation). (Snapshot now stores `ips_invoice_total_*` + `has_total_mismatch`/`total_mismatch_*`, and settlement blocks render warning rows when totals diverge.)
- [x] Validate DE/EU VAT matrix in sandbox: DE B2C, EU B2C (OSS), EU B2B with VAT ID, non-EU customer. (Automated Stripe Tax calculation matrix PASS in test mode: DE B2C tax=95, EU B2C tax=100, EU B2B VAT tax=0 reverse_charge, non-EU tax=0 not_collecting.)
- [x] Validate full and partial refund tax behavior remains consistent in Stripe snapshot display. (Automated check validates snapshot consistency across refunded transactions and confirms both partial and full refund evidence via Stripe Refunds API for the same payment intent.)

### Post-Backlog Enhancements
- [x] Add manual webhook replay action in ACP integrity panel. (Admins can run replay immediately via `Run Webhook Replay Now` button without waiting for scheduler.)

---

## Issues Found (Code Audit 2026-02-16) — Completed

### P1 - Bugs

- [x] **Webhook auto-create missing async event types** — fixed: `testSettings()` now also registers `checkout.session.async_payment_succeeded` and `checkout.session.async_payment_failed` when creating the webhook endpoint.
- [x] **Webhook handler null-dereferences `$gateway`** — fixed: charge/dispute handlers now gracefully short-circuit with HTTP `200` if no `XPolarCheckout` gateway instance is available (and log to `xpolarcheckout_webhook`) instead of fataling.
- [x] **Webhook signature parsing fragile/unsafe** — fixed: `checkSignature()` now defensively parses header parts, supports multiple `v1` signatures, and validates with `hash_equals()` across all candidates.
- [x] **`formatTimestamp()` labels output as "UTC" but uses server-local time** — fixed: switched from `\date()` to `\gmdate()` in integrity panel formatter.
- [x] **Upgrade step `upg_10012/data.json` incomplete** — fixed: enabled `lang/modules/tasks` and added `setup/upg_10012/lang.json`, `setup/upg_10012/modules.json`, and `setup/upg_10012/tasks.json` to ship new integrity/replay assets in upgrades.
- [x] **`build.xml` stale** — fixed: added `admin/monitoring` module, `webhookReplay` task, and theme hooks (`theme_sc_clients_settle`, `theme_sc_print_settle`) to build manifest.

### P2 - Security (Low Risk)

- [x] **Unescaped JS output in `auth()`** — fixed: Stripe publishable key + session id are now embedded using JSON-encoded JS literals (`JSON_HEX_*` flags) before rendering redirect script.
- [x] **Replay task disables SSL verification** — fixed: TLS verification is now disabled only in `IN_DEV` environments; production path enforces `CURLOPT_SSL_VERIFYPEER=TRUE` and `CURLOPT_SSL_VERIFYHOST=2`.
- [x] **Template hook output unescaped for Stripe-sourced values** — mitigated: all Stripe/customer-facing URLs persisted into snapshot payloads are now normalized through strict URL validation (`http/https` only, valid host/scheme) before template rendering.

### P3 - Code Quality

- [x] **`checkValidity()` dead code** — fixed: removed unused settings decode.
- [x] **`getCustomer()` wrong docblock** — fixed: docblock now reflects actual transaction/settings parameters and return type.
- [x] **Replay task uses raw cURL instead of IPS HTTP framework** — fixed: replay forwarding now uses `\IPS\Http\Url::external()->request()->post()` and retains dev/prod TLS behavior via `sslCheck()`.

---

## Framework Standards Follow-up (2026-02-16) — Completed

### P1 - Output Escaping
- [x] **Unescaped template expression output for taxability reasons** — settlement hooks now build the reason string in a template variable and render with `{$var}` auto-escaping (no raw `{expression=""}` output).
  - `ips-dev-source/apps/xpolarcheckout/app-source/hooks/theme_sc_clients_settle.php:56`
  - `ips-dev-source/apps/xpolarcheckout/app-source/hooks/theme_sc_print_settle.php:55`

### P2 - Hook Resilience
- [x] **Hook exception handler should catch `\Throwable`** — gateway registry and JS-load hooks now use `catch ( \Throwable $e )` for full fallback coverage.
  - `ips-dev-source/apps/xpolarcheckout/app-source/hooks/code_GatewayModel.php:26`
  - `ips-dev-source/apps/xpolarcheckout/app-source/hooks/code_loadJs.php:32`

### P3 - String API Consistency
- [x] **String helper consistency in webhook module** — replaced webhook `\strtoupper()` / `\strtolower()` with `\mb_strtoupper()` / `\mb_strtolower()` for IN_DEV compliance.
  - `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php:564`
  - `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php:585`
  - `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php:674`
  - `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php:852`
  - `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php:976`

---

*Archived 2026-02-16. All items verified via code review and 7 automated test scripts (all PASS).*

---

## Active Backlog (2026-02-17) — Completed

### P0 - Webhook Endpoint Sync & Drift Detection (completed 2026-02-17)
- [x] Consolidated `REQUIRED_WEBHOOK_EVENTS` constant on gateway class and removed replay constant duplication.
- [x] `testSettings()` now stores `webhook_endpoint_id`.
- [x] Added gateway helpers `fetchWebhookEndpoint()` and `syncWebhookEvents()`.
- [x] Extended ACP integrity panel with endpoint drift detection (`missing`/`extra`, URL/API-version match, endpoint status).
- [x] Added `syncEvents()` ACP action with CSRF protection and ACP audit logging.
- [x] Added legacy endpoint-ID backfill helper.
- [x] Added webhook sync language keys and audit log language string.
- [x] Added automation test `docs/automation/test_webhook_sync.php` (A12).
- [x] Follow-up hardening completed:
  - coupon-name cap + best-effort fallback checkout path
  - stale `webhook_endpoint_id` recovery via URL fallback
  - automation test `docs/automation/test_discount_coupon_safety.php` (A13)
- [x] Verification:
  - Import-sync complete
  - 13/13 automation scripts PASS (A1-A13)

### P0 - Coupon Discount Bug (fixed 2026-02-17)
- [x] Coupon/discount amounts not passed to Stripe Checkout sessions. Fixed with `calculateInvoiceDiscount()` + `createOneTimeStripeCoupon()` and math verification fallback.

### P1 - Runtime Validation (completed 2026-02-17)
- [x] Validate `checkout.session.async_payment_succeeded/failed` flow with real async Stripe payment method in test mode (end-to-end webhook + transaction state).
- [x] Scope update: full dispute bank-network lifecycle validation is not tracked as a dev backlog test anymore; it is handled as live operations verification only.

### P1 - Chargeback Protection & Fraud Visibility (completed 2026-02-17)
- [x] Auto-ban on chargeback (`dispute_ban` setting, default ON). Refund ban removed.
- [x] Comprehensive dispute data extraction into `t_extra['xpolarcheckout_dispute']`.
- [x] Comprehensive refund data extraction into `t_extra['xpolarcheckout_refund']`.
- [x] Dispute closure metadata and history entries.
- [x] Checkout snapshot enrichment (customer email/name/address, card last4/brand/fingerprint, payment method type).
- [x] All webhook handler catch blocks upgraded to `\Throwable`.

### P2 - Next Planning Inputs (completed 2026-02-17)
- [x] Convert selected high-value `FUTURE_IDEAS.md` items into implementation-ready backlog tasks.

### P2 - ACP Dispute Visibility on Member Profile (completed 2026-02-17)
- [x] MemberACPProfileBlocks extension (`StripeDisputeSummary.php`) shows dispute/refund counts, latest dispute reason, ban status, and integrity panel link on ACP member profile.
- [x] Members with no disputes show no block (returns NULL).
- [x] Wrapped in `\Throwable` catch — never breaks the ACP member page.
- [x] Registered in `extensions.json` and `build.xml`.

### P3 - Stripe Radar Risk Data Capture (completed 2026-02-17)
- [x] `buildStripeSnapshot()` captures `risk_level`, `risk_score`, `outcome_type`, `outcome_seller_message` from `latest_charge.outcome`.
- [x] Client and print settlement hooks display payment method (type/brand/last4) and risk level (with score) when present.
- [x] Elevated/highest risk levels highlighted with warning style in client hook.
- [x] No automated actions based on risk score — display only.

*Archived 2026-02-17. All items verified via 9 automated test scripts (all PASS) and runtime import-sync.*
