# Runbook · Fraud attack (card testing / promo abuse / mule patterns)

**Severity when invoked for real:** SEV-2 — chargebacks are scored by gateways, and a bad score can de-platform the business
**Owner:** CTO · **Related:** Admin → Management → Leaderboards (Most Failed Transactions) · Admin → Customers

> Tighten, don't close. The goal is to make the attack uneconomical while real
> customers keep buying. Gift cards are cash equivalents — they are always the
> target.

---

## 1 · Detect

- Card-testing signature: many small failed payments, few succeeding, fresh accounts.
- Leaderboards → "Most Failed Transactions" suddenly dominated by new accounts.
- Wallet funding → immediate withdrawal/spend velocity.
- Coupon/referral redemption cluster from related accounts.
- Fraud-engine flags arriving in a wave (fraud arrives in waves; the wave is the signal).

## 2 · Stabilize — raise the cost of the attack

In escalating order; stop when the wave breaks:

1. **Hold, don't ban** the flagged accounts' withdrawals and gift-card delivery (Admin → Customers → hold funds / restrict). A hold is reversible; a false-positive ban of a real customer is not.
2. Pause instant delivery for the targeted SKUs — gift cards first (`features.gift_cards_enabled` off, or per-product unavailable).
3. If registration is being farmed: `features.signup_enabled` / `features.guest_checkout_enabled` off temporarily.
4. Tighten payment throttles at Cloudflare on the payment endpoints (many-small-attempts pattern).

## 3 · Diagnose — cluster the attackers

Cluster by, in order of reliability: `device_uuid` → IP/ASN → card BIN → email pattern → referral source. Admin → Customers and the payment attempts data
(`admin/api/monitoring/payment-attempts`) carry all of these.

Name the exploited seam explicitly in the incident note:
stolen cards (card testing) · promo/coupon abuse · referral farming · account takeover · mule flow (fund → withdraw).

## 4 · Recover

1. **Refund/void confirmed-fraud orders proactively** — eating the refund beats eating a chargeback's fee AND its score penalty.
2. Ban the confirmed cluster (Admin → Customers → ban), release the false positives with an apology.
3. Cancel undelivered fraudulent gift cards with the provider where possible.
4. Tally the loss (orders, fees, product) into the incident note — fraud loss is a number, not a feeling.

## 5 · Validate

- Failed-transaction rate back to baseline for 24h.
- No held real customers left in limbo (every hold resolved to release or ban).
- `php artisan ledger:verify` after the refund batch.

## 6 · Prevent

- **Every attack signature becomes a codified rule with a test** — that is how the fraud engines earn their keep.
- Velocity rules on funding→withdrawal and small-payment bursts.
- Manual-review gate above a payout threshold.
- Keep the "Most Failed Transactions" leaderboard in the weekly review — it is the cheapest early-warning screen the platform has.
