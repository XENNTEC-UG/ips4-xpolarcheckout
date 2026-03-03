# Polar CLI Local Debug Runbook

Last updated: February 22, 2026

## Purpose

Forward Polar webhook events to the local IPS4 `xpolarcheckout` webhook endpoint for real-time debugging during development.

## Official references

- `https://github.com/polarsource/cli`
- `https://polar.sh/docs/integrate/webhooks/locally`

---

## Option A: Docker service (recommended)

Uses the same local-listener pattern as other gateway debug services in this stack. The `polar-cli` Docker service connects to Polar's SSE endpoint, forwards webhook events to the local IPS webhook URL, and auto-syncs the session signing secret to the database.

### Prerequisites

1. **Polar sandbox account** at `https://sandbox.polar.sh`
2. **Organization Access Token** — create one from your org settings in the Polar dashboard
3. **Organization ID** (UUID) — visible in your Polar dashboard URL or API responses

### Setup

1. Add your credentials to `.env`:

```bash
POLAR_ACCESS_TOKEN=polar_oat_xxxxxxxxxxxxx
POLAR_ORG_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
```

2. Add `polar` to your compose profiles in `.env`:

```bash
COMPOSE_PROFILES=http,polar,xunicore-mock,cron
```

3. Build and start:

```bash
docker compose build polar-cli
docker compose up -d polar-cli
```

4. Check logs:

```bash
docker compose logs -f polar-cli
```

You should see:

```
  Connected  (org: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx)
  Secret     xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
  Forwarding http://host.docker.internal/index.php?app=xpolarcheckout&module=webhook&controller=webhook

  Waiting for events...
```

The webhook secret is automatically synced to the `XPolarCheckout` gateway in the database. No manual ACP configuration needed.

### Environment variables

| Variable | Required | Default | Description |
|---|---|---|---|
| `POLAR_ACCESS_TOKEN` | Yes | - | Organization Access Token from Polar dashboard |
| `POLAR_ORG_ID` | Yes | - | Organization ID (UUID) |
| `POLAR_FORWARD_TO` | No | `http://host.docker.internal/index.php?app=xpolarcheckout&module=webhook&controller=webhook` | Local webhook URL |
| `POLAR_ENVIRONMENT` | No | `sandbox` | `sandbox` or `production` |

### How it works

- Connects via Server-Sent Events (SSE) to `https://sandbox-api.polar.sh/v1/cli/listen/{org_id}`
- Receives an ephemeral webhook signing secret as plain hex on connection
- Auto-syncs the secret to `nexus_paymethods` via `docker exec` on the db container
- Forwards each webhook event to the local URL with Standard Webhooks headers (`webhook-id`, `webhook-timestamp`, `webhook-signature`)
- Reconnects automatically if the SSE connection drops

### Testing

Trigger sandbox actions that emit events:

- Checkout/payment completion -> `order.paid`
- Refunds -> `order.refunded`, `refund.updated`

Validate:

- Webhook handler logs (`docker compose logs -f polar-cli`)
- Forensics table (`xpc_webhook_forensics`) in ACP — no signature failures
- Transaction status transitions in IPS

---

## Option B: WSL manual approach (fallback)

Use when Docker approach is unavailable or for ad-hoc testing with the Polar CLI binary directly.

### Platform support note

As of Polar CLI `v1.2.0` (released February 6, 2026), official binaries are published for:

- `darwin-arm64`
- `darwin-x64`
- `linux-x64`

No native Windows binary is published, so this Windows-based stack should run the CLI via WSL/Linux.

### 1) Install WSL distro (if needed)

If you only have `docker-desktop` WSL distro, install Ubuntu:

```powershell
wsl --install -d Ubuntu
```

Then open Ubuntu and verify:

```bash
uname -a
```

### 2) Install Polar CLI in WSL

```bash
curl -fsSL https://polar.sh/install.sh | bash
polar --version
```

### 3) Login

```bash
polar login
```

### 4) Start webhook tunnel to IPS endpoint

Use your actual local IPS URL. For this app, the route is:

`index.php?app=xpolarcheckout&module=webhook&controller=webhook`

Example:

```bash
polar listen "https://localhost/index.php?app=xpolarcheckout&module=webhook&controller=webhook"
```

CLI output will show:

- connected organization
- forwarding target URL
- tunnel session secret

### 5) Configure temporary webhook secret in ACP

For signature verification to pass during tunnel testing:

1. Open ACP payment method settings for `xpolarcheckout`.
2. Temporarily set gateway `webhook_secret` to the secret shown by `polar listen`.
3. Save.

After finishing local tunnel tests, restore your normal endpoint secret.

### 6) Run test events

Trigger sandbox actions that emit events:

- checkout/payment completion (`order.paid`)
- refunds (`order.refunded`, `refund.updated`)

Validate:

- webhook handler logs
- forensics table/module (no signature failures)
- transaction status transitions in IPS

---

## Common failures

`403` invalid signature:

- wrong `webhook_secret` configured in ACP (Docker approach auto-syncs this)
- CLI/Docker container restarted and secret rotated

`404`/connection errors:

- wrong local URL path
- local IPS host not reachable from your machine (check `host.docker.internal` resolves)

No events received:

- wrong Polar organization ID configured
- testing in wrong environment (production vs sandbox)
- Polar sandbox account not set up

## Team usage rule

Treat the CLI session secret as ephemeral test secret only; do not commit it or keep it in long-term production configuration.
