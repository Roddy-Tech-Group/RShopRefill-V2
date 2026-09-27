<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Inside the dashboard the product page runs as wide as the overview.
 *
 * The dashboard frame pads every page itself (16px a side on a phone). The
 * product page also padded its own wrapper, so inside the frame it sat 16px
 * narrower than the overview on each side, with doubled vertical space. In
 * the dashboard the wrapper now adds no padding; on the storefront, which
 * has no frame, it keeps its own.
 */
class ProductPageDashboardWidthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $category = Category::create(['name' => 'Gift Cards', 'slug' => 'gift-cards', 'type' => 'digital']);

        $product = Product::create([
            'category_id' => $category->id,
            'provider_name' => 'zendit',
            'brand_key' => 'Acme',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'Acme Gift Card',
            'slug' => 'acme-gift-card',
            'is_active' => true,
        ]);

        ProductVariant::factory()->for($product)->create([
            'currency' => 'USD',
            'face_value' => 10,
            'retail_price' => 10,
            'manual_retail_price_usd' => 10,
            'is_available' => true,
            'is_variable' => false,
        ]);
    }

    public function test_in_the_dashboard_the_wrapper_leaves_the_padding_to_the_frame(): void
    {
        $html = $this->withoutVite()
            ->actingAs(User::factory()->create())
            ->get(route('dashboard.shop.brand', ['brandSlug' => 'acme']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/class="mx-auto w-full max-w-7xl\s*"/', $html, 'the product wrapper should carry no padding of its own in the dashboard');
        $this->assertStringNotContainsString('max-w-7xl px-4', $html);

        // The frame pads the page, the same as it does for the overview.
        $this->assertStringContainsString('data-page-content', $html);
        $this->assertStringContainsString('px-4 pt-5 pb-10 sm:px-6', $html);
    }

    public function test_on_the_storefront_the_wrapper_keeps_its_own_padding(): void
    {
        $html = $this->withoutVite()
            ->get(route('shop.brand', ['brandSlug' => 'acme']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-10"', $html);
    }
}
