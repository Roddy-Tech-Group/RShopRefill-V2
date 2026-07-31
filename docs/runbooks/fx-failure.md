# Runbook · Exchange-rate failure (stale or wrong FX)

**Severity when invoked for real:** SEV-2 — wrong FX misprices the entire catalog at once
**Owner:** CTO · **Related:** Admin → Management → Monitoring (Exchange rates tile) · [pricing-anomaly.md](pricing-anomaly.md)

> **Wrong rates are worse than stale rates.** A stale rate is yesterday's truth;
> a corrupt rate is a fiction customers can buy at. If in doubt, pin the last
> known-good rate and stop syncing until you understand what happened.

---

## 1 · Detect

- Monitoring page: "Exchange rates" tile stale (> 26h) or red.
- Sentry cron monitor `rates-fetch-daily` / `sync-exchange-rates` missed.
- A single currency's prices suddenly absurd (10× too cheap/expensive).
- Order-velocity spike on one currency corridor — customers find mispricing before you do.

## 2 · Triage

```bash
php artisan tinker --execute 'App\Models\CurrencyRate::where("is_active", true)->orderBy("updated_at")->get(["code","rate_per_usd","updated_at"])->each(fn($r) => print($r->code." ".$r->rate_per_usd." ".$r->updated_at."\n"));'
```

| Finding | Meaning | Go to |
|---|---|---|
| All rates old, values plausible | Feed/scheduler dead — **stale** | §3A |
| One or more rates absurd | Feed returned garbage — **corrupt** | §3B |

Check Horizon for `SyncExchangeRatesJob` failures (hourly job) and the upstream
API's status page.

## 3A · Stale — keep serving last-known-good

Stale-but-sane rates are survivable for hours. Do not halt sales.

```bash
php artisan rates:fetch          # force a refresh; watch for the failure reason
```

If the upstream API is down, wait it out; consider widening safety margins on
volatile corridors only if the outage passes a day.

## 3B · Corrupt — stop the bleeding first

1. Deactivate or manually correct the corrupt rate(s) (Admin → Rate Management, or tinker: set `rate_per_usd` back to the last plausible value).
2. Only then investigate the feed (format change? partial response? API returning nulls?).
3. Do NOT re-run the sync until you know why it wrote garbage.

## 4 · Recover

Once rates are correct again, audit the damage window:

```bash
php artisan pricing:audit-below-cost         # anything sold below cost?
php artisan catalog:audit-gift-card-pricing  # gift card face-vs-price sanity
```

For orders placed at customer-favorable wrong prices: honor good-faith
purchases, cancel-and-refund obvious exploitation, and log the realized loss in
the incident note.

## 5 · Validate

- Monitoring tile green (< 26h, plausible values).
- Spot-check one product price in NGN/XAF against a manual conversion.
- `php artisan pricing:audit-below-cost` clean.

## 6 · Prevent

- Sanity band on ingest: reject any single-sync move > 10% without confirmation — the highest-value guard this playbook can produce (roadmap P1-2).
- Second rate source as a cross-check.
- The pricing floor in `enforce` mode caps how much a bad rate can cost (see [pricing-anomaly.md](pricing-anomaly.md)).
