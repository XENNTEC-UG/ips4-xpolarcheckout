# XPolarCheckout Audit Snapshot (2026-02-12)

Scope audited: `ips-dev-source/apps/xpolarcheckout/app-source` (source of truth).

## Critical Findings

1. Amount conversion is not minor-unit safe (always `* 100`).
- File: `ips-dev-source/apps/xpolarcheckout/app-source/sources/XPolarCheckout/XPolarCheckout.php:103`, `ips-dev-source/apps/xpolarcheckout/app-source/sources/XPolarCheckout/XPolarCheckout.php:358`
- Risk: zero-decimal currencies can be overcharged/over-refunded.

2. Async webhook flow throws even after successful paid handling.
- File: `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php:281`, `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php:295`, `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php:305`
- Risk: `checkout.session.async_payment_succeeded` can still return 500 and trigger retries.

3. Invalid webhook signatures return HTTP 200.
- File: `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php:324`
- Risk: misconfiguration causes silent webhook loss (Stripe stops retrying on 2xx).

4. Refund API calls have no idempotency key.
- File: `ips-dev-source/apps/xpolarcheckout/app-source/sources/XPolarCheckout/XPolarCheckout.php:370`
- Risk: duplicate refunds on retry/timeout conditions.

## High Findings

5. Signature parser lacks replay-window checks and robust multi-signature handling.
- File: `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php:316`

6. Auto-created Stripe webhook endpoint omits async Checkout events.
- File: `ips-dev-source/apps/xpolarcheckout/app-source/sources/XPolarCheckout/XPolarCheckout.php:320`

7. `charge.refunded` path always marks full refund and may ban members.
- File: `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php:41`, `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php:68`

8. Refund/dispute branches resolve gateway by scanning roots (not transaction-bound config).
- File: `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php:43`, `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php:81`, `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php:131`

9. No persistent processed-event ledger (`event.id`) for webhook idempotency.
- File: `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php:33`

10. Missing safe defaults for optional/new settings keys.
- File: `ips-dev-source/apps/xpolarcheckout/app-source/sources/XPolarCheckout/XPolarCheckout.php:66`

## Medium Findings

11. Webhook auto-creation only runs when both URL and secret are missing.
- File: `ips-dev-source/apps/xpolarcheckout/app-source/sources/XPolarCheckout/XPolarCheckout.php:316`

12. Webhook-create response is not shape-validated before saving settings.
- File: `ips-dev-source/apps/xpolarcheckout/app-source/sources/XPolarCheckout/XPolarCheckout.php:333`

13. Inconsistent Stripe API versions in app paths.
- File: `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php:218`

## Low Findings

14. Always-on debug logging in async webhook path.
- File: `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php:260`, `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php:269`, `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php:274`

## Key Uncertainty Flags

- Multi-gateway XPolarCheckout installations may be impacted by root-scan secret selection. This depends on runtime configuration.
- Partial refund semantics in webhook processing need confirmation against desired business behavior before changing status transitions.

## Status

- Logged only. No production code fixes included in this snapshot file.

## Local Runtime Debug Log (2026-02-12)

### Context

- Goal: make Stripe sandbox webhook testing work against local IPS Docker stack.
- Local endpoint: `http://host.docker.internal/index.php?app=xpolarcheckout&module=webhook&controller=webhook`.

### Observed Failures

1. Stripe Dashboard endpoint creation failed with `Invalid URL`.
- Cause: Dashboard requires public HTTPS endpoint; localhost/internal hostnames are rejected.
- Resolution: use local Stripe CLI forwarder only (`stripe-cli` service).

2. Initial webhook forwarding produced `POST ... [500]` for `checkout.session.completed`.
- Cause: synthetic trigger lacked IPS-required `checkout_session.metadata.transaction`.
- Resolution: trigger with metadata override:
  - `docker compose exec -T stripe-cli stripe trigger checkout.session.completed --add checkout_session:metadata.transaction=1`

