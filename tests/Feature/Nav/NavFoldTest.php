<?php

namespace Tests\Feature\Nav;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * What folds on scroll, and how.
 *
 * Only the category strip gives its space back once the page is scrolled;
 * the phone's search row stays pinned with the primary row. The fold is a
 * grid row going from 1fr to 0fr, which animates from the strip's real
 * height. It used to be max-height from 120px on a ~50px strip, so half the
 * transition passed with nothing moving and then it snapped shut.
 */
class NavFoldTest extends TestCase
{
    private function nav(): string
    {
        return File::get(resource_path('views/components/nav/main-nav.blade.php'));
    }

    public function test_the_phone_search_row_no_longer_folds(): void
    {
        $nav = $this->nav();

        $this->assertStringContainsString('class="relative pb-3 md:hidden"', $nav);
        $this->assertStringNotContainsString("hidden ? 'max-h-0 pb-0", $nav);
    }

    public function test_the_category_strip_folds_from_its_real_height(): void
    {
        $nav = $this->nav();

        $this->assertStringContainsString("hidden ? '[grid-template-rows:0fr] opacity-0' : '[grid-template-rows:1fr] opacity-100'", $nav);
        $this->assertStringContainsString('[transition:grid-template-rows_240ms_cubic-bezier(0.4,0,0.2,1),opacity_160ms_ease-out]', $nav);
        // The old fold, gone from the strip and from the search row alike.
        $this->assertStringNotContainsString('max-h-[120px]', $nav);
    }

    /**
     * The inner box is clipped while the fold runs either way, and released
     * on a timer once open so the desktop mega-menu panel can hang below its
     * pill. A timer, not transitionend: a skipped transition fires no end
     * event and would leave the panel clipped for good.
     */
    public function test_the_strip_is_clipped_while_folding_and_released_once_open(): void
    {
        $nav = $this->nav();

        $this->assertStringContainsString('x-data="{ clip: false }"', $nav);
        $this->assertStringContainsString('x-effect="if (hidden) { clip = true } else { setTimeout(() => { if (! hidden) clip = false }, 300) }"', $nav);
        $this->assertStringContainsString(":class=\"clip ? 'overflow-hidden' : 'overflow-visible'\" class=\"min-h-0\"", $nav);
    }

    /** On checkout the strip hides below sm; from sm it must come back as a GRID or the fold is inert. */
    public function test_checkout_keeps_the_strip_a_grid(): void
    {
        $nav = $this->nav();

        $this->assertStringContainsString("? 'hidden sm:grid' : 'grid'", $nav);
        $this->assertStringNotContainsString("'hidden sm:block'", $nav);
    }
}
