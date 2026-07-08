<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;

/**
 * Feature flags for the two coming-soon categories, seeded OFF: Sell Gift
 * Cards and GoMarket are hidden from every storefront surface (nav, homepage
 * tiles, shop rail/picker, dashboard menu, sitemap) until an admin switches
 * them on from the System Settings page. Their coming-soon pages stay
 * reachable by direct URL either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        $flags = [
            'features.sell_gift_cards_enabled' => 'Off hides the Sell Gift Cards category from the storefront nav, tiles and menus (the coming-soon page stays reachable by URL).',
            'features.gomarket_enabled' => 'Off hides the GoMarket category from the storefront nav, tiles and menus (the coming-soon page stays reachable by URL).',
        ];

        foreach ($flags as $key => $description) {
            if (! SiteSetting::query()->where('key', $key)->exists()) {
                SiteSetting::put($key, 'off', 'features', $description);
            }
        }
    }

    public function down(): void
    {
        SiteSetting::query()
            ->whereIn('key', ['features.sell_gift_cards_enabled', 'features.gomarket_enabled'])
            ->delete();
    }
};
