# RShopRefills — Operations Manual

**Internal · Living document · Owner: CTO**
**If you are in an incident right now, go to [§9 Incident quick reference](#9-incident-quick-reference).**

This is the document that makes the platform operable by someone who did not
build it. If a step here is wrong, fix it the same day — a manual you had to
improvise around is a manual that will fail you at 3 a.m.

Companion documents:
[ONCALL.md](ONCALL.md) (break-glass) ·
[runbooks/](runbooks/) ·
[CTO_roadmap.md](../CTO_roadmap.md) (why any of this exists)

---

## 1 · Topology

| Layer | What runs | Notes |
|---|---|---|
| Edge | Cloudflare (proxied DNS) | Origin IP must never be exposed; DNS records stay orange-clouded |
| Web | nginx → PHP-FPM 8.2 | Laravel 12 |
| Queue | Horizon (Redis) under Supervisor | 5 supervisors, ~17 queues |
| Data | MySQL · Redis | Both bound to localhost / private network only |
| Scheduler | system cron → `schedule:run` every minute | Its heartbeat is checked by `/health` |
| Errors | Sentry (exceptions + cron monitors) | |
| Alerts | Telegram (primary) + mail (backstop) via `OperationalAlerter` | See §6 |

**Application root:** `/var/www/rshoprefills` (adjust if different — verify before an incident, not during one).

### Production URLs

Canonical host is **`www.rshoprefill.com`** (verified live 2026-07-31: the
bare apex `rshoprefill.com` 301-redirects onto it). The plural
`rshoprefills.com` is an *alternate* host that folds in the same way (see
`APP_ALTERNATE_HOSTS` in [config/app.php](../config/app.php)). Note the
`seo.canonical_base_url` site setting was *seeded* with the plural default;
verify the production value reads `https://www.rshoprefill.com`, because
sitemaps and email links build from it.

| What | URL | Auth |
|---|---|---|
| Storefront | `https://www.rshoprefill.com` | public |
| Health check | `https://www.rshoprefill.com/health` | none; this is what the uptime probe watches |
| Admin panel | `https://www.rshoprefill.com/admin` | admin login + 2FA |
| Horizon (queues) | `https://www.rshoprefill.com/horizon` | session-gated; path is `HORIZON_PATH` |

⚠️ **Verify on the VPS that `APP_URL` agrees with the setting above.** Two
independent canonicals exist in this codebase and nothing forces them to match:
the HTTP redirect layer folds hosts toward `app.canonical_host` (derived from
`APP_URL`, or `APP_CANONICAL_HOST` if set), while sitemaps and emails build
absolute URLs from `seo.canonical_base_url` in the database. If they disagree,
every link the platform emails a customer lands on a 301 — which works, but
leaks link equity and makes support screenshots confusing. Run
`php artisan config:show app.canonical_host` on production and compare.

Off-platform consoles you will need during an incident — Sentry, Cloudflare, the
R2 bucket, the mail provider, and each payment/fulfilment provider — are **not
listed here on purpose.** Their URLs travel with their credentials in the escrow
vault ([ONCALL.md](ONCALL.md)), so there is one place to look and one place to
revoke.

---

## 2 · Deploying

> Deploys are currently manual. Automating them with atomic releases and a
> one-command rollback is roadmap **P1-3**, and until that lands every deploy is
> an untested change hitting real money. Treat this section as the interim
> procedure, not the destination.

```bash
cd /var/www/rshoprefills
php artisan down --render="errors::503"      # customer-visible maintenance page

git fetch origin && git checkout main && git pull

composer install --no-dev --optimize-autoloader
npm ci && npm run build

# ALWAYS snapshot before a deploy that migrates. Restoring a bad migration
# without one is not possible.
php artisan backup:run --only-db

php artisan migrate --force

php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan horizon:terminate                # Supervisor restarts it with new code

php artisan up
```

**Post-deploy verification (do not skip on a money-path change):**

```bash
curl -fsS https://<host>/health | jq .status   # expect "ok"
php artisan ledger:verify
php artisan backup:health
```

Then watch Sentry and the storefront for one full checkout cycle.

### Rolling back

- **Code only:** `git checkout <previous-sha>` and repeat the deploy steps.
- **If the deploy migrated:** **never blind-rollback.** Old code against new
  schema can corrupt data. Prefer rolling *forward* with a revert commit. If data
  is already damaged, restore the pre-deploy snapshot
  ([runbooks/database-restore.md](runbooks/database-restore.md)).

### Change classes

Anything touching Ledger, Wallet, pricing math, or webhook handlers is a
**money-path change**: it requires tests, a rollback note, and the post-deploy
verification above. Everything else ships on green CI.

**No prod hot-edits.** Every fix goes through git, including at 2 a.m. The
runbooks exist so the emergency fix is a documented procedure rather than an SSH
session nobody can review.

---

## 3 · Maintenance mode

```bash
php artisan down --secret="<random-string>"   # you can still browse via /<secret>
php artisan up
```

There is also an application-level `maintenance-guard` middleware and feature
flags (`EnsureFeatureEnabled`) for taking down **one** product line rather than
the whole site — always prefer the smallest lever that stops the harm.

---

## 4 · Queues (Horizon)

```bash
php artisan horizon:status
php artisan horizon:terminate        # graceful restart; Supervisor brings it back
sudo supervisorctl status
sudo supervisorctl restart horizon
php artisan horizon:snapshot         # metrics; also scheduled every 5 min
```

Dashboard: `https://<host>/horizon`

**Horizon down means every queue in the company has stopped** — fulfillment,
webhooks, payment verification, and email all silently stop moving while the
storefront keeps taking orders. `/health` reports this.

Queues are partitioned by blast radius; wait thresholds live in
`config/horizon.php` under `waits`. Every job has an explicit queue so one
flood cannot starve unrelated work.

---

## 5 · Scheduled work

`php artisan schedule:list` is the source of truth. The critical entries:

| When | Command | Purpose |
|---|---|---|
| every minute | scheduler heartbeat | Proves cron is alive to `/health` |
| every 5 min | `horizon:snapshot` | Horizon metrics |
| every 5 min | fulfillment rescue sweeper | Catches dropped webhooks |
| 01:15 | `backup:clean` | Prune old archives |
| 01:30 | `backup:run` | Nightly encrypted backup |
| 02:00 | `reconcile:wallet-balances` | Wallet vs transaction history |
| 02:30 | `ledger:verify` | Double-entry integrity — **SEV-1 on failure** |
| 03:00 | `rates:fetch` | FX rates |
| 06:00 | `pricing:audit-below-cost` | Margin protection |
| 08:00 | `backup:health` | Proves a restorable backup exists |
| Mon 05:00 | `payments:validate` | Payment platform self-test |
| Mon 05:15 | `communication:validate` | Comms platform self-test |

If cron dies, **all of this stops at once** and the app gives no other sign.
That is exactly what the heartbeat check exists to catch.

---

## 6 · Alerting

Configure at least one channel or the platform cannot tell anyone anything:

```bash
php artisan alerting:test sev1     # fires through the real delivery path
```

The command prints a **per-channel delivery table** and exits non-zero if every
channel failed. Read that table rather than the exit banner: a channel can accept
a message and still not reach you.

| Severity | Reaches | Meaning |
|---|---|---|
| SEV-1 | Telegram → mail | Money is wrong or the platform is down. Wake up. |
| SEV-2 | Telegram | A revenue path is degraded. Act within hours. |
| SEV-3 | Mail | Degradation with a workaround. Next working day. |

**Telegram is the primary channel** (`OPS_TELEGRAM_BOT_TOKEN` +
`OPS_TELEGRAM_CHAT_ID`) because a phone can be set to override silent mode for a
single chat, which email cannot do reliably. **Mail is the backstop**
(`OPS_ALERT_EMAIL`): slower and easier to miss, but it survives Telegram being
unreachable and leaves a durable, searchable record. SEV-3 is mail-only on
purpose — a digest that buzzes a phone is how a team learns to silence the phone.

Slack is **not in use**. It stays available behind `OPS_SLACK_ENABLED=false` for a
future team, and until that flag is true a Slack webhook left in the environment
is ignored — deliberately, so a dormant channel can never be counted as a working
escalation path by `/health` or by `alerting:test`.

> **Telegram accepting a message is not the same as your phone alerting you.**
> After any deploy or credential rotation, fire the drill *and look at the
> handset.* Confirm the chat overrides silent mode; otherwise a 3 a.m. SEV-1
> arrives politely and waits until morning.

Identical alerts are throttled for `ALERTING_THROTTLE_MINUTES` so a five-minute
check cannot send the same message 288 times. Throttled alerts are **still
logged** — the forensic record never has gaps.

**Standing rule:** any alert that repeatedly turns out to be "fine" gets
re-thresholded or deleted the same day. Alert fatigue is how real pages get
ignored, and a solo operator has no tolerance budget for noise.

---

## 7 · Health & monitoring

```bash
curl -fsS https://<host>/health | jq
```

Returns **200** when the platform can serve customers, **503** when a critical
component (database, cache) is down. Non-critical components (Redis, Horizon,
scheduler, alerting) are reported but do not trip the uptime probe — a stalled
scheduler is serious, but the storefront is still taking orders, and paging the
probe for it would train everyone to ignore the probe.

Point the external uptime monitor at `/health` **from outside the VPS**.
Self-hosted monitoring cannot tell you the server is unreachable.

### UptimeRobot setup (free tier, account created 2026-07-31)

Two monitors, both on the free plan's 5-minute interval:

| Monitor | Type | Target | Settings |
|---|---|---|---|
| `RShopRefills health` | HTTP(s) | `https://www.rshoprefill.com/health` | Any non-2xx counts as down; `/health` returns 503 when the platform cannot serve customers, so this pages on real outages, not cosmetic ones. **Create only after `/health` is deployed** (it 404s until then, which reads as down) |
| `RShopRefills storefront` | Keyword | `https://www.rshoprefill.com/` | Keyword `RshopRefills` (renders in the footer copyright line). In UptimeRobot's settings this must be **"start incident when keyword does not exist"**; the "when keyword exists" option is inverted and alarms on every healthy page load, which is exactly what happened on first setup (2026-07-31). Catches "server up but page broken", which a status-code check cannot see |

Alert contacts: email, plus the UptimeRobot mobile app (free) with push
notifications enabled; in the app's notification settings allow it to bypass
silent mode, because a 3 a.m. outage that respects Do Not Disturb defeats the
purpose.

**Cloudflare caveat, do this once:** the site sits behind Cloudflare, and a bot
challenge served to UptimeRobot's probes would read as down (or worse, mask a
real outage). In Cloudflare: Security → WAF → Custom rules → create a rule
`URI Path equals /health` with action **Skip** (all security features). The
endpoint is deliberately secret-free, so skipping security scanning on it
gives up nothing.

