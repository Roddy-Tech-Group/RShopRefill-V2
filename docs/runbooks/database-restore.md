# Runbook · Database restore & backup failure

**Severity when invoked for real:** SEV-1 (money is unavailable or wrong)
**Owner:** CTO · **Last rehearsed:** 2026-07-26 · **Measured restore time:** 19.4s (dev, 78 tables / 35 MB dump)
**Related:** [P0-1 in CTO_roadmap.md](../../CTO_roadmap.md) · [OPERATIONS.md](../OPERATIONS.md)

> During an incident you execute this file; you do not compose. If a step here is
> wrong or missing, fix it the same day — a runbook you had to improvise around is
> a runbook bug.

---

## 0 · What exists

| Thing | Where |
|---|---|
| Nightly encrypted archive | `backup:run` at 01:15 (prune) / 01:30 (dump), before the 02:00 reconciliation window |
| Destinations | `BACKUP_DESTINATION_DISKS` — `backup-local` (on-box, `storage/backups`) and `backup-remote` (off-site bucket) |
| Archive contents | `db-dumps/` gzipped SQL dump · `.env` · `storage/app` |
| Encryption | AES-256, password in `BACKUP_ARCHIVE_PASSWORD` |
| Verification | `backup:health` daily at 08:00 — exits non-zero on stale, tiny, unencrypted, or dump-less archives |
| Drill | `backup:restore-drill` — restores into a scratch database and verifies it |

**The archive password is not recoverable.** It lives in the credential escrow
(see [ONCALL.md](../ONCALL.md)). An encrypted backup whose key died with the
server is not a backup.

---

## 1 · Detect

- `backup:health` exited non-zero (daily 08:00, Sentry monitor `backup-health-daily`).
- Sentry cron monitor `backup-run-nightly` missed a check-in.
- `Log::critical` "Backup FAILED" from `BackupFailureListener`.
- Health probe / customer reports of database errors (then this is a restore, not a backup failure — jump to §4).

---

## 2 · Triage: which problem is this?

```bash
php artisan backup:health          # human-readable
php artisan backup:health --json   # machine-readable, for dashboards
```

| Symptom in output | Meaning | Go to |
|---|---|---|
| `No backup archive found` | Backups are not running at all | §3 |
| `Newest backup is N h old` | Schedule or cron is dead | §3 |
| `Database dump inside the archive is only N bytes` | Dump is failing silently — **the dangerous one** | §3.2 |
| `Newest archive is NOT encrypted` | `BACKUP_ARCHIVE_PASSWORD` unset | §3.3 |
| `No off-site destination configured` | Backups exist only on the box they protect | §3.4 |
| `Destination unreachable` | Bucket credentials / network | §3.4 |
| All OK, but the database is gone | This is a real restore | §4 |

---

## 3 · Backup is broken

### 3.1 Nothing is running

```bash
php artisan schedule:list | grep backup     # are the entries registered?
crontab -l                                  # is schedule:run installed at all?
php artisan backup:run                      # run manually; read the error
```

A dead crontab kills backups, reconciliation, sweeps and syncs simultaneously —
check `schedule:list` output against Sentry cron monitors before assuming it is
backup-specific.

### 3.2 Dump is empty or truncated

This is the failure that looks like success. Reproduce the dump alone:

```bash
php artisan backup:run --only-db
php artisan backup:health          # inspect the "DB dump" column
```

Common causes:

- **Database unreachable** (service stopped, credentials rotated, host changed).
  On Linux the dump fails loudly; the exit code propagates through the gzip pipe.
  **On Windows/XAMPP it does not** — `mysqldump | gzip` returns gzip's status, so
  a failed dump produces a valid 20-byte empty gzip and the backup reports
  success. This is why `backup:health` inspects the dump size inside the archive
  rather than trusting the archive's total size.
- `DB_DUMP_BINARY_PATH` points at a directory with no `mysqldump`.
- Disk full on `storage/backup-temp`.

