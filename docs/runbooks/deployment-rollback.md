# Runbook · Deployment gone wrong / rollback

**Severity when invoked for real:** SEV-2 (checkout or money path broken by a change) — SEV-1 if data is being corrupted
**Owner:** CTO · **Related:** [OPERATIONS.md](../OPERATIONS.md) (deploy + rollback procedure) · [database-restore.md](database-restore.md)

> The cardinal rule: **never blind-rollback a deploy that ran migrations.**
> Old code against a new schema can corrupt data worse than the bug you are
> reverting. Code-only deploys roll back; migrated deploys roll forward.

---

## 1 · Detect

- Error spike in Sentry immediately after deploying.
- Checkout failures / blank pages / 500s that were not happening an hour ago.
- A migration error printed mid-deploy.

First question, always: **"what did the deploy contain — did it migrate?"**

```bash
git log --oneline -5              # what shipped
git diff HEAD~1 --stat -- database/migrations   # did the schema change?
```

## 2 · Stabilize

- If money is being corrupted (double credits, wrong balances): `php artisan down --render="errors::503"` and stop Horizon (see OPERATIONS.md) before anything else.
- If a single product line is broken: turn off just its feature flag (Admin → System Settings) and keep the rest of the site up.

## 3 · Code-only deploy → roll back

Follow the rollback procedure in OPERATIONS.md (re-deploy the previous commit):

```bash
git revert --no-edit HEAD         # or: git checkout <previous-sha> in the release flow
# redeploy per OPERATIONS.md, then:
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan horizon:terminate     # workers restart on the old code
```

## 4 · Migrated deploy → roll forward

1. Do **not** run `migrate:rollback` in production unless you wrote and tested that exact `down()` path today.
2. Write the smallest fix (or `git revert` the offending commit **keeping** the migration if it was additive), ship it through CI as a normal deploy.
3. If data was already damaged: restore path — [database-restore.md](database-restore.md). A pre-migration snapshot should exist (take one before every migrating deploy; the backup tooling is `php artisan backup:run`).

## 5 · Validate

```bash
php artisan ledger:verify
php artisan payments:validate
```

- Sentry error rate back to baseline for 30 min.
- One real end-to-end purchase.
- Monitoring page fully green.

## 6 · Prevent

- Expand/contract migration discipline: additive change first deploy; destructive change only after no code references the old shape.
- `php artisan backup:run` before every deploy that migrates — make it a habit until the deploy pipeline (roadmap P1-3) automates it.
- Deploy small, deploy often, never after ~2 p.m. Friday.
- Every rollback gets an incident note: what broke, why CI missed it, what test now catches it.
