<?php

namespace Tests\Feature\Shop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The storefront header pins to the top of the page. It scrolled away for a
 * while; it is sticky again, and the bar is a surface above the page rather
 * than the page itself, so what slides under it cannot read through.
 */
class NavStickyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_header_pins_to_the_top_of_the_page(): void
    {
        $layout = File::get(resource_path('views/components/layouts/app/header.blade.php'));

        // The class attribute is extracted and its contents tested, rather
        // than matching a literal class string that changes for unrelated
        // reasons.
        preg_match('/<header class="([^"]*)"/', $layout, $m);

        $this->assertNotEmpty($m, 'No <header> in the layout.');
        foreach (['sticky', 'top-0', 'z-50'] as $class) {
            $this->assertMatchesRegularExpression('/(^|\s)'.preg_quote($class, '/').'(\s|$)/', $m[1], $class.' is missing from the header');
        }
        $this->assertDoesNotMatchRegularExpression('/(^|\s)relative(\s|$)/', $m[1]);

        // The status-bar strip is the header's own (PwaSafeAreaTest).
        $this->assertStringContainsString('pt-[env(safe-area-inset-top)]', $m[1]);
    }

    /**
     * In LIGHT the bar is deliberately NOT the page tone - it sits above the
     * page - and the category pills then sit on that white at the page tone, so
     * each reads as a filled chip rather than an outlined one.
     *
     * Dark used to make the bar the page (transparent). A pinned bar cannot
     * be: the page slid underneath and read straight through it. It takes
     * --ash, the colour of every surface above the content, with the grain
     * those surfaces carry; phones take flat black, so the bar meets the
     * screen edge without a seam.
     */
    public function test_the_nav_bar_is_a_surface_above_the_page(): void
    {
        $layout = File::get(resource_path('views/components/layouts/app/header.blade.php'));
        $css = File::get(resource_path('css/app.css'));

        // A named class, NOT the page's bg-[#eff6ff]/dark:bg-[#0c1a36] pair.
        // That pair resolves by ELEMENT: the page tone is granted only to a
        // named list and anything outside it falls through to the card tone,
        // so a <header> and a <div> would come out different colours from
        // identical classes.
        $this->assertStringContainsString('<header class="nav-surface sticky top-0 z-50', $layout);
        $this->assertStringContainsString('.nav-surface { background-color: #ffffff; }', $css);

        preg_match('/\.dark \.nav-surface \{([^}]*)\}/', $css, $dark);
        $this->assertNotEmpty($dark, 'No dark rule for the nav surface.');
        $this->assertStringContainsString('background-color: var(--ash);', $dark[1]);
        $this->assertStringContainsString('background-image: var(--grain);', $dark[1]);
        $this->assertStringNotContainsString('transparent', $dark[1]);
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 767\.98px\) \{\s*\.dark \.nav-surface \{\s*background-color: #000000;\s*background-image: none;/',
            $css,
            'phones should take flat black'
        );

        // And no dead selector left behind for a header that no longer wears
        // those classes.
        $this->assertStringNotContainsString('html.dark header.dark', $css);

        // Nor the white wash that frosted content under the old pinned bar:
        // the bar is opaque now, so nothing needs frosting.
        $nav = File::get(resource_path('views/components/nav/main-nav.blade.php'));
        $this->assertStringNotContainsString('bg-white/30 backdrop-blur-sm', $nav);
    }
}