3. Secret mismatch risk during listener restarts.
- Check added:
  - compare `whsec_...` from `docker compose logs --tail 40 stripe-cli`
  - against DB value in `nexus_paymethods.m_settings.webhook_secret`.

### Verified Working State

- `docker compose ps` shows `stripe-cli` running with core stack services.
- Listener forwards to `host.docker.internal` webhook URL.
- Current listener `whsec_...` equals IPS stored XPolarCheckout webhook secret.
- Latest triggered `checkout.session.completed` deliveries return HTTP `200`.

### Recovery Commands (Fast Path)

1. `docker compose up -d`
2. `docker compose logs --tail 40 stripe-cli`
3. `docker compose exec -T db mysql -uroot -pREDACTED_DB_PASSWORD ips -e "SELECT m_gateway,JSON_UNQUOTE(JSON_EXTRACT(m_settings,'$.webhook_secret')) AS webhook_secret FROM nexus_paymethods WHERE m_gateway='XPolarCheckout';"`
4. `docker compose exec -T stripe-cli stripe trigger checkout.session.completed --add checkout_session:metadata.transaction=1`
5. `docker compose logs --since 2m stripe-cli`

## API Version Alignment Log (2026-02-12)

### Objective

- Align Stripe Checkout app and local listener tooling to Stripe API version `2026-01-28.clover`.

### Completed Changes

1. App request version pin updated:
- `ips-dev-source/apps/xpolarcheckout/app-source/sources/XPolarCheckout/XPolarCheckout.php`
- `const STRIPE_VERSION = '2026-01-28.clover'`

2. Webhook PaymentIntent fetch uses shared app version pin:
- `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php`
- Replaced hardcoded `2022-11-15` with `\IPS\xpolarcheckout\XPolarCheckout::STRIPE_VERSION`.

3. Auto-created Stripe webhook endpoint now pins endpoint API version:
- `ips-dev-source/apps/xpolarcheckout/app-source/sources/XPolarCheckout/XPolarCheckout.php`
- Added `api_version => self::STRIPE_VERSION` to webhook endpoint creation payload.

4. Local Docker Stripe listener updated:
- `compose.yaml`
- Added `stripe listen --latest` so local forwarded events are emitted in latest Stripe schema.

5. Source-to-mirror sync executed:
- `powershell -ExecutionPolicy Bypass -File .\scripts\ips-dev-sync.ps1 -Mode import`
- Confirmed mirrored files in `data/ips/applications/xpolarcheckout` contain the same version pin updates.

### Verification Results

- `docker compose logs --tail 80 stripe-cli` shows:
  - `Ready! You are using Stripe API Version [2026-01-28.clover]`
- Trigger and delivery:
  - `docker compose exec -T stripe-cli stripe trigger checkout.session.completed --add checkout_session:metadata.transaction=1`
  - Follow-up logs show webhook delivery `POST ... [200]`.

## Product Currency Repair (USD -> EUR) (2026-02-12)

### Issue Summary

- Store currency config was switched to EUR:
  - `core_sys_conf_settings.nexus_currency = {"EUR":[1]}`
- Product catalog pricing still referenced USD:
  - `nexus_packages.p_base_price` contained `{"USD": ...}` for all 54 products.
  - `nexus_packages.p_renew_options` still contained USD for renewal-enabled products.
- Price cache table was inconsistent:
  - `nexus_package_base_prices` had only 5 rows, all `EUR = NULL`.
  - 49 package IDs had no cache row at all.

### Backup Created

- `ips-dev-source/apps/xpolarcheckout/docs/archive/sql-backups/price-migration-backup-20260212-171849.sql`
- Contains: `nexus_packages`, `nexus_package_base_prices`

### Repair Applied

1. Converted product base-price JSON to EUR while preserving numeric amounts.
2. Converted renewal-option JSON currency tokens from USD to EUR.
3. Rebuilt `nexus_package_base_prices` cache:
   - inserted missing rows for package IDs
   - populated `EUR` from package base-price JSON

