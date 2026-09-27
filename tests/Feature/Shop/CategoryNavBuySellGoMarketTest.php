<?php

namespace Tests\Feature\Shop;

use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The GoMarket coming-soon teaser sits behind its feature flag
 * (features.gomarket_enabled, seeded off): hidden from the nav and homepage
 * while off, but its page stays reachable by URL. Gift card trading ("Sell
 * Gift Cards") was removed from the product entirely, so its page must be
 * gone outright.
 *
 * The gift-cards entry reads "Gift Cards". It was "Buy Gift Cards" in
 * x-home.category-tiles, which the home page no longer renders - the four
 * cards come from x-home.category-cards, which labels them from
 * navigation.gift_cards.
 */
class CategoryNavBuySellGoMarketTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_gomarket_is_hidden_from_the_storefront_by_default(): void
    {
        $this->withoutVite();

        // Its feature flag is seeded OFF, so it appears nowhere on the page.
        // Trading is removed outright, so "Sell Gift Cards" must never appear.
        $this->get(route('home'))
            ->assertOk()
            ->assertSee(__('navigation.gift_cards'))
            ->assertDontSee('Sell Gift Cards')
            ->assertDontSee('GoMarket');
    }

    public function test_home_category_nav_shows_buy_then_gomarket_when_enabled(): void
    {
        $this->withoutVite();

        SiteSetting::put('features.gomarket_enabled', 'on', 'features');

        $html = $this->get(route('home'))
            ->assertOk()
            ->assertSee(__('navigation.gift_cards'))
            ->assertSee('GoMarket')
            ->assertDontSee('Sell Gift Cards')
            ->getContent();

        // Order: the gift-cards entry, then GoMarket.
        $buy = strpos($html, __('navigation.gift_cards'));
        $market = strpos($html, 'GoMarket');

        $this->assertNotFalse($buy);
        $this->assertLessThan($market, $buy);
    }

    public function test_gomarket_is_public_and_the_removed_trading_page_is_gone(): void
    {
        $this->withoutVite();

        // GoMarket is a public teaser regardless of auth.
        $this->get('/gomarket')->assertOk();

        // Gift card trading is removed from the product: its page is gone
        // outright, not gated.
        $this->get('/sell-gift-cards')->assertNotFound();
    }
}
