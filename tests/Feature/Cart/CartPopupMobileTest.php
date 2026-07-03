<?php

namespace Tests\Feature\Cart;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The nav cart popup (which opens on add-to-cart) is a bottom sheet on mobile so
 * it pops up in view even when the shopper added from far down the page; sm+
 * keeps the desktop top-right card. Guards the responsive positioning classes.
 */
class CartPopupMobileTest extends TestCase
{
    use RefreshDatabase;

    public function test_cart_popup_is_a_bottom_sheet_on_mobile_and_top_right_on_desktop(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('inset-x-3 bottom-3', false)   // mobile: bottom sheet
            ->assertSee('sm:top-[84px]', false);       // sm+: restores desktop top-right
    }

    /**
     * The dashboard desktop cart popup lives in the lg:flex header, hidden on
     * phones - so the dashboard layout ships its own teleported bottom sheet,
     * pinned above the mobile bottom nav, that opens on the same $store.cart.open.
     */
    public function test_dashboard_has_a_mobile_cart_bottom_sheet(): void
    {
        $this->withoutVite();

        $this->actingAs(User::factory()->create())
            ->get('/dashboard/shop/checkout')
            ->assertOk()
            ->assertSee('inset-x-3 bottom-20 z-[80]', false); // mobile sheet above the bottom nav
    }
}
