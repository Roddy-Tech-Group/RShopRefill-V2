<?php

namespace Tests\Feature\Home;

use Tests\TestCase;

/**
 * The storefront "What our customers say" carousel: no skeleton overlay, and the
 * first card starts at the same content-column left as the header (a static CSS
 * pl-[...] instead of the JS measurement that raced the reveal animation).
 */
class CustomerReviewsCarouselTest extends TestCase
{
    public function test_no_skeleton_and_first_card_aligns_to_header(): void
    {
        $blade = file_get_contents(resource_path('views/components/home/customer-reviews.blade.php'));

        // Skeleton overlay + its navigate trigger are gone.
        $this->assertStringNotContainsString('x-show="navigating"', $blade);
        $this->assertStringNotContainsString('skeleton-stagger', $blade);

        // First card aligns to the header content column via CSS padding.
        $this->assertStringContainsString('pl-[max(1rem,calc((100vw-1550px)/2+1rem))]', $blade);
    }
}
