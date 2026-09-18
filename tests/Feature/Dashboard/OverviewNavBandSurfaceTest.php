<?php

namespace Tests\Feature\Dashboard;

use Tests\TestCase;

/**
 * The mobile overview's top nav band is a plain surface, not glass.
 *
 * It used to be a frosted pane: a white gradient over a 24px saturated blur.
 * Against a fixed band that the whole page scrolls under, that read as a
 * smeared strip across the top of the screen - cards passing beneath stayed
 * half-legible through the blur, and the white gradient sat a shade off the
 * page it covered. The band now wears the page's own surface, so it is
 * invisible at rest and simply hides what scrolls under it.
 *
 * "The page's own surface" is a moving value, which is why the light assertion
 * below reads the literal from body rather than being a fixed colour in prose.
 * The dashboard page tone was #eff6ff and is now #f4f6f5 - the light redesign
 * put white cards on a warm grey ground - and the band followed it.
 *
 * Source-reading only: no route is hit here, so the class carries no
 * RefreshDatabase. Rendered coverage lives in MobileNavChromeVisibilityTest.
 */
class OverviewNavBandSurfaceTest extends TestCase
{
    private function css(): string
    {
        return file_get_contents(resource_path('css/app.css'));
    }

    /** Both rules of the band, light then dark, as written in app.css. */
    private function bandRules(): string
    {
        preg_match_all('/(?:^|\n)(?:html\.dark )?\.nav-band-top\s*\{[^}]*\}/', $this->css(), $m);

        $this->assertCount(2, $m[0], 'Expected a light and a dark rule for .nav-band-top.');

        return implode("\n", $m[0]);
    }

    /**
     * The light page tone as the dashboard layout itself declares it, read off
     * <body> rather than written down here. Hardcoding the hex is what made this
     * test go stale the first time the palette moved: the band had correctly
     * followed the page to #f4f6f5 and the only thing left asserting #eff6ff was
     * this file. Reading the value means the pair can never drift apart, and a
     * band that stops matching the page still fails.
     */
    private function pageTone(): string
    {
        $layout = file_get_contents(resource_path('views/components/layouts/dashboard.blade.php'));

        preg_match('/<body class="dashboard[^"]*?\bbg-\[(#[0-9a-fA-F]{6})\]/', $layout, $m);

        $this->assertNotEmpty($m, 'Could not read the dashboard page tone off <body>.');

        return $m[1];
    }

    public function test_the_band_paints_the_page_surface_in_both_themes(): void
    {
        $rules = $this->bandRules();

        // Light: the page tint itself, flat, and the same literal the dashboard
        // body paints rather than one this test remembers.
        $this->assertStringContainsString('background: '.$this->pageTone().';', $rules);

        // Dark: the same ground + grain the page body carries, so the band
        // does not read as a flat black strip over a textured page.
        $this->assertStringContainsString('background-color: var(--ground);', $rules);
        $this->assertStringContainsString('background-image: var(--grain);', $rules);
    }

    /** The point of the change: no blur, and nothing showing through. */
    public function test_the_band_is_not_glass(): void
    {
        $rules = $this->bandRules();

        $this->assertStringNotContainsString('backdrop-filter', $rules);
        $this->assertStringNotContainsString('rgba(', $rules);
        $this->assertStringNotContainsString('linear-gradient', $rules);
    }

    /**
     * The old name is retired everywhere. A stale `nav-glass-top` in the markup
     * would leave the band with no background at all, which is worse than the
     * frosting it replaced.
     */
    public function test_the_frosted_class_is_gone_and_the_band_wears_the_new_one(): void
    {
        $layout = file_get_contents(resource_path('views/components/layouts/dashboard.blade.php'));

        $this->assertStringNotContainsString('nav-glass-top', $this->css());
        $this->assertStringNotContainsString('nav-glass-top', $layout);
        $this->assertStringContainsString('nav-band-top', $layout);

        // The floating bottom bar keeps its own glass - only the top band changed.
        $this->assertStringContainsString('.nav-glass {', $this->css());
    }
}
