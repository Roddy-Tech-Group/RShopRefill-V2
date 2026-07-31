# Runbook · Queue backlog / Horizon down

**Severity when invoked for real:** SEV-1 if Horizon is dead (every queue in the company stops) · SEV-2 for a single drowning queue
**Owner:** CTO · **Related:** `/horizon` dashboard · [OPERATIONS.md](../OPERATIONS.md) §Horizon · [fulfillment-provider-outage.md](fulfillment-provider-outage.md)

> Horizon dead means: no fulfillment, no webhooks processed, no emails, no
> reconciliation. It fails quiet — the site looks fine while money piles up
> unprocessed. The `/health` endpoint and Monitoring page both watch it.

---

## 1 · Detect

- Monitoring page: "Horizon" tile red.
- `/health` returns queue component fail.
- `LongWaitDetected` notification for a queue.
- "Stuck paid orders" climbing while the site is otherwise fine.

## 2 · Horizon dead → restart it

```bash
php artisan horizon:status        # confirm: running / paused / inactive
```

Restart per OPERATIONS.md (Supervisor manages the process):

```bash
sudo supervisorctl restart horizon      # production
# after any code deploy, prefer a graceful cycle instead:
php artisan horizon:terminate           # supervisor auto-restarts it on new code
```

If it dies again immediately: check `storage/logs/` for the crash loop cause —
usually a bad config cache (`php artisan config:cache` after fixing) or Redis
being down (Redis tile on the Monitoring page).

## 3 · One queue drowning → find the flood

Open `/horizon` → Pending. Identify the flooding job class and its queue.

| Cause | Signature | Response |
|---|---|---|
| Provider hanging | `fulfillment` queue, jobs long-running | [fulfillment-provider-outage.md](fulfillment-provider-outage.md) — the breaker/flags stop the bleeding |
| Poison job retrying forever | Same job failing repeatedly in Failed tab | Delete/fix the poison job; `php artisan horizon:forget` its failed copies if needed |
| Legit burst (campaign send) | `comm-*` queues busy, money queues fine | Let it drain; pause the campaign supervisor if it starves others |
| Redis memory pressure | Evictions in Redis, jobs vanishing | SEV-2: Redis eviction also weakens webhook replay protection — grow memory now |

## 4 · Recover

1. Drain order: money first. Confirm `critical`, `webhooks`, `payments`, `fulfillment` queues empty before caring about marketing queues.
2. Failed jobs from the outage window: review in `/horizon` → Failed (it expires after 7 days), retry what should retry:

```bash
php artisan fulfillment:retry --all-failed   # fulfillment specifically
php artisan queue:retry all                  # everything else, after review — not before
```

3. Sweep for orders that missed their window: the rescue sweeper runs every 5 min; check "Stuck paid orders" hits 0.

## 5 · Validate

```bash
php artisan horizon:status
php artisan ledger:verify
```

- Monitoring page fully green; queue waits under thresholds for 30 min.
- Scheduler heartbeat green (a dead cron often accompanies a dead Horizon after server issues).

## 6 · Prevent

- Every queue has a `waits` threshold and every job an explicit queue (done in P0-5) — keep it that way for new jobs.
- After any deploy: `php artisan horizon:terminate`, never leave old workers running stale code.
- Redis memory alert before evictions start, not after.
