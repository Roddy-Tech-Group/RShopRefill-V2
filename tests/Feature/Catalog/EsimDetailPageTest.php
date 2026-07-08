<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class EsimDetailPageTest extends TestCase
{
    use RefreshDatabase;

    private function esimRegion(): Product
    {
        $category = Category::create(['name' => 'eSIMs', 'slug' => 'esims', 'type' => 'digital']);

        $product = Product::create([
            'category_id' => $category->id,
            'provider_name' => 'zendit',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'United States Data eSIM',
            'slug' => 'united-states-data-esim',
            'is_active' => true,
        ]);

        // Zendit-style fixed-GB plan (data in raw_payload).
        ProductVariant::create([
            'product_id' => $product->id,
            'provider_offer_id' => 'ESIM-US-7D-1GB',
            'currency' => 'USD',
            'face_value' => 2.03,
            'cost_price' => 1.50,
            'retail_price' => 2.03,
            'is_available' => true,
            'metadata' => [
                'plan_type' => 'data_only',
                'coverage' => ['US'],
                'raw_payload' => ['dataGB' => 1, 'durationDays' => 7, 'dataUnlimited' => false],
            ],
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'provider_offer_id' => 'ESIM-US-30D-10GB',
            'currency' => 'USD',
            'face_value' => 12.14,
            'cost_price' => 9.00,
            'retail_price' => 12.14,
            'is_available' => true,
            'metadata' => [
                'plan_type' => 'data_only',
                'coverage' => ['US'],
                'raw_payload' => ['dataGB' => 10, 'durationDays' => 30, 'dataUnlimited' => false],
            ],
        ]);

        // Zendit-style unlimited-daily plan (drives the Unlimited toggle + hero).
        ProductVariant::create([
            'product_id' => $product->id,
            'provider_offer_id' => 'ESIM-US-30D-UL',
            'currency' => 'USD',
            'face_value' => 30.00,
            'cost_price' => 22.00,
            'retail_price' => 30.00,
            'is_available' => true,
            'metadata' => [
                'plan_type' => 'data_only',
                'coverage' => ['US'],
                'raw_payload' => ['dataGB' => 0, 'durationDays' => 30, 'dataUnlimited' => true, 'shortNotes' => '2GB unthrottled Daily'],
            ],
        ]);

        // Airalo-style voice + SMS plan.
        ProductVariant::create([
            'product_id' => $product->id,
            'provider_offer_id' => 'airalo_us-voice-5gb',
            'currency' => 'USD',
            'face_value' => 18.00,
            'cost_price' => 13.50,
            'retail_price' => 18.00,
            'is_available' => true,
            'metadata' => [
                'plan_type' => 'voice_sms_data',
                'supports_voice' => true,
                'supports_sms' => true,
                'data_limit' => '5 GB',
                'voice_limit' => '120',
                'sms_limit' => '100',
                'validity_days' => 15,
                'network' => 'Orange',
            ],
        ]);

        return $product;
    }

    public function test_the_esim_store_page_renders_the_airalo_style_structure(): void
    {
        $this->withoutVite();

        $this->esimRegion();

        $this->get(route('shop.esim', 'united-states-data-esim'))
            ->assertOk()
            ->assertSee('eSIM Store')                  // breadcrumb
            ->assertSee('United States eSIMs')          // country header
            ->assertSee('Choose your package')
            ->assertSee('Data Only')                    // category selector (Data Only / Voice)
            ->assertSee('Standard')                     // standard/unlimited toggle
            ->assertSee('Check compatibility')
            ->assertSee('Why travelers choose RshopRefills eSIMs')
            ->assertSee('Frequently asked questions')
            ->assertSee("All purchases are final unless you don't receive your order", false)
            ->assertSee('Buy now');
    }

    public function test_the_package_data_is_handed_to_the_client(): void
    {
        $this->withoutVite();

        $this->esimRegion();

        // Packages render client-side from the x-data JSON payload.
        $this->get(route('shop.esim', 'united-states-data-esim'))
            ->assertOk()
            ->assertSee('1 GB')
            ->assertSee('10 GB')
            ->assertSee('5 GB')
            ->assertSee('120 mins')
            ->assertSee('100 SMS');
    }

    public function test_the_compatibility_modal_lists_devices_from_config(): void
    {
        $this->withoutVite();

        $this->esimRegion();

        $this->get(route('shop.esim', 'united-states-data-esim'))
            ->assertOk()
            ->assertSee('iPhone 15')      // iOS list
            ->assertSee('Galaxy S24')     // Android list
            ->assertSee('Pixel 9');
    }

    public function test_the_esims_index_is_a_lean_landing(): void
    {
        $this->withoutVite();
        Cache::flush();

        $this->esimRegion();

        // The /esims entry point (what the nav links to) is a hero + browse-by-location
        // landing — not the country store.
        $this->get(route('shop.esims'))
            ->assertOk()
            ->assertSee('Feel the freedom of unlimited data')
            ->assertSee('Popular')
            ->assertSee('United States')
            ->assertDontSee('Choose your package');
    }

    public function test_the_esims_landing_shows_the_first_visit_destination_nudge(): void
    {
        $this->withoutVite();
        Cache::flush();

        $this->esimRegion();

        // First-visit "where are you going?" modal, gated to one appearance per
        // session, using the World Cup hero illustration.
        $this->get(route('shop.esims'))
            ->assertOk()
            ->assertSee('rshopEsimTip', false)   // once-per-session gate
            ->assertSee('Where are you going')    // the travel hook
            ->assertSee('Find my eSIM');          // dismiss CTA
    }

    public function test_the_esims_landing_accepts_a_scope_filter(): void
    {
        $this->withoutVite();
        Cache::flush();

        $this->esimRegion();

        // Valid scope renders; an unknown scope falls back without erroring.
        $this->get(route('shop.esims', ['scope' => 'regional']))->assertOk()->assertSee('Feel the freedom of unlimited data');
        $this->get(route('shop.esims', ['scope' => 'not-a-scope']))->assertOk();
    }

    public function test_voice_plans_label_bare_numbers(): void
    {
        $this->withoutVite();

        $this->esimRegion();

        // voice_limit "120" / sms_limit "100" should render as "120 mins" / "100 SMS".
        $this->get(route('shop.esim', 'united-states-data-esim'))
            ->assertOk()
            ->assertSee('120 mins')
            ->assertSee('100 SMS');
    }

    public function test_a_country_page_merges_plans_from_multiple_suppliers(): void
    {
        $this->withoutVite();

        $category = Category::create(['name' => 'eSIMs', 'slug' => 'esims', 'type' => 'digital']);

        // Supplier A (data only).
        $zendit = Product::create([
            'category_id' => $category->id, 'provider_name' => 'zendit', 'country_code' => 'US',
            'currency_code' => 'USD', 'name' => 'United States Data eSIM', 'slug' => 'us-zendit', 'is_active' => true,
        ]);
        ProductVariant::create([
            'product_id' => $zendit->id, 'provider_offer_id' => 'z-us-1gb', 'currency' => 'USD',
            'face_value' => 2.03, 'cost_price' => 1.50, 'retail_price' => 2.03, 'is_available' => true,
            'metadata' => ['raw_payload' => ['dataGB' => 1, 'durationDays' => 7, 'dataUnlimited' => false]],
        ]);

        // Supplier B (voice + SMS), same country, different product.
        $airalo = Product::create([
            'category_id' => $category->id, 'provider_name' => 'airalo', 'country_code' => 'US',
            'currency_code' => 'USD', 'name' => 'United States eSIM', 'slug' => 'us-airalo', 'is_active' => true,
        ]);
        ProductVariant::create([
            'product_id' => $airalo->id, 'provider_offer_id' => 'airalo_us-voice', 'currency' => 'USD',
            'face_value' => 18.00, 'cost_price' => 13.50, 'retail_price' => 18.00, 'is_available' => true,
            'metadata' => [
                'supports_voice' => true, 'supports_sms' => true, 'data_limit' => '5 GB',
                'voice_limit' => '120', 'sms_limit' => '100', 'validity_days' => 15, 'network' => 'Orange',
            ],
        ]);

        // Opening either supplier's slug shows BOTH suppliers' plans on one country page.
        $this->get(route('shop.esim', 'us-zendit'))
            ->assertOk()
            ->assertSee('1 GB')       // supplier A data plan
            ->assertSee('120 mins')   // supplier B voice plan
            ->assertSee('100 SMS');
    }

    public function test_multi_country_esims_show_a_coverage_count(): void
    {
        $this->withoutVite();

        $category = Category::create(['name' => 'eSIMs', 'slug' => 'esims', 'type' => 'digital']);
        $product = Product::create([
            'category_id' => $category->id, 'provider_name' => 'zendit', 'country_code' => 'WW',
            'currency_code' => 'USD', 'name' => 'Global Data eSIM', 'slug' => 'esim-ww-global', 'is_active' => true,
        ]);
        ProductVariant::create([
            'product_id' => $product->id, 'provider_offer_id' => 'glob-1', 'currency' => 'USD',
            'face_value' => 10, 'cost_price' => 5, 'retail_price' => 10, 'is_available' => true,
            'metadata' => ['coverage' => ['US', 'GB', 'FR', 'DE'], 'raw_payload' => ['dataGB' => 5, 'durationDays' => 30]],
        ]);

        $this->get(route('shop.esim', 'esim-ww-global'))
            ->assertOk()
            ->assertSee('4 Countries and Networks')   // header
            ->assertSee('United States')               // modal coverage list (ISO -> name)
            ->assertSee('Germany');
    }

    public function test_the_country_route_resolves_by_iso_code(): void
    {
        $this->withoutVite();

        $this->esimRegion();

        $this->get(route('shop.esim.country', 'US'))
            ->assertOk()
            ->assertSee('United States eSIMs')
            ->assertSee('Choose your package');

        $this->get(route('shop.esim.country', 'ZZ'))->assertNotFound();
    }

    public function test_an_unknown_region_returns_404(): void
    {
        $this->get('/esims/this-region-does-not-exist')->assertNotFound();
    }

    public function test_buy_bar_renders_as_a_top_toast(): void
    {
        $this->withoutVite();

        $this->esimRegion();

        // The plan-selection buy bar now slides in from the top of the viewport
        // (fixed, offset just below the live nav height), so it sits clear of
        // the nav and the dashboard's bottom tab bar. Storefront shares it.
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard.shop.esim', 'united-states-data-esim'))
            ->assertOk()
            ->assertSee('fixed inset-x-0 z-[100]', false);

        $this->get(route('shop.esim', 'united-states-data-esim'))
            ->assertOk()
            ->assertSee('fixed inset-x-0 z-[100]', false);
    }
}
