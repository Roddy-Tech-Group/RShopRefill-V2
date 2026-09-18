<?php

namespace Tests\Feature\Dashboard;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\HighMarginBrands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The referral hero's logo tiles show the catalogue's highest-margin brands,
 * ranked by the real checkout pricing, one tile per brand, logos only.
 */
class HighMarginBrandsTest extends TestCase
{
    use RefreshDatabase;

    private function brand(string $key, float $cost, ?string $logo = 'https://example.test/logo.png', bool $active = true): void
    {
        $product = Product::factory()->create([
            'brand_key' => $key,
            'logo_url' => $logo ? str_replace('logo', $key, $logo) : null,
            'is_active' => $active,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'currency' => 'USD',
            'face_value' => 10,
            'cost_price' => $cost,
            'retail_price' => 10,
            'is_available' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_brands_rank_by_margin_highest_first(): void
    {
        $this->brand('thin', 9.5);
        $this->brand('wide', 5.0);
        $this->brand('middle', 8.0);

        $ranked = array_column(HighMarginBrands::top(4), 'brand');

        $this->assertSame(['wide', 'middle', 'thin'], $ranked);
    }

    public function test_inactive_brands_and_brands_without_a_logo_are_skipped(): void
    {
        $this->brand('hidden', 1.0, active: false);
        $this->brand('nologo', 1.0, logo: null);
        $this->brand('shown', 7.0);

        $this->assertSame(['shown'], array_column(HighMarginBrands::top(4), 'brand'));
    }

    public function test_it_returns_at_most_the_requested_count(): void
    {
        foreach (['a' => 5.0, 'b' => 6.0, 'c' => 7.0, 'd' => 8.0, 'e' => 9.0] as $key => $cost) {
            $this->brand($key, $cost);
        }

        $this->assertCount(4, HighMarginBrands::top(4));
    }
}
