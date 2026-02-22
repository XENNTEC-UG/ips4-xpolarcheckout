# X Polar Checkout

## Status

Phase 0 baseline cleanup is in progress.

This app currently keeps the Stripe-derived gateway architecture but removes legacy packaging artifacts and prepares for Polar provider migration.

## Source Paths

- Gateway: `app-source/sources/XPolarCheckout/XPolarCheckout.php`
- Webhook controller: `app-source/modules/front/webhook/webhook.php`
- ACP integrity: `app-source/modules/admin/monitoring/integrity.php`
- Forensics schema: `app-source/data/schema.json` (`xpc_webhook_forensics`)

## Phase 0 Notes

- Submodule/app key: `xpolarcheckout`
- Setup migrations reduced to `setup/upg_10000`
- Legacy Stripe release tar removed from `releases/`
- Legacy `docs/automation/` scripts removed

## Next Work

- Replace Stripe API calls with Polar API calls
- Implement Polar webhook verification and event mapping
- Rebuild runtime test matrix for Polar flows
