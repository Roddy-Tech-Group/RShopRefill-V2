# Runbook · Payment provider failure (Flutterwave / Paystack / Korapay / NOWPayments)

**Severity when invoked for real:** SEV-2 (a revenue rail is down) — SEV-1 if every rail is down
**Owner:** CTO · **Related:** [OPERATIONS.md](../OPERATIONS.md) · Admin → Management → Monitoring · Admin → Management → Payment Insights

> During an incident you execute this file; you do not compose. If a step here is
> wrong or missing, fix it the same day.

---

## 0 · The kill switches (know these before anything else)

Admin → System Settings → features group. One toggle per gateway:

| Flag | Gateway | Normal state |
|---|---|---|
| `features.gateway_flutterwave_enabled` | Flutterwave (primary fiat: cards, banks, mobile money) | **on** |
| `features.gateway_paystack_enabled` | Paystack (fiat backup: NGN bank/USSD, GHS/KES/XOF mobile money) | **off** (standby) |
| `features.gateway_korapay_enabled` | Korapay (fiat backup: NGN cards/banks, XAF/XOF/GHS/KES/EGP/TZS mobile money) | **off** (standby) |
| `features.gateway_nowpayments_enabled` | NOWPayments (crypto) | on |
| `features.gateway_wallet_enabled` | Wallet balance payments | on |

The contract every flip honours (tested in `GatewayKillSwitchTest`):

- **New money is refused** on a disabled gateway — tiles grey out, and the server
  refuses fresh charges even from already-open payment modals (fiat, crypto AND wallet).
- **In-flight money always lands** — webhooks, verification jobs and the sweeper
  are never gated, so a customer whose transfer was moving when you flipped the
  switch still gets their order.
- Every flip fires a SEV-2 alert (Telegram) recording who flipped what.
- Routing is automatic: while Flutterwave is ON it takes all fiat traffic; the
  moment it is OFF, live backups serve whatever their capability matrix covers
  (`FiatGatewayRouter::BACKUP_CAPABILITIES`).

**Coverage honesty — what failover does NOT save:** card and Apple Pay have a
backup only in NGN (until Paystack international USD approval lands in
`PAYSTACK_CHARGE_CURRENCIES`). With Flutterwave off: EUR/GBP/UGX/RWF lose the
whole fiat rail; USD/GHS/XAF/KES lose cards but keep their mobile-money/bank
corridors via backups. Crypto and wallet are unaffected by fiat outages.

## 1 · Detect

- SEV-2 alert or Payment Insights success rate collapsing for one gateway while others stay normal.
- Checkout error spike in Sentry tagged with the gateway name.
- Webhook silence: orders stuck `pending` payment while customers claim they paid.
- Customer reports of "payment page not loading" / "paid but no order".

## 2 · Which failure is this?

| Symptom | Meaning | Response |
|---|---|---|
| Session creation fails (errors before redirect) | Provider API down | §3 failover |
| Customers redirect but never return paid | Provider checkout page broken | §3 failover |
| Provider up but declining most charges | Partial degradation | §3 failover (same moves) |
| Payments succeed at provider, orders stay unpaid | **Webhooks lost — money arriving unrecorded** | §4 immediately, do NOT debug first |

## 3 · Stabilize — fail over, don't shut down

**Flutterwave incident (the common case):**

1. Turn **ON** `features.gateway_paystack_enabled` and `features.gateway_korapay_enabled` (backups first, so there is never a moment with zero fiat rails).
2. Turn **OFF** `features.gateway_flutterwave_enabled`.
3. Confirm on /checkout: NGN cards/banks and covered mobile-money corridors stay live through the backups; uncovered methods grey out with the built-in amber notice. Crypto and wallet are untouched.
4. Check status.flutterwave.com and open a ticket with them now, not after recovery.

**NOWPayments incident:** turn off `features.gateway_nowpayments_enabled`. Crypto tiles grey; fiat and wallet unaffected. Wallet funding automatically survives the reverse case too (fiat dark, crypto alive).

**Do NOT reach for these unless every rail is down or checkout is actively harming customers** (double charges, corrupt sessions):

- `features.checkout_enabled` off — stops ALL product checkout.
- `php artisan down --render="errors::503"` — absolute last resort.

Using the all-off hammer during a single-gateway incident is the expensive
mistake this runbook exists to prevent.

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
2. Reverse §3 in the safe order: turn the recovered primary **ON** first, then
   turn the backups **OFF** (they stop receiving new traffic the moment the
   primary is live again anyway — Flutterwave always wins the routing while on).
   Their in-flight payments still settle. `php artisan up` if you went down.
3. Sweep the outage window (§4 commands) once more, end to end.
4. Verify recovery emails went out to genuinely-failed customers (Payment Insights → Recovery).
5. **Confirm nothing was left off**: Admin → System Settings — all five gateway
   flags back to their normal state (table in §0). A backup left ON is harmless;
   a primary left OFF bleeds revenue silently.

## 6 · Validate

```bash
php artisan payments:validate     # platform validation suite must pass
php artisan ledger:verify         # money integrity after the sweep
php artisan money:report          # float vs earnings sanity
```

Make one real small purchase on each rail. Watch Monitoring + Payment Insights for one full hour.

## 7 · Prevent

- Webhook-silence alert per provider (no webhook received in N business-hours).
- Paystack international USD approval → add `USD` to `PAYSTACK_CHARGE_CURRENCIES`
  so the global card rail gains a backup (today it has none outside NGN).
- Gateway health checks + auto-failover (today failover is a human reading this file).
- Every incident: record timeline in `docs/incidents/`.
