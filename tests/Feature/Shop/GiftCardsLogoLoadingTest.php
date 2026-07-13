<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gift-card logos are tuned to load fast: the tiles above the fold load eagerly
 * with a high fetch priority, everything below defers with lazy loading, decode
 * is async, and the page pre-opens a connection to the brand-logo CDN so the
 * first row starts downloading the moment the grid paints.
 */
class GiftCardsLogoLoadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_above_the_fold_logos_load_eagerly_and_the_rest_defer(): void
    {
        $giftCards = Category::factory()->create(['name' => 'Gift Cards', 'slug' => 'gift-cards']);
        $subcategory = Subcategory::factory()->create(['category_id' => $giftCards->id, 'slug' => 'shopping']);

        // 12 distinct brands so the eager (first 10) / lazy (11th onward) split
        // is actually exercised on one page.
        foreach (range(1, 12) as $i) {
            $product = Product::factory()->create([
                'category_id' => $giftCards->id,
                'subcategory_id' => $subcategory->id,
                'brand_key' => 'Brand'.$i,
                'country_code' => 'US',
                'is_active' => true,
                'logo_url' => 'https://bmk.idtdigitalpayments.com/assets/brand'.$i.'.png',
            ]);
            ProductVariant::factory()->create(['product_id' => $product->id]);
        }

        $response = $this->withoutVite()->get(route('shop.gift-cards'))->assertOk();

        // Above the fold: eager + high priority. Below: lazy. Every logo: async decode.
        $response->assertSee('loading="eager"', false);
        $response->assertSee('fetchpriority="high"', false);
        $response->assertSee('loading="lazy"', false);
        $response->assertSee('decoding="async"', false);

        // The brand-logo CDN connection is pre-opened in the document head.
        $response->assertSee('<link rel="preconnect" href="https://bmk.idtdigitalpayments.com">', false);
    }
}
