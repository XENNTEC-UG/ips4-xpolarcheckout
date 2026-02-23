# X Polar Checkout App - Changelog

## 2026-02-23 - v1.0.5: Polar Invoice Generation + Enriched Snapshots

### Polar Invoice Generation
- Added `triggerInvoiceGeneration()` gateway method — fires `POST /v1/orders/{id}/invoice` (async, 202) after `order.paid` to request Polar-hosted invoice creation.
- Added `fetchInvoiceUrl()` gateway method — fetches `GET /v1/orders/{id}/invoice` URL when `order.updated` arrives with `is_invoice_generated=true`.
- Invoice URL persisted to `customer_invoice_url` in both transaction `t_extra` and invoice `i_status_extra` snapshots.
- URL preservation guard: later webhook events (e.g. `order.refunded`) no longer overwrite a previously-fetched invoice URL.

### Enriched Settlement Snapshots
- Added 13+ new fields to `buildPolarSnapshot()` from the Polar Order model:
  - `discount_amount_minor/display` — provider-side discount amount.
  - `net_amount_minor/display` — net amount after discount.
  - `refunded_tax_amount_minor/display` — tax portion of refunds.
  - `polar_invoice_number` — Polar-generated invoice number.
  - `billing_name`, `billing_reason` — billing context from order.
  - `is_invoice_generated` — invoice generation status flag.
  - `customer_email`, `customer_name` — customer details from order.
  - `customer_tax_id` — array of tax ID strings from customer object.
  - `discount_name`, `discount_code` — coupon details from discount object.
  - `line_items` — array of `{label, amount, tax_amount}` from order items.
- Replaced dead URL extraction code with explicit NULL placeholders and proper API-driven URL fetch.

### Invoice View Enhancements
- Discount section now uses explicit `discount_amount_minor` from provider (falls back to computed).
- Added discount name/code display row in Charge Summary.
- Added refunded tax row when refunded tax amount is present.
- Added Polar invoice number, billing name, customer email, billing reason, and customer tax ID rows in Payment & References.
- Print hook and clients settle hook updated with matching enriched fields.

### Language Strings
- Added 13 new language keys for enriched snapshot display (discount, net amount, invoice number, billing, customer, tax, billing reason labels).

## 2026-02-23 - v1.0.4: Stripe-Parity Invoice View

### Invoice View (Stripe parity)
- Implemented full two-column invoice layout matching xstripecheckout's UX: Order Details (left) + Polar Charge Summary (right).
- Charge Summary box: subtotal, discount (computed), net subtotal, tax, total charged (bold divider), refunded amount (red), provider status badge (color-coded: paid/refunded/partially_refunded/pending).
- Payment & References box: captured timestamp, Polar Order ID with View Invoice / Download PDF buttons, Polar Checkout ID with View Receipt button, source-of-truth footer.
- Tax-explains-difference info message and total mismatch warning matching Stripe's pattern.
- Global order details enhancements: products subtotal before coupons, tag icon replacement, green coupon pricing, duplicate coupon section hiding. Idempotency guard prevents double-processing when both Stripe and Polar apps are active.
- Clients page settlement hook: enhanced order total box showing provider total (incl. tax) + IPS invoice total comparison with full status-dependent action buttons.
- Print invoice hook: charge summary + payment references tables in print-friendly format.
- Added 7 new language strings for provider status badges and refund display.

## 2026-02-23 - Unreleased: Checkout Flow Controls + Label Modes

- Added ACP setting `Checkout flow mode`:
  - `Allow Polar for all carts` (default behavior).
  - `Hybrid route: show Polar only for single-item carts` (hides Polar in checkout when invoice has more than one payable line).
- Added ACP setting `Multi-item receipt label mode` for consolidated Polar checkouts:
  - `Use first item name (legacy)`.
  - `Use neutral label (Invoice # + item count)`.
  - `Use item list label (Item A + Item B + ...)`.
- Added runtime guard in `auth()` for single-item-only mode to prevent direct multi-item authorization if the gateway is forced via non-standard flow.
- Implemented checkout-label product cache (`xpolarcheckout_checkout_label_products` in datastore) to reuse synthetic Polar products for explicit consolidated receipt titles.
- Documented official post-dependency goal: migrate from consolidated fallback to native provider-side additive multi-line checkout/invoice parity once Polar supports it.

