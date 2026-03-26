# X Polar Checkout

Polar payment gateway for IPS Nexus. Provides hosted checkout redirect, webhook-driven reconciliation with Standard Webhooks signature validation, refund processing, and operational tooling (integrity panel, forensics viewer, webhook replay). Uses dynamic product mapping (`xpc_product_map`) for IPS-to-Polar product linkage. Local webhook forwarding via `polar-cli` Docker service (SSE tunnel, auto-syncs gateway settings from `.env`). Sibling architecture to `xstripecheckout` (Stripe) and `xpaynowcheckout` (PayNow).

## Read Order

1. [GitHub Issues](https://github.com/XENNTEC-UG/ips4-xpolarcheckout/issues) — open work items
2. [ARCHITECTURE.md](ARCHITECTURE.md) — architecture, Polar API contracts, data model
3. [FEATURES.MD](FEATURES.MD) — capability overview
4. [FLOW.md](FLOW.md) — runtime flows
5. [TEST_RUNTIME.md](TEST_RUNTIME.md) — manual verification checklist

## Source Paths

- Gateway: `app-source/sources/XPolarCheckout/XPolarCheckout.php`
- Invoice view helper: `app-source/sources/Invoice/ViewHelper.php`
- Application bootstrap: `app-source/Application.php`
- Webhook controller: `app-source/modules/front/webhook/webhook.php`
- ACP integrity panel: `app-source/modules/admin/monitoring/integrity.php`
- ACP forensics viewer: `app-source/modules/admin/monitoring/forensics.php`
- ACP product mappings: `app-source/modules/admin/monitoring/products.php`
- Webhook replay task: `app-source/tasks/xpcWebhookReplay.php`
- Integrity monitor task: `app-source/tasks/xpcIntegrityMonitor.php`
- Invoice view hook: `app-source/hooks/invoiceViewHook.php`
- Settlement theme hooks: `app-source/hooks/theme_sc_clients_settle.php`, `app-source/hooks/theme_sc_print_settle.php`
- Gateway model hook: `app-source/hooks/code_GatewayModel.php`
- Member profile hook: `app-source/hooks/code_memberProfileTab.php`
- Coupon name hook: `app-source/hooks/couponNameHook.php`
- Payment summary extension: `app-source/extensions/core/MemberACPProfileBlocks/PolarPaymentSummary.php`
- Admin notification extension: `app-source/extensions/core/AdminNotifications/PaymentIntegrity.php`
- DB schema: `app-source/data/schema.json` (`xpc_webhook_forensics`, `xpc_product_map`)
- Polar CLI Docker: `docker/polar-cli/` (in main repo, not this submodule)

## Source of Truth

- App code: `ips-dev-source/apps/xpolarcheckout/app-source/`
- Runtime copy: `data/ips/applications/xpolarcheckout/` (synced via `ips-dev-sync.ps1`)

## Global Context

- [../../../../README.md](../../../../README.md)
- [../../../../IPS4_DEV_GUIDE.md](../../../../IPS4_DEV_GUIDE.md)
- [../../../../AI_TOOLS.md](../../../../AI_TOOLS.md)
- [../../../../CLAUDE.md](../../../../CLAUDE.md)
