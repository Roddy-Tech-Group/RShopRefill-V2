<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The category menu lists only categories with something to sell.
 *
 * It used to list every supplier subcategory on every page. Switch the country
 * to one with a thin catalogue and most entries led straight to "No gift cards
 * match these filters" - a menu of dead ends the visitor had to discover one
 * tap at a time.
 */
class CategoryStockFilterTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->category = Category::factory()->create(['slug' => 'gift-cards', 'name' => 'Gift Cards']);
    }

    /** A brand in $country, filed under $subcategory. */
    private function stock(string $brand, string $country, Subcategory $subcategory): void
    {
        $product = Product::factory()->create([
            'category_id' => $this->category->id,
            'brand_key' => $brand,
            'name' => $brand,
            'country_code' => $country,
            'is_active' => true,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'subcategory_id' => $subcategory->id,
            'is_available' => true,
            'retail_price' => 10,
            'face_value' => 10,
        ]);
    }

    private function subcategory(string $name, string $slug): Subcategory
    {
        return Subcategory::factory()->create([
            'category_id' => $this->category->id,
            'name' => $name,
            'slug' => $slug,
        ]);
    }

    public function test_a_category_with_no_stock_in_the_country_is_not_listed(): void
    {
        $gaming = $this->subcategory('Gaming', 'gaming');
        $travel = $this->subcategory('Travel', 'travel');

        $this->stock('GamerBrand', 'US', $gaming);
        $this->stock('TravelBrand', 'GB', $travel);

        $us = $this->get('/gift-cards?country=US')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/>\s*Gaming\s*</', $us);
        $this->assertDoesNotMatchRegularExpression('/>\s*Travel\s*</', $us);

        $gb = $this->get('/gift-cards?country=GB')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/>\s*Travel\s*</', $gb);
        $this->assertDoesNotMatchRegularExpression('/>\s*Gaming\s*</', $gb);
    }

    /** A category nobody stocks anywhere never appears. */
    public function test_an_entirely_empty_category_is_never_listed(): void
    {
        $this->subcategory('Pets', 'pets');
        $this->stock('GamerBrand', 'US', $this->subcategory('Gaming', 'gaming'));

        $html = $this->get('/gift-cards?country=US')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/>\s*Pets\s*</', $html);
    }

    /**
     * Picking a category must not collapse the menu to that one entry.
     *
     * The list is derived from the filters WITHOUT the subcategory clause for
     * exactly this reason: the grid holds only the picked category once one is
     * chosen, so deriving the menu from the grid would strand the visitor.
     */
    public function test_the_menu_survives_picking_a_category(): void
    {
        $gaming = $this->subcategory('Gaming', 'gaming');
        $travel = $this->subcategory('Travel', 'travel');

        $this->stock('GamerBrand', 'US', $gaming);
        $this->stock('TravelBrand', 'US', $travel);

        $html = $this->get('/gift-cards?country=US&subcategory=gaming')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/>\s*Gaming\s*</', $html);
        $this->assertMatchesRegularExpression('/>\s*Travel\s*</', $html);
    }

    /** The mobile sheet reads the same list, so it narrows with the sidebar. */
    public function test_the_mobile_sheet_lists_the_same_categories(): void
    {
        $gaming = $this->subcategory('Gaming', 'gaming');
        $this->subcategory('Pets', 'pets');
        $this->stock('GamerBrand', 'US', $gaming);

        $html = $this->get('/gift-cards?country=US')->assertOk()->getContent();

        // The two surfaces mark up a label differently - the sidebar puts it
        // straight in the <a> (so it renders surrounded by whitespace), the
        // sheet wraps it in a span - so anchor on the filter URL they share.
        // A listed category emits it more than twice (both menus plus the
        // section heading's own links), which is why this asserts presence
        // rather than a count.
        $this->assertStringContainsString('subcategory=gaming', $html);
        $this->assertStringNotContainsString('subcategory=pets', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*Pets\s*</', $html);
    }
}