## 2026-02-23 - v1.0.3: Dynamic Polar Product Mapping

### Feature: Dynamic Product Mapping
- Polar checkout receipts/emails now show the actual IPS Nexus product name instead of generic "IPS4 NEXUS DEFAULT".
- New `ensurePolarProduct()` method creates Polar products on-demand at first checkout, cached in `xpc_product_map` table.
- Package-type invoice items (`\IPS\nexus\extensions\nexus\Item\Package`) are mapped to dedicated Polar products; non-package items (renewals, charges) fall back to the default product.
- Name sync at checkout time: if the IPS package name has changed since last checkout, the Polar product is PATCHed automatically.
- Safe catalog placeholder price (EUR 9.99) on Polar products — ad-hoc prices override at checkout, but prevents zero-cost access if someone reaches the product directly on Polar.
- Non-blocking: all product creation/sync failures are caught and fall back to the default product silently.

### ACP: Product Mappings Viewer
- New ACP page at `Monitoring > Product Mappings` showing all IPS package ↔ Polar product mappings.
- Table columns: Package ID, Product Name, Polar Product ID, Last Synced.
- Quick search by product name.
- "Sync All Names" button iterates all mappings, fetches current IPS package names, and PATCHes Polar products where names differ.

### Multi-Item Checkout Consolidation
- `auth()` iterates invoice items and creates Polar products for each package-type item on-demand.
- **Polar API limitation**: multiple products in `products[]` are treated as radio-button choices, not line items. Fix: when an invoice has multiple items, all are consolidated into the first product's Polar entry with the combined total amount.
- Single-item invoices show the correct product name at the correct price (most common case).
- Multi-item invoices show the first item's product name at the combined invoice total.
- Checkout metadata includes `ips_item_names` (up to 3) for audit trail.

### New DB Table
- `xpc_product_map`: `map_id`, `ips_package_id` (UNIQUE), `polar_product_id`, `product_name`, `created_at`, `updated_at`.

### Scope Requirement
- Polar API token needs `products:write` scope for product creation/update. If token lacks this scope, product creation fails silently and falls back to default.

## 2026-02-23 - v1.0.2: Webhook Processing Fixes

### Critical Fix: Transaction Processing Lock
- Fixed `acquireTransactionProcessingLock()` and `releaseTransactionProcessingLock()` — replaced `\IPS\Db::i()->select()` with `\IPS\Db::i()->query()` for MySQL `GET_LOCK()`/`RELEASE_LOCK()` calls. IPS4's `convert_hook_Db` overrides `select()` and requires at least 2 arguments (columns + table), causing every single-argument call to throw `Too few arguments` silently. This prevented ALL webhook events from being processed since initial deployment.

### Fix: Transaction Resolution Gap
- Expanded `resolveTransactionFromPayload()` to also match `checkout_id` and `checkout.*` event object IDs against `t_gw_id`. Previously only `order_id` and `order.*` IDs were tried, but `auth()` stores the Polar checkout UUID as `t_gw_id` — so early `checkout.*` events could not find their transaction when metadata was missing.

### Verified (2026-02-23)
- Full end-to-end paid checkout: Store product → Cart → IPS Checkout → Polar sandbox hosted checkout → test card payment → webhook delivery → transaction `okay` (paid) + invoice `paid`.
- 7 webhook events processed on single transaction: `checkout.created`, `checkout.updated` (x2), `order.updated` (x2), `order.paid`, `order.created`.
- Settlement snapshot correctly captured: subtotal/tax/total with `has_total_mismatch: false`.
- `t_gw_id` updated from checkout UUID to Polar order UUID on `order.*` events.

## 2026-02-22 - Webhook Endpoint Lifecycle + Runtime Scope Diagnosis

- Implemented webhook endpoint lifecycle wiring in `app-source/sources/XPolarCheckout/XPolarCheckout.php`:
  - `testSettings()` now attempts automatic endpoint creation and stores `webhook_endpoint_id` when available.
  - `syncWebhookEvents()` now performs real `PATCH /v1/webhooks/endpoints/{id}` with `REQUIRED_WEBHOOK_EVENTS`.
  - `fetchWebhookEndpoint()` now supports endpoint discovery by matching configured `webhook_url` against provider endpoint list when endpoint id is missing.
