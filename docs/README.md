# Stripe Checkout Component Docs

Entrypoint for the IPS4 `xpolarcheckout` app context.

## Purpose And How It Works

- Purpose: provide a production-ready Stripe Checkout gateway implementation for IPS Nexus.
- Main payment flow: gateway `auth()` creates a Stripe Checkout Session with IPS metadata, then user is redirected to Stripe-hosted checkout.
- Main reconciliation flow: webhook controller processes Stripe events (`checkout.session.*`, refunds, disputes) and maps them back to IPS transaction/invoice states.
- Runtime entry points: `ips-dev-source/apps/xpolarcheckout/app-source/sources/XPolarCheckout/XPolarCheckout.php`, `ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php`, and `index.php?app=xpolarcheckout&module=webhook&controller=webhook`.
- Data behavior: settlement/tax snapshots are stored on Nexus transaction/invoice extra fields so invoice and print views can render Stripe-specific details.
- Boundary: this app is gateway orchestration only; it does not own product entitlement rules beyond payment status signaling.

## Read Order

1. [BACKLOG.md](BACKLOG.md) - active tasks and risks
2. [FEATURES.MD](FEATURES.MD) - sales-style capability overview
3. [FLOW.md](FLOW.md) - architecture and request flow
4. [TEST_RUNTIME.md](TEST_RUNTIME.md) - runtime/manual verification
5. [CHANGELOG.md](CHANGELOG.md) - completed work history
6. [BACKLOG_ARCHIVE.md](archive/BACKLOG_ARCHIVE.md) - completed backlog history

## Global Context

Read these root docs before component work:
- [../../../../README.md](../../../../README.md)
- [../../../../IPS4_DEV_GUIDE.md](../../../../IPS4_DEV_GUIDE.md)
- [../../../../AI_TOOLS.md](../../../../AI_TOOLS.md)
- [../../../../PROJECT.md](../../../../PROJECT.md)

## Source Of Truth

- App code: `ips-dev-source/apps/xpolarcheckout/app-source/`
- Source package provided: `ips-dev-source/apps/xpolarcheckout/releases/Stripe Checkout Gateway 1.1.1.tar`
- Runtime import/export: `powershell -ExecutionPolicy Bypass -File .\scripts\ips-dev-sync.ps1 -Mode import|export`

## Install Workflow

- Code setup in repo is done.
- ACP install/upgrade is still required to register hooks/gateway metadata in IPS.
- Install path in ACP: `System -> Applications -> Install`.
- For Stripe Checkout, install/upgrade from:
  - `Stripe Checkout Gateway 1.1.1.tar` (original package), or
  - an exported package you build after modifications.

## URL Contract

- Webhook endpoint uses dynamic URL:
  - `index.php?app=xpolarcheckout&module=webhook&controller=webhook`

## ACP Integrity Panel

- ACP location:
  - `System -> Payments -> X Polar Checkout -> Integrity` (module `app=xpolarcheckout&module=monitoring&controller=integrity`)
- Note:
  - Run ACP app upgrade/install after deploy so new ACP module/menu/restriction records are registered.
- Coverage:
  - Gateway webhook configuration status (URL/secret present)
  - Replay task health (`xpolarcheckout_webhook_replay_state`)
  - Recent webhook/snapshot processing errors from `core_log`
  - Stripe-vs-IPS total mismatch counters and recent mismatch rows
  - Manual replay action (`Run Webhook Replay Now`) for immediate outage recovery

## Stripe Settlement Display

- Stripe Checkout settlement/tax snapshot is stored in:
  - `nexus_transactions.t_extra['xpolarcheckout_snapshot']`
  - `nexus_invoices.i_status_extra['xpolarcheckout_snapshot']`
- Display is rendered via StripeCheckout theme hooks (invoice + print views).
- `invoice notes` are intentionally not used for Stripe settlement system data.

## Stripe API Version Target

- Gateway request version is pinned in source to `2026-01-28.clover`.
- Webhook endpoint auto-creation also pins `api_version` to `2026-01-28.clover`.
- Local Stripe CLI listener is started with `--latest` (Stripe CLI does not support an exact version flag for `listen`).

## Local Stripe Listener (Docker)

Use the root stack `stripe-cli` service (profile: `stripe`) to forward Stripe events to local IPS:

1. Set `STRIPE_API_KEY=sk_test_...` in `.env`.
   - optional: `STRIPE_FORWARD_TO=http://host.docker.internal/index.php?app=xpolarcheckout&module=webhook&controller=webhook`
2. Start listener:
   - `docker compose --profile stripe up -d stripe-cli`
   - or helper script: `powershell -ExecutionPolicy Bypass -File .\scripts\start-stripe-listener.ps1`
3. Read logs and copy `whsec_...`:
   - `docker compose logs -f stripe-cli`
4. Paste `whsec_...` into ACP gateway setting `Webhook Signing secret`.

### Important local constraint

