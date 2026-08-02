<?php

namespace Tests\Feature\Shop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Safe-area handling for the installed PWA.
 *
 * The manifest ships display: standalone, so once the site is added to a home
 * screen there is no browser UI above the page. Every fixed or sticky surface
 * pinned to a screen edge therefore has to reserve the status bar / home
 * indicator itself, via env(safe-area-inset-*).
 *
 * Those insets only resolve to real numbers when the viewport meta opts in with
 * viewport-fit=cover. Without it iOS reports 0 for all of them, every
 * max(fallback, env(...)) silently collapses to its small fallback, and the top
 * chrome ends up under the status bar in the installed app while looking
 * perfectly fine in a browser - which is exactly how it shipped. These tests pin
 * the opt-in and the surfaces together, because either one alone is useless.
 */
class PwaSafeAreaTest extends TestCase
{
    // The homepage reads the catalog (categories, brands) while rendering, so
    // the cases that GET / need a schema to hit.
    use RefreshDatabase;

    public function test_the_viewport_opts_into_safe_area_insets(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('viewport-fit=cover', $html);
    }

    public function test_the_storefront_header_reserves_the_status_bar_strip(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        // The padding belongs on <header class="sticky">, not on the top bar
        // inside it: the eSIM floating plan bars position themselves off that
        // element's measured height, so growing the header keeps them clear.
        $this->assertMatchesRegularExpression(
            '/<header class="sticky[^"]*pt-\[env\(safe-area-inset-top\)\]/',
            $html,
        );
    }

    /** Edge-anchored bottom bars have to clear the home indicator. */
    public function test_the_cookie_bar_clears_the_home_indicator(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('pb-[max(1rem,env(safe-area-inset-bottom))]', $html);
    }

    /**
     * The dashboard's mobile chrome is behind auth, so it is asserted at the
     * source. Each of these is pinned to a screen edge with nothing above or
     * below it in the installed app.
     */
    public function test_every_pinned_dashboard_surface_pads_for_its_inset(): void
    {
        $layout = File::get(resource_path('views/components/layouts/dashboard.blade.php'));

        foreach ([
            // Fixed frosted top bar, and the spacer that holds its room in flow.
            'padding-top: max(0.75rem, env(safe-area-inset-top));',
            'height: calc(max(0.75rem, env(safe-area-inset-top)) + 3.25rem);',
            // Floating locale / cart / bell pills.
            'top: max(0.5rem, env(safe-area-inset-top));',
            // Bottom tab bar.
            'pb-[max(12px,env(safe-area-inset-bottom))]',
        ] as $rule) {
            $this->assertStringContainsString($rule, $layout);
        }
    }

    public function test_the_mobile_nav_drawer_pads_for_its_inset(): void
    {
        $nav = File::get(resource_path('views/components/nav/main-nav.blade.php'));

        $this->assertStringContainsString('pt-[max(0.75rem,env(safe-area-inset-top))]', $nav);
    }
}
