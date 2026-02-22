# Polar CLI Local Debug Runbook

Last updated: February 22, 2026

## Purpose

Use the official Polar CLI tunnel to forward Polar webhook events to the local IPS4 `xpolarcheckout` webhook endpoint for real-time debugging.

## Official references

- `https://github.com/polarsource/cli`
- `https://polar.sh/docs/integrate/webhooks/locally`

## Platform support note

As of Polar CLI `v1.2.0` (released February 6, 2026), official binaries are published for:

- `darwin-arm64`
- `darwin-x64`
- `linux-x64`

No native Windows binary is published in the GitHub release assets, so this Windows-based stack should run CLI via WSL/Linux.

## 1) Install WSL distro (if needed)

If you only have `docker-desktop` WSL distro, install Ubuntu:

```powershell
wsl --install -d Ubuntu
```

Then open Ubuntu and verify:

```bash
uname -a
```

## 2) Install Polar CLI in WSL

```bash
curl -fsSL https://polar.sh/install.sh | bash
polar --version
```

## 3) Login

```bash
polar login
```

## 4) Start webhook tunnel to IPS endpoint

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

## 5) Configure temporary webhook secret in ACP

For signature verification to pass during tunnel testing:

1. Open ACP payment method settings for `xpolarcheckout`.
2. Temporarily set gateway `webhook_secret` to the secret shown by `polar listen`.
3. Save.

After finishing local tunnel tests, restore your normal endpoint secret.

## 6) Run test events

Trigger sandbox actions that emit events:

- checkout/payment completion (`order.paid`)
- refunds (`order.refunded`, `refund.updated`)

Validate:

- webhook handler logs
- forensics table/module (no signature failures)
- transaction status transitions in IPS

## 7) Common failures

`403` invalid signature:

- wrong `webhook_secret` configured in ACP
- CLI tunnel restarted and secret rotated

`404`/connection errors:

- wrong local URL path
- local IPS host not reachable from your machine

No events received:

- wrong Polar organization selected at `polar listen`
- testing in wrong environment (production vs sandbox)

## 8) Team usage rule

Treat the CLI session secret as ephemeral test secret only; do not commit it or keep it in long-term production configuration.

