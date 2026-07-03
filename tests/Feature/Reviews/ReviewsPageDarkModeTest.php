<?php

namespace Tests\Feature\Reviews;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reviews page used `dark:bg-[#0c1a36]!` (Tailwind important). That compiles
 * to a different class than the Extra Dark override targets, so the page stayed
 * navy in Extra Dark while the rest of the site went pure black. The important
 * variant must not return - sections use the plain `dark:bg-[#0c1a36]` (which the
 * pure-dark rule remaps to black) and inner cards elevate via the base remap.
 */
class ReviewsPageDarkModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_reviews_page_does_not_use_the_important_navy_variant(): void
    {
        $this->get('/reviews')
            ->assertOk()
            // The buggy important variant that escaped the Extra Dark remap is gone.
            ->assertDontSee('dark:bg-[#0c1a36]!', false)
            // Sections still carry the plain dark navy class (remapped to black in Extra Dark).
            ->assertSee('dark:bg-[#0c1a36]', false);
    }
}
