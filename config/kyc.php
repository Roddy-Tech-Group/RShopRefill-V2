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
    | Triggered when a single money action reaches this USD amount, OR when a
    | customer's cumulative lifetime volume crosses it. This is the admin-tunable
    | "amount that works for us"; the CEO/CTO edits `features.kyc_tier2_threshold_usd`
    | on the admin Settings page and this is only the default. Driven by RISK EXPOSURE,
    | not profit, but set high enough that realised margin has typically covered
    | a paid check by the time one fires.
    */
    'tier2_threshold_usd' => (float) env('KYC_TIER2_THRESHOLD_USD', 500),

    // Optional velocity trigger: this many orders within 24h forces Tier 2.
    // 0 = disabled.
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