### Post-Repair Validation

- Package pricing JSON:
  - `pkg_base_usd=0`, `pkg_base_eur=54`
  - `pkg_renew_usd=0`, `pkg_renew_eur=2`
- Base-price cache:
  - `base_price_rows=54`, `eur_null_rows=0`, `eur_set_rows=54`
- Sample rows now show:
  - `{"EUR":{"amount":"...","currency":"EUR"}}`

### Notes

- Historical financial records were intentionally not rewritten:
  - `nexus_purchases.ps_renewal_currency='USD'` rows still exist (4).
  - `nexus_invoices.i_currency='USD'` rows still exist (3).
- This preserves historical integrity and avoids mutating already-issued records.

## Tax Validation + Hardening (2026-02-12)

### Verified Payment

- Stripe test payment succeeded:
  - PaymentIntent: `pi_REDACTED_TEST_001`
  - Currency/amount: `EUR 5.00`
- IPS reconciliation:
  - `nexus_transactions.t_status='okay'`
  - linked invoice `i_id=10` status `paid`.

### Stripe Tax Signal From API Logs

- Stripe invoice for this payment: `in_REDACTED_TEST_001`
- Automatic tax state:
  - `automatic_tax.enabled = true`
  - `automatic_tax.status = complete`
- Tax amount remained `0` with `taxability_reason = not_collecting`.
- Interpretation:
  - Checkout tax flow is technically working, but Stripe account tax registrations for the customer jurisdiction are not set to collect tax.

### Code Hardening Applied

1. Configurable VAT behavior with safe defaults:
- Stripe tax is enabled by default when setting is unset/new.
- Stripe tax can be disabled completely by admin (`tax_enable=false`).
- When Stripe tax is enabled, Checkout tax behavior is configurable (`exclusive` or `inclusive`) and defaults to `exclusive`.

2. Improved location capture for Stripe Tax:
- When tax is enabled, Checkout now forces `billing_address_collection='required'`.
- Also sets `customer_update` with `address` and `name` auto-sync.

3. Stripe-compatible payload typing:
- `automatic_tax.enabled` and `invoice_creation.enabled` use `'true'` tokens so form-encoded Stripe requests do not serialize to invalid `1/0` values.

4. Settings safety:
- Tax behavior is validated against allowed values and defaults to `exclusive` when missing/invalid.

## Phase A: Stripe Settlement Snapshot Sync For IPS Invoice Display (2026-02-12)

### Problem

- IPS invoice totals are based on local invoice item/tax model.
- With Stripe automatic tax, real collected VAT can differ from what IPS line-items currently show.
- Requirement: keep Stripe as source of truth for Stripe Checkout payments while avoiding risky core Nexus tax rewrites.

### Implemented (Low-Risk)

1. Added Stripe snapshot persistence in `checkout.session.completed` path:
- File: `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php`
- Captures session/invoice settlement values:
  - `currency`
  - `amount_subtotal_minor`
  - `amount_tax_minor`
  - `amount_total_minor`
  - `automatic_tax_status`
  - Stripe `payment_intent` and `invoice` IDs

2. Persisted snapshot in both transaction and invoice metadata:
- `nexus_transactions.t_extra['xpolarcheckout_snapshot']`
- `nexus_invoices.i_status_extra['xpolarcheckout_snapshot']`

3. Initial display approach (superseded):
- Stripe settlement was first written into `invoice->notes` as a marker block.
- This was later replaced with dedicated read-only theme hook blocks (Phase B) because notes are user-editable and not clean for system settlement data.

4. Kept behavior-compatible payment flow:
- No change to existing paid/pending/dispute/refund status transitions.
- No Nexus core tax engine modifications.
- If Stripe invoice fetch fails, payment processing still continues.

### Runtime/Sync Validation

