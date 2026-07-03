<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The gift-card single product page's buy-panel surfaces (amount/quantity/crypto
 * selectors, their dropdowns, the hero plate and the empty state) use light /
 * navy tints that collapse to pure black in Extra Dark, washing out against the
 * black page. They carry `pure-card` so Extra Dark paints them the #0d0d0d
 * surface instead.
 */
class ProductPageExtraDarkTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_page_buy_panel_uses_the_extra_dark_surface_class(): void
    {
        $category = Category::factory()->create(['name' => 'Gift Cards', 'slug' => 'gift-cards']);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'brand_key' => 'TestBrand',
            'country_code' => 'US',
            'is_active' => true,
        ]);
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'is_available' => true,
            'is_variable' => false,
            'face_value' => 10,
            'retail_price' => 10,
        ]);

        $this->get('/gift-cards/test-brand')
            ->assertOk()
            ->assertSee('pure-card', false);
    }
}
