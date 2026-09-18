<?php

/**
 * KYC tier engine configuration.
 *
 * The active identity provider and the risk thresholds that step a user up the
 * tier ladder. Enforcement is a feature flag (features.kyc_enforce_enabled),
 * seeded off, so the engine ships in shadow mode: it scores tiers and logs what
 * it WOULD step up without turning anyone away, exactly like the payment
 * eligibility gate, and is switched to enforcing deliberately.
 *
 * The Tier 2 amount is admin-tunable at runtime (the
 * `features.kyc_tier2_threshold_usd` row on the admin Settings page, under
 * Verification & Limits); the values here are only the fallback defaults.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Active / default provider
    |--------------------------------------------------------------------------
    | The fallback KycProvider when no geo-routing applies. 'manual' is the
    | built-in admin document review. Geo-routing (Smile ID for Africa, Sumsub
    | elsewhere) is resolved per-user in KycProviderFactory.
    */
    'provider' => env('KYC_PROVIDER', 'smile'),

    /*
    |--------------------------------------------------------------------------
    | Tier 2 — identity verification (paid provider)
    |--------------------------------------------------------------------------
    | Two independent dials, the way a bank sets a per-transaction limit and a
    | cumulative one. Either crossing demands identity verification. Both are
    | admin-tunable on the Settings page (Verification & Limits); the values
    | here are only the fallback defaults. Driven by RISK EXPOSURE, not profit,
    | but set high enough that realised margin has typically covered a paid
    | check by the time one fires.
    |
    | Single transaction: one order or one wallet funding at or above this USD
    | amount. Site setting `features.kyc_tier2_threshold_usd` (the key predates
    | the split; the risk engine's high-value signal reads the same number).
    */
    'tier2_threshold_usd' => (float) env('KYC_TIER2_THRESHOLD_USD', 500),

    /*
    | Cumulative: the customer's MONEY IN over the rolling window, plus the
    | action in hand, at or above this USD amount. Money in is every completed
    | wallet funding and every paid order settled by card, mobile money or
    | crypto; an order paid from wallet balance is not counted (that money was
    | counted when it was funded) and does not add itself. Site setting
    | `features.kyc_cumulative_threshold_usd`.
    */
    'cumulative_threshold_usd' => (float) env('KYC_CUMULATIVE_THRESHOLD_USD', 2000),

    // How far back money in is summed. Thirty days, the same window as the
    // standard monthly spending cap (config/limits.php), so the amount an
    // unverified customer is shown as their monthly limit is the amount at
    // which they are asked to verify. Keep the two in step.
    'cumulative_window_days' => (int) env('KYC_CUMULATIVE_WINDOW_DAYS', 30),

    // Optional velocity trigger: this many orders within 24h forces Tier 2.
    // 0 = disabled. Left off: Engine 4 (spending limits) owns velocity.
    'tier2_orders_in_24h' => (int) env('KYC_TIER2_ORDERS_24H', 0),

    /*
    |--------------------------------------------------------------------------
    | Tier 1 — phone verification (SMS OTP)
    |--------------------------------------------------------------------------
    | The USD value at or above which the phone tier is required. 0 = required on
    | the first money action of any value.
    */
    'tier1_trigger_usd' => (float) env('KYC_TIER1_TRIGGER_USD', 0),

];
