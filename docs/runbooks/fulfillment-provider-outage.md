# Runbook · Fulfillment provider outage (Zendit / Airalo)

**Severity when invoked for real:** SEV-2 (customers paid and are not receiving product)
**Owner:** CTO · **Related:** Admin → Management → Monitoring · [payment-provider-failure.md](payment-provider-failure.md)

> Stop selling what you cannot deliver. Refund debt compounds much faster than
> the outage itself.

---

## 1 · Detect

- Monitoring page: provider success rate red, errors climbing, "in flight" items piling up.
- Fulfillment queue backlog in Horizon; `LongWaitDetected` on `fulfillment`.
- "Stuck paid orders" tile rising (paid > 15 min, fulfilment never started).
- Support tickets: "paid but nothing delivered".

## 2 · Triage first — outage or empty float?

An "outage" is sometimes just an empty prepaid balance:

```bash
php artisan zendit:check-balance
```

If the balance is low: **top up now** — that IS the fix. Airalo has no balance
command; check the Airalo partner dashboard credit directly.

If balances are fine: check the provider status page and probe their API
(Monitoring page shows last successful activity per provider).

## 3 · Stabilize — stop selling the affected lines

Admin → System Settings, turn **off** only what the broken provider fulfils:

| Flag | Stops selling |
|---|---|
| `features.gift_cards_enabled` | Gift cards (Zendit) |
| `features.esims_enabled` | eSIMs (Zendit + Airalo) |
| `features.topups_enabled` | Mobile airtime (Zendit) |
| `features.bills_enabled` | Bill payments (Zendit) |

Keep unaffected lines selling. Post an honest note ("eSIM delivery delayed,
orders will fulfil automatically") rather than silence.

Already-paid orders stay queued — they retry automatically
(`PollPendingFulfillmentJob` every minute, rescue sweeper every 5 min). Do not
refund proactively during a short outage; delivery on recovery is the better
customer outcome.

## 4 · Recover

1. Provider recovers (or float topped up).
2. Re-enable the feature flags from §3.
3. Drain the backlog:

```bash
php artisan fulfillment:retry --all-failed
```

4. Spot-check the oldest stuck order fulfilled correctly (Admin → Orders, sort oldest).

## 5 · Validate

- Monitoring page: provider success rate green, in-flight back to ~0, stuck-paid-orders 0.
- `php artisan ledger:verify` — refunds/credits issued during the window must balance.
- One real test purchase per re-enabled product line.

## 6 · Prevent

- Zendit float alerts exist (`zendit:check-balance`, scheduled). Airalo has **no balance visibility** — add an equivalent or a manual weekly check.
- Circuit breaker covers Zendit only; wiring Airalo (P2-2 in the roadmap) stops a hung Airalo call from eating fulfillment workers.
- For top-selling gift card SKUs, evaluate a second-source provider — single-sourcing best sellers is a business risk wearing an engineering costume.
