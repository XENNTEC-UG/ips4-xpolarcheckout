# X Polar Checkout

## Current Status

`xpolarcheckout` is installed as an IPS4 app and is in active provider migration.

- App install and gateway registration recovery shipped in `v1.0.1` (`10001`).
- Gateway, webhook controller, integrity panel, and replay pipeline are implemented.
- Standard Webhooks signature validation hardening is implemented.
- Polar checkout/refund provider calls are implemented with sandbox payload validation completed.
- Settlement snapshot normalization for integrity/reporting is implemented.
- Remaining work is end-to-end paid/refund runtime validation and ACP click-through verification.
- Local webhook forwarding via `polar-cli` Docker service is operational (SSE tunnel, auto-syncs all gateway settings from `.env`).

## Source Paths

- Gateway: `app-source/sources/XPolarCheckout/XPolarCheckout.php`
- Webhook controller: `app-source/modules/front/webhook/webhook.php`
- Integrity ACP module: `app-source/modules/admin/monitoring/integrity.php`
- Forensics schema: `app-source/data/schema.json` (`xpc_webhook_forensics`)
- Polar CLI Docker: `docker/polar-cli/` (in main repo, not this submodule)
- Polar CLI runbook: `docs/POLAR_CLI_LOCAL_DEBUG.md`

## Doc Read Order

1. `docs/POLAR_GATEWAY_IMPLEMENTATION_PLAN.md`
2. `docs/BACKLOG.md`
3. `docs/TEST_RUNTIME.md`
4. `docs/CHANGELOG.md`
5. `docs/FLOW.md`
6. `docs/FEATURES.MD`

## Working Rules

- Keep active execution tracking in `docs/BACKLOG.md` and GitHub issue `#1`.
- `docs/BACKLOG.md` now keeps only active/open tasks (manual user-required items are listed first).
- Completed historical backlog detail is moved to `docs/archive/BACKLOG_ARCHIVE.md`.
- Log completed milestones in `docs/CHANGELOG.md` with date and version.
- Update this file if architecture entry points or status materially change.
