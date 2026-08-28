<?php

namespace Tests\Feature\Shop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class NavNotStickyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_header_scrolls_away_with_the_page(): void
    {
        $layout = File::get(resource_path('views/components/layouts/app/header.blade.php'));

        // `relative`, not `sticky` - matched without pinning the whole class
        // string, which has changed twice for unrelated reasons.
        // `relative`, not `sticky`. The class attribute is extracted and its
        // contents tested, rather than matching a literal class string - that
        // string has already changed twice for unrelated reasons.
        preg_match('/<header class="([^"]*)"/', $layout, $m);

        $this->assertNotEmpty($m, 'No <header> in the layout.');
        $this->assertStringContainsString('relative', $m[1]);
        $this->assertStringNotContainsString('sticky', $m[1]);
    }

    /**
     * In LIGHT the bar is deliberately NOT the page tone - it sits above the
     * page - and the category pills then sit on that white at the page tone, so
     * each reads as a filled chip rather than an outlined one.
     *
     * Dark inverts that: there the bar is the page.
     */
    public function test_the_nav_bar_is_white_and_distinct_from_the_page(): void
    {
        $layout = File::get(resource_path('views/components/layouts/app/header.blade.php'));
        $css = File::get(resource_path('css/app.css'));

        // White in light, ground in dark - a named class, NOT the page's
        // bg-[#eff6ff]/dark:bg-[#0c1a36] pair. That pair resolves by ELEMENT:
        // the page tone is granted only to a named list and anything outside it
        // falls through to the card tone, so a <header> and a <div> would come
        // out different colours from identical classes.
        $this->assertStringContainsString('<header class="nav-surface relative z-50', $layout);
        $this->assertStringContainsString('.nav-surface { background-color: #ffffff; }', $css);

        // Dark is the exception and is transparent, not white: the bar is meant
        // to BE the page there. var(--ground) is the same colour but the page
        // also carries a grain layer the bar did not, so a matching fill still
        // read as a flat strip above a textured page.
        $this->assertStringContainsString('.dark .nav-surface { background-color: transparent; }', $css);

        // And no dead selector left behind for a header that no longer wears
        // those classes.
        $this->assertStringNotContainsString('html.dark header.dark', $css);

        // The white wash existed to frost content sliding under a sticky header.
        // Nothing slides under it now, and it was what stopped the strip
        // matching the page.
        $nav = File::get(resource_path('views/components/nav/main-nav.blade.php'));
        $this->assertStringNotContainsString('bg-white/30 backdrop-blur-sm', $nav);
    }

    /**
     * The eSIM and discover floating bars used to measure `header.sticky` to
     * place themselves below the pinned nav. That class no longer exists, so a
     * surviving querySelector would silently return null and leave the bars
     * relying on a fallback that no longer means anything.
     */
    public function test_nothing_still_measures_the_sticky_header(): void
    {
        foreach ([
            'views/shop/esim.blade.php',
            'views/components/home/discover-global.blade.php',
        ] as $view) {
            $this->assertStringNotContainsString(
                "querySelector('header.sticky')",
                File::get(resource_path($view)),
                "{$view} still measures a header that is no longer sticky."
            );
        }
    }

    public function test_light_mode_separates_page_from_card(): void
    {
        // The page and the cards must not resolve to the same value, or the
        // cards stop reading as cards at all.
        $css = File::get(resource_path('css/app.css'));

        $this->assertStringContainsString(
            'html:not(.dark) .bg-\[\#eff6ff\] { background-color: #f4f6f5; }',
            $css
        );
        $this->assertStringContainsString(
            'html:not(.dark) .bg-\[\#eff6ff\].dash-shimmer { background-color: #ffffff; }',
            $css
        );
    }

    public function test_pages_still_render(): void
    {
        $this->get('/')->assertOk();
        $this->get('/esims')->assertOk();
    }
}
