# X Polar Checkout Gateway (IPS4) - Research and Implementation Plan

Research date: February 22, 2026  
Target app directory: `ips-dev-source/apps/xpolarcheckout`

## 1) Goal

Build a new IPS4 Nexus gateway app for Polar that preserves as much proven behavior from `xstripecheckout` as possible:

- same gateway lifecycle integration pattern
- same webhook-first transaction finalization model
- same operational tooling (forensics, integrity checks, replay/recovery controls)
- same ACP visibility standards

## 2) Executive Decision

Primary implementation strategy:

1. Duplicate `xstripecheckout` as the engineering baseline.
2. Keep cross-provider architecture, observability, and IPS glue code.
3. Rewrite payment-provider logic from Stripe to Polar (checkout creation, webhook verification, event mapping, refunds).
4. Explicitly de-scope Stripe-only features where Polar has no equivalent (mainly dispute-specific automation in v1).

This is the fastest path to production with the lowest regression risk.

## 3) Existing Polar IPS4 Gateway Availability Check

I searched for an existing IPS4 Polar gateway through:

- web queries for Invision + Polar + payment gateway
- marketplace category scans (Invision Developers style listings)
- GitHub-style discovery queries for IPS/Nexus gateway implementations

Result:

- no clear, maintained IPS4/Nexus Polar gateway found in public results as of February 22, 2026
- plan assumes greenfield app implementation from our own `xstripecheckout` base

## 4) Core Research Findings (Polar)

## 4.1 API + SDK

- Polar Core API base URLs:
  - production: `https://api.polar.sh/v1`
  - sandbox: `https://sandbox-api.polar.sh/v1`
- Auth uses Organization Access Token (OAT) via Bearer token.
- Official PHP SDK package: `polar-sh/sdk`.
- SDK supports `setServer('sandbox')` for sandbox testing.

## 4.2 Checkout

- Checkout sessions are created via `POST /v1/checkouts/`.
- `products` is required.
- `metadata` is supported and copied to resulting order/subscription.
- `success_url` supports `checkout_id={CHECKOUT_ID}` placeholder.
- `external_customer_id` and `customer_id` support customer reconciliation and email-lock behavior.
- Ad-hoc prices are supported via `prices` mapping per product (critical for dynamic IPS invoice totals).

## 4.3 Orders and Payment State

- Order statuses: `pending`, `paid`, `refunded`, `partially_refunded`.
- Polar explicitly recommends `order.paid` as source-of-truth event for successful payment.
- `order.created` may be `pending`, not yet paid.

## 4.4 Refunds

- Refund API: `POST /v1/refunds/`.
- Requires `order_id`, `reason`, `amount`.
- Refund webhooks exist: `refund.created`, `refund.updated`.
- Order refund webhook: `order.refunded` (full or partial).

## 4.5 Webhooks

- Polar webhooks follow Standard Webhooks.
- Signature headers used in examples:
  - `webhook-id`
  - `webhook-signature`
  - `webhook-timestamp`
- Raw body validation is required.
- Important gotcha: Standard Webhooks verification expects base64-encoded secret when using those libraries directly.
- Delivery characteristics (official guidance):
  - up to 10 retries with backoff
  - 10-second timeout
  - endpoint auto-disabled after 10 consecutive failures
  - target handler should respond quickly (recommended ~2 seconds) and process async

## 5) Current Stripe App Inventory (What We Can Reuse)

Primary implementation assets in `xstripecheckout`:

- Gateway class:
  - `app-source/sources/XStripeCheckout/XStripeCheckout.php`
- Webhook controller:
  - `app-source/modules/front/webhook/webhook.php`
- Operational tasks:
  - `app-source/tasks/webhookReplay.php`
  - `app-source/tasks/integrityMonitor.php`
- ACP monitoring:
  - `app-source/modules/admin/monitoring/integrity.php`
  - `app-source/modules/admin/monitoring/forensics.php`
- Hook registration and UI hooks:
  - gateway model hook
  - invoice/print settlement rendering hooks
  - coupon naming hook
  - profile tab hook
  - app JS-loading hook (Stripe-specific)
- Extensions:
  - admin notification extension
  - member ACP block
- Schema:
  - `xsc_webhook_forensics` forensic log table

## 6) Portability Matrix (Stripe -> Polar)

