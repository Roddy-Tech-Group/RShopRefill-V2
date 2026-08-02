# Runbook · Security breach (suspected or confirmed)

**Severity when invoked for real:** SEV-1 — assume compromise until disproven
**Owner:** CTO · **Related:** [ONCALL.md](../ONCALL.md) (credential escrow) · [OPERATIONS.md](../OPERATIONS.md)

> **Preserve evidence before cleanup.** You cannot investigate what you deleted.
> Copy logs off-box first; rotate second; diagnose third. The cover-up is always
> more damaging than the breach — notify honestly and promptly when duties apply.

---

## 1 · Detect

- Unrecognized admin login, new admin trusted device, or 2FA anomaly you didn't cause.
- An admin account created or permissions escalated that you didn't do (attacker end-goals).
- Webhook signature rejection spike (forgery attempts).
- Audit-log entries (Admin → Account Activity / SRE audit logs) that no admin performed.
- Data appearing where it shouldn't (pastebin, a customer reporting knowledge they shouldn't have).

## 2 · Preserve evidence (do this FIRST, ~5 minutes)

```bash
# From your machine — copy logs off the box before touching anything
scp -r server:/path-to-app/storage/logs ./incident-$(date +%Y%m%d)/
# Also export: audit_logs, admin sessions, security_events tables
php artisan tinker --execute 'App\Models\AuditLog::latest()->limit(500)->get()->toJson();' > audit-snapshot.json
```

Note the exact time you started responding. Timestamps make the postmortem.

## 3 · Contain

In order, fastest lock first:

1. **Invalidate all admin sessions** and force re-login (rotate `SESSION` by changing admin passwords; suspend any admin account you don't recognize — Admin → Admins).
2. **Revoke suspect trusted devices** (Admin → device management / trusted devices).
3. Rotate admin passwords + 2FA re-enrollment for every admin.
4. If active exploitation is ongoing: `php artisan down --render="errors::503"` and stop Horizon.
5. Rotate secrets from the escrow inventory ([ONCALL.md](../ONCALL.md)) — start with: `APP_KEY`-adjacent secrets, provider API keys (Flutterwave, NOWPayments, Zendit, Airalo, Maplerad, Resend), webhook signing secrets, DB password, backup archive password. Every key the attacker could have read.

## 4 · Diagnose

- **Entry vector:** credentials (phishing/reuse)? dependency CVE (`composer audit`)? server (SSH, exposed service)? XSS/injection?
- **Scope:** what did they touch — the audit logs and `financial_ledger_events` hash chain exist for exactly this question.
- **Duration:** earliest anomalous entry defines the window.

```bash
php artisan ledger:verify            # tampering with money leaves imbalances
php artisan reconcile:wallet-balances
```

## 5 · Recover

1. Patch the entry vector before bringing anything back.
2. Finish rotating **every** secret (the inventory makes this a checklist, not archaeology).
3. Verify money integrity (commands above) and spot-check recent admin actions.
4. `php artisan up`; watch Monitoring for a full cycle.
5. **Notification duties:** NDPR/GDPR require notifying affected users and the regulator within statutory windows if personal data was exposed. Draft honestly: what, when, what you did, what users should do.

## 6 · Validate

- All secrets rotated (checklist complete, dated).
- No anomalous logins for 48h; audit stream clean.
- `php artisan alerting:test sev1` still reaches your phone (alert channel credentials were rotated too).

## 7 · Prevent

- Postmortem to a systemic control (never "be more careful").
- Verify the server-hardening checklist: SSH keys only, no root login, Redis/MySQL localhost-bound, Cloudflare proxied-only DNS.
- Alert on: new admin created, permission escalation, new admin trusted device — always-notify events.
- Rehearse this runbook as a tabletop once a quarter — it is the one you must never execute for the first time.