- Hardened endpoint error handling:
  - endpoint create/sync now validate provider response shape (`id` required).
  - provider error payloads now surface readable runtime messages (instead of silent no-op behavior).
  - creation failures are logged under `xpolarcheckout_webhook_endpoint`.
- Runtime verification:
  - checkout API remains functional with configured sandbox token (`POST /v1/checkouts/` -> `201 Created`).
  - webhook endpoint API currently returns `401 Unauthorized` for the same token (`GET /v1/webhooks/endpoints/`), indicating missing/insufficient webhook scopes on current token.

## 2026-02-22 - ACP Presentment Currency Control

- Added new gateway ACP setting: `Default presentment currency`.
- On gateway save, `testSettings()` now syncs Polar organization `default_presentment_currency` using Polar API.
- Persisted organization metadata in gateway settings:
  - `organization_id`
  - `organization_default_presentment_currency`
- Added currency guardrails:
  - gateway validity now rejects non-matching transaction currencies with `xpolarcheckout_presentment_currency_mismatch`.
  - checkout payload now sets explicit top-level `currency`.
- Validation:
  - runtime `testSettings()` call successfully synced org currency and returned normalized metadata.
  - sandbox checkout API accepted EUR-only payload after sync (`201`).

## 2026-02-22 - Runtime Verification Pass + Signature Secret Normalization

- Fixed webhook/replay secret normalization for Polar CLI hex secrets:
  - `app-source/modules/front/webhook/webhook.php`
  - `app-source/tasks/webhookReplay.php`
- Normalization now applies `whsec_` strip, then hex-byte decode, then base64 fallback, then raw fallback.
- Verified behavior with live runtime probes:
  - valid HMAC built from hex-key bytes -> `200 SUCCESS`
  - incorrect HMAC built from base64-decoded key path -> `403 INVALID_SIGNATURE`
- Re-applied `xpolarcheckout` schema in runtime and verified forensic persistence:
  - `xpc_webhook_forensics` exists and writes rows for `missing_signature`, `invalid_signature`, and `timestamp_too_old`.
- Performed ACP regression checks (MCP browser):
  - gateway appears in payment method selection (`X Polar Checkout`)
  - gateway edit/save succeeds
  - integrity actions (`Dry Run`, `Run Webhook Replay Now`) execute successfully
- Identified current Polar sandbox blocker:
  - checkout request fails when payload omits org default presentment currency (`default_presentment_currency=usd` in current org).
  - tracked in `docs/BACKLOG.md` as top user/environment prerequisite.

## 2026-02-22 - Infrastructure: Polar CLI Docker Service

- Added `polar-cli` Docker service for local webhook forwarding via Polar SSE endpoint.
- Service auto-syncs all gateway settings (`webhook_secret`, `access_token`, `environment`, `default_product_id`) from `.env` to `nexus_paymethods` on every container start.
- Added `.env` variables: `POLAR_ACCESS_TOKEN`, `POLAR_ORG_ID`, `POLAR_DEFAULT_PRODUCT_ID`, `POLAR_FORWARD_TO`, `POLAR_ENVIRONMENT`.
- Docker profile: `polar`. Enable with `COMPOSE_PROFILES=...,polar,...`.
- Custom Dockerfile with official Docker CE CLI + Polar CLI v1.2.0 binary.
- Entrypoint handles SSE parsing, secret extraction, DB auto-sync, event forwarding with Standard Webhooks headers, and automatic reconnection.
- Updated `docs/POLAR_CLI_LOCAL_DEBUG.md` with Docker approach (primary) and WSL fallback.
- Note: Infrastructure files live in main repo (`docker/polar-cli/`, `compose.yaml`, `.env.example`), not in this submodule.

## 2026-02-22 - v1.0.1

