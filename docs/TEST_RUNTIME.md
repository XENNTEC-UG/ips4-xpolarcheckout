# X Polar Checkout Runtime Verification

This checklist is reset for the Phase 0 migration baseline.

## Baseline Checks

1. App metadata loads with version `1.0.0` (`app-source/data/application.json`).
2. Only `setup/upg_10000` exists under `app-source/setup/`.
3. Hooks no longer reference `code_loadJs`.
4. Member ACP block is `PolarPaymentSummary`.
5. Forensics table key is `xpc_webhook_forensics`.
6. Webhook returns `IGNORED_EVENT` for dispute events in Phase 0.

## Pending Full Runtime Suite

A new Polar-specific automated/runtime test suite will be introduced after provider swap work starts.
