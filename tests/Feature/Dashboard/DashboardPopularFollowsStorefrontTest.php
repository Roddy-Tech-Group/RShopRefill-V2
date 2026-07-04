<?php

namespace Tests\Feature\Dashboard;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The dashboard overview "Popular Gift Cards" row must mirror the storefront's
 * curated Popular row exactly - same source (Product::popularForRegion), same
 * config/popular_brands.php order - so the two surfaces never diverge.
 */
class DashboardPopularFollowsStorefrontTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_popular_row_matches_the_storefront_curated_order(): void
    {
        config(['popular_brands.keys' => ['Apple', 'Amazon', 'Visa']]);
        $category = Category::factory()->create(['slug' => 'gift-cards']);

        // Seed out of config order to prove the row is ordered by the curated config
        // list (like the storefront), not by name / is_popular / insertion order.
        foreach (['Visa', 'Apple', 'Amazon'] as $brand) {
            Product::factory()->create([
                'category_id' => $category->id,
                'brand_key' => $brand,
                'country_code' => 'US',
                'slug' => strtolower($brand).'-us',
                'is_active' => true,
            ]);
        }

        $this->actingAs(User::factory()->create());

        // #[Lazy]: the first render is the skeleton placeholder; $refresh boots the
        // real render so the computed view data (popularProducts) is available.
        Volt::test('dashboard.overview')
            ->call('$refresh')
            ->assertViewHas('popularProducts', function (Collection $products) {
                $order = $products->pluck('brand_key')->all();

                // Identical to the storefront source, and in curated config order.
                return $order === Product::popularForRegion('US')->pluck('brand_key')->all()
                    && $order === ['Apple', 'Amazon', 'Visa'];
            });
    }
}