| Capability | Current Stripe Behavior | Polar Equivalent | Action |
|---|---|---|---|
| Gateway registration hook | Adds gateway class to `\IPS\nexus\Gateway::gateways()` | Same IPS pattern | Reuse (rename only) |
| Checkout creation flow | Creates Stripe Checkout Session, redirects with Stripe.js | Create Polar checkout session, redirect to `checkout.url` | Rewrite provider calls, keep IPS flow |
| Customer linking | Stripe customer/profile mapping + metadata | `customer_id` / `external_customer_id` + metadata | Adapt |
| Metadata correlation | Stores `ips_transaction_id`, `ips_invoice_id`, `ips_member_id` | Same via checkout metadata -> order metadata | Reuse design |
| Webhook endpoint provisioning in `testSettings()` | Creates Stripe endpoint automatically | Create Polar webhook endpoint automatically | Adapt |
| Webhook signature verification | Stripe HMAC format | Standard Webhooks validation | Rewrite |
| Idempotency (event dedupe) | transaction `extra` key map | same pattern, keyed by `webhook-id` | Reuse |
| Transaction processing lock | MySQL `GET_LOCK`/`RELEASE_LOCK` | provider-agnostic | Reuse |
| Payment success finalization | `checkout.session.*` -> capture/mark paid | `order.paid` primary event | Adapt event mapping |
| Pending state handling | `processing` maps to gateway pending | `order.created` pending maps to gateway pending | Adapt |
| Refund status updates | `charge.refunded` | `order.refunded` + `refund.updated` | Adapt |
| Refund API | `POST /v1/refunds` on Stripe by payment intent | `POST /v1/refunds/` by `order_id` | Rewrite |
| Forensics DB + ACP viewer | Logs invalid signatures/payloads | same forensic model needed | Reuse with rename |
| Replay task | Pull Stripe events + local re-forward | use Polar webhook delivery/event redelivery APIs | Rewrite |
| Integrity monitor + ACP notifications | local health checks + alerts | same architecture | Reuse/adapt |
| Tax readiness checks | Stripe Tax readiness API snapshot | no direct equivalent needed | Drop/replace |
| Dispute automation and evidence | Stripe dispute webhooks + API evidence draft + optional ban | no equivalent webhook set in primary docs | De-scope v1 |
| Settlement UI block | Stripe-specific invoice/PI/tax fields | Polar order/refund snapshot fields | Adapt template logic |
| Stripe.js hook injection | loads `https://js.stripe.com/v3/` | not needed for simple redirect checkout | Drop |
| Coupon display hook | force coupon label | gateway-agnostic | Reuse |
| ACP member dispute block | dispute/refund summary | keep refund summary; dispute details uncertain | Adapt/de-scope dispute data |

## 7) Recommended App Bootstrap

Create app by cloning `xstripecheckout` into `xpolarcheckout` and then performing a controlled rename/refactor.

## 7.1 Clone Base (code + metadata)

Copy and systematically rename:

- namespace `IPS\xstripecheckout` -> `IPS\xpolarcheckout`
- gateway class name `XStripeCheckout` -> `XPolarCheckout`
- language keys, hook IDs, module names, task IDs, extension class names
- schema table prefix from `xsc_` to `xpc_`

## 7.2 Immediate removals after clone

- Stripe JS injection hook (`code_loadJs.php`)
- Stripe-only tax readiness logic and Stripe Tax API polling
- Stripe dispute evidence push flow (v1)

## 8) Polar Gateway Design Blueprint

## 8.1 Gateway settings (v1)

Minimum required:

- `environment` (`sandbox` or `production`)
- `access_token`
- `webhook_endpoint_id`
- `webhook_url`
- `webhook_secret`
- `default_product_id` (for generic checkout)

Recommended operational settings:

- `replay_lookback`
- `replay_overlap`
- `replay_max_events`
- `allow_discount_codes` (default false to avoid mismatch with IPS invoice-calculated discounts)

Optional:

- `organization_id` and/or `organization_slug` (if needed for dashboard URL generation)

## 8.2 Checkout payload strategy

V1 strategy for maximum compatibility with IPS invoice dynamics:

- use one configurable Polar product ID (generic "IPS Invoice" product)
- set dynamic per-transaction price via ad-hoc `prices` object
- pass correlation metadata:
  - `ips_transaction_id`
  - `ips_invoice_id`
  - `ips_member_id`
  - `gateway_id`
- pass `external_customer_id` using IPS member ID (or stable internal customer key)
- redirect user to returned `checkout.url`

## 8.3 Tax model risk (critical)

Polar is MoR and computes tax; IPS/Nexus can also compute tax.  
We must choose one tax source-of-truth before production.

Decision required:

1. Polar-calculated tax as source-of-truth (recommended for MoR alignment), or
2. IPS-calculated tax as source-of-truth with strict reconciliation logic

Without this decision, over/under-charge risk exists.