### 3.3 Archive not encrypted

Set `BACKUP_ARCHIVE_PASSWORD` (≥ 24 random characters), store it in the escrow,
then `php artisan config:clear && php artisan backup:run`. Previously written
unencrypted archives contain `.env` and customer PII — delete them from every
destination.

### 3.4 Off-site destination missing or unreachable

```bash
php artisan tinker --execute 'Storage::disk("backup-remote")->put("probe.txt","ok"); echo Storage::disk("backup-remote")->get("probe.txt");'
```

Check `BACKUP_REMOTE_*` credentials, bucket name and endpoint. The bucket must be
**versioned with object lock** so `backup:clean` (and a stolen credential) can
only delete logically.

---

## 4 · Restore (the real thing)

### 4.1 Stabilise first

```bash
php artisan down                 # or the maintenance-guard flow in OPERATIONS.md
sudo supervisorctl stop all      # STOP HORIZON — workers hammering a sick
                                 # database slow recovery and can compound damage
```

### 4.2 Decide the restore target

**Never restore over the live database while diagnosing.** Restore into a scratch
database first, confirm it is good, then promote it.

```bash
php artisan backup:restore-drill --database=rshoprefills_recovery --keep
```

This downloads the newest archive (from `--disk=backup-remote` if the box is
gone), decrypts it, imports it, spot-checks row counts, and runs ledger
integrity. `--keep` leaves the database in place for promotion.

### 4.3 Promote the scratch database

```bash
# after verifying contents
mysql -e "DROP DATABASE IF EXISTS rshoprefills_broken;"
mysql -e "CREATE DATABASE rshoprefills_broken;"
# move the damaged database aside rather than deleting it — it is evidence
# and may hold rows newer than the backup
```

Point `DB_DATABASE` at the recovered database, or rename. Then:

```bash
php artisan config:clear
php artisan ledger:verify                 # MUST pass before reopening
php artisan reconcile:wallet-balances
php artisan reconcile:nowpayments --hours=48
```

### 4.4 Recover the gap between the backup and the failure

Everything between the last archive (≤ 26h) and the outage is missing. Recover it
from provider records, which are the external source of truth:

```bash
php artisan reconcile:nowpayments --hours=48
php artisan reconcile:orphaned-sessions
# replay stranded webhooks per the payment-provider runbook
```

Publish an honest data-loss note if the RPO was breached.

### 4.5 Reopen

```bash
sudo supervisorctl start all
php artisan up
php artisan backup:health
php artisan backup:run            # take a fresh backup of the recovered state
```

Watch the dashboard for one full cycle before declaring the incident closed.

---

## 5 · Monthly drill (do not skip)

```bash
php artisan backup:restore-drill
```

Record the elapsed time in [OPERATIONS.md](../OPERATIONS.md). **That number is the
real RTO** — not the one anybody asserted. Each drill should be no slower than
the last; if it is, find out why.

Rotate the source: restore from `--disk=backup-remote` at least quarterly, since
the off-site copy is the one a real disaster will use, and it is the copy nobody
tests.

---

## 6 · Prevent

- Managed database with point-in-time recovery (roadmap **P1-5**) drops RPO from
  ≤ 24h to ≤ 5 min. The nightly dumps stay as an independent, provider-agnostic
  second copy — never rely on a single backup system.
- Snapshot immediately before any destructive migration; the deploy pipeline
  (**P1-3**) automates this.
- Never let `backup:health` sit red for more than a day. The whole point of this
  system is that the answer to "are we recoverable?" is never a guess.

---

## Known open issue

`ledger:verify` currently throws `Call to undefined method App\Models\LedgerEntry::journal()`
— `App\Models\LedgerEntry` is an empty stub with no relationships, so the ledger
integrity check has never successfully run. Until this is fixed (roadmap **P0-4**),
§4.3's ledger verification step cannot be completed and a restore cannot be fully
proven. Treat this as a live gap, not a footnote.
