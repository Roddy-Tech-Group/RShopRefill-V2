<?php

/*
|--------------------------------------------------------------------------
| Spending limits (USD, rolling windows)
|--------------------------------------------------------------------------
|
| The per-verification-level transaction caps enforced at order placement:
| rolling 24h / 7d / 30d sums of PAID order value (settlement USD). Purchases
| only — wallet funding is deliberately not counted, because funded balance can
| only leave through checkout, where these caps catch it; counting both would
| double-charge the same dollar against the limit.
|
| These are the DEFAULTS. Each value is admin-tunable at runtime through the
| settings table (key names below); the KYC page renders from the same
| resolution, so what the customer is shown and what the gate enforces can
| never disagree.
|
| Enforcement is behind features.spending_limits_enforce_enabled (seeded off):
| in shadow the gate logs the would-be refusal and lets the order through.
|
| Setting keys:
|   spend_limit_standard_day_usd / _week_usd / _month_usd
|   spend_limit_verified_day_usd / _week_usd / _month_usd
|
| A limit of 0 means "no cap on this window".
*/

return [

    'standard' => [
        'day' => 2000,
        'week' => 5000,
        'month' => 10000,
    ],

    'verified' => [
        'day' => 5000,
        'week' => 10000,
        'month' => 15000,
    ],
];
