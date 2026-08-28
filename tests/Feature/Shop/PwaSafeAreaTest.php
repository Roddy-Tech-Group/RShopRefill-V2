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
            // Bottom tab bar. It floats 12px clear of the edge already, so it
            // takes that back out of the inset rather than stacking on top of
            // it; the max() floor keeps the 12px where there is no inset.
            // The underscores are Tailwind's escape for the spaces CSS requires
            // around a calc() minus; without them the declaration is invalid
            // and padding-bottom silently collapses to 0.
            'pb-[max(12px,calc(env(safe-area-inset-bottom)_-_12px))]',
        ] as $rule) {
            $this->assertStringContainsString($rule, $layout);
        }
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
     * something holding that strip in normal flow. Only the overview had it
     * (the spacer under its glass band); every other page started on pt-5
     * alone and read as shifted up, with the floating flag/cart pills landing
     * on top of the first card on the shop pages.
     */
    public function test_a_task_page_reserves_the_status_bar_strip_in_flow(): void
    {
        $html = $this->withoutVite()
            ->actingAs(User::factory()->create())
            ->get(route('dashboard.orders'))
            ->assertOk()
            ->getContent();

        // No floating pills on a task page, so it owes the inset and nothing more.
        $this->assertStringContainsString('height: env(safe-area-inset-top);', $html);
    }

    /**
     * Shop pages float the flag + cart pills over the page with no band beneath
     * them, so they have to reserve the pill row's height on top of the inset.
     */
    public function test_a_shop_page_reserves_the_floating_pill_row_too(): void
    {
        $html = $this->withoutVite()
            ->actingAs(User::factory()->create())
            ->get(route('dashboard.shop.gift-cards'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'height: calc(max(0.5rem, env(safe-area-inset-top)) + 3.25rem);',
            $html,
        );
    }

    /**
     * The overview already holds the band's room. Rendering the new spacer
     * there as well would push its content down by a second status bar.
     */
    public function test_the_overview_does_not_stack_a_second_spacer(): void
    {
        $html = $this->withoutVite()
            ->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        // Its own band spacer, and only that one.
        $this->assertStringContainsString('height: calc(max(0.75rem, env(safe-area-inset-top)) + 3.25rem);', $html);
        $this->assertStringNotContainsString('height: calc(max(0.5rem, env(safe-area-inset-top)) + 3.25rem);', $html);
        $this->assertStringNotContainsString('height: env(safe-area-inset-top);', $html);
    }
}
