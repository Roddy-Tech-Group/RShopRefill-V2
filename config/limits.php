<?php

/*
|--------------------------------------------------------------------------
| Transaction limits (USD, rolling windows)
|--------------------------------------------------------------------------
|
| The per-verification-level caps: rolling 24h / 7d / 30d sums of MONEY IN
| (settled USD). Money in is every completed wallet funding plus every paid
| order settled by card, mobile money or crypto. An order paid from wallet
| balance is not counted, because that money was counted when it was funded,
| and is never refused, because it brings nothing new in. It is the same meter
| the cumulative KYC dial reads (KycTierService::moneyInUsd), so the two
| engines cannot measure one customer two ways.
|
| These are the DEFAULTS. Each value is admin-tunable on the Settings page
| (Verification & Limits) as `features.spend_limit_{tier}_{window}_usd`; the
| KYC page renders from the same resolution, so what the customer is shown and
| what the gate enforces can never disagree.
|
| There is no standard monthly value here on purpose. The standard
| (unverified) monthly cap IS the cumulative KYC dial
| (`features.kyc_cumulative_threshold_usd`, config kyc.cumulative_threshold_usd,
| $2,000 over kyc.cumulative_window_days): one number doing both jobs, so the
| ceiling an unverified customer is shown is the amount at which they are asked
| to verify. The standard daily cap is normally the single-transaction KYC
| dial ($500).
|
| Enforcement is behind features.spending_limits_enforce_enabled (seeded off):
| in shadow the gate logs the would-be refusal and lets the action through.
|
| A limit of 0 means "no cap on this window".
*/

return [

    'standard' => [
        'day' => 500,
        'week' => 1000,
    ],

    'verified' => [
        'day' => 5000,
        'week' => 10000,
        'month' => 15000,
    ],
];
