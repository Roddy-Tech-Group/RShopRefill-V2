<?php

namespace Tests\Feature\Dashboard;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The phone dashboard's top bar is the desktop header itself.
 *
 * This file used to pin the overview's identity band (.nav-band-top), a
 * page-tone strip the overview alone carried. The 2026-09-23 redesign
 * replaced it, the floating pill row and the glass bottom tab bar with the
 * desktop header fixed to the top below lg - same pills, same dropdowns - and
 * the page sits under it as a rounded panel on the header's colour, the way
 * the desktop frame lifts its pane off the header. The old classes are
 * retired from the markup and the stylesheet.
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

    private function layout(): string
    {
        return file_get_contents(resource_path('views/components/layouts/dashboard.blade.php'));
    }

    /** The phone rules, as written in app.css. */
    private function phoneRules(): string
    {
        preg_match('/@media \(max-width: 1023\.98px\) \{.*?\n\}\n/s', $this->css(), $m);

        $this->assertNotEmpty($m, 'Could not find the phone media block in app.css.');

        return $m[0];
    }

    public function test_the_header_is_fixed_to_the_top_on_phones_only(): void
    {
        $rules = $this->phoneRules();

        $this->assertMatchesRegularExpression('/body\.dashboard > \[data-flux-header\] \{[^}]*position: fixed;/', $rules);
        // Visible on every width: the old `hidden ... lg:flex` gate is gone.
        preg_match('/<flux:header class="([^"]*)"/', $this->layout(), $h);
        $this->assertNotEmpty($h);
        $this->assertStringNotContainsString('hidden', $h[1]);
        $this->assertStringContainsString('flex', $h[1]);
    }

    public function test_the_header_popups_drop_as_full_width_sheets_on_phones(): void
    {
        $rules = $this->phoneRules();

        $this->assertMatchesRegularExpression('/\.dashboard-frame-popup,\s*body\.dashboard \.dashboard-refer-popup \{[^}]*position: fixed;[^}]*left: 0\.75rem;[^}]*right: 0\.75rem;/', $rules);
    }

    public function test_the_page_sits_on_the_header_colour_as_a_rounded_panel(): void
    {
        $rules = $this->phoneRules();

        // The curve under the bar is DRAWN, not cut.
        //
        // Cutting it out of the panel made two holes: a rounded corner shows
        // whatever its parent paints there, and a probe colour on the pane
        // never appeared anywhere on the page, so the panel overflows its
        // parent and the corners fell through to the page canvas. Putting it
        // on the balance band instead made it scroll away with the band.
        //
        // So the panel is square and two corner wedges are painted over the
        // top, fixed. Only the corners are painted, the middle is transparent,
        // which is why it can sit above the page without covering the cards.
        $this->assertMatchesRegularExpression('/\.dash-mobile-panel \{[^}]*border-top-left-radius: 0;/s', $rules);
        $this->assertMatchesRegularExpression('/\.dash-curve-fill \{[^}]*position: fixed;[^}]*height: 32px;/s', $rules);
        $this->assertMatchesRegularExpression('/\.dash-curve-fill \{[^}]*radial-gradient\(circle 32px at 32px 32px, transparent 0 31\.4px, #0c1337 32\.6px\)/s', $rules);
        $this->assertMatchesRegularExpression('/html\.dark \.dash-curve-fill \{[^}]*#000000 32\.6px\)/s', $rules);
        $this->assertStringContainsString('<div class="dash-mobile-panel lg:contents" x-data', $this->layout());
        // The bar mirrors the desktop header: navy in light, pure black in
        // dark, and the same colour runs behind the page so the panel's curve
        // sits on it.
        $this->assertMatchesRegularExpression('/body\.dashboard > \[data-flux-header\] \{[^}]*background-color: #0c1337 !important;/', $rules);
        $this->assertMatchesRegularExpression('/body\.dashboard,\s*body\.dashboard > \[data-flux-main\] \{[^}]*background-color: #0c1337 !important;/', $rules);
        $this->assertMatchesRegularExpression('/html\.dark body\.dashboard > \[data-flux-header\] \{[^}]*background-color: #000000 !important;/', $rules);
    }

    /**
     * The old chrome is retired everywhere. A stale class in the markup would
     * leave an element with no background at all, which is worse than the
     * chrome it replaced.
     */
    public function test_the_old_band_and_bottom_bar_are_gone(): void
    {
        foreach (['nav-band-top', 'nav-glass-top', 'nav-glass', 'liquid-pill', 'pill-shake', 'dash-mobile-bar'] as $old) {
            $this->assertStringNotContainsString($old, $this->css(), "{$old} should be gone from app.css");
            $this->assertStringNotContainsString($old, $this->layout(), "{$old} should be gone from the layout");
        }
    }

    /**
     * The wallet balance area is a coloured band in BOTH themes.
     *
     * Dark keeps the navy the sidebar marks wear. Light used to be transparent,
     * so the balance sat on the plain panel and the band did not read as a band
     * at all; it carries a blue wash now. It runs deeper than its text needs
     * and curves at the bottom, so the cards below rise into it rather than
     * meeting a flat edge.
     */
    public function test_the_balance_area_is_a_coloured_band_in_both_themes(): void
    {
        // The band carries only its BOTTOM curve. The curve under the bar is
        // drawn over the top by .dash-curve-fill, because it has to stay put
        // while the page scrolls and anything on the band scrolls away with it.
        $this->assertStringContainsString(
            '<header class="dash-hero-wash relative z-10 rounded-b-[28px] px-5 pt-5 pb-14 lg:hidden">',
            $this->layout(),
        );

        $css = File::get(resource_path('css/app.css'));

        // Layered blooms, not one straight ramp: a single linear gradient reads
        // as a printed ribbon, and the point is for the colour to pool.
        $this->assertMatchesRegularExpression('/\.dash-hero-wash \{[^}]*radial-gradient\([^)]*at 88% 6%/s', $css);
        $this->assertMatchesRegularExpression('/\.dash-hero-wash \{[^}]*radial-gradient\([^)]*at 97% 96%/s', $css);
        $this->assertMatchesRegularExpression('/\.dash-hero-wash \{[^}]*radial-gradient\([^)]*at 0% 52%/s', $css);
    }

    /**
     * bg-none is what lets dark keep its navy. The gradient is a background
     * IMAGE, so a background-colour alone would sit under it rather than
     * replace it, and dark would wear the light theme's wash.
     */
    public function test_dark_clears_the_gradient_rather_than_painting_over_it(): void
    {
        $css = File::get(resource_path('css/app.css'));

        // background-image: none is the load-bearing line. The wash is an
        // IMAGE, so dark's background-COLOUR alone would sit under it and dark
        // would wear the light theme's gradient.
        $this->assertMatchesRegularExpression(
            '/html\.dark \.dash-hero-wash \{[^}]*background-color: #0c1337;[^}]*background-image: none;/s',
            $css
        );
    }

    /**
     * No hairline above the band on a phone.
     *
     * The panel carried a 1px top border so its curve would read against the
     * black ground. On a phone the band is the panel's top and provides that
     * edge itself, and the border only ever produced a seam above it: the
     * panel's own background showing through a transparent border in light,
     * and a white line in dark.
     */
    public function test_the_phone_panel_has_no_hairline_above_the_band(): void
    {
        $rules = File::get(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/\.dash-mobile-panel \{[^}]*border-top: 0;/s',
            $rules
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.dash-mobile-panel \{[^}]*border-top: 1px solid var\(--dash-pane-line/s',
            $rules
        );
    }
}
