<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Seeds the enforcement switch for the spending-limit gate, OFF.
 *
 * Off means shadow mode: the gate computes the rolling-window spend and logs
 * the refusals it WOULD issue, but no customer is capped. Flipped deliberately
 * once the seeded amounts (config/limits.php, admin-tunable via settings) are
 * confirmed. Same shadow -> enforce rollout as the KYC and risk-engine gates.
 */
return new class extends Migration
{
    // Must match the key FeatureFlag::on('spending_limits_enforce') resolves to.
    private const FLAG_KEY = 'features.spending_limits_enforce_enabled';

    public function up(): void
    {
        if (! SiteSetting::query()->where('key', self::FLAG_KEY)->exists()) {
            SiteSetting::put(
                self::FLAG_KEY,
                'off',
                'features',
                'Spending-limit gate. On enforces the daily/weekly/monthly transaction caps shown on the KYC page; off is shadow mode: would-be refusals are logged, no customer is capped. Seeded off.',
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
