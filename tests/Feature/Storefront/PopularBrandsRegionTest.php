<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The home Popular row shows every curated brand's USA row to all visitors, and floats
 * a visitor's own-country row (e.g. Cameroon top-ups in CM) to the top - in the order
 * defined by config/popular_brands.php.
 */
class PopularBrandsRegionTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        config(['popular_brands.keys' => ['Apple', 'MTN', 'Camtel']]);
        $this->category = Category::factory()->create();
    }

    private function product(string $brandKey, string $country): Product
    {
        return Product::factory()->create([
            'category_id' => $this->category->id,
            'brand_key' => $brandKey,
            'country_code' => $country,
            'slug' => strtolower($brandKey.'-'.$country),
            'is_active' => true,
        ]);
    }

    public function test_local_country_brands_float_to_top_and_usa_brands_still_show(): void
    {
        $this->product('Apple', 'US');
        $this->product('MTN', 'CM');
        $this->product('Camtel', 'CM');

        // Cameroon visitor: their local brands first (config order), then the USA gift card.
        $this->assertSame(
            ['MTN', 'Camtel', 'Apple'],
            Product::popularForRegion('CM')->pluck('brand_key')->all()
        );

        // US visitor: the US brand leads; CM-only brands fall to the bottom but still show.
        $this->assertSame(
            ['Apple', 'MTN', 'Camtel'],
            Product::popularForRegion('US')->pluck('brand_key')->all()
        );
    }

    public function test_a_brand_with_a_local_row_uses_that_row_not_the_usa_one(): void
    {
        $this->product('Apple', 'US');
        $appleCm = $this->product('Apple', 'CM');

        $picked = Product::popularForRegion('CM')->firstWhere('brand_key', 'Apple');

        $this->assertSame('CM', $picked->country_code);
        $this->assertSame($appleCm->id, $picked->id);
    }
}
