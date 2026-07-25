<?php

namespace Tests\Feature\Catalog;

use App\Http\Controllers\EsimStoreController;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The "Discover Global" worldwide eSIM is surfaced on the home page + dashboard.
 * EsimStoreController::discoverGlobal() is the shared data source: it returns the
 * global product and its priced plans, or null when it isn't in the catalogue.
 */
class DiscoverGlobalTest extends TestCase
{
    use RefreshDatabase;

    private function makeGlobalEsim(): Product
    {
        $category = Category::create(['name' => 'eSIMs', 'slug' => 'esims', 'type' => 'digital']);

        $product = Product::create([
            'category_id' => $category->id,
            'provider_name' => 'airalo',
            'country_code' => 'WW',
            'currency_code' => 'USD',
            'name' => 'Global Data eSIM',
            'slug' => 'esim-ww-discover-global',
            'is_active' => true,
        ]);

        ProductVariant::factory()->for($product)->create([
            'currency' => 'USD',
            'face_value' => 5,
            'retail_price' => 5,
            'is_available' => true,
            'is_variable' => false,
            'metadata' => ['data_limit' => '1 GB', 'duration_days' => 7],
        ]);

        return $product;
    }

    public function test_discover_global_returns_product_with_priced_plans(): void
    {
        $this->makeGlobalEsim();

        $dg = EsimStoreController::discoverGlobal();

        $this->assertNotNull($dg);
        $this->assertSame('esim-ww-discover-global', $dg['product']->slug);
        $this->assertCount(1, $dg['plans']);
        $this->assertSame('1 GB', $dg['plans'][0]['data']);
        $this->assertSame(7, $dg['plans'][0]['days']);
        $this->assertGreaterThan(0, $dg['plans'][0]['price']);
    }

    public function test_discover_global_returns_null_when_absent(): void
    {
        Category::create(['name' => 'eSIMs', 'slug' => 'esims', 'type' => 'digital']);

        $this->assertNull(EsimStoreController::discoverGlobal());
    }

    public function test_catalog_pins_discover_global_usa_first_and_drops_us_from_popular(): void
    {
        $product = $this->makeGlobalEsim();
        $categoryId = $product->category_id;

        // A real United States local eSIM - it must no longer be flagged popular,
        // because the pinned Discover Global USA card now fills the USA slot.
        $us = Product::create([
            'category_id' => $categoryId,
            'provider_name' => 'airalo',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'United States Data eSIM',
            'slug' => 'esim-us-united-states',
            'is_active' => true,
        ]);
        ProductVariant::factory()->for($us)->create([
            'currency' => 'USD',
            'face_value' => 7,
            'retail_price' => 7,
            'is_available' => true,
            'is_variable' => false,
            'metadata' => ['data_limit' => '1 GB', 'duration_days' => 7],
        ]);

        Cache::flush();
        $catalog = EsimStoreController::catalogSummary();

        $first = $catalog->first();
        $this->assertStringContainsString('discover-global', $first['slug']);
        $this->assertSame('Discover Global USA eSIM', $first['name']);
        $this->assertSame(Product::flagUrl('US'), $first['flag']);
        $this->assertTrue($first['popular']);

        $usCard = $catalog->firstWhere('slug', 'esim-us-united-states');
        $this->assertNotNull($usCard);
        $this->assertFalse($usCard['popular']);
    }

    public function test_home_page_renders_discover_global_section(): void
    {
        $this->withoutVite();
        $product = $this->makeGlobalEsim();

        // makeGlobalEsim() seeds a data-only plan; add a voice plan (numeric
        // voice/sms allowance) so the "Voice eSIMs with a Real +1 USA Number"
        // group renders alongside the "Data-Only eSIMs" data group.
        ProductVariant::factory()->for($product)->create([
            'currency' => 'USD',
            'face_value' => 10,
            'retail_price' => 10,
            'is_available' => true,
            'is_variable' => false,
            'metadata' => ['data_limit' => '5 GB', 'duration_days' => 15, 'voice_limit' => 100, 'sms_limit' => 100],
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Discover Global USA')
            ->assertSee('Voice eSIMs with a Real +1 USA Number')
            ->assertSee('Data-Only eSIMs for Global Travel')
            ->assertSee('Choose voice if you want the +1 real USA number');
    }

    /**
     * The dashboard renders this inside an off-center column, so it must pass
     * :contained="true" to drop the carousel's 100vw full-bleed track (w-screen)
     * - otherwise the track overflows onto the right rail. The home page keeps it.
     */
    public function test_contained_carousel_drops_the_full_bleed_track(): void
    {
        $this->makeGlobalEsim();

        $contained = Blade::render('<x-home.discover-global :contained="true" />');
        $this->assertStringNotContainsString('w-screen', $contained);

        $bleed = Blade::render('<x-home.discover-global />');
        $this->assertStringContainsString('w-screen', $bleed);
    }
}