## 9) Webhook Processing Blueprint

Endpoint:

- `app=xpolarcheckout&module=webhook&controller=webhook`

Processing pipeline:

1. Read raw body from `php://input`.
2. Collect required signature headers.
3. Verify signature (Standard Webhooks compliant).
4. Fail closed (`403`) on invalid signature; log forensic entry.
5. Resolve transaction via metadata (`ips_transaction_id`) and/or `gw_id` mapping.
6. Enforce idempotency using event delivery ID (`webhook-id`).
7. Enforce transaction lock via DB mutex.
8. Persist normalized snapshot to transaction and invoice extras.
9. Ack quickly with 2xx.

## 9.1 Event mapping v1

| Polar event | IPS action |
|---|---|
| `order.created` | set transaction to `STATUS_GATEWAY_PENDING` (if not paid/refused), store order snapshot |
| `order.paid` | set `gw_id=order.id`, run fraud/capture path (`checkFraudRulesAndCapture`), persist paid snapshot |
| `order.updated` | optional secondary update path (status reconciliation) |
| `order.refunded` | set `STATUS_REFUNDED` or `STATUS_PART_REFUNDED` based on refund amounts |
| `refund.updated` | persist refund metadata; optionally reconcile final refund status |
| `checkout.updated` | snapshot/debug enrichment only (no payment finalization) |

Subscription events (`subscription.*`) can be added in v2 for recurring-specific workflows.

## 10) Refund Blueprint

Gateway `refund()` implementation:

- call Polar `POST /v1/refunds/`
- map `transaction->gw_id` as `order_id`
- map reasons:
  - `duplicate` -> `duplicate`
  - `fraudulent` -> `fraudulent`
  - `requested_by_customer` -> `customer_request`
  - fallback -> `other`
- return Polar refund ID if available
- final IPS status updates remain webhook-driven

## 11) Monitoring, Replay, and Forensics

## 11.1 Forensics

Keep forensic model from Stripe app:

- table `xpc_webhook_forensics`
- log failure reason, event type/id, IP, HTTP status, payload snippet, timestamp
- ACP viewer module with filters/search

## 11.2 Integrity panel

Preserve current ACP integrity dashboard pattern:

- webhook configured/not configured
- replay/recovery recency
- webhook errors in last 24h
- mismatch counts
- endpoint drift checks

Replace Stripe-specific checks with Polar-relevant checks.

## 11.3 Replay/recovery

Replace Stripe replay logic with Polar-native recovery:

- use webhook delivery/event APIs where possible:
  - list webhook deliveries
  - redeliver failed events
- keep manual ACP actions:
  - run recovery now
  - dry run

If delivery API schema is insufficient, fallback v1:

- rely on Polar native retry + dashboard redelivery
- keep monitor task but reduce replay complexity

## 11.4 Local Debug via Polar CLI

We will use the official Polar CLI (`polarsource/cli`) for local webhook debugging.

Important platform note (as of release `v1.2.0`, February 6, 2026):

- official prebuilt binaries are published for `darwin-arm64`, `darwin-x64`, and `linux-x64`
- no native Windows binary is published in releases
- for this Windows workspace, local debugging should run via WSL/Linux

Recommended workflow in this stack:

1. Install Polar CLI in WSL/Linux:
   - `curl -fsSL https://polar.sh/install.sh | bash`
2. Authenticate:
   - `polar login`
3. Start forwarding to local IPS webhook route:
   - `polar listen \"https://<your-local-host>/index.php?app=xpolarcheckout&module=webhook&controller=webhook\"`
4. Copy the secret shown by `polar listen`.
5. Temporarily set the gateway webhook secret in ACP to that listen-session secret for signature validation tests.
6. Trigger sandbox purchases/refunds and validate end-to-end handling in IPS logs + forensics panel.

Implementation follow-up for developer ergonomics:

- add a small debug utility in ACP to indicate "CLI tunnel mode" and avoid secret confusion
- add `docs/TEST_RUNTIME.md` steps for CLI session start/stop + secret rotation checklist

## 12) UI and Hook Strategy

Keep and adapt:

- `code_GatewayModel.php` (gateway registration)
- `invoiceViewHook.php`, `theme_sc_clients_settle.php`, `theme_sc_print_settle.php` (render Polar settlement summary)
- `couponNameHook.php` (if still desired)

Remove or replace:

- `code_loadJs.php` (Stripe.js injection)

Member ACP block:

- convert dispute-heavy block into Polar payment/refund summary in v1
- optionally re-introduce dispute insights later if Polar dispute data/events are fully documented and stable