**Prove it once (fire drill):** pause nginx for one minute in a quiet window
(`systemctl stop nginx`, wait for the push notification, `systemctl start
nginx`). A probe that has never been seen to fire is a hope, not a control.

---

## 8 · Backups & recovery

```bash
php artisan backup:run            # manual backup
php artisan backup:health         # is a recent, encrypted, off-site archive present?
php artisan backup:restore-drill  # restore into a scratch DB and verify it
```

| Objective | Target | Measured |
|---|---|---|
| RPO (max data loss) | ≤ 24h (≤ 5 min once P1-5 managed DB lands) | 24h — nightly archive |
| RTO (time to restore) | ≤ 4h | **19.4s** for the database restore itself (2026-07-26 drill, 78 tables / 35 MB) |

The measured RTO above covers restoring the data only. Rebuilding a lost server
around it has not yet been drilled — that is roadmap **P5-4** (the "VPS is gone"
game day), and until it happens the end-to-end RTO is an estimate.

**Run the drill monthly and record the time.** An untested backup is a hope.
Full procedure: [runbooks/database-restore.md](runbooks/database-restore.md).

⚠️ Archives are AES-256 encrypted with `BACKUP_ARCHIVE_PASSWORD`. **Losing that
value loses every backup.** It belongs in the credential escrow, not only in
`.env` on the server it protects.

