<?php

namespace Tests\Feature\Catalog;

use App\Http\Controllers\EsimStoreController;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The "Discover Global" worldwide eSIM component. Its plan carousel sat on
 * the storefront home page, then on the dashboard overview; both were
 * removed, so nothing renders it today. These tests exercise the component
 * directly - it is kept ready to mount rather than deleted.
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
        // Sold as "Global", never under the supplier's own product name, which
        // is a brand a shopper has no reason to know; the grid adds the suffix.
        $this->assertSame('Global eSIM', $first['name']);
        $this->assertSame(Product::flagUrl('US'), $first['flag']);
        $this->assertTrue($first['popular']);

        $usCard = $catalog->firstWhere('slug', 'esim-us-united-states');
        $this->assertNotNull($usCard);
        $this->assertFalse($usCard['popular']);
    }

    /**
     * The plan carousel was pulled off the storefront home page. The component
     * itself is untouched, so this guards the home page specifically rather
     * than the component.
     */
    public function test_home_page_no_longer_renders_the_discover_global_plans(): void
    {
        $this->withoutVite();
        $product = $this->makeGlobalEsim();

        // A voice plan alongside the seeded data-only one, so both plan groups
        // would render if the section were still on the page.
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
            ->assertDontSee('Discover Global USA')
            ->assertDontSee('Voice eSIMs with a Real +1 USA Number')
            ->assertDontSee('Data-Only eSIMs for Global Travel')
            ->assertDontSee('Choose voice if you want the +1 real USA number');

        $this->assertStringNotContainsString(
            'x-home.discover-global',
            File::get(resource_path('views/welcome.blade.php'))
        );
    }

    /** The component itself still works, wherever it is next mounted. */
    public function test_the_component_still_renders_its_plan_groups(): void
    {
        $product = $this->makeGlobalEsim();

        ProductVariant::factory()->for($product)->create([
            'currency' => 'USD',
            'face_value' => 10,
            'retail_price' => 10,
            'is_available' => true,
            'is_variable' => false,
            'metadata' => ['data_limit' => '5 GB', 'duration_days' => 15, 'voice_limit' => 100, 'sms_limit' => 100],
        ]);

        $html = Blade::render('<x-home.discover-global :contained="true" />');

        $this->assertStringContainsString('Discover Global USA', $html);
        $this->assertStringContainsString('Voice eSIMs with a Real +1 USA Number', $html);
        $this->assertStringContainsString('Data-Only eSIMs for Global Travel', $html);
    }

    /**
     * Mounted inside an off-center column, this must pass :contained="true" to
     * drop the carousel's 100vw full-bleed track - otherwise it overflows its
     * neighbour. The dashboard overview needed exactly that before the panel
     * was taken off it, and the next host will too.
     *
     * The anchor is the .full-bleed class, not the w-screen utility: the bleed
     * moved into CSS (margin-inline: calc(50% - 50vw); width: 100vw) when a
     * scrollbar-width correction was reverted out of it.
     */
    public function test_contained_carousel_drops_the_full_bleed_track(): void
    {
        $this->makeGlobalEsim();

        // Anchored on the track's class attribute, not the bare word: the
        // carousel's x-data carries a JS comment mentioning the full-bleed
        // track, and that renders in both variants.
        $contained = Blade::render('<x-home.discover-global :contained="true" />');
        $this->assertStringNotContainsString('class="full-bleed overflow-x-auto', $contained);
        $this->assertStringContainsString('class="w-full overflow-x-auto', $contained);

        $bleed = Blade::render('<x-home.discover-global />');
        $this->assertStringContainsString('class="full-bleed overflow-x-auto', $bleed);
    }
}