## 13) Implementation Phases

## Phase 0 - Repository setup

1. Create dedicated component repo/submodule for `xpolarcheckout`.
2. Copy `xstripecheckout` baseline into new app folder.
3. Complete namespace/identifier renaming.

## Phase 1 - Compile-safe provider swap

1. Replace Stripe API constants and endpoints with Polar client calls.
2. Remove Stripe-only hooks/settings.
3. Ensure app installs and gateway appears in ACP.

## Phase 2 - Checkout + settings

1. Implement Polar checkout session creation and redirect.
2. Implement settings form for token/environment/webhook/product.
3. Implement `testSettings()` webhook endpoint create/update logic.

## Phase 3 - Webhooks

1. Implement Standard Webhooks verification.
2. Implement event routing and status mapping.
3. Implement idempotency + lock + snapshot persistence.

## Phase 4 - Refunds

1. Implement Polar refund API call.
2. Implement refund reason mapping.
3. Ensure webhook-driven final state reconciliation.

## Phase 5 - Monitoring and recovery

1. Port forensics table + ACP viewer.
2. Port integrity dashboard and notification extension.
3. Rewrite replay task to Polar recovery model.

## Phase 6 - UI polish + docs + tests

1. Update invoice/print hooks to Polar labels/fields.
2. Update docs contract files (`README.md`, `FLOW.md`, `FEATURES.MD`, `TEST_RUNTIME.md`, `CHANGELOG.md`).
3. Build runtime/manual test scripts analogous to current Stripe app quality bar.

## 14) Test Plan (Acceptance)

Must-pass scenarios:

1. Gateway appears and can be configured with sandbox token.
2. Checkout redirect works and returns user to IPS after completion.
3. `order.created` marks transaction pending when applicable.
4. `order.paid` finalizes transaction and marks invoice paid.
5. Duplicate webhook delivery does not double-process.
6. Invalid signature returns `403` and records forensic log.
7. Full refund sets `STATUS_REFUNDED`.
8. Partial refund sets `STATUS_PART_REFUNDED`.
9. Integrity panel shows recent run and error metrics.
10. Manual replay/recovery control works (or clearly documented fallback behavior).

## 15) Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Tax model mismatch (IPS tax + Polar tax) | Over/undercharge | Lock tax strategy before build completion; validate with sandbox matrix |
| Webhook validation implementation error | Payment flow break | Use official Standard Webhooks approach, add forensic logging and signature tests |
| API/docs drift | Runtime failures | Pin SDK version, verify endpoints in integration tests, keep changelog watchlist |
| Currency constraints | Checkout creation failures | Validate currency support at gateway `checkValidity()` |
| Missing dispute-equivalent events | Loss of Stripe dispute features | Explicitly de-scope in v1 and document gap |

## 16) Open Decisions (Need Confirmation)

1. Final app name: `xpolarcheckout` (confirmed).
2. Tax source-of-truth:
   - Polar MoR tax (recommended), or
   - IPS tax with reconciliation.
3. V1 scope:
   - one-time invoice payments only, or
   - include subscription lifecycle handling immediately.
4. Webhook verification implementation:
   - vendor Standard Webhooks library, or
   - custom implementation of Standard Webhooks spec.
5. Dispute feature parity:
   - defer to v2, or
   - add partial polling-based dispute visibility in v1.

## 17) Definition of Done

The Polar gateway is done when:

- it installs cleanly as a separate IPS app
- checkout -> webhook -> paid/refund transitions are reliable and idempotent
- observability tooling (forensics + integrity + recovery controls) is operational
- docs and runtime tests match existing repository standards
- Stripe app remains untouched and independently functional

## 18) Sources Used

Polar official docs:

- `https://polar.sh/docs/introduction`
- `https://polar.sh/docs/integrate/sdk/php`
- `https://polar.sh/docs/api-reference/introduction`
- `https://polar.sh/docs/api-reference/checkouts/create-session`
- `https://polar.sh/docs/api-reference/orders/get`
- `https://polar.sh/docs/api-reference/refunds/create`
- `https://polar.sh/docs/api-reference/webhooks/endpoints/create`
- `https://polar.sh/docs/api-reference/webhooks/endpoints/get`
- `https://polar.sh/docs/api-reference/webhooks/endpoints/update`
- `https://polar.sh/docs/api-reference/webhooks/order.paid`
- `https://polar.sh/docs/api-reference/webhooks/order.refunded`
- `https://polar.sh/docs/api-reference/webhooks/checkout.updated`
- `https://polar.sh/docs/api-reference/webhooks/refund.updated`
- `https://polar.sh/docs/integrate/webhooks/endpoints`
- `https://polar.sh/docs/integrate/webhooks/delivery`
- `https://polar.sh/docs/integrate/webhooks/events`
- `https://polar.sh/docs/integrate/webhooks/locally`
- `https://polar.sh/docs/guides/create-checkout-session`

