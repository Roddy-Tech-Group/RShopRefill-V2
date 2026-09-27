<?php

namespace Tests\Feature\Home;

use Illuminate\Support\Facades\Blade;
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

    public function test_see_more_matches_the_arrow_height_and_radius(): void
    {
        $blade = file_get_contents(resource_path('views/components/home/customer-reviews.blade.php'));

        // The pill and arrows moved into x-ui.row-controls, which the gift-card
        // and eSIM row headers share. This used to assert the class string and
        // the h-8 / rounded-full counts inline here; counting them in this file
        // is what stopped being meaningful once the markup moved, so it asserts
        // delegation and then checks the treatment where it now lives.
        $this->assertStringContainsString('<x-ui.row-controls', $blade);
        $this->assertStringNotContainsString('h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white', $blade);

        $rendered = Blade::render('<x-ui.row-controls href="/reviews" prev="prev()" next="next()" :arrows-on-mobile="true" />');

        $this->assertStringContainsString('class="flex h-8 shrink-0 items-center rounded-full bg-white px-4', $rendered);
        $this->assertSame(3, substr_count($rendered, 'h-8'));
        $this->assertSame(3, substr_count($rendered, 'rounded-full'));
    }
}
