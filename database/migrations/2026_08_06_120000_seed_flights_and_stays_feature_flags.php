<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;

/**
 * Feature flags for the two remaining coming-soon categories, Flights and
 * Stays. They were the only storefront categories with no gate at all -
 * FeatureFlag::categoryEnabled() fell through to "on" for them, so there was
 * no way to hide their homepage cards or their admin sidebar links.
 *
 * Seeded ON, unlike sell_gift_cards / gomarket which shipped OFF: those flags
 * were introduced alongside the surfaces they hide, whereas the Flights and
 * Stays cards are already live on the homepage. Seeding OFF would have pulled
 * them from production the moment this migration ran, which is a content
 * decision for an admin to make from System Settings, not a side effect of a
 * schema change.
 */
return new class extends Migration
{
    private const FLAGS = [
        'features.flights_enabled' => 'Off hides the Flights card from the homepage and the Flights link from the admin products menu (the category is not built yet).',
        'features.stays_enabled' => 'Off hides the Stays card from the homepage and the Stays link from the admin products menu (the category is not built yet).',
    ];

    public function up(): void
    {
        foreach (self::FLAGS as $key => $description) {
            if (! SiteSetting::query()->where('key', $key)->exists()) {
                SiteSetting::put($key, 'on', 'features', $description);
            }
        }
    }

    public function down(): void
    {
        SiteSetting::query()->whereIn('key', array_keys(self::FLAGS))->delete();
    }
};