Polar SDK docs:

- `https://raw.githubusercontent.com/polarsource/polar-php/main/README.md`
- `https://raw.githubusercontent.com/polarsource/polar-php/main/docs/sdks/checkouts/README.md`
- `https://raw.githubusercontent.com/polarsource/polar-php/main/docs/sdks/webhooks/README.md`
- `https://raw.githubusercontent.com/polarsource/polar-php/main/docs/sdks/refunds/README.md`

Polar CLI docs:

- `https://github.com/polarsource/cli`
- `https://raw.githubusercontent.com/polarsource/cli/main/README.md`
- `https://raw.githubusercontent.com/polarsource/cli/main/install.sh`
- `https://raw.githubusercontent.com/polarsource/cli/main/src/commands/listen.ts`

Internal app baseline:

- `ips-dev-source/apps/xstripecheckout/app-source/...` (gateway, webhook, tasks, hooks, extensions, schema, ACP modules)
- `ips-dev-source/apps/xstripecheckout/docs/...` (README/FLOW/FEATURES/TEST docs)

## 19) Implementation Review Notes (Dev Review, 2026-02-22)

Peer review of this plan after inspecting the actual xpolarcheckout source tree.

### 19.1 Current Source State

The app-source tree was copied verbatim from xstripecheckout. As of review:

- Gateway class has already been renamed to `XPolarCheckout`
- Namespace baseline is already `IPS\xpolarcheckout`
- Extension class is still named `StripeDisputeSummary`
- `code_loadJs.php` (Stripe.js injection) is still present
- All 12 setup/upgrade migrations (`upg_10000` through `upg_10012`) carry Stripe-specific schema history
- `docs/` folder was copied wholesale including Stripe-specific automation test scripts
- Table prefix is still `xsc_` in schema references

Phase 0/1 must address the remaining Stripe-specific items before any functional Polar work begins.

### 19.2 Dependency Management (Critical Decision for Phase 1)

Recheck correction: the current `xstripecheckout` implementation does not use Stripe SDK/Composer; it uses direct `\IPS\Http` REST calls. So Composer is not automatically the baseline pattern.

Implementation options:

1. **No external dependencies (baseline-compatible)**: Use direct `\IPS\Http` calls for Polar endpoints and implement Standard Webhooks-compatible verification in app code.
2. **Composer dependencies (optional)**: Adopt `polar-sh/sdk` and/or `standard-webhooks/standard-webhooks`, then explicitly define install/autoload strategy for IPS4/container runtime.

Decision must be made in Phase 1 and documented before Phase 2 coding starts.

### 19.3 Polar Customer Model (Under-specified)

Section 4.2 mentions `external_customer_id` and `customer_id` but several questions are unresolved:

- Does Polar auto-create customers on first checkout, or must we `POST /v1/customers/` first?
- How are returning IPS members reconciled? Stripe has a persistent customer object linked via metadata; clarify the Polar equivalent.
- The Stripe gateway's `getCustomer()` method does significant work (member-to-Stripe-customer mapping, metadata sync, `joined` timestamp). The Polar equivalent needs a clear design.

Recommend a sandbox spike early in Phase 2: create a checkout with `external_customer_id` set to an IPS member ID, complete it, then inspect the resulting order to confirm metadata flow and customer state.

### 19.4 Webhook Event Subscriptions

Section 8.1 lists `webhook_endpoint_id` as a setting and Phase 2 mentions auto-provisioning via `testSettings()`, but the plan does not specify which events to subscribe to when creating the endpoint. Based on the event mapping in section 9.1, the v1 subscription list should be:

- `order.created`
- `order.paid`
- `order.updated`
- `order.refunded`
- `refund.created`
- `refund.updated`
- `checkout.updated`

Document this explicitly so `testSettings()` creates the endpoint with the correct event filter.

### 19.5 Setup/Upgrade Migration Strategy

The 12 copied upgrade directories contain Stripe-specific migrations (hook registrations, settings inserts, schema changes). Carrying this history into the Polar app is dangerous — it references wrong table names, wrong setting keys, and wrong hook class paths.

Recommendation: **Gut all `setup/upg_*` directories**. Start with a single clean `upg_10000` (or whatever version code maps to v1.0.0) containing only:

