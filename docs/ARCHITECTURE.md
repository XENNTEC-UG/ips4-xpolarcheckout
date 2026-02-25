# X Polar Checkout — Architecture

## 1) Objective

Production-ready IPS4 Nexus payment gateway app (`xpolarcheckout`) backed by Polar hosted checkout and Polar webhooks, preserving proven reliability patterns from the existing gateway architecture (sibling to `xstripecheckout` and `xpaynowcheckout`).

## 2) External References

- Polar overview: `https://polar.sh/docs/introduction`
- Polar PHP SDK: `https://polar.sh/docs/integrate/sdk/php`
- Polar CLI repository: `https://github.com/polarsource/cli`

## 3) Current State

- Version: `1.0.7` / `10007` (as of 2026-02-25)
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
  - Do not mutate transaction state during session creation
  - Persist provider checkout id in `t_extra` for traceability
  - Explicit `currency` field in checkout payload (must match configured presentment currency)
  - One generic Polar product (`default_product_id`) with per-transaction price override via `prices` array

### 6.2 Webhook Verification

- Standard Webhooks signature format (HMAC-SHA256)
- Validate expected signature header presence
- Parse and validate timestamp
- Enforce drift tolerance window (default 600 seconds)
- Verify HMAC against configured secret (handles both hex and base64 secret formats)
- Reject invalid payloads with forensic log entry

### 6.3 Event Mapping

Map provider event families into IPS status transitions:

- `order.paid` → `STATUS_PAID`
- `order.created` → gateway pending state
- `checkout.updated` (failed/expired) → `STATUS_REFUSED`
- `order.refunded` / `refund.updated` → partial: `STATUS_PART_REFUNDED`, full: `STATUS_REFUNDED`

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

- `xpolarcheckout_provider_event_ids` — recent event ids
- `xpolarcheckout_settlement` — order totals, currency, timestamps
- `xpolarcheckout_refunds` — refund entries
- `xpolarcheckout_webhook_meta` — delivery id, received-at

### 7.2 Invoice Metadata (`i_status_extra`)

Read-only render fields for customer/print settlement blocks:

- Settlement totals (minor/display)
- Provider order reference
- Checkout confirmation link (validated URL only)
- Last reconciliation timestamp
- Provider/IPS total comparison fields (`has_total_mismatch`, `total_mismatch_display`)

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

- Maps Nexus package IDs to Polar product IDs
- Dynamic mapping (v1.0.3) — ACP CRUD for mappings
- Fallback to `default_product_id` when no mapping exists

## 8) ACP Settings Model

Required settings:

- `access_token` — Polar Organization Access Token
- `webhook_secret` — Standard Webhooks signing secret
- `environment` — `sandbox` or `production`
- `replay_lookback_seconds`
- `replay_overlap_seconds`
- `replay_max_events`

Optional settings:

- `default_product_id` — generic Polar product for ad-hoc pricing
- `enable_integrity_alerts`
- `signature_tolerance_seconds`
- `default_presentment_currency` — synced to Polar org via API on save
- `organization_id` — auto-populated from API
- `webhook_endpoint_id` — auto-populated from API
- `checkout_flow_mode` — controls multi-item cart behavior (see section 10)

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

## 11) Key Files

| File | Purpose |
|------|---------|
| `sources/XPolarCheckout/XPolarCheckout.php` | Gateway class (auth, refund, testSettings, settings) |
| `modules/front/webhook/webhook.php` | Webhook controller (Standard Webhooks sig verification) |
| `modules/admin/monitoring/integrity.php` | ACP integrity panel |
| `modules/admin/monitoring/forensics.php` | ACP forensics viewer |
| `tasks/webhookReplay.php` | Webhook replay task (15min) |
| `tasks/integrityMonitor.php` | Integrity monitor task (5min) |
| `data/schema.json` | DB schema (`xpc_webhook_forensics`, `xpc_product_map`) |

## 12) Local Development

### Polar CLI Docker Service

- Docker profile: `polar` (add to `COMPOSE_PROFILES`)
- Auto-syncs ALL gateway settings from `.env` to `nexus_paymethods` on every start
- SSE tunnel secret format: plain hex string (NOT `whsec_` prefixed)
- Default product: one generic "shell" product, `auth()` overrides price per-transaction
- `.env` variables: `POLAR_ACCESS_TOKEN`, `POLAR_ORG_ID`, `POLAR_DEFAULT_PRODUCT_ID`, `POLAR_FORWARD_TO`, `POLAR_ENVIRONMENT`
- See [POLAR_CLI_LOCAL_DEBUG.md](POLAR_CLI_LOCAL_DEBUG.md) for detailed runbook

### Known Constraints

- Polar API trailing slashes cause 307 redirects — use no-slash URLs
- Polar checkout API requires `default_presentment_currency` to match org setting
- Webhook endpoint API operations require `webhooks:read` + `webhooks:write` token scopes