---

## 9 · Incident quick reference

| Symptom | First move |
|---|---|
| Site down | `/health` → which component? → [runbooks/](runbooks/) |
| `ledger:verify` failed | **SEV-1.** Freeze wallet ops. Do not "fix" the ledger — investigate to root cause |
| Backup unhealthy | [runbooks/database-restore.md](runbooks/database-restore.md) |
| Payments failing | Disable the failing method in checkout; customers route to working rails |
| Provider outage | Feature-flag the affected product lines off; keep the rest selling |
| Queue backlog | `horizon:status`, `supervisorctl status`; check for a poison job |
| Suspected breach | **Preserve evidence first** — copy logs off-box before any cleanup |

**Sequence, every time:** Declare severity out loud → Stabilise (smallest lever
that stops harm) → Communicate → Diagnose → Verify recovery → Postmortem within
48h. Adrenaline destroys recall, so write a timestamped note as you go.

**Never auto-fix a reconciliation break or a ledger discrepancy.** Understand it
first; a wrong correction is worse than the original drift.

---

## 10 · Common tasks

```bash
# Recover one stuck order
php artisan fulfillment:retry --all-failed
php artisan reconcile:nowpayments --hours=48
php artisan reconcile:orphaned-sessions

# Money & margin
php artisan money:report
php artisan pricing:audit-below-cost

# Provider float
php artisan zendit:check-balance --threshold=250

# Platform self-tests
php artisan payments:validate
php artisan communication:validate

# Caches (after any config or .env change)
php artisan config:clear && php artisan config:cache
```

