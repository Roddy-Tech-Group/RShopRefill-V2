<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Splits the rate limiter's master switch from its enforcement switch.
 *
 * `progressive_backoff` stays the master: off means no limiting and no
 * measuring. `payment_backoff_enforce` decides whether a computed limit is
 * actually applied, and it is seeded OFF on purpose. The limiter's thresholds
 * have never been checked against live traffic, and a limiter that turns
 * customers away on unvalidated thresholds loses revenue where nobody can see
 * it. It records what it would have done first; run `payments:backoff-report`
 * and turn this on once the numbers justify it.
 */
return new class extends Migration
{
    private const ENFORCE_KEY = 'features.payment_backoff_enforce_enabled';

    private const MASTER_KEY = 'features.progressive_backoff_enabled';

    public function up(): void
    {
        if (! SiteSetting::query()->where('key', self::ENFORCE_KEY)->exists()) {
            SiteSetting::put(
                self::ENFORCE_KEY,
                'off',
                'features',
                'On applies the payment cooldown to customers. Off keeps the limiter in shadow: it still measures and records every decision (see payments:backoff-report) but nobody is blocked. Leave off until the report shows the thresholds are hitting abuse rather than buyers.',
            );
        }

        // The old description promised "3+ consecutive failed payment attempts",
        // which is no longer what is counted: abandonment is deliberately
        // excluded, and there are now two separate counters.
        SiteSetting::query()->where('key', self::MASTER_KEY)->update([
            'description' => 'Master switch for payment rate limiting. Off removes it entirely, including measurement. On counts two signals separately: declines (a payment was submitted and refused) and no-shows (checkout opened, no payment method ever engaged). Abandoning at the payment screen is deliberately not counted. Whether a limit is actually applied is controlled by payment_backoff_enforce.',
        ]);

        Cache::forget('site_setting_'.self::MASTER_KEY);
        Cache::forget('site_setting_'.self::ENFORCE_KEY);
    }

    public function down(): void
    {
        SiteSetting::query()->where('key', self::ENFORCE_KEY)->delete();
        Cache::forget('site_setting_'.self::ENFORCE_KEY);
    }
};
