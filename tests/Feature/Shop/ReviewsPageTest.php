<?php

namespace Tests\Feature\Shop;

use Tests\TestCase;

/**
 * The Customer Reviews page lists its review sources Google-first: Google
 * Business, then Trustpilot, then our own system.
 */
class ReviewsPageTest extends TestCase
{
    public function test_recent_reviews_lists_sources_google_first(): void
    {
        $blade = file_get_contents(resource_path('views/shop/reviews.blade.php'));

        $google = strpos($blade, 'Reviews collected from Google Business');
        $trustpilot = strpos($blade, 'Trustpilot and our system');

        $this->assertNotFalse($google, 'Source line should lead with Google Business.');
        $this->assertNotFalse($trustpilot, 'Source line should keep Trustpilot before our system.');
        $this->assertLessThan($trustpilot, $google, 'Google Business must come before Trustpilot and our system.');
    }
}