- Fixed install blocker where schema creation could fail on missing table `name` metadata for `xpc_webhook_forensics`.
- Added recovery upgrade package `setup/upg_10001` to reapply hooks/modules/tasks for partially installed environments.
- Updated app version metadata to `1.0.1` / `10001`.
- Completed Phase 2 B1 signature hardening in webhook controller.
- Completed provider-name cleanup across `xpolarcheckout` docs.
- Completed Phase 2 B2 webhook event-map pass in `app-source/modules/front/webhook/webhook.php`:
  - Added explicit transitions for `order.updated`, `order.refunded`, `checkout.updated`, and `refund.updated`.
  - Added safe pending/paid/refused/refunded transition helpers with terminal-state guardrails.
  - Improved refund-state classification using Polar fields (`total_amount`, `refunded_amount`, refund `amount` on succeeded updates).
  - Updated snapshot extraction to persist total/refunded amounts from Polar-native fields.
- Added Phase 2 B3 payload hardening in `app-source/sources/XPolarCheckout/XPolarCheckout.php`:
  - `price_currency` now uses lowercase ISO format expected by Polar API enums.
  - `moneyToMinorUnit()` now uses IPS currency decimal precision via `\IPS\nexus\Money::numberOfDecimalsForCurrency()` + `\IPS\Math\Number` (no fixed 2-decimal assumption).
- Completed B3 checkout payload compatibility fix and sandbox validation:
  - fixed checkout payload shape to `prices[product_id] = [ { ...price override... } ]` (list format required by Polar API).
  - validated `POST /v1/checkouts/` returns `open` checkout with hosted URL in sandbox.
  - validated refund request schema against sandbox (`POST /v1/refunds/`) with expected provider-side `Order not found` for unknown order id.
- Completed B4 replay pipeline rewrite in `app-source/tasks/webhookReplay.php`:
  - replaces placeholder state-touch task with real replay source from Polar `/v1/webhooks/deliveries`.
  - filters + dedupes candidates by `REQUIRED_WEBHOOK_EVENTS` and webhook event id.
  - replays payloads through local webhook controller with Standard Webhooks headers/signature.
  - supports dry-run output and persisted replay cursor (`last_run_at`, `last_event_created`, `last_event_id`, `last_replayed_count`).
  - runtime guardrails: lookback, overlap, max events, max pages, max runtime.
  - runtime live execution verified: non-dry run updates replay state without exceptions when no replayable events are present.
- Added replay guardrail settings to gateway config:
  - `replay_lookback`, `replay_overlap`, `replay_max_events` with clamped bounds in `testSettings()`.
- Integrity panel now renders explicit environment badge (`SANDBOX` / `PRODUCTION`) from gateway settings.
- Normalized settlement snapshot schema in webhook persistence (`app-source/modules/front/webhook/webhook.php`):
  - added display keys for provider and IPS totals (`amount_total_display`, `ips_invoice_total_display`).
  - added comparison/mismatch keys (`total_difference_display`, `has_total_mismatch`, `total_mismatch_display`).
  - added subtotal/tax/refund display fields when provider payload includes values.
  - improved snapshot error observability by logging invoice status-extra write failures to `xpolarcheckout_snapshot`.
  - verified comparison behavior in IPS runtime (`applyIpsInvoiceTotalComparison`): exact match, tax-explained difference, and mismatch paths.
- Added webhook runtime hardening for invalid transaction gateway references:
  - webhook controller now returns `INVALID_GATEWAY_SETTINGS` (HTTP 400) when resolved transaction has non-object/invalid method data instead of triggering a PHP warning/500.
  - file: `app-source/modules/front/webhook/webhook.php`.
- Additional automated runtime validation pass completed:
  - gateway registration runtime checks passed (`XPolarCheckout` in gateway map + roots).
  - replay dry-run and live execution checks passed.
  - signature smoke checks passed (`missing`, `invalid`, `stale`).
  - Polar sandbox API contract checks passed:
    - checkout payload accepted (`status=open`, checkout URL returned).
    - refund payload schema accepted (unknown order returns expected provider error).

## 2026-02-21 - Baseline Hardening

- Added timestamp freshness tolerance in webhook signature validation.
- Improved webhook forensics logging for invalid signatures and stale payloads.
- Confirmed app install/enable path and ACP gateway discovery behavior after migration cleanup.

## 2026-02-18 - Migration Baseline Normalization

- Normalized app key, namespaces, extension names, and setup metadata for `xpolarcheckout`.
- Removed obsolete release artifacts and legacy migration debris from app packaging.
- Consolidated implementation planning and testing docs for Polar migration phases.
