# X Polar Checkout — Polar Payment Gateway for IPS4 / Invision Community

A free, open-source payment gateway that integrates [Polar](https://polar.sh) with [IPS4 / Invision Community](https://invisioncommunity.com) Nexus Commerce. Accept payments through Polar's hosted checkout with full webhook reconciliation, settlement tracking, and admin tooling — built to the same standard as Stripe gateway integrations.

---

## Why Polar + IPS4?

[Polar](https://polar.sh) is a modern payment platform built for software creators, offering hosted checkout, subscription billing, and built-in tax handling. Until now, IPS4 Nexus had no native Polar integration. This app bridges that gap with a production-grade gateway that handles the full payment lifecycle:

- **Checkout** — Redirect customers to Polar's hosted checkout from any Nexus invoice
- **Reconciliation** — Webhook-driven state sync keeps IPS transactions accurate
- **Refunds** — Process full and partial refunds through the Nexus admin interface
- **Monitoring** — ACP integrity panel, forensics viewer, and automated replay recovery

## Features

### Payment Flow
- Hosted checkout redirect from Nexus invoices (no PCI exposure)
- Currency-aware amount conversion (supports all decimal configurations, not just 2-digit)
- Dynamic product mapping — automatically creates Polar products mirroring your Nexus packages
- Multi-item checkout consolidation with configurable receipt labels
- Hybrid checkout gating (optionally restrict Polar to single-item carts)

### Webhook Processing
- [Standard Webhooks](https://www.standardwebhooks.com/) signature verification
- Hex-secret normalization for local dev tunnels (Polar CLI compatibility)
- Event-ID idempotency — safe against duplicate deliveries
- Terminal-state guardrails prevent accidental status regression
- Failed webhook forensics persisted for admin audit (90-day retention)

### Settlement & Invoicing
- Normalized settlement snapshots stored on every transaction
- Provider-vs-IPS total comparison with mismatch detection
- Automatic Polar invoice generation after payment (`POST /v1/orders/{id}/invoice`)
- Invoice URL persistence across webhook events
- Enriched snapshots: discount, tax, net amount, billing details, customer info, line items
- Stripe-parity invoice view: two-column layout with charge summary, payment references, status badges

### Admin Control Panel (ACP)
- Integrity panel with environment badge (sandbox/production) and health indicators
- Webhook replay pipeline sourced from Polar's deliveries API with dedup and dry-run mode
- Configurable replay guardrails (lookback window, overlap, max events per run)
- Forensics viewer for webhook failure audit trail
- Product mapping viewer with bulk name sync
- Admin notifications for persistent payment integrity issues

### Developer Experience
- Docker-based local webhook forwarding via Polar CLI SSE tunnel
- Auto-syncs all gateway settings from `.env` — zero manual ACP config for dev
- Recovery upgrade package for partial installations
- Comprehensive documentation and runbooks

## Requirements

| Requirement | Version |
|---|---|
| IPS4 / Invision Community | 4.7+ |
| PHP | 8.1+ |
| Nexus Commerce | Included with IPS4 |
| Polar Account | [sandbox.polar.sh](https://sandbox.polar.sh) (test) or [polar.sh](https://polar.sh) (production) |

## Installation

### 1. Download

Download the latest release from the [Releases](https://github.com/XENNSU-IO/ips4-xpolarcheckout/releases) page, or clone the repo:

```bash
git clone https://github.com/XENNSU-IO/ips4-xpolarcheckout.git
```

The IPS4 application files are in the `app-source/` directory.

### 2. Upload to IPS4

Copy the contents of `app-source/` into your IPS4 installation:

```
app-source/ --> /applications/xpolarcheckout/
```

### 3. Install via ACP

1. Go to **AdminCP > System > Applications**
2. The app will appear as "X Polar Checkout" — click **Install**
3. Navigate to **AdminCP > Commerce > Payment Methods**
4. Add a new payment method and select **X Polar Checkout**

### 4. Configure Gateway Settings

You'll need from your Polar dashboard:
- **Organization Access Token** — create one from your org settings
- **Webhook Secret** — from your Polar webhook endpoint configuration
- **Environment** — `sandbox` for testing, `production` for live

Enter these in the gateway settings form and save. The gateway will automatically sync your Polar organization's presentment currency.

## Local Development

For local webhook testing, a Docker-based Polar CLI service is available that tunnels Polar webhook events to your local IPS4 instance via SSE:

```bash
# Add to your .env
POLAR_ACCESS_TOKEN=polar_oat_your_token_here
POLAR_ORG_ID=your-org-uuid
COMPOSE_PROFILES=http,polar

# Start the service
docker compose up -d polar-cli
```

The webhook secret is automatically synced to the database — no manual ACP configuration needed. See [docs/POLAR_CLI_LOCAL_DEBUG.md](docs/POLAR_CLI_LOCAL_DEBUG.md) for the full setup guide.

## Architecture

```
Customer  -->  Nexus Invoice  -->  Polar Hosted Checkout
                                         |
                                    (customer pays)
                                         |
                                   Polar Webhook
                                         |
                              Signature Verification
                                         |
                              Event Mapping & State Sync
                                         |
                         IPS Transaction Updated (paid/refunded)
                                         |
                         Settlement Snapshot Persisted
```

**Key design decisions:**
- Webhook-first reconciliation (no polling)
- Idempotent event processing with lock guards
- Fail-safe behavior — webhook failures never corrupt transaction state
- Settlement snapshots provide full audit trail independent of Polar dashboard access

## File Structure

```
app-source/
  data/             Application metadata, schema, hooks, extensions
  dev/              Language strings
  hooks/            Gateway registration, invoice view, settlement display
  modules/
    admin/          ACP integrity panel, forensics viewer, product mapping
    front/          Webhook endpoint
  sources/          Gateway class (XPolarCheckout.php)
  tasks/            Webhook replay, integrity monitor
  extensions/       Admin notifications, member profile blocks
docs/               Documentation, changelogs, runbooks
```

## Documentation

| Document | Description |
|---|---|
| [FEATURES.MD](docs/FEATURES.MD) | Complete feature list and capability status |
| [FLOW.md](docs/FLOW.md) | End-to-end payment flow and webhook invariants |
| [CHANGELOG.md](docs/CHANGELOG.md) | Version history and release notes |
| [POLAR_CLI_LOCAL_DEBUG.md](docs/POLAR_CLI_LOCAL_DEBUG.md) | Local dev webhook forwarding setup |
| [TEST_RUNTIME.md](docs/TEST_RUNTIME.md) | Runtime testing procedures |

## Compatibility

This app is designed to coexist with other Nexus payment gateways, including Stripe. When both `xstripecheckout` and `xpolarcheckout` are installed, an idempotency guard prevents double-processing of shared UI enhancements (invoice view, order details, coupon display).

## Contributing

Contributions are welcome. Please open an issue first to discuss what you'd like to change.

## License

This project is free to use. See the repository for license details.

## Links

- [Polar](https://polar.sh) — Payment platform for software creators
- [Polar Documentation](https://docs.polar.sh) — API reference and guides
- [IPS4 / Invision Community](https://invisioncommunity.com) — Community platform
- [XENNSU](https://xenntec.com) — Developer

---

**Made by [XENNSU](https://github.com/XENNSU-IO)**