- The `xpc_webhook_forensics` table creation
- Polar-specific gateway settings inserts
- Correct hook/module/task registrations for xpolarcheckout

### 19.6 Table Prefix Decision

Decision: use `xpc_` consistently (app-key aligned prefix for `xpolarcheckout`).

### 19.7 Currency and Ad-hoc Price Validation

The checkout strategy (section 8.2) relies on ad-hoc prices. Validate in sandbox early:

- Confirm ad-hoc prices work alongside `external_customer_id`
- Confirm metadata flows from checkout to order (the plan assumes this)
- Confirm amount precision and rounding matches IPS invoice totals (cent-level)
- Confirm which currencies Polar supports and add `checkValidity()` enforcement

### 19.8 Phase 0/1 Checklist

Consolidated rename/cleanup checklist for Codex:

- [x] Namespace: `IPS\xstripecheckout` -> `IPS\xpolarcheckout` (all files)
- [x] Gateway class: `XStripeCheckout` -> `XPolarCheckout` (class + directory name)
- [x] Table prefix: `xsc_` -> `xpc_` (schema.json, all PHP references)
- [x] Extension rename: `StripeDisputeSummary` -> `PolarPaymentSummary` (class + extensions.json)
- [x] Remove `code_loadJs.php` hook + its entry in `hooks.json`
- [ ] Remove Stripe Tax readiness logic
- [ ] Remove dispute automation code paths (Phase 0 interim: `charge.dispute.*` webhook events are ignored)
- [x] Gut `setup/upg_*` directories — start clean
- [x] Update `data/application.json` (app key, name, author, version)
- [ ] Update all `data/*.json` metadata files for new names
- [ ] Remove or stub Stripe API calls/references so the app is parse-clean with zero Stripe references
- [ ] Choose dependency strategy (`\IPS\Http`-only vs Composer libs) and document autoload/runtime implications
- [x] Clean `docs/` — remove Stripe-specific automation scripts, update README.md entry points
- [x] Remove `releases/Stripe Checkout Gateway 1.1.1.tar`

### 19.9 Open Decision Recommendations

| # | Decision | Recommendation | Rationale |
|---|---|---|---|
| 2 | Tax source-of-truth | Polar MoR tax | Fighting the MoR model creates reconciliation nightmares. Disable IPS tax calc for Polar gateway transactions. |
| 3 | V1 scope | One-time only | Subscriptions add massive lifecycle complexity (upgrades, cancellations, renewals). Ship v1 without them. |
| 4 | Webhook verification | Decide in Phase 1 (`\IPS\Http`-only vs vendor library) | Either approach can work; lock one path early to avoid rework. |
| 5 | Dispute parity | Defer to v2 | Polar lacks Stripe's dispute event richness. Don't build speculative infrastructure. |

### 19.10 Codex Recheck Result (Issue #1 Alignment)

Cross-check completed on February 22, 2026:

- Issue `#1` and Section 19 are aligned on rename/migration blockers.
- Table prefix decision is now locked to `xpc_` in this plan.
- Dependency discussion is corrected to reflect the actual Stripe baseline (`\IPS\Http` direct API, no Stripe SDK/Composer dependency).

### 19.11 Phase 1 Audit (Dev Review, 2026-02-22)

Full source audit after Codex reported Phase 1 complete. Result: **Phase 0 is complete, Phase 1 is not.**

**303 Stripe references found across 11 files.** The entire functional codebase is still pure Stripe.

Detailed findings posted to GitHub issue #1 comment. Summary:

**Must REMOVE (plan says Drop/De-scope):**

- Stripe Tax readiness (`XPolarCheckout.php` lines 945-1107): `fetchTaxReadiness()`, `normalizeTaxReadiness()`, `applyTaxReadinessSnapshotToSettings()`
- Dispute automation (`webhook.php` `charge.dispute.*` handlers + evidence push)
- Legacy Stripe migration (`loadLegacyStripeCheckoutSettings()`, `applyLegacySettingDefaults()`, `buildSettingsWithLegacyDefaults()`)
- Stripe.js redirect (`XPolarCheckout.php` line 208)
- `STRIPE_VERSION` constant and all `Stripe-Version` / `Stripe-Signature` header references

**Must REWRITE to Polar (or stub with TODO markers):**

