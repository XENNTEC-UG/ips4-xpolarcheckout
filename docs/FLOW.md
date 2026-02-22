# X Polar Checkout Flow

## Current Scope

This document captures the active migration architecture for `xpolarcheckout`.

## Runtime Entry Points

- Gateway class: `app-source/sources/XPolarCheckout/XPolarCheckout.php`
- Webhook endpoint: `index.php?app=xpolarcheckout&module=webhook&controller=webhook`
- Integrity panel: `app-source/modules/admin/monitoring/integrity.php`
- Replay task: `app-source/tasks/webhookReplay.php`

## End-to-End Flow (Target)

1. Customer starts checkout from Nexus invoice/transaction.
2. Gateway creates Polar checkout session and receives `checkout_url`.
3. Customer is redirected to Polar hosted checkout.
4. Polar sends webhook events to IPS endpoint.
5. Webhook controller verifies signature and timestamp freshness.
6. Event mapper updates IPS transaction state and stores provider snapshot metadata.
7. Integrity panel and replay task monitor lag, mismatches, and recovery needs.

## Webhook Invariants

- Reject missing/invalid signatures.
- Enforce replay window tolerance.
- Process each event idempotently.
- Never regress already-terminal transaction states.
- Log failures into `xpc_webhook_forensics` for ACP review.

## Data Model Notes

- `xpc_webhook_forensics` stores webhook validation and processing failures.
- `t_extra` stores normalized provider snapshot fields for transaction-level evidence.
- `i_status_extra` is used for invoice-level settlement display metadata where needed.

## Pending Migration Items

- Finalize checkout/refund end-to-end validation using a real sandbox paid order flow.
- Validate replay dry-run and live replay operations through ACP integrity actions.
- Optional: extend customer/print hook presentation using normalized settlement snapshot schema.
