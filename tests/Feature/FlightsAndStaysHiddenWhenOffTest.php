<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Support\FeatureFlag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Switching Flights or Stays off has to remove them EVERYWHERE, not grey them
 * out. The grey treatment means "we sell this, it is briefly unavailable" -
 * true for Gift Cards during an outage, false for these two, which have no
 * category row, no products and no fulfillment path.
 *
 * The first pass only gated the homepage cards and the admin menu, leaving the
 * nav pills, the category pickers, both dashboard rails, the sitemap, the
 * marketing copy and the pages themselves still advertising them.
 */
class FlightsAndStaysHiddenWhenOffTest extends TestCase
{
    use RefreshDatabase;

    private function setFlags(string $flights, string $stays): void
    {
        SiteSetting::put('features.flights_enabled', $flights, 'features');
        SiteSetting::put('features.stays_enabled', $stays, 'features');
    }

    // ────────────────────────────────────────────────────────────
    //  The rule itself
    // ────────────────────────────────────────────────────────────

    public function test_only_the_unbuilt_categories_can_be_hidden(): void
    {
        $this->setFlags('off', 'off');

        $this->assertFalse(FeatureFlag::categoryVisible('flights'));
        $this->assertFalse(FeatureFlag::categoryVisible('stays'));

        // A live category switched off greys out; it must never vanish.
        SiteSetting::put('features.gift_cards_enabled', 'off', 'features');
        $this->assertTrue(FeatureFlag::categoryVisible('gift-cards'));
        $this->assertFalse(FeatureFlag::categoryEnabled('gift-cards'));

        // Unknown slugs are always visible.
        $this->assertTrue(FeatureFlag::categoryVisible('something-new'));
        $this->assertTrue(FeatureFlag::categoryVisible(null));
    }

    // ────────────────────────────────────────────────────────────
    //  Their own pages
    // ────────────────────────────────────────────────────────────

    public function test_the_pages_404_when_switched_off(): void
    {
        $this->setFlags('off', 'off');

        $this->get('/flights')->assertNotFound();
        $this->get('/stays')->assertNotFound();
    }

    public function test_the_pages_open_when_switched_on(): void
    {
        $this->setFlags('on', 'on');

        $this->get('/flights')->assertOk();
        $this->get('/stays')->assertOk();
    }

    public function test_one_can_be_off_while_the_other_stays_open(): void
    {
        $this->setFlags('off', 'on');

        $this->get('/flights')->assertNotFound();
        $this->get('/stays')->assertOk();
    }

    // ────────────────────────────────────────────────────────────
    //  Storefront surfaces
    // ────────────────────────────────────────────────────────────

    public function test_the_nav_pills_disappear_with_the_flag(): void
    {
        $this->setFlags('on', 'on');
        $this->get('/')->assertOk()->assertSee('Flights')->assertSee('Stays');

        $this->setFlags('off', 'off');
        $response = $this->get('/')->assertOk();
        $response->assertDontSee('>Flights<', escape: false);
        $response->assertDontSee('>Stays<', escape: false);
    }

    public function test_the_homepage_never_links_to_a_closed_page(): void
    {
        $this->setFlags('off', 'off');

        $response = $this->get('/')->assertOk();

        $response->assertDontSee(url('/flights'), escape: false);
        $response->assertDontSee(url('/stays'), escape: false);
    }

    public function test_marketing_copy_drops_them_from_the_service_list(): void
    {
        $this->setFlags('on', 'on');
        $this->get('/')->assertOk()->assertSee('gift cards, eSIMs, flights, stays and mobile top-ups');

        $this->setFlags('off', 'off');
        $response = $this->get('/')->assertOk();
        $response->assertSee('gift cards, eSIMs and mobile top-ups');
        $response->assertDontSee('gift cards, eSIMs, flights, stays and mobile top-ups');
    }

    public function test_the_html_sitemap_drops_them(): void
    {
        $this->setFlags('off', 'off');

        $response = $this->get(route('shop.sitemap'))->assertOk();

        $response->assertDontSee(url('/flights'), escape: false);
        $response->assertDontSee(url('/stays'), escape: false);
    }

    /**
     * A sitemap full of 404s is a live SEO penalty, so the XML feed must drop
     * them the moment the routes close.
     */
    public function test_the_xml_sitemap_drops_them(): void
    {
        $this->setFlags('off', 'off');

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringNotContainsString('<loc>'.url('/flights').'</loc>', $xml);
        $this->assertStringNotContainsString('<loc>'.url('/stays').'</loc>', $xml);

        $this->setFlags('on', 'on');
        Cache::flush();

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('<loc>'.url('/flights').'</loc>', $xml);
    }
}
