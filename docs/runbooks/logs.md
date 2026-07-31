# Runbook · Logs: where they live, how to read them, disk pressure

**Severity when invoked for real:** SEV-2 for disk pressure or a failing scheduled task; SEV-3 for routine investigation
**Owner:** CTO · **Related:** Admin → Management → Monitoring (Disk tile, Queues & jobs) · [queue-backlog.md](queue-backlog.md)

> Logs answer "what actually happened", but only if you read them before they
> rotate away and only if the disk they live on has room to keep writing.
> A full disk stops MySQL, backups and logging at the same time; that is why
> the disk alert links here.

---

## 1 · Where every log lives

Application root: `/home/rshoprefills-v2/htdocs/rshoprefill.com` (see
[OPERATIONS.md](../OPERATIONS.md) §1).

| What | Where | Notes |
|---|---|---|
| Laravel application log | `storage/logs/laravel.log` | Every alert the platform sends is also written here with its context |
| Horizon / queue workers | `storage/logs/` + Supervisor's own log dir | Worker crashes land in Supervisor logs, job failures in the `failed_jobs` table |
| nginx access + error | typically `/home/rshoprefills-v2/logs/nginx/` on CloudPanel; verify with `nginx -T \| grep log` | 5xx spikes show here before anywhere else |
| PHP-FPM | `php-fpm` error log per pool; verify with `php-fpm8.2 -tt 2>&1 \| grep error_log` | Fatal errors that never reach Laravel |
| MySQL | error log per server config (`SHOW VARIABLES LIKE 'log_error';`) | Disk-full and crash-recovery messages appear here |
| cron / scheduler | `grep CRON /var/log/syslog` | Proves whether `schedule:run` fired at all |

## 2 · Reading under pressure

```bash
tail -f storage/logs/laravel.log                 # live view
grep -n "SEV-" storage/logs/laravel.log | tail   # recent operational alerts
grep -n "alert_key" storage/logs/laravel.log | tail
```

Every `OperationalAlerter` message is logged with its `alert_key` and context
even when Telegram delivery is throttled, so the log is the complete alert
history; Telegram is only the delivery copy.

## 3 · Disk pressure (the Disk tile / disk alert sends you here)

Biggest consumers, in the order they usually matter:

1. **Local backup archives**: `storage/backups/`. Retention pruning runs at
   01:15 (`backup:clean`); check it is actually running before deleting by
   hand. Off-site copies are unaffected by local pruning.
2. **The Laravel log**: single-file logging grows without bound. Check size,
   then truncate safely (do not `rm` while PHP holds the handle):
   ```bash
   ls -lh storage/logs/
   : > storage/logs/laravel.log
   ```
3. **MySQL data growth**: largest tables are telemetry and audit tables;
   retention jobs are roadmap P2-7. Measure with:
   ```bash
   du -sh /var/lib/mysql 2>/dev/null || df -h
   ```
4. General sweep: `du -sh /home/rshoprefills-v2/htdocs/rshoprefill.com/* | sort -rh | head`.

After freeing space, confirm the Disk tile goes green on the Monitoring page
and that MySQL is still writing (`php artisan ledger:verify` is a cheap
end-to-end write/read proof).

## 4 · A scheduled task failed (the cron-failure alert sends you here)

1. The alert names the task. Run it by hand and read its output:
   ```bash
   php artisan schedule:list
   php artisan <the-failing-command>
   ```
2. Check the Laravel log around the alert's timestamp for the exception.
3. If the task is one of the integrity fleet, its own runbook takes over:
   ledger failures go to [ledger-integrity.md](ledger-integrity.md), backup
   failures to [database-restore.md](database-restore.md), FX to
   [fx-failure.md](fx-failure.md).
4. If *many* tasks fail at once, suspect the shared cause first: disk full
   (§3), Redis down, or a dead cron daemon (`grep CRON /var/log/syslog`).

## 5 · Credentials live elsewhere, permanently

Account credentials are **never** written in this or any runbook. The
password-manager vault is the source of truth (escrow process in
[ONCALL.md](../ONCALL.md)); a local-only scratch copy, if one must exist,
belongs in `storage/app/private/` which git ignores by design. Git history
keeps every committed byte retrievable forever, so a password that touches a
tracked file is a password you rotate.