- `auth()` — Polar checkout session creation via `\IPS\Http\Url` to `api.polar.sh/v1/checkouts/`
- Webhook signature verification — Standard Webhooks headers (`webhook-id`, `webhook-signature`, `webhook-timestamp`)
- Webhook event handlers — Replace `checkout.session.*` / `charge.*` with `order.created`, `order.paid`, `order.refunded`, `refund.updated`, `checkout.updated`
- `refund()` — Polar `POST /v1/refunds/` with `order_id`
- `testSettings()` webhook provisioning — Polar `POST /v1/webhooks/endpoints/`
- `getCustomer()` — Adapt to Polar `external_customer_id`
- Replay task — Replace Stripe `/v1/events` with Polar webhook delivery API
- Settings form — Replace Stripe keys with Polar settings (access_token, environment, default_product_id)
- `dev/lang.php` — All 42 Stripe-referencing lang keys and values

**Can KEEP as-is (provider-agnostic):**

- MySQL `GET_LOCK`/`RELEASE_LOCK` concurrency protection
- Forensics table write pattern (`xpc_webhook_forensics`)
- Idempotency via transaction `extra` key map
- `checkFraudRulesAndCapture()` IPS-side logic
- ACP forensics viewer module
- `code_GatewayModel.php` (already clean)
- `couponNameHook.php` (already clean)
- `code_memberProfileTab.php` (already clean)

**Phase 1 done = parse-clean:** zero Stripe references, all methods rewritten or stubbed, lang keys updated, app installs and gateway appears in ACP.

### 19.12 Phase 1 Completion Pass (Codex, 2026-02-22)

Recheck performed against the latest `#1` issue comments and the19.11 audit.

Status update:

- `app-source` is now parse-clean with zero Stripe references.
- Stripe-specific comments/docblocks were normalized to Polar/provider wording.
- Language assets were updated to Polar/provider wording:
  - `app-source/dev/lang.php`
  - `app-source/data/lang.xml`

Validation evidence:

- `rg -n -i "stripe" app-source` -> no matches
- `rg -n -i "api\\.stripe|stripe-signature|stripe-version|STRIPE_VERSION" app-source` -> no matches
- Docker PHP lint pass for touched files and a full `app-source` parse check in container.

Known Phase 1 implementation posture:

- Core gateway/webhook/replay/integrity files have been rewritten to Polar-oriented baselines.
- Replay task currently remains a safe placeholder (no provider redelivery fetch yet) until Polar redelivery API workflow is finalized in subsequent phases.

### 19.13 Phase 1 Verification & Phase 2 Blockers (Dev Review, 2026-02-22)

Full code audit of every file in `app-source/` after Codex reported Phase 1 complete (19.12).

**Verification result: Phase 1 is CONFIRMED COMPLETE.**

- `rg -n -i "stripe" app-source` → zero matches (independently confirmed)
- All PHP files read and reviewed: gateway, webhook, replay, integrity, forensics, hooks, extensions, lang, data JSON
- All methods are Polar-oriented with correct API base URLs, endpoint paths, and event types

**Phase 2 blockers and issues found during audit:**

#### B1 — CRITICAL: Webhook signature verification is incorrect (`webhook.php` lines 498-541)

The `checkSignature()` implementation does not match the Standard Webhooks spec that Polar uses. Four bugs:

1. **Missing `msg_id` in signed payload** — Standard Webhooks signed content is `{msg_id}.{timestamp}.{body}`. Current code uses only `{timestamp}.{body}` (line 507). The `msg_id` comes from the `webhook-id` header, already extracted at line 191.
2. **Secret not base64-decoded** — Standard Webhooks requires `base64_decode($secret)` before using as HMAC key. Current code passes `$secret` raw to `hash_hmac()` (line 508).
3. **Hash output format wrong** — `hash_hmac('sha256', ...)` returns hex by default. Standard Webhooks signatures are `base64_encode(hash_hmac('sha256', ..., ..., true))` (raw binary output, then base64).
4. **Token delimiter mismatch** — Standard Webhooks uses `v1,<base64sig>` (comma separator). Current parser at line 520-524 splits on `=`. Should split on `,` and match the `v1` version prefix.

**Fix pattern:**
```php
$webhookId = isset($_SERVER['HTTP_WEBHOOK_ID']) ? (string) $_SERVER['HTTP_WEBHOOK_ID'] : '';
$signedPayload = $webhookId . '.' . $timestamp . '.' . $body;
$secretBytes = base64_decode($secret);
$computed = base64_encode(hash_hmac('sha256', $signedPayload, $secretBytes, true));
// Compare against v1,<sig> tokens split on comma
```

This will reject every real Polar webhook until fixed. Must be the FIRST fix in Phase 2.

#### B2 — Stub methods needing real implementation in Phase 2

