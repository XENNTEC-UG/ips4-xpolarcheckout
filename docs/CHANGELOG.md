# X Polar Checkout App - Changelog

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

## 2026-02-21 - Baseline Hardening

- Added timestamp freshness tolerance in webhook signature validation.
- Improved webhook forensics logging for invalid signatures and stale payloads.
- Confirmed app install/enable path and ACP gateway discovery behavior after migration cleanup.

## 2026-02-18 - Migration Baseline Normalization

- Normalized app key, namespaces, extension names, and setup metadata for `xpolarcheckout`.
- Removed obsolete release artifacts and legacy migration debris from app packaging.
- Consolidated implementation planning and testing docs for Polar migration phases.
