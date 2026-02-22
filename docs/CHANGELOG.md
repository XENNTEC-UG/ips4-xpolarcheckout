# X Polar Checkout App - Changelog

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

## 2026-02-21 - Baseline Hardening

- Added timestamp freshness tolerance in webhook signature validation.
- Improved webhook forensics logging for invalid signatures and stale payloads.
- Confirmed app install/enable path and ACP gateway discovery behavior after migration cleanup.

## 2026-02-18 - Migration Baseline Normalization

- Normalized app key, namespaces, extension names, and setup metadata for `xpolarcheckout`.
- Removed obsolete release artifacts and legacy migration debris from app packaging.
- Consolidated implementation planning and testing docs for Polar migration phases.
