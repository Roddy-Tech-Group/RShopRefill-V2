<?php

namespace Tests\Feature\Shop;

use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The gift-cards category tab was renamed "Buy Gift Cards" and two new coming-soon
 * destinations, "Sell Gift Cards" and "GoMarket", were added after it across the
 * storefront category nav. Both new pages reuse the shared shop.coming-soon view.
 *
 * Both categories are hidden storefront-wide while their feature flags
 * (features.sell_gift_cards_enabled / features.gomarket_enabled) are off,
 * which is how they are seeded. The GoMarket coming-soon page stays reachable
 * by URL; the Sell Gift Cards page is gated by its flag (the no-trace rule:
 * while trading is off there must be no mention of it anywhere, so its own
 * page 404s rather than advertising the feature).
 */
class CategoryNavBuySellGoMarketTest extends TestCase
{
    use RefreshDatabase;

    public function test_sell_gift_cards_coming_soon_page_renders_its_own_copy(): void
    {
        $this->withoutVite();

        // The page only exists while trading is switched on.
        SiteSetting::put('features.sell_gift_cards_enabled', 'on', 'features');

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

    public function test_sell_and_gomarket_are_hidden_from_the_storefront_by_default(): void
    {
        $this->withoutVite();

        // Their feature flags are seeded OFF, so neither appears in the nav,
        // homepage tiles, or anywhere else on the page.
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Buy Gift Cards')
            ->assertDontSee('Sell Gift Cards')
            ->assertDontSee('GoMarket');
    }

    public function test_home_category_nav_shows_buy_sell_and_gomarket_in_order_when_enabled(): void
    {
        $this->withoutVite();

        SiteSetting::put('features.sell_gift_cards_enabled', 'on', 'features');
        SiteSetting::put('features.gomarket_enabled', 'on', 'features');

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

    public function test_gomarket_is_public_while_sell_gift_cards_follows_its_flag(): void
    {
        $this->withoutVite();

        // GoMarket is a public teaser regardless of auth.
        $this->get('/gomarket')->assertOk();

        // Sell Gift Cards leaves no trace while trading is off...
        $this->get('/sell-gift-cards')->assertNotFound();

        // ...and comes back the moment the flag is flipped.
        SiteSetting::put('features.sell_gift_cards_enabled', 'on', 'features');
        $this->get('/sell-gift-cards')->assertOk();
    }
}
