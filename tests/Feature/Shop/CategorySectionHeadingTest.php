<?php

namespace Tests\Feature\Shop;

use Tests\TestCase;

/**
 * The category-section heading on the gift-card listing.
 *
 * At the desktop size on a phone, "Check out the most popular Brands !"
 * ellipsized against the pager arrows: the row read "Check out the most
 * pop...". Phones get smaller type that is free to wrap; sm+ keeps the
 * original single-line treatment.
 */
class CategorySectionHeadingTest extends TestCase
{
    public function test_the_heading_shrinks_and_wraps_on_phones(): void
    {
        $section = file_get_contents(resource_path('views/components/shop/category-section.blade.php'));

        $this->assertStringContainsString('text-[19px] font-bold leading-[24px]', $section);
        $this->assertStringContainsString('sm:truncate sm:text-[25.6px] sm:leading-[31.5px]', $section);

        // No unconditional truncate: below sm the full title must show.
        $this->assertStringNotContainsString('truncate text-[25.6px]', $section);
    }
}