- Source lint:
  - `docker compose exec -T php php -l /workspace/ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php`
- Runtime lint:
  - `docker compose exec -T php php -l /var/www/html/applications/xpolarcheckout/modules/front/webhook/webhook.php`
- Sync:
  - `powershell -ExecutionPolicy Bypass -File .\scripts\ips-dev-sync.ps1 -Mode import`

### Remaining Risk (Explicit)

- Existing signature verification logic still has known weaknesses documented in Critical findings (not changed in this phase).
- Full end-to-end verification of new snapshot write requires a fresh non-paid test transaction.

## Phase B: Dedicated Stripe Settlement Display Block (2026-02-12)

### Objective

- Remove Stripe system settlement data from editable invoice notes.
- Show Stripe settlement in a clean, read-only invoice/print block sourced from structured metadata.

### Implemented

1. Webhook persistence cleanup:
- File: `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php`
- `checkout.session.completed` now:
  - persists snapshot to transaction/invoice metadata only,
  - adds display-friendly fields (`amount_*_display`, `captured_at_iso`),
  - adds Stripe dashboard links (`dashboard_invoice_url`, `dashboard_payment_url`),
  - removes any legacy marker block from `invoice->notes`.

2. Added theme hooks for clean rendering:
- File: `ips-dev-source/apps/xpolarcheckout/app-source/data/hooks.json`
  - `theme_sc_clients_settle` -> `\IPS\Theme\class_nexus_front_clients`
  - `theme_sc_print_settle` -> `\IPS\Theme\class_nexus_global_invoices`
- Files:
  - `ips-dev-source/apps/xpolarcheckout/app-source/hooks/theme_sc_clients_settle.php`
  - `ips-dev-source/apps/xpolarcheckout/app-source/hooks/theme_sc_print_settle.php`
- Output:
  - dedicated "Stripe settlement" block on invoice page,
  - dedicated Stripe settlement section on printable invoice.

3. Runtime registration:
- Added hooks to `core_hooks` (local dev DB) with filenames:
  - `theme_sc_clients_settle`
  - `theme_sc_print_settle`

4. Legacy data cleanup:
- Removed prior marker block from existing notes rows in `nexus_invoices`.

### Verification

- PHP lint passed for webhook + new hooks in source and runtime mirror.
- `core_hooks` contains 4 XPolarCheckout hooks (2 C + 2 S).
- Paid invoice `i_id=11` still has correct Stripe snapshot data in `i_status_extra`.

## Phase C: Customer-Facing Stripe Links In Settlement Block (2026-02-12)

### Problem

- Settlement block links used Stripe Dashboard URLs only.
- These URLs are admin-only and not usable for normal customers.

### Implemented

1. Webhook snapshot enrichment:
- File: `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php`
- Added customer-facing fields to `xpolarcheckout_snapshot`:
  - `customer_invoice_url` from Stripe invoice `hosted_invoice_url`
  - `customer_invoice_pdf_url` from Stripe invoice `invoice_pdf`
  - `customer_receipt_url` from Stripe charge `receipt_url` (resolved from PaymentIntent latest charge)

2. Client invoice rendering update:
- File: `ips-dev-source/apps/xpolarcheckout/app-source/hooks/theme_sc_clients_settle.php`
- Moved Stripe links inline next to:
  - `Stripe invoice ID` (`View invoice`, `PDF`)
  - `Stripe payment intent` (`View receipt`)
- Removed standalone bottom row with dashboard-only links.

3. Print invoice rendering update:
- File: `ips-dev-source/apps/xpolarcheckout/app-source/hooks/theme_sc_print_settle.php`
- Added customer-facing invoice/receipt links in the matching ID rows.

### Backward Compatibility Notes

- Existing snapshots remain valid; they simply won’t show new customer links until a new payment/webhook stores the new fields.
- Existing `dashboard_*` fields are still kept in snapshot for compatibility/debugging, but no longer shown in customer invoice block.

