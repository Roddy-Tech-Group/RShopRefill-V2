<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Seeds the master enforcement switch for the KYC tier engine, OFF.
 *
 * Off means shadow mode: the engine records the tier step-ups it WOULD demand
 * but turns no customer away, so deploying this changes nothing customers
 * experience. It is switched to enforcing deliberately, once the higher tiers'
 * flows exist (phone OTP, a provider integration) and the thresholds in
 * config/kyc.php are confirmed. Same shadow -> enforce rollout as the payment
 * eligibility gate.
 */
return new class extends Migration
{
    // Must match the key FeatureFlag::on('kyc_enforce') resolves to: it
    // prefixes 'features.' and suffixes '_enabled'.
    private const FLAG_KEY = 'features.kyc_enforce_enabled';

    public function up(): void
    {
        if (! SiteSetting::query()->where('key', self::FLAG_KEY)->exists()) {
            SiteSetting::put(
                self::FLAG_KEY,
                'off',
                'features',
                'Master switch for the KYC tier engine. On enforces risk-based identity step-ups; off is shadow mode: step-ups are logged, no customer is turned away. Seeded off.',
            );
        }

        Cache::forget('site_setting:'.self::FLAG_KEY);
    }

    public function down(): void
    {
        SiteSetting::query()->where('key', self::FLAG_KEY)->delete();
        Cache::forget('site_setting:'.self::FLAG_KEY);
    }
};
