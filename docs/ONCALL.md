# Break-glass — when the CTO is unreachable

**Internal · Confidential · Owner: CTO · Review quarterly**

This document exists for the situation nobody plans for: the one person who
understands this platform is unreachable — asleep, on a flight, in hospital, or
simply gone — and the company still has customers' money in it.

If you are reading this in that situation: you are not expected to fix the
product. You are expected to **stop the bleeding, protect the money, and tell
customers the truth.** That is enough.

---

## 0 · The first five minutes

Do these in order. Do not skip to diagnosis.

1. **Confirm it is real.** Open `https://<host>/health`. A JSON body with
   `"status":"ok"` means the platform believes it is fine — the problem may be
   narrower than it looks.
2. **If money could be moving incorrectly, stop it.** Maintenance mode is
   reversible; a thousand wrong transactions are not.
   ```bash
   php artisan down
   sudo supervisorctl stop all      # stops queue workers
   ```
3. **Tell customers something true.** Silence is what people remember. Even
   "We're aware of a problem with payments and are working on it" is enough.
4. **Escalate** to the contacts in §2.

**Never** run a database migration, "fix" a wallet balance by hand, or edit
files on the server directly. If you are unsure, leaving the platform *stopped*
is almost always safer than leaving it *running and wrong*.

---

## 1 · Who has access

> ⚠️ **This table is currently incomplete, and that is the single largest
> operational risk the company carries.** Bus factor is 1: no one but the CTO
> can presently deploy, restore, or rotate anything. Closing this is roadmap
> **P5-1**. Fill it in before it is needed.

| Role | Person | Reaches | Can do |
|---|---|---|---|
| CTO / primary | *(fill in)* | *(phone, email)* | Everything |
| CEO | Roddy | *(fill in)* | Credential escrow emergency access; customer comms |
| DevOps contractor | **NOT YET RETAINED** — P5-1 | — | Server access, restore, deploy |
| Hosting provider | *(fill in)* | *(support URL + account id)* | Console, snapshots, reboots |
| Domain / DNS (Cloudflare) | *(fill in)* | *(account email)* | DNS, maintenance page, WAF |

---

## 2 · Escalation order

1. CTO — phone, then message.
2. If unreachable for **30 minutes** and money is affected: CEO (Roddy), who
   holds emergency access to the credential escrow.
3. DevOps contractor, once retained (P5-1).
4. Payment providers, if the fault is theirs — see §5.

---

## 3 · Credentials

All secrets live in the **credential escrow** (password manager) with emergency
access granted to the CEO and one trusted technical contact.

| Item | Where |
|---|---|
| Escrow vault | *(fill in: vault name + how to trigger emergency access)* |
| Server SSH keys | Escrow |
| `.env` (production) | On the server, **and** inside every nightly backup archive |
| `BACKUP_ARCHIVE_PASSWORD` | Escrow — **without it every backup is unrecoverable** |
| Provider API keys | Escrow, one entry per provider |

**Emergency access to most password managers has a deliberate waiting period
(commonly 24–72 hours). Trigger it early — you cannot shorten it later.**

---

## 4 · What you can safely do

### Put the site into maintenance mode
```bash
cd /var/www/rshoprefills
php artisan down
# and to bring it back:
php artisan up
```

### Stop or restart background workers
```bash
sudo supervisorctl status
sudo supervisorctl stop all
sudo supervisorctl start all
```

### Check what the platform thinks is wrong
```bash
curl -fsS https://<host>/health | jq
php artisan backup:health
php artisan ledger:verify
tail -n 200 storage/logs/laravel.log
```

### Confirm a recent backup exists
```bash
php artisan backup:health
```
If this reports a recent, encrypted, off-site archive, **the company's data is
safe** even if the server is not. That is the thing to verify first and report
upward — it converts an existential emergency into an inconvenience.

### Restore the database
Only with the escrow password, and only following
[runbooks/database-restore.md](runbooks/database-restore.md). Restore into a
**scratch** database first; never over the live one while diagnosing.

---

## 5 · Provider contacts

Money can be stuck on a provider's side rather than ours. Fill in account IDs
and support routes **before** you need them.

| Provider | Handles | Support | Account ref |
|---|---|---|---|
| Flutterwave | Cards, bank transfers | *(fill in)* | *(fill in)* |
| NOWPayments | Crypto | *(fill in)* | *(fill in)* |
| Maplerad | Virtual cards, payouts | *(fill in)* | *(fill in)* |
| Zendit | Gift cards, airtime, bills | *(fill in)* | *(fill in)* |
| Airalo | eSIM | *(fill in)* | *(fill in)* |
| Resend | Email delivery | *(fill in)* | *(fill in)* |
| Cloudflare | DNS, WAF, edge | *(fill in)* | *(fill in)* |
| Hosting | Server | *(fill in)* | *(fill in)* |

---

## 6 · What to tell customers

Say what is happening, what you are doing, and when you will next update. Do not
speculate about causes, and never promise a restoration time you cannot keep.

> We're currently experiencing a problem affecting [orders / payments / eSIM
> delivery]. We've paused affected transactions while we investigate, and no
> further charges are being taken. We'll update here within [N] hours.

If customer money is affected, say so plainly and commit to making people whole.
A marketplace survives outages; it does not survive being seen to hide one.

---

## 7 · Dry run

This document is only real if it has been tested by someone who did not write
it. **The test:** a non-CTO follows this file alone, unassisted, and within 30
minutes can locate credentials, put the site into maintenance mode, and confirm
whether a valid backup exists.

| Date | Who | Result |
|---|---|---|
| *(not yet performed)* | | |

Until that table has a row in it, treat every claim in this document as
untested. Documentation that has never been exercised is a hypothesis.
