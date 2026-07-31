# Runbook · Pricing anomaly (selling below cost / absurd price live)

**Severity when invoked for real:** SEV-2 — every order at a wrong price is realized loss; minutes matter
**Owner:** CTO · **Related:** [fx-failure.md](fx-failure.md) · Admin → Management → Finances · Admin → Pricing Rules

> Customers find mispricing before you do. A sudden order spike on one variant
> is a pricing alarm, not a marketing win, until proven otherwise.

---

## 1 · Detect

- `pricing:audit-below-cost` (daily 06:00) alerting.
- Finances page: a provider or category showing negative/zero margin.
- Order-velocity spike on a single variant.
- A sync writing placeholder prices (the historical Zendit $1 eSIM bug — `esims:reprice-zendit` exists because of it).

## 2 · Stabilize — take the mispriced thing off the shelf NOW

Speed order, fastest first:

1. **Single variant/product:** Admin → Products → set the variant unavailable / product inactive.
2. **Whole category:** Admin → System Settings → turn off the category flag (`features.gift_cards_enabled` / `features.esims_enabled` / `features.topups_enabled` / `features.bills_enabled`).

Do this before diagnosing. Every minute at a wrong price is money out.

## 3 · Diagnose — which of the four causes?

```bash
php artisan pricing:audit-below-cost         # current exposure, item by item
php artisan catalog:audit-gift-card-pricing --all
```

| Cause | Signature | Fix |
|---|---|---|
| Bad cost data from sync | Cost absurdly low/zero on the variant | Re-sync; `php artisan esims:reprice-zendit --dry-run` first |
| FX event | Whole currency corridor wrong | [fx-failure.md](fx-failure.md) |
| Sync wrote placeholder prices | Prices like 1.00 across a batch | Re-run the relevant sync; audit the batch |
| Manual override slipped | One variant, override table has a row | Remove/fix the override (Admin → Products price override) |

## 4 · Recover

1. Fix the source data; reprice.
2. Verify: `php artisan pricing:audit-below-cost` clean.
3. Re-enable the variant/category from §2.
4. Decide the honor/cancel line for orders placed at the wrong price: honor good-faith purchases, cancel-and-refund clear exploitation (many units, fresh account, resale patterns). Log the realized loss.

## 5 · Validate

- Audit commands clean.
- Finances page margin for the affected category back to normal.
- One manual price spot-check against provider cost.

## 6 · Prevent

- **Finish the pricing-floor rollout to `enforce`** (`PRICING_FLOOR_MODE`, roadmap P2-1) — this playbook is the argument for it: an enforced floor makes below-cost sales structurally impossible.
- Ingest validation on sync-written prices (a $1 eSIM should never be writable).
- Per-variant order-velocity alert — the customer-behavior tripwire.
