# Stripe Checkout Dev Status - Active Tasks

**Related docs:**
- [README.md](README.md) - component entrypoint and install notes
- [FLOW.md](FLOW.md) - entry points and event flow
- [TEST_RUNTIME.md](TEST_RUNTIME.md) - manual verification list
- [CHANGELOG.md](CHANGELOG.md) - completed work history
- [BACKLOG_ARCHIVE.md](archive/BACKLOG_ARCHIVE.md) - completed backlog history
- [../../../../IPS4_DEV_GUIDE.md](../../../../IPS4_DEV_GUIDE.md) - sync/export workflow

## Current Status (2026-02-20)

16/16 automated test scripts PASS (A1-A14, A16-A17). Additional targeted guard check A15 PASS (webhook capture lock). All browser-test bugs resolved. Both queued improvements implemented (tax-aware mismatch, registration type display). Chargeback protection suite complete: B1-B8, B10-B11 implemented. Completed work archived in [BACKLOG_ARCHIVE.md](archive/BACKLOG_ARCHIVE.md).

**App version:** 1.1.4 (long version 10014)

---

## TODO — Active Backlog

No open items.

---

## Implementation Notes

These guardrails are mandatory for future changes and should be checked against `IPS4_DEV_GUIDE.md` before merge.

1. **Template safety** — use `{$var}` output for dynamic values. Avoid raw `{expression="..."}` for user/external data.
2. **Hook robustness** — catch `\Throwable`, keep parent fallback. Never break core Nexus page execution.
3. **String API** — prefer `\mb_*` helpers in IN_DEV-checked code paths. Keep fully qualified function calls.
4. **ACP action safety** — state-changing actions require CSRF. No ACP toggles that weaken transport/security.
5. **Network/Stripe** — use IPS HTTP framework, validate external URLs, keep webhook handlers idempotent.
6. **Definition of done** — automation test + doc updates + import-sync verification for every feature.
