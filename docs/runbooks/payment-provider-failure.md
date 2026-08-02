# Runbook · Payment provider failure (Flutterwave / NOWPayments)

**Severity when invoked for real:** SEV-2 (a revenue rail is down) — SEV-1 if every rail is down
**Owner:** CTO · **Related:** [OPERATIONS.md](../OPERATIONS.md) · Admin → Management → Monitoring · Admin → Management → Payment Insights

> During an incident you execute this file; you do not compose. If a step here is
> wrong or missing, fix it the same day.

---

## 1 · Detect

- Payment Insights success rate collapsing for one gateway while others stay normal.
- Checkout error spike in Sentry tagged with the gateway name.
- Webhook silence: orders stuck `pending` payment while customers claim they paid.
- Customer reports of "payment page not loading" / "paid but no order".

## 2 · Which failure is this?

| Symptom | Meaning | Response |
|---|---|---|
| Session creation fails (errors before redirect) | Provider API down | §3, disable rail |
| Customers redirect but never return paid | Provider checkout page broken | §3, disable rail |
| Payments succeed at provider, orders stay unpaid | **Webhooks lost — money arriving unrecorded** | §4 immediately, do NOT debug first |

## 3 · Stabilize — route customers to surviving rails

There is no per-gateway kill switch yet (prevention item below). Options, least drastic first:

1. **Say it honestly:** post a banner/status note that card (or crypto) payments are degraded; wallet still works.
2. **If checkout is actively harming customers** (double charges, corrupt sessions):
   Admin → System Settings → turn **off** `features.checkout_enabled` (stops all product checkout; wallet withdrawals/funding have their own flags `features.wallet_funding_enabled`).
3. Full outage of every rail: `php artisan down --render="errors::503"` — last resort.

Check the provider's status page (status.flutterwave.com / NOWPayments status) and open a ticket with them now, not after recovery.

## 4 · Webhook-silence path — reconcile, don't debug

Money is arriving that the platform can't see. Recover the orders first:

```bash
# Crypto: re-verify every recent NOWPayments payment against their API
php artisan reconcile:nowpayments --hours=48

# Sweep sessions that were paid but never confirmed
php artisan reconcile:orphaned-sessions

# Replay a specific stranded order's webhook
php artisan webhook:replay {order-uuid} --provider=flutterwave
```

The rescue sweeper (`fulfillment:rescue-orphaned-orders`, every 5 min) picks up
paid-but-unfulfilled orders automatically — Monitoring page shows the count as
"Stuck paid orders". If it keeps climbing, the webhook route or secret is broken:
check `payment_webhooks` processing failures (Admin → api/monitoring/payment-webhooks)
and recent `VerifyWebhookSignature` rejections in the logs (a rejection spike right
after a provider maintenance window usually means they rotated the signing secret).

## 5 · Recover

1. Provider confirms recovery (or their status page goes green).
2. Re-enable anything you disabled in §3; `php artisan up` if you went down.
3. Sweep the outage window (§4 commands) once more, end to end.
4. Verify recovery emails went out to genuinely-failed customers (Payment Insights → Recovery).

## 6 · Validate

```bash
php artisan payments:validate     # platform validation suite must pass
php artisan ledger:verify         # money integrity after the sweep
php artisan money:report          # float vs earnings sanity
```

Make one real small purchase on each rail. Watch Monitoring + Payment Insights for one full hour.

## 7 · Prevent

- Per-gateway feature flags (`features.card_payments_enabled`, `features.crypto_payments_enabled`) so §3 stops being all-or-nothing — **backlog item, high value**.
- Webhook-silence alert per provider (no webhook received in N business-hours).
- Every incident: record timeline in `docs/incidents/`.
