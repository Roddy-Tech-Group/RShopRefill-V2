<?php

namespace Tests\Feature\Theme;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cart + checkout content cards are frosted glass (bg-white/70
 * dark:bg-[#0c1a36]/60) which collapses to ~60% black on the pure-black Extra
 * Dark page, washing the cards out. They carry `pure-card` so the Extra Dark
 * rule paints them the readable #0d0d0d card surface instead.
 */
class CartCheckoutExtraDarkTest extends TestCase
{
    use RefreshDatabase;

    public function test_cart_page_cards_carry_the_extra_dark_surface_class(): void
    {
        $this->get(route('shop.cart'))
            ->assertOk()
            ->assertSee('pure-card', false);
    }

    public function test_checkout_page_cards_carry_the_extra_dark_surface_class(): void
    {
        $this->get(route('shop.checkout'))
            ->assertOk()
            ->assertSee('pure-card', false);
    }
}
