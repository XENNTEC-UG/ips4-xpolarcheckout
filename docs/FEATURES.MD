# X Polar Checkout Features

> Enterprise-grade Stripe Checkout for IPS Nexus. `xpolarcheckout` delivers secure hosted payments, webhook reconciliation, tax visibility, and operational integrity tooling in one gateway app.

## Version Snapshot

| Package version | Long version | Source |
| --- | --- | --- |
| `1.1.7` | `10017` | `app-source/data/versions.json` |

## Product Positioning

`xpolarcheckout` is a full payment operations layer for teams that want Stripe Checkout convenience without losing IPS-native transaction control, auditability, and back-office visibility.

## Feature Pillars

| Pillar | What it enables | Business impact |
| --- | --- | --- |
| Hosted Stripe checkout | Stripe session creation from Nexus transactions with metadata linkage | Faster, trusted payment UX with reduced PCI burden |
| Reconciliation webhooks | Capture/async/refund/dispute event handling mapped to IPS transaction states | Payment state remains consistent across systems |
| Idempotent processing | Signature validation, event tracking, and transaction processing lock guards | Mitigates duplicate delivery and race-condition risk |
| Discount and tax support | Stripe-compatible line-item generation, discount coupon bridging, tax behavior configuration | Preserves financial correctness in real-world invoice scenarios |
| Settlement snapshots | Rich Stripe snapshot storage on transactions/invoices (tax, totals, payment, evidence) | Gives support/accounting teams durable payment context |
| Customer/print rendering | Settlement and tax data injected into invoice and printable invoice templates | Improves transparency for customers and finance |
| Integrity operations center | ACP integrity dashboard with webhook health, mismatch counters, tax readiness, replay controls | Enables proactive payment monitoring and incident response |
| Replay and alerting | Scheduled webhook replay + admin notifications for payment-integrity issues | Reduces revenue risk during webhook downtime |

## Capability Highlights

### Payment flow and gateway behavior
- Creates Stripe Checkout Sessions directly from Nexus transactions.
- Converts amounts using minor-unit logic to avoid float drift.
- Applies safety fallback paths when complex discount math cannot be represented as line-item negatives.

### Webhook reliability and safety
- Validates `Stripe-Signature` with HMAC verification.
- Tracks processed webhook event IDs per transaction to maintain idempotency.
- Uses transaction-level processing locks around capture-sensitive paths.
- Handles async payment success/failure, refunds, and dispute lifecycle transitions.
- Persists webhook validation failures to a forensics table for security audit, with ACP log viewer and automatic 90-day retention cleanup.

### Tax, mismatch, and settlement visibility
- Persists taxability reasons, tax breakdown rows, and customer tax evidence data.
- Compares Stripe-vs-IPS totals and flags unexplained differences.
- Supports tax-readiness snapshoting and ACP refresh actions.
- Renders settlement details consistently in client and print invoice contexts.

### Fraud and dispute operations
- Captures dispute and refund evidence payloads in transaction metadata.
- Supports chargeback protection workflows, including configurable dispute-ban behavior.
- Adds ACP member-profile dispute summary visibility for staff.

### Monitoring and recovery
- Integrity panel includes replay status, error tables, mismatch tables, and endpoint drift checks.
- Manual and scheduled replay features support outage recovery.
- Admin notification extension surfaces persistent integrity problems to ACP users.

## Built-In Guardrails

- Defensive catch patterns to avoid breaking core storefront execution.
- URL normalization and sanitization before persisting external links.
- Centralized required-webhook-event definitions to prevent config drift.
- Stripe API version pinning for predictable gateway behavior.

## Ideal Use Cases

- IPS stores running serious subscription or one-time revenue through Stripe.
- Operations teams needing invoice-level tax and settlement explainability.
- Admin teams requiring active payment monitoring instead of passive webhook trust.
