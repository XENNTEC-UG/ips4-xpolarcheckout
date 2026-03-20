# X Polar Checkout — Architecture

## 1) Objective

Production-ready IPS4 Nexus payment gateway app (`xpolarcheckout`) backed by Polar hosted checkout and Polar webhooks, preserving proven reliability patterns from the existing gateway architecture (sibling to `xstripecheckout` and `xpaynowcheckout`).

## 2) External References

- Polar overview: `https://polar.sh/docs/introduction`
- Polar PHP SDK: `https://polar.sh/docs/integrate/sdk/php`
- Polar CLI repository: `https://github.com/polarsource/cli`

## 3) Current State

- Version: `1.0.13` / `10013`
- Gateway registration and recovery shipped in `v1.0.1`
- Standard Webhooks signature validation hardening implemented
- Forensics table: `xpc_webhook_forensics`
- Integrity ACP module, forensics viewer, and replay pipeline operational
- Polar CLI Docker service operational (SSE tunnel, auto-syncs settings from `.env`)
- Paid checkout verified (v1.0.2), invoice generation + settlement snapshots (v1.0.5)
- IPS coupon forwarding (v1.0.6), dynamic product mapping (v1.0.3)
- Refund flow implemented, pending end-to-end test (GitHub Issue #8)

## 4) Reuse Strategy

### Keep (High Value, Low Risk)

- IPS app/module structure and routing
- Transaction and invoice linkage model
- Forensics storage and ACP visibility patterns
- Idempotency and duplicate-delivery guards
- Replay runtime guardrails (lookback/overlap/max events/runtime caps)
- Integrity panel shell, cards, and action workflow

### Replace (Provider-Specific)

- Checkout session creation call and payload
- Webhook signature verification (Standard Webhooks)
- Event-to-status mapping logic
- Refund provider API call in gateway `refund()`
- Replay event source and cursor strategy
- Snapshot extraction keys for settlement display

## 5) Architecture

1. Nexus transaction enters gateway `auth()`
2. Gateway requests Polar checkout session and receives redirect URL
3. Customer pays in hosted checkout
4. Polar webhook delivers authoritative order/refund state changes
5. Webhook controller validates signature and timestamp window
6. Mapper updates IPS transaction state and stores normalized snapshot
7. Integrity panel surfaces health and replay diagnostics
8. Replay task handles recovery when webhook deliveries were missed

## 6) Provider Integration Design

### 6.1 Checkout Session

- Inputs: IPS transaction id, invoice id, amount/currency, return/cancel URLs, member identity metadata
- Output: provider checkout URL, provider checkout id
- Rules:
  - Transaction is set to `STATUS_GATEWAY_PENDING` before session creation to persist `$transaction->id`
  - Persist provider checkout id in `t_gw_id` for traceability
  - Explicit `currency` field in checkout payload (must match configured presentment currency)
  - Dynamic Polar product mapping per IPS package via `ensurePolarProduct()`, with fallback to generic `default_product_id`
  - Per-transaction price override via `prices` array
  - Multi-item invoices consolidated into single product with combined total (Polar does not support additive multi-line checkout)

### 6.2 Webhook Verification

- Standard Webhooks signature format (HMAC-SHA256)
- Validate expected signature header presence
- Parse and validate timestamp
- Enforce drift tolerance window (default 600 seconds)
- Verify HMAC against configured secret (handles both hex and base64 secret formats)
- Reject invalid payloads with forensic log entry

### 6.3 Event Mapping

Map provider event families into IPS status transitions (7 required events):

- `order.created` → gateway pending state (unless terminal)
- `order.paid` → `STATUS_PAID` + snapshot + invoice generation
- `order.updated` → paid/refunded/partially_refunded state transitions + invoice URL fetch
- `order.refunded` → `STATUS_REFUNDED`
- `refund.created` → no-op (acknowledged without state change)
- `refund.updated` → partial: `STATUS_PART_REFUNDED`, full: `STATUS_REFUNDED`
- `checkout.updated` (failed/expired) → `STATUS_REFUSED`

Invariants:

- Idempotent by provider event id
- No terminal-state regression
- Duplicate deliveries return success without double-processing

### 6.4 Refunds

- `refund($amount)` calls Polar refund endpoint with provider order reference
- Convert amount using IPS currency decimal precision
- Return provider refund id where available
- Persist refund evidence snapshot in `t_extra`

### 6.5 Replay

- Pull replay candidates from Polar `/v1/webhooks/deliveries` with pagination
- Filter to supported event families before forwarding
- Preserve cursor and last-run timestamps in datastore
- Dry-run mode produces report without state mutation
- Live mode re-forwards payloads with Standard Webhooks headers/signature
- Guardrails: lookback window, overlap, max events, max pages, max runtime

## 7) Data Contract

### 7.1 Transaction Metadata (`t_extra`)

Normalized keys under `xpolarcheckout_*` namespace:

- `xpolarcheckout_snapshot` — full settlement snapshot (totals, tax, discount, billing, customer, provider status, invoice URLs, refund amounts)
- `xpolarcheckout_webhook_events` — recent webhook event IDs for idempotency (last 50)
- `history[]` — event transition history entries (event type, timestamp, status transitions)
- Polar checkout/order ID is stored in `nexus_transactions.t_gw_id` (updated from checkout UUID to order UUID on `order.created`/`order.paid`)

### 7.2 Invoice Metadata (`i_status_extra`)

Read-only render fields for customer/print settlement blocks:

- `xpolarcheckout_snapshot` — same snapshot structure as transaction, includes:
  - Settlement totals (`amount_total_minor`, `amount_total_display`, `amount_subtotal_minor`, `amount_tax_minor`)
  - Discount fields (`discount_amount_minor`, `discount_amount_display`, `discount_name`, `discount_code`)
  - Net amount (`net_amount_minor`, `net_amount_display`)
  - Refund fields (`amount_refunded_minor`, `amount_refunded_display`, `refunded_tax_amount_minor`)
  - Provider references (`order_id`, `checkout_id`, `polar_invoice_number`)
  - Customer/billing (`billing_name`, `billing_reason`, `customer_email`, `customer_tax_id`)
  - Invoice URLs (`customer_invoice_url`, `customer_invoice_pdf_url`, `customer_receipt_url`)
  - Provider status (`provider_status`)
  - IPS comparison (`ips_invoice_total_minor`, `ips_invoice_total_display`, `has_total_mismatch`, `total_mismatch_display`, `total_difference_tax_explained`)

### 7.3 Forensics Table

`xpc_webhook_forensics` — audit trail for:

- Missing signature header
- Invalid signature
- Stale timestamp
- Malformed payload
- Unexpected processing exceptions
- 90-day retention via cleanup task

### 7.4 Product Mapping Table

`xpc_product_map` — IPS ↔ Polar product mapping:

| Column | Type | Purpose |
|--------|------|---------|
| `map_id` | INT PK | Auto-increment |
| `ips_package_id` | INT UNIQUE | IPS Nexus package ID |
| `polar_product_id` | VARCHAR(64) | Polar product UUID |
| `product_name` | VARCHAR(255) | Synced product name |
| `created_at` | INT | Creation timestamp |
| `updated_at` | INT | Last sync timestamp |

- Dynamic on-demand creation at checkout time (v1.0.3)
- ACP viewer with bulk name sync via `products` controller
- Fallback to `default_product_id` when no mapping exists
- Cached lookup via datastore key `xpolarcheckout_checkout_label_products` (max 200 entries)

## 8) ACP Settings Model

Required settings:

- `access_token` — Polar Organization Access Token
- `default_product_id` — generic Polar product for ad-hoc pricing
- `webhook_secret` — Standard Webhooks signing secret
- `environment` — `sandbox` or `production`

Configurable settings:

- `replay_lookback` — replay lookback window in seconds (300-86400, default 3600)
- `replay_overlap` — overlap window in seconds (60-1800, default 300)
- `replay_max_events` — max events per replay run (10-100, default 100)
- `presentment_currency` — checkout currency code (default `eur`), synced to Polar org via API on save
- `checkout_flow_mode` — controls multi-item cart behavior: `allow_all` or `single_item_only` (see section 10)
- `multi_item_label_mode` — controls receipt label for consolidated multi-item checkouts (`first_item`, `invoice_count`, `item_list`)
- `allow_discount_codes` — allow Polar promotional codes at checkout (default OFF)

Auto-populated settings:

- `organization_id` — auto-populated from API on save
- `organization_default_presentment_currency` — auto-populated from API on save
- `webhook_endpoint_id` — auto-populated from API on first save
- `webhook_url` — auto-generated webhook endpoint URL

Validation:

- Enforce numeric bounds at form and runtime
- Never store plaintext secrets in logs
- Mask secrets in ACP display
- `testSettings()` syncs org currency and attempts webhook endpoint creation

## 9) Security Model

- Verify signatures before any payload processing
- Reject stale deliveries
- Validate all externally provided URLs before persistence
- Use strict allowlist for accepted event types
- Keep forensics logs for at least 90 days
- Maintain CSRF checks on ACP actions
- Invalid gateway settings on webhook → return 400 (not 500)

## 10) Multi-Cart Strategy

### Current Constraint

Polar does not yet support additive multi-line checkout (multiple distinct products in one checkout session with separate line items).

### Interim Implementation (Active)

ACP-controlled checkout routing:

- `Allow Polar for all carts` — consolidated single-line checkout for multi-item carts
- `Hybrid route: show Polar only for single-item carts` — hides Polar for multi-item invoices

For consolidated multi-item payments, ACP-controlled label modes:
- Legacy first-item
- Invoice count label
- Item-list label

### Target State (Pending Polar Feature)

When Polar ships Stripe-like additive cart support:

1. One checkout session with all invoice lines as additive items
2. Provider-side itemized invoice/receipt lines (name, quantity, unit amount, line total, subtotal/tax/total)
3. Stable line identifiers in metadata/webhooks for reconciliation
4. Line-aware partial refunds with deterministic state mapping
5. Decommission consolidation fallbacks behind migration-safe feature flags

Adoption gate: official API support must be GA/stable + sandbox/production smoke tests pass + no regressions in webhook idempotency and refund transitions. Tracked as GitHub Issue #10.

## 11) Hooks (6)

| Hook | Type | Target | Purpose |
|------|------|--------|---------|
| `code_GatewayModel` | C | `\IPS\nexus\Gateway` | Register gateway in Nexus gateway map |
| `theme_sc_clients_settle` | S | `Theme\class_nexus_front_clients` | Settlement block on customer invoice |
| `theme_sc_print_settle` | S | `Theme\class_nexus_global_invoices` | Settlement block on printable invoice |
| `code_memberProfileTab` | C | `core\extensions\core\MemberACPProfileTabs\Main` | ACP member profile payment summary |
| `invoiceViewHook` | C | `nexus\modules\front\clients\invoices` | Two-column invoice view with Polar settlement |
| `couponNameHook` | C | `\IPS\nexus\Coupon` | Coupon name display formatting |

## 12) Extensions

| Type | Class | Purpose |
|------|-------|---------|
| MemberACPProfileBlocks | PolarPaymentSummary | Transaction count, refund count, last activity |
| AdminNotifications | PaymentIntegrity | Persistent webhook/payment integrity alerts |

## 13) Tasks

| Key | Interval | Purpose |
|-----|----------|---------|
| `xpcWebhookReplay` | 15 min | Fetch failed Polar webhook deliveries and replay to local endpoint |
| `xpcIntegrityMonitor` | 5 min | Collect integrity stats, trigger admin notifications, prune forensics (90-day retention) |

## 14) Key Files

| File | Purpose |
|------|---------|
| `sources/XPolarCheckout/XPolarCheckout.php` | Gateway class (auth, refund, testSettings, settings) |
| `modules/front/webhook/webhook.php` | Webhook controller (Standard Webhooks sig verification) |
| `modules/admin/monitoring/integrity.php` | ACP integrity panel |
| `modules/admin/monitoring/forensics.php` | ACP forensics viewer |
| `modules/admin/monitoring/products.php` | ACP product mapping viewer with bulk name sync |
| `tasks/xpcWebhookReplay.php` | Webhook replay task (15min) |
| `tasks/xpcIntegrityMonitor.php` | Integrity monitor task (5min) |
| `hooks/code_GatewayModel.php` | Gateway registration hook |
| `hooks/invoiceViewHook.php` | Invoice view two-column layout + global enhancements |
| `hooks/couponNameHook.php` | Coupon name display hook |
| `hooks/theme_sc_clients_settle.php` | Client invoice settlement display |
| `hooks/theme_sc_print_settle.php` | Print invoice settlement display |
| `hooks/code_memberProfileTab.php` | ACP member profile block injection |
| `extensions/core/MemberACPProfileBlocks/PolarPaymentSummary.php` | ACP member profile block |
| `extensions/core/AdminNotifications/PaymentIntegrity.php` | Admin notification extension |
| `data/schema.json` | DB schema (`xpc_webhook_forensics`, `xpc_product_map`) |

## 15) Local Development

### Polar CLI Docker Service

- Docker profile: `polar` (add to `COMPOSE_PROFILES`)
- Auto-syncs ALL gateway settings from `.env` to `nexus_paymethods` on every start
- SSE tunnel secret format: plain hex string (NOT `whsec_` prefixed)
- Default product: one generic "shell" product, `auth()` overrides price per-transaction
- `.env` variables: `POLAR_ACCESS_TOKEN`, `POLAR_ORG_ID`, `POLAR_DEFAULT_PRODUCT_ID`, `POLAR_FORWARD_TO`, `POLAR_ENVIRONMENT`
- See [POLAR_CLI_LOCAL_DEBUG.md](POLAR_CLI_LOCAL_DEBUG.md) for detailed runbook

### Known Constraints

- Polar API trailing slash behavior varies by endpoint — some endpoints require trailing slashes (e.g. `/products/`, `/checkouts/`, `/refunds/`), while list endpoints use no trailing slash (e.g. `/webhooks/endpoints`)
- Polar checkout API requires `default_presentment_currency` to match org setting
- Webhook endpoint API operations require `webhooks:read` + `webhooks:write` token scopes
