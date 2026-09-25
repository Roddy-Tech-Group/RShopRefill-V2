<?php

namespace Tests\Feature\Shop;

use App\Models\User;
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

        // The padding belongs on the <header> itself, not on the top bar inside
        // it, so the reserved strip is part of the same block and carries the
        // same background. (It used to matter for a second reason - the eSIM
        // floating bars measured this element - but the header is no longer
        // sticky and they no longer measure anything.)
        $this->assertMatchesRegularExpression(
            '/<header class="[^"]*pt-\[env\(safe-area-inset-top\)\]/',
            $html,
        );
    }

    /** Edge-anchored bottom bars have to clear the home indicator. */
    public function test_the_cookie_bar_clears_the_home_indicator(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        // Anchored above the inset rather than padded for it, so the card
        // keeps its own padding on every device.
        $this->assertStringContainsString('bottom-[max(1rem,env(safe-area-inset-bottom))]', $html);
    }

    /**
     * The dashboard's mobile chrome is behind auth, so it is asserted at the
     * source. Each of these is pinned to a screen edge with nothing above or
     * below it in the installed app.
     */
    public function test_every_pinned_dashboard_surface_pads_for_its_inset(): void
    {
        $layout = File::get(resource_path('views/components/layouts/dashboard.blade.php'));
        $css = File::get(resource_path('css/app.css'));

        // The fixed navy bar reserves the status-bar strip itself, and the
        // spacer under it holds the same room in normal flow.
        $this->assertStringContainsString('padding-top: calc(0.75rem + env(safe-area-inset-top));', $css);
        $this->assertStringContainsString('height: calc(env(safe-area-inset-top) + 3.75rem);', $layout);

        // Every header popup drops under the bar as a sheet, below the strip,
        // and never taller than what the strip leaves.
        $this->assertStringContainsString('top: calc(env(safe-area-inset-top) + 4.25rem);', $css);
        $this->assertStringContainsString('max-height: calc(100dvh - env(safe-area-inset-top) - 5rem);', $css);

        // The notification bottom sheet clears the home indicator; the drawer
        // owes the status bar at its head.
        $this->assertStringContainsString('padding-bottom: env(safe-area-inset-bottom);', $css);
        $this->assertStringContainsString('body.dashboard .dash-drawer-head { min-height: calc(3.75rem + env(safe-area-inset-top)); padding-top: env(safe-area-inset-top); }', $css);
    }

    /**
     * The drawer is the menu sheet now - fixed inset-y-0 on the left, holding
     * the categories and the utilities under them. It owes BOTH insets: the
     * status bar at the top, the home indicator at the foot.
     *
     * The floor is its own 1.25rem padding rather than the 0.75rem the search
     * sheet used, or the top would read tighter than the sides on a phone with
     * no inset at all.
     */
    public function test_the_mobile_nav_drawer_pads_for_its_inset(): void
    {
        $nav = File::get(resource_path('views/components/nav/main-nav.blade.php'));

        $this->assertStringContainsString('pt-[max(1.25rem,env(safe-area-inset-top))]', $nav);
        $this->assertStringContainsString('pb-[max(1.25rem,env(safe-area-inset-bottom))]', $nav);
    }

    /**
     * Padding the fixed chrome is only half the job. viewport-fit=cover also
     * pulls the viewport up under the status bar, so the page content needs
     * something holding that strip in normal flow. One spacer, the bar's own
     * height plus the inset, on every dashboard page and only once: a task
     * page, a shop page and the overview alike.
     */
    public function test_every_dashboard_page_reserves_the_bar_and_the_status_strip_once(): void
    {
        $user = User::factory()->create();

        foreach (['dashboard.orders', 'dashboard.shop.gift-cards', 'dashboard'] as $route) {
            $html = $this->withoutVite()->actingAs($user)->get(route($route))->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, 'height: calc(env(safe-area-inset-top) + 3.75rem);'), $route.' should reserve the strip once');
            $this->assertStringNotContainsString('height: env(safe-area-inset-top);', $html, $route.' stacks a second spacer');
        }
    }
}