| Method | File | Current State | Phase 2 Action |
|---|---|---|---|
| `syncWebhookEvents()` | `XPolarCheckout.php:398` | Returns static array, no API call | `PATCH /v1/webhooks/endpoints/{id}` with `events` body |
| `testSettings()` | `XPolarCheckout.php:209` | Normalizes + generates webhook_url only | Add `POST /v1/webhooks/endpoints/` auto-provisioning |
| `webhookReplay::execute()` | `webhookReplay.php:39` | Timestamp placeholder, no event fetch | Implement Polar webhook delivery list API |
| `applyTaxReadinessSnapshotToSettings()` | `XPolarCheckout.php:412` | Passthrough stub | Remove entirely or repurpose for Polar MoR tax status |

#### B3 — Dead lang keys (~40+ unused strings in `dev/lang.php`)

Leftover from Stripe clone. No code references these keys:

- Lines 10-11: `xpolarcheckout_secret` / `xpolarcheckout_publishable` — "Secret key"/"Publishable Key" (Polar uses Access Token)
- Lines 14-21: Tax behavior settings (`xpolarcheckout_tax*`) — Stripe Tax was removed
- Lines 29-39: Fraud Protection toggles (`dispute_ban`, `phone_collection_enabled`, `tos_consent_enabled`, `threeds_enabled`, `custom_checkout_text`) — Stripe chargeback suite features not in Polar
- Lines 43-75: 30+ payment method names (`xpolarcheckout_methods_*`) — Stripe-specific methods
- Lines 76-77: `xpolarcheckout_address_collection*` — Stripe Checkout feature
- Lines 101-128: Tax readiness + tax ID collection labels (`xpolarcheckout_tax_readiness*`, `xpolarcheckout_tax_id*`)

Not a runtime bug but adds confusion. Recommend pruning early in Phase 2.

#### B4 — `auth()` ad-hoc pricing payload needs sandbox validation

The `prices` object structure at `XPolarCheckout.php:77-83` uses this format:
```php
'prices' => array(
    $defaultProductId => array(
        'amount_type' => 'fixed',
        'price_amount' => (int) $amountMinor,
        'price_currency' => strtoupper($currency),
    ),
),
```

This must be tested against the real Polar sandbox `POST /v1/checkouts/` endpoint early in Phase 2 to confirm:
- Ad-hoc prices work alongside `external_customer_id`
- Metadata flows from checkout to order
- Amount precision matches IPS invoice totals (cent-level)
- Supported currencies list (add `checkValidity()` enforcement)

#### Phase 0/1 checklist final status

- [x] Namespace: `IPS\xpolarcheckout` (all files)
- [x] Gateway class: `XPolarCheckout`
- [x] Table prefix: `xpc_`
- [x] Extension rename: `PolarPaymentSummary`
- [x] Remove `code_loadJs.php` hook
- [x] Remove Stripe Tax readiness logic (stubbed as passthrough)
- [x] Remove dispute automation code paths (no dispute handlers in webhook)
- [x] Gut `setup/upg_*` — single clean `upg_10000`
- [x] Update `data/application.json`
- [x] Update all `data/*.json` metadata files
- [x] Remove Stripe API calls/references — zero matches
- [x] Dependency strategy: `\IPS\Http`-only (confirmed)
- [x] Clean `docs/` — removed Stripe automation scripts
- [x] Remove release tar
- [ ] Prune dead lang keys (deferred to Phase 2, B3 above)

### 19.14 Phase 2 Progress — B1 Signature Fix Completed (Codex, 2026-02-22)

Implemented the first Phase 2 blocker fix in `app-source/modules/front/webhook/webhook.php`.

What changed:

- Signature content now follows Standard Webhooks: `webhook-id.webhook-timestamp.raw-body`
- HMAC key now uses decoded webhook secret bytes (supports `whsec_` prefix; falls back to raw secret for local CLI dev secrets)
- Signature digest now uses base64 of raw HMAC bytes:
  - `base64_encode(hash_hmac('sha256', $signedPayload, $secretBytes, true))`
- Signature token parsing now expects Standard Webhooks token format:
  - space-delimited tokens
  - each token as `v1,<signature>`
- Verification now requires both `webhook-id` and `webhook-timestamp` headers
- Added replay-window guard (`±300s`) with forensics reason `timestamp_too_old`

Validation:

- Docker PHP lint: `app-source/modules/front/webhook/webhook.php` parse clean
- Docker PHP lint: full `app-source` parse clean
- Stripe scan still clean: `rg -n -i "stripe" app-source` -> no matches

Remaining Phase 2 blockers:

- B2: implement webhook endpoint sync/provision and replay API calls
- B3: prune dead lang keys
- B4: sandbox-validate ad-hoc checkout price payload and currency handling
