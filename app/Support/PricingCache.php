<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class PricingCache
{
    /**
     * Bust every cache entry that embeds a COMPUTED retail price. The rule set
     * itself (`pricing_rules.active`) is busted by PricingRuleObserver; these
     * are the downstream surfaces that bake the resulting price into cached
     * output and would otherwise serve stale figures for up to 6h after a
     * markup rule, manual override, or floor-mode change — the Google feed
     * docs warn a feed/landing-page price mismatch risks Merchant Center
     * disapproval.
     */
    public static function bustComputedPriceCaches(): void
    {
        Cache::forget('google-merchant-feed-esims');
        Cache::forget('esim-catalog-summary-v7-z0');
        Cache::forget('esim-catalog-summary-v7-z1');
    }
}
