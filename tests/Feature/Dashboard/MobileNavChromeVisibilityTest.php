<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mobile chrome visibility:
 *   - The floating bottom nav bar (its centre Menu button dispatches
 *     'open-mobile-menu') stays on EVERY dashboard page - it is the primary
 *     navigation and must always be reachable.
 *   - The top chrome (cart / country switcher / notification, plus the hamburger
 *     that dispatches 'open-connect-panel') rides along ONLY on the browse hub -
 *     the overview and the dashboard shop pages - and is hidden on task/inner
 *     pages (orders, wallet, settings, ...) so they stay clean.
 */
class MobileNavChromeVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /** Bottom nav bar's Menu FAB - present on every page. */
    private const NAV_BAR_MARKER = "\$dispatch('open-mobile-menu')";

    /** Top-chrome hamburger - present only on overview + shop pages. */
    private const TOP_CHROME_MARKER = "\$dispatch('open-connect-panel')";

    public function test_overview_shows_the_bottom_nav_bar_and_the_top_chrome(): void
    {
        $this->withoutVite();

        $response = $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk();

        $response->assertSee(self::NAV_BAR_MARKER, false);
        $response->assertSee(self::TOP_CHROME_MARKER, false);
    }

    public function test_shop_pages_show_the_bottom_nav_bar_and_the_top_chrome(): void
    {
        $this->withoutVite();

        $response = $this->actingAs(User::factory()->create())
            ->get('/dashboard/shop/cart')
            ->assertOk();

        $response->assertSee(self::NAV_BAR_MARKER, false);
        $response->assertSee(self::TOP_CHROME_MARKER, false);
    }

    public function test_inner_pages_keep_the_bottom_nav_bar_but_hide_the_top_chrome(): void
    {
        $this->withoutVite();

        $response = $this->actingAs(User::factory()->create())
            ->get('/dashboard/orders')
            ->assertOk();

        // The bottom nav bar (navigation) stays...
        $response->assertSee(self::NAV_BAR_MARKER, false);
        // ...but the top cart / country switcher / notification + hamburger are gone.
        $response->assertDontSee(self::TOP_CHROME_MARKER, false);
    }
}
