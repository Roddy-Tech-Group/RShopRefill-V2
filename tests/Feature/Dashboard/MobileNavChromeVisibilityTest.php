<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The mobile floating bottom-nav pill (its centre Menu button dispatches
 * 'open-mobile-menu') and the mobile hamburger (dispatches 'open-connect-panel')
 * ride along ONLY on the browse hub - the overview and the dashboard shop pages.
 * On task/inner pages (orders, wallet, settings, ...) they are hidden so those
 * pages stay clean.
 */
class MobileNavChromeVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const PILL_MARKER = "\$dispatch('open-mobile-menu')";

    private const HAMBURGER_MARKER = "\$dispatch('open-connect-panel')";

    public function test_overview_shows_the_floating_pill_and_hamburger(): void
    {
        $this->withoutVite();

        $response = $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk();

        $response->assertSee(self::PILL_MARKER, false);
        $response->assertSee(self::HAMBURGER_MARKER, false);
    }

    public function test_shop_pages_show_the_floating_pill_and_hamburger(): void
    {
        $this->withoutVite();

        $response = $this->actingAs(User::factory()->create())
            ->get('/dashboard/shop/cart')
            ->assertOk();

        $response->assertSee(self::PILL_MARKER, false);
        $response->assertSee(self::HAMBURGER_MARKER, false);
    }

    public function test_inner_pages_hide_the_floating_pill_and_hamburger(): void
    {
        $this->withoutVite();

        $response = $this->actingAs(User::factory()->create())
            ->get('/dashboard/orders')
            ->assertOk();

        $response->assertDontSee(self::PILL_MARKER, false);
        $response->assertDontSee(self::HAMBURGER_MARKER, false);
    }
}
