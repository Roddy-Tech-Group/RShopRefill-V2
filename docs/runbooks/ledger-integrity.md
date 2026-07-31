# Runbook · Ledger integrity failure (verify failure or reconciliation break)

**Severity when invoked for real:** SEV-1 for a `ledger:verify` failure; SEV-2 for a wallet reconciliation break
**Owner:** CTO · **Related:** Admin → Management → Monitoring (Money integrity tiles) · [database-restore.md](database-restore.md)

> **Never auto-fix a ledger discrepancy.** A break is evidence. Correcting it
> before you understand it destroys the evidence, and the thing it hides may
> be a double-credit bug or theft. Freeze, explain, then correct through a
> written adjustment; in that order, every time.

---

## 1 · Detect

- Telegram SEV-1 "Ledger integrity violation": the nightly `ledger:verify` (02:30) found a failing check.
- Telegram SEV-2 "Wallet reconciliation found anomalies": the nightly `reconcile:wallet-balances` (02:00) found wallets whose balance disagrees with their transaction history.
- Monitoring page: "Ledger integrity" or "Wallet reconciliation" tile red (the ledger tile recomputes live every 5 minutes, so it can catch a break before the nightly run does).

## 2 · Freeze

First question: **is the drift live or historical?** Run the verifier twice, ten minutes apart:

```bash
php artisan ledger:verify --json
```

- **Violation counts growing between runs:** money is being written wrongly *right now*. Enter maintenance mode per [OPERATIONS.md](../OPERATIONS.md) to stop wallet mutations while you work. Minutes matter.
- **Counts static:** the bad write already happened and is not repeating. No freeze needed; investigate calmly. Do not "quickly fix" anything yet.

## 3 · Triage

`ledger:verify --json` names the failing check and the offending journal or wallet ids. What each check means:

| Failing check | Meaning | Likely cause |
|---|---|---|
| Unbalanced journal | Debits ≠ credits inside one journal | Writer bug in a new code path calling `LedgerService` |
| Empty journal | A journal with zero entries | A transaction recorded as happening that never happened; crashed mid-write |
| Non-positive amount | An entry with amount ≤ 0 | Corrupted write; sign handling bug |
| Invalid direction | Entry direction is neither debit nor credit | Corrupted write |
| Currency mismatch | One journal mixing currencies | 100 NGN of debit does not offset 100 USD of credit even when integers cancel |
| Orphan entries | Entries whose journal is missing | Partial write; a transaction that died between inserts |
| Projection drift | `wallet_balances` disagrees with the ledger's sum | **The important one.** Customers spend against the projection, so someone is spending against a number nobody can vouch for |

For a reconciliation break, read the stored report rather than re-deriving it:

```bash
php artisan tinker --execute 'print_r(App\Domain\Reconciliation\Models\ReconciliationReport::where("type","wallet_balance")->latest("id")->first()->anomalies_found);'
```

## 4 · Diagnose

1. Take the affected wallet or journal ids from the output above.
2. Pull the full history for the window: `wallet_transactions`, ledger entries, and the orders or fundings they claim to represent. The three must tell one story; where they diverge is the defect.
3. Check what changed: recent deploys (`git log --oneline -15`), failed jobs in Horizon for the same window, and any manual database access.
4. Common causes at this platform's age: a new code path writing to `LedgerService` incorrectly; a retried job crediting twice (idempotency gap); a manual DB edit; a restore mixing two time windows.

**Known boundary, not a break:** the ledger only began recording on **2026-07-26** (the P0-7 repair; it had never executed before then). Wallet history before that date lives in `wallet_transactions` only, so any comparison spanning that date will "disagree" by construction. That is expected and documented, not an incident.

## 5 · Recover

1. Fix the root cause in code, through git, with a test that reproduces the bad write. Never SSH-edit, including at 2 a.m.
2. Correct the money via a compensating `LedgerService` transaction with the reason recorded in its metadata. **Never** a raw SQL `UPDATE` on ledger or balance tables; the correction must itself be a ledger entry.
3. Re-verify, both suites:

```bash
php artisan ledger:verify
php artisan reconcile:wallet-balances
```

4. Only when both are clean do wallet operations resume (lift maintenance mode if you froze).

## 6 · Validate

- `ledger:verify` exits 0 with all checks green, twice in a row.
- The next scheduled reconciliation run (02:00) records a clean report.
- Monitoring page Money integrity tiles green; watch the next two nightly cycles before calling it closed.

## 7 · Prevent

- Every incident here produces two artifacts: a test that reproduces the bad write, and, if the verifier missed the class of error, a new check in `LedgerIntegrityService`.
- Idempotency keys on every wallet credit and fulfillment dispatch (roadmap P2-3) close the double-credit class permanently.
- The write-up goes in the incident log with the correcting journal's id, so the adjustment is auditable forever.