- Stripe Dashboard webhook endpoints require public HTTPS URLs.
- `http://localhost/...` and `http://host.docker.internal/...` will fail with `Invalid URL`.
- For localhost development, use only the Stripe CLI forwarder (`stripe-cli` container).

## Stripe Tax Sandbox Setup (DE/EU VAT)

- Use official Stripe dashboard paths (switch to Sandbox/Test mode):
  - Registrations: `https://dashboard.stripe.com/tax/registrations`
  - Tax settings: `https://dashboard.stripe.com/tax/settings`
  - Test-mode tax transactions: `https://dashboard.stripe.com/test/tax/transactions`
- Recommended for Germany/EU VAT:
  - Add Germany registration in sandbox.
  - Add OSS/IOSS registrations if you collect cross-border EU VAT.
  - Keep Stripe automatic tax enabled in gateway settings.

### Quick health check

1. Confirm listener is ready and has a secret:
   - `docker compose logs --tail 40 stripe-cli`
2. Confirm secret is saved in IPS:
   - `docker compose exec -T db mysql -uroot -pREDACTED_DB_PASSWORD ips -e "SELECT m_gateway,JSON_UNQUOTE(JSON_EXTRACT(m_settings,'$.webhook_secret')) AS webhook_secret FROM nexus_paymethods WHERE m_gateway='XPolarCheckout';"`
3. Send synthetic event with required metadata:
   - `docker compose exec -T stripe-cli stripe trigger checkout.session.completed --add checkout_session:metadata.transaction=1`
4. Confirm delivery status:
   - `docker compose logs --since 2m stripe-cli`
   - Expect: `POST ... [200]`.

## Automated Tax Payload Check

- Run:
  - `docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_tax_payload.php`
- Coverage:
  - Tax-enabled checkout payload includes `automatic_tax`, `billing_address_collection`, and `customer_update`.
  - Line-item `tax_behavior` is set/normalized (`exclusive` or `inclusive`) when tax is enabled.
  - Tax-disabled line items do not include `tax_behavior`.

## Automated Tax Breakdown Snapshot Check

- Run:
  - `docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_tax_breakdown_snapshot.php`
- Coverage:
  - Validates snapshot extraction for Stripe invoice tax metadata:
    - `taxability_reason`
    - `taxability_reasons[]`
    - `tax_breakdown[]`
  - Confirms tax breakdown rows include Stripe tax rate linkage and jurisdiction/rate metadata where available.

## Automated Stripe-vs-IPS Total Mismatch Check

- Run:
  - `docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_total_mismatch_snapshot.php`
- Coverage:
  - Validates snapshot comparison fields:
    - `ips_invoice_total_minor`
    - `ips_invoice_total_display`
    - `has_total_mismatch`
    - `total_mismatch_minor`
    - `total_mismatch_display`
  - Asserts both paths:
    - matching Stripe/IPS totals => no mismatch warning
    - adjusted Stripe total => mismatch warning fields populated

## Automated ACP Integrity Panel Stats Check

- Run:
  - `docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_integrity_panel_stats.php`
- Coverage:
  - Verifies ACP integrity collector returns required keys and valid data types for:
    - webhook configuration status
    - replay task metrics
    - webhook error counters
    - mismatch counters and row payloads

## Automated Settlement Rendering Check

- Run:
  - `docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_settlement_rendering.php`
- Coverage:
  - Verifies settlement hooks are registered for both customer invoice and printable invoice views.
  - Verifies hook selectors still match Nexus template anchors.
  - Verifies hook content still includes Stripe settlement/tax/payment rows.
  - Verifies legacy notes marker blocks are absent from invoices with snapshot data.

## Automated VAT Matrix Sandbox Check

- Run:
  - `docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_vat_matrix_sandbox.php`
- Coverage:
  - Executes four Stripe Tax calculation scenarios in test mode:
    - DE B2C
    - EU B2C (OSS-style address)
    - EU B2B with VAT ID
    - non-EU customer
  - Asserts all scenarios return valid calculation payloads and validates expected zero/non-zero tax mix.

## Automated Refund Snapshot Consistency Check

- Run:
  - `docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_refund_snapshot_consistency.php`
- Coverage:
  - Validates refunded transaction snapshot fields against invoice snapshot fields.
  - Confirms both partial-refund and full-refund evidence via Stripe Refunds API for xpolarcheckout payment intents.

## Webhook Downtime Replay Task

- Task file:
  - `ips-dev-source/apps/xpolarcheckout/app-source/tasks/webhookReplay.php`
- Schedule:
  - `ips-dev-source/apps/xpolarcheckout/app-source/data/tasks.json` (`webhookReplay` every 15 minutes)
- Manual run (runtime):
  - `docker compose exec -T php php -r 'require "/var/www/html/init.php"; $task=new \IPS\xpolarcheckout\tasks\webhookReplay; var_export($task->execute()); echo "\n";'`
