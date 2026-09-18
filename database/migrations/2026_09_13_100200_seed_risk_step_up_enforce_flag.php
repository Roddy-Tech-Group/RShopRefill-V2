<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Splits the risk engine's step-up from its review/deny enforcement.
 *
 * A step-up hands the customer to the identity provider and bypasses the KYC
 * enforcement flag, so switching risk enforcement on used to put the provider
 * live through the back door. Review and deny come only from behaviour
 * signals and touch nobody who is merely new or unverified, so they can be
 * enforced first. This flag gates the step-up alone and is seeded OFF: with
 * risk_engine_enforce on and this off, step-ups are logged and let through.
 */
return new class extends Migration
{
    private const KEY = 'features.risk_step_up_enforce_enabled';

    public function up(): void
    {
        if (! SiteSetting::query()->where('key', self::KEY)->exists()) {
            SiteSetting::put(
                self::KEY,
                'off',
                'features',
                'On lets an enforced risk step-up send the customer to identity verification. Off logs the step-up and lets them through, while review and deny still apply. Flip only once the KYC provider is proven live.',
            );
        }

        Cache::forget('site_setting:'.self::KEY);
        Cache::forget('site_setting_'.self::KEY);
    }

    public function down(): void
    {
        SiteSetting::query()->where('key', self::KEY)->delete();
        Cache::forget('site_setting:'.self::KEY);
        Cache::forget('site_setting_'.self::KEY);
    }
};
