<?php

namespace Tests\Feature\Shop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The gift-cards category tab was renamed "Buy Gift Cards" and two new coming-soon
 * destinations, "Sell Gift Cards" and "GoMarket", were added after it across the
 * storefront category nav. Both new pages reuse the shared shop.coming-soon view.
 */
class CategoryNavBuySellGoMarketTest extends TestCase
{
    use RefreshDatabase;

    public function test_sell_gift_cards_coming_soon_page_renders_its_own_copy(): void
    {
        $this->withoutVite();

        $this->get(route('shop.sell-gift-cards'))
            ->assertOk()
            ->assertSee('Sell Gift Cards')
            ->assertSee('Coming soon')
            ->assertSee('Turn your gift cards into instant cash.')
            // Not the Flights fallback copy.
            ->assertDontSee('Book flights');
    }

    public function test_gomarket_coming_soon_page_renders_its_own_copy(): void
    {
        $this->withoutVite();

        $this->get(route('shop.gomarket'))
            ->assertOk()
            ->assertSee('GoMarket')
            ->assertSee('Coming soon')
            ->assertSee('One marketplace for everything you need.')
            // Uses the hero "Find what you need fast" inline illustration, not a cover image.
            ->assertSee('data-illo="search"', false)
            ->assertDontSee('Book flights');
    }

    public function test_home_category_nav_shows_buy_sell_and_gomarket_in_order(): void
    {
        $this->withoutVite();

        $html = $this->get(route('home'))
            ->assertOk()
            ->assertSee('Buy Gift Cards')
            ->assertSee('Sell Gift Cards')
            ->assertSee('GoMarket')
            ->getContent();

        // Order: Buy Gift Cards, then Sell Gift Cards, then GoMarket.
        $buy = strpos($html, 'Buy Gift Cards');
        $sell = strpos($html, 'Sell Gift Cards');
        $market = strpos($html, 'GoMarket');

        $this->assertNotFalse($buy);
        $this->assertLessThan($sell, $buy);
        $this->assertLessThan($market, $sell);
    }

    public function test_the_two_new_pages_are_ungated_and_public(): void
    {
        $this->withoutVite();

        // Guests (no auth) can reach both, like Flights/Stays.
        $this->get('/sell-gift-cards')->assertOk();
        $this->get('/gomarket')->assertOk();
    }
}
