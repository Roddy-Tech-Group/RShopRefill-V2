<?php

namespace Tests\Feature\Dashboard;

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

        $this->assertMatchesRegularExpression('/\.dash-mobile-panel \{[^}]*border-top-left-radius: 32px;[^}]*border-top-right-radius: 32px;/', $rules);
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
}
