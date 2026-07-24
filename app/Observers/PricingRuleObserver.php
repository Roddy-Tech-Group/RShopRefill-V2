<?php

namespace App\Observers;

use App\Models\PricingRule;
use App\Support\PricingCache;
use Illuminate\Support\Facades\Cache;

class PricingRuleObserver
{
    /**
     * Bust the cached ruleset whenever a rule is created or updated, so an
     * admin markup change takes effect on the next request instead of being
     * stale for the lifetime of the forever-cache entry.
     */
    public function saved(PricingRule $pricingRule): void
    {
        $this->flush();
    }

    public function deleted(PricingRule $pricingRule): void
    {
        $this->flush();
    }

    private function flush(): void
    {
        Cache::forget('pricing_rules.active');

        // Downstream caches (Google feed, eSIM catalog summary) embed prices
        // computed FROM the rules, so a rule change must invalidate them too.
        PricingCache::bustComputedPriceCaches();
    }
}