---

## 11 · Environment variables

The full annotated list is [.env.example](../.env.example) — it is generated
from what the code actually reads, and is the reference for a rebuild.

The values that will hurt most if wrong:

| Variable | Why it matters |
|---|---|
| `APP_KEY` | Rotating it without `APP_PREVIOUS_KEYS` makes existing encrypted data unreadable |
| `BACKUP_ARCHIVE_PASSWORD` | Lose it and every backup is unrecoverable |
| `PRICING_FLOOR_MODE` | `enforce` with bad cost data raises storefront prices by the same corrupt factor |
| `PAYMENT_MOCK` | `true` in production short-circuits real gateways |
| `APP_DEBUG` | `true` in production leaks stack traces and config |
| `*_WEBHOOK_*` secrets | A wrong value silently drops real provider events |

---

## 12 · Server checklist

Verify quarterly; record the date here.

- [ ] SSH keys only, root login disabled
- [ ] Firewall open on 80/443/SSH only
- [ ] MySQL and Redis bound to localhost / private network
- [ ] fail2ban active
- [ ] Cloudflare proxied-only DNS (origin IP not resolvable)
- [ ] Unattended security upgrades enabled
- [ ] TLS certificate auto-renewal verified
- [ ] Disk usage < 75% (`storage/logs` grows fastest)

*Last verified: — (not yet done)*

---

## 13 · Known gaps

Honesty here is worth more than a clean-looking document. Tracked in
[CTO_roadmap.md](../CTO_roadmap.md).

| Gap | Roadmap |
|---|---|
| **Off-site uploads not yet enabled in production** — the R2 bucket and credentials exist and are verified, but `BACKUP_DESTINATION_DISKS` must include `backup-remote` on the VPS. Until it does, backups accumulate on the same disk they exist to protect against losing | P0-1 (operator action) |
| **Object lock / versioning on the backup bucket unconfirmed** — the backup credentials can currently delete objects outright, so ransomware on the VPS could destroy backup history along with the data | P0-1 (operator action) |
| No staging environment — changes go from a dev laptop to production | P1-4 |
| Deploys are manual; no one-command rollback | P1-3 |
| External uptime probe: UptimeRobot account created 2026-07-31; the two monitors in §7 still need creating, the Cloudflare skip rule applying, and the stop-nginx fire drill passing | P0-3 (operator action) |
| Mail alert delivery proven only against the `log` mailer; unproven over real SMTP | P0-3 (verify on VPS) |
| Bus factor is 1; nobody else has rehearsed access, and `BACKUP_ARCHIVE_PASSWORD` is not yet in escrow | P5-1 / P0-6 |
| Server checklist above has never been formally verified | P4-1 |

**On object lock:** do not solve this by removing delete permission from the
backup credentials — `backup:clean` needs it to enforce retention. The correct
configuration is **versioning + object lock enabled on the bucket**, so a delete
writes a delete marker while the locked version survives until its retention
expires. Retention keeps working; ransomware stops working.
