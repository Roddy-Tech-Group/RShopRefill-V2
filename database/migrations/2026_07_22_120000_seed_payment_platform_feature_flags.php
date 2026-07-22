<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Seed the on/off flags for the Payment Intelligence platform so admins can
 * toggle each subsystem independently from the settings screen. All three
 * ship enabled; FeatureFlag::on() also defaults to true when a row is
 * missing, so environments that skip this migration behave identically.
 */
return new class extends Migration
{
    private const FLAGS = [
        [
            'key' => 'features.payment_insights_enabled',
            'description' => 'Off stops payment outcome recording, transaction recovery emails, and the insights dashboard data feed. Payments themselves are unaffected.',
        ],
        [
            'key' => 'features.frontend_payment_tracking_enabled',
            'description' => 'Off makes the browser telemetry endpoint a no-op, so frontend payment failures (closed popup, ad blocker, JS errors) are no longer recorded.',
        ],
        [
            'key' => 'features.progressive_backoff_enabled',
            'description' => 'Off removes the escalating cooldown (30s/1m/5m/15m) applied after 3+ consecutive failed payment attempts.',
        ],
    ];

    public function up(): void
    {
        foreach (self::FLAGS as $flag) {
            if (! SiteSetting::query()->where('key', $flag['key'])->exists()) {
                SiteSetting::put($flag['key'], 'on', 'features', $flag['description']);
            }
        }
    }

    public function down(): void
    {
        foreach (self::FLAGS as $flag) {
            SiteSetting::query()->where('key', $flag['key'])->delete();
            Cache::forget('site_setting_'.$flag['key']);
        }
    }
};
