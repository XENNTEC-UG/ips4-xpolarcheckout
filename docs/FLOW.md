# X Polar Checkout Flow

## Current State

This document reflects the Phase 0 migration baseline.

## Entry Points

- Gateway class: `app-source/sources/XPolarCheckout/XPolarCheckout.php`
- Webhook endpoint: `index.php?app=xpolarcheckout&module=webhook&controller=webhook`
- Integrity panel: `app-source/modules/admin/monitoring/integrity.php`
- Replay task: `app-source/tasks/webhookReplay.php`

## Webhook Behavior (Phase 0)

- `charge.refunded` is processed.
- `charge.dispute.created` and `charge.dispute.closed` are explicitly ignored while dispute automation is removed.
- Signature validation and idempotency guards remain in place from the Stripe baseline.

## Data/Schema

- Forensics table key is `xpc_webhook_forensics`.
- App metadata version reset to `1.0.0` / `10000`.
- Setup upgrades are collapsed to `setup/upg_10000` only.

## Pending Polar Migration

- Replace Stripe checkout/session creation with Polar checkout creation.
- Replace Stripe webhook signature model with Polar/Standard Webhooks model.
- Replace Stripe event map with Polar `order.*`/`refund.*` event map.
