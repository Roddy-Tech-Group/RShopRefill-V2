<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The flat "All gift cards" grid reveals tiles in batches without duplicating
 * the complete catalogue in the default response.
 */
class GiftCardsProgressiveRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_gift_card_faq_keeps_a_gap_before_the_footer(): void
    {
        $view = file_get_contents(resource_path('views/shop/gift-cards.blade.php'));

        // bg-[#eff6ff] is remapped to the storefront's #f4f6f5 page surface;
        // the shared FAQ panel remains #ffffff on top of it.
        $this->assertStringContainsString('class="bg-[#eff6ff] pb-3 sm:pb-4 lg:pb-5"', $view);
        $this->assertStringContainsString('heading-id="gift-cards-faq-heading"', $view);
    }

    public function test_default_page_renders_at_most_four_batches_in_the_flat_grid(): void
    {
        $this->seedBrands(121);

        $flatGrid = $this->flatGrid(
            $this->withoutVite()->get('/gift-cards')->assertOk()->getContent()
        );

        $this->assertStringContainsString('rendered: 120', $flatGrid);
        $this->assertStringContainsString('total: 121', $flatGrid);
        $this->assertStringContainsString('brands=all', $flatGrid);
        $this->assertSame(120, substr_count($flatGrid, 'aria-label="Progressive Brand'));
    }

    public function test_brands_all_renders_the_complete_flat_grid_without_another_full_list_link(): void
    {
        $this->seedBrands(121);

        $flatGrid = $this->flatGrid(
            $this->withoutVite()->get('/gift-cards?brands=all')->assertOk()->getContent()
        );

        $this->assertStringContainsString('rendered: 121', $flatGrid);
        $this->assertStringContainsString('total: 121', $flatGrid);
        $this->assertStringNotContainsString('brands=all', $flatGrid);
        $this->assertSame(121, substr_count($flatGrid, 'aria-label="Progressive Brand'));
    }

    private function seedBrands(int $count): void
    {
        $category = Category::factory()->create(['name' => 'Gift Cards', 'slug' => 'gift-cards']);
        $subcategory = Subcategory::factory()->create([
            'category_id' => $category->id,
            'name' => 'Shopping',
            'slug' => 'shopping',
        ]);

        foreach (range(1, $count) as $index) {
            $product = Product::factory()->create([
                'category_id' => $category->id,
                'subcategory_id' => $subcategory->id,
                'brand_key' => sprintf('ProgressiveBrand%03d', $index),
                'name' => sprintf('Progressive Brand %03d', $index),
                'country_code' => 'US',
                'is_active' => true,
            ]);

            ProductVariant::factory()->create([
                'product_id' => $product->id,
                'subcategory_id' => $subcategory->id,
                'is_available' => true,
            ]);
        }
    }

    private function flatGrid(string $html): string
    {
        $heading = strpos($html, 'id="all-gift-cards"');
        $this->assertNotFalse($heading, 'The flat gift card grid heading must render.');

        $start = strrpos(substr($html, 0, $heading), '<section');
        $end = strpos($html, '</section>', $heading);

        $this->assertNotFalse($start, 'The flat gift card grid must render.');
        $this->assertNotFalse($end, 'The flat gift card grid must have a closing section.');

        return substr($html, $start, $end - $start);
    }
}
