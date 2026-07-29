<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The mobile top-up and bill-payment listings used to render every synced brand
 * on a single page, so the request grew forever as the catalog grew. Both now
 * paginate at 60 cards, and the page links must carry the active filters.
 */
class ShopListingPaginationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create $count brands in $category named Brand01..BrandNN so the default
     * (popularity, then alphabetical) sort is deterministic across pages.
     *
     * @param  array<string, mixed>  $lastVariantAttributes  applied to the highest-numbered brand only
     */
    private function seedBrands(Category $category, string $prefix, int $count, array $lastVariantAttributes = []): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $key = $prefix.str_pad((string) $i, 2, '0', STR_PAD_LEFT);

            $product = Product::factory()->create([
                'category_id' => $category->id,
                'brand_key' => $key,
                'name' => $key,
                'country_code' => 'US',
                'is_active' => true,
            ]);

            ProductVariant::factory()->create(array_merge(
                ['product_id' => $product->id],
                $i === $count ? $lastVariantAttributes : [],
            ));
        }
    }

    public function test_topups_listing_paginates_at_sixty_operators(): void
    {
        $topups = Category::factory()->create(['name' => 'Mobile Airtime', 'slug' => 'mobile-airtime']);

        // 61 operators: the 61st can only appear once the listing is paginated.
        // Its variant carries a Data offer so the badge lookup below the query is
        // exercised against a paginator rather than a plain collection.
        $this->seedBrands($topups, 'Netone', 61, ['metadata' => ['subTypes' => ['Data']]]);

        $this->withoutVite()->get(route('shop.topups'))
            ->assertOk()
            ->assertSee('Netone01')
            ->assertSee('Netone60')
            ->assertDontSee('Netone61')
            ->assertSee('page=2', escape: false);

        $this->withoutVite()->get(route('shop.topups', ['page' => 2]))
            ->assertOk()
            ->assertSee('Netone61')
            ->assertSee('Data');
    }

    public function test_topups_page_links_keep_the_active_search_filter(): void
    {
        $topups = Category::factory()->create(['name' => 'Mobile Airtime', 'slug' => 'mobile-airtime']);
        $this->seedBrands($topups, 'Netone', 61);

        $this->withoutVite()->get(route('shop.topups', ['q' => 'Netone']))
            ->assertOk()
            ->assertSee('q=Netone', escape: false);
    }

    public function test_bills_listing_paginates_at_sixty_billers(): void
    {
        $bills = Category::factory()->create(['name' => 'Bill Payments', 'slug' => 'bill-payments']);

        $this->seedBrands($bills, 'Biller', 61);

        $this->withoutVite()->get(route('shop.bills'))
            ->assertOk()
            ->assertSee('Biller01')
            ->assertSee('Biller60')
            ->assertDontSee('Biller61')
            ->assertSee('page=2', escape: false);

        $this->withoutVite()->get(route('shop.bills', ['page' => 2]))
            ->assertOk()
            ->assertSee('Biller61');
    }

    public function test_bills_page_links_keep_the_active_search_filter(): void
    {
        $bills = Category::factory()->create(['name' => 'Bill Payments', 'slug' => 'bill-payments']);
        $this->seedBrands($bills, 'Biller', 61);

        $this->withoutVite()->get(route('shop.bills', ['q' => 'Biller']))
            ->assertOk()
            ->assertSee('q=Biller', escape: false);
    }
}
