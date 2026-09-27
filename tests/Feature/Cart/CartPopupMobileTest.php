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
        // The storefront popup hangs under the nav on every screen now (the
        // bottom-sheet variant it once had on phones is gone from the nav).
        $this->get('/')
            ->assertOk()
            ->assertSee('cart-panel fixed right-3 top-[calc(var(--nav-h,64px)+8px)]', false);
    }

    /**
     * The dashboard header is the phone bar too (2026-09-23), so its cart
     * popup serves phones as a sheet under the bar (app.css positions every
     * dashboard-frame-popup that way). The separate teleported mobile cart
     * sheet it used to ship is gone: with both bound to $store.cart.open,
     * a phone got two empty-cart panels stacked on top of each other.
     */
    public function test_dashboard_has_one_cart_panel_for_every_screen(): void
    {
        $this->withoutVite();
        $html = $this->actingAs(User::factory()->create())
            ->get('/dashboard/shop/checkout')
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'dashboard-frame-popup cart-panel'), 'one dashboard cart panel: the header popup');
        $this->assertStringNotContainsString('cart-panel fixed inset-x-3', $html);
    }
}
