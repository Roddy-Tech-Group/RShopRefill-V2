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
            // Descriptions must stay under 255 chars: site_settings.description
            // is a VARCHAR(255) on MySQL. SQLite does not enforce the limit, so
            // the test suite cannot catch an overrun - the first MySQL migrate
            // does, by failing.
            SiteSetting::put(
                self::ENFORCE_KEY,
                'off',
                'features',
                'On applies the payment cooldown to customers. Off keeps shadow mode: every decision is recorded (payments:backoff-report) but nobody is blocked. Enable once the report shows it hits abuse, not buyers.',
            );
        }

        // The old description promised "3+ consecutive failed payment attempts",
        // which is no longer what is counted: abandonment is deliberately
        // excluded, and there are now two separate counters.
        SiteSetting::query()->where('key', self::MASTER_KEY)->update([
            'description' => 'Master switch for payment rate limiting. Off disables limiting and measurement. Counts declines and no-shows separately; abandonment at the payment screen is never counted. Enforcement is gated by payment_backoff_enforce.',
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
