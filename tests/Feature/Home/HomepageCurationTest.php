<?php

namespace Tests\Feature\Home;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The homepage Featured / Popular rows are admin-driven. Curation is
 * brand-level: a product row carries the flag on a single country, but the
 * badge must follow the brand so the homepage surfaces it regardless of which
 * country row the admin happened to flag.
 */
class HomepageCurationTest extends TestCase
{
    use RefreshDatabase;

    private function giftCards(): Category
    {
        return Category::factory()->create(['name' => 'Gift Cards', 'slug' => 'gift-cards']);
    }

    public function test_brand_flagged_on_another_region_is_featured_on_the_us_storefront(): void
    {
        $giftCards = $this->giftCards();

        // The brand is flagged on its GB row only - the US homepage must still
        // feature it via the US row (this is the bug: previously the flag check
        // was region-locked, so a non-US flag never surfaced).
        Product::factory()->create([
            'category_id' => $giftCards->id,
            'brand_key' => 'ZcrossRegionBrand',
            'country_code' => 'US',
            'is_featured' => false,
            'is_active' => true,
        ]);
        Product::factory()->create([
            'category_id' => $giftCards->id,
            'brand_key' => 'ZcrossRegionBrand',
            'country_code' => 'GB',
            'is_featured' => true,
            'is_active' => true,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Featured Gift Cards')
            ->assertSee('Zcross Region Brand');
    }

    public function test_popular_flag_on_any_row_surfaces_the_brand(): void
    {
        $giftCards = $this->giftCards();

        Product::factory()->create([
            'category_id' => $giftCards->id,
            'brand_key' => 'ZpopularElsewhere',
            'country_code' => 'US',
            'is_popular' => false,
            'is_active' => true,
        ]);
        Product::factory()->create([
            'category_id' => $giftCards->id,
            'brand_key' => 'ZpopularElsewhere',
            'country_code' => 'NG',
            'is_popular' => true,
            'is_active' => true,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Zpopular Elsewhere');
    }

    public function test_brand_with_no_row_in_the_region_is_not_featured(): void
    {
        $giftCards = $this->giftCards();

        // Flagged, but only sold in GB. The US storefront can't sell it, so it
        // must not appear - display stays region-locked even though curation is
        // brand-level.
        Product::factory()->create([
            'category_id' => $giftCards->id,
            'brand_key' => 'ZgbOnlyBrand',
            'country_code' => 'GB',
            'is_featured' => true,
            'is_active' => true,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Zgb Only Brand');
    }
}
