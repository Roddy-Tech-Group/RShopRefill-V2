<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Out of stock is not the same as switched off, and the tiles used to treat it
 * as though it were: no link, no pointer events, aria-disabled.
 *
 * A category behind a feature flag has nothing to show, so its tiles stay
 * inert. A product that is merely out of stock in this region still opens. The
 * page it opens says so and offers the country switcher, because the same
 * brand is often on the shelf a country over, and refusing the click told the
 * customer only that something was wrong while leaving them nowhere to go.
 */
class OutOfStockStillOpensTest extends TestCase
{
    use RefreshDatabase;

    private function giftCard(bool $outOfStock): Product
    {
        $category = Category::firstOrCreate(
            ['slug' => 'gift-cards'],
            ['name' => 'Gift Cards', 'type' => 'digital']
        );

        $product = Product::create([
            'category_id' => $category->id,
            'provider_name' => 'zendit',
            'brand_key' => 'Acme',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'Acme Gift Card',
            'slug' => 'acme-gift-card',
            'is_active' => true,
            'is_out_of_stock' => $outOfStock,
        ]);

        ProductVariant::factory()->for($product)->create([
            'currency' => 'USD',
            'face_value' => 25,
            'retail_price' => 25,
            'manual_retail_price_usd' => 25,
            'is_available' => true,
            'is_variable' => false,
        ]);

        Cache::flush();

        return $product;
    }

    /** The tile keeps its link, and only the greying stays. */
    public function test_an_out_of_stock_tile_is_still_a_link(): void
    {
        $tile = File::get(resource_path('views/components/shop/brand-tile.blade.php'));

        // Inert is now about the category alone, not about stock.
        $this->assertStringContainsString('$tileInert = ! $categoryEnabled;', $tile);
        $this->assertStringContainsString('@if (! $tileInert && $href) href=', $tile);
        $this->assertStringContainsString('@if ($tileInert) aria-disabled="true" @endif', $tile);

        // Out of stock still reads as out of stock, it just is not a wall.
        $this->assertStringContainsString("'opacity-60 grayscale' => \$notBuyable,", $tile);
        $this->assertStringContainsString("'pointer-events-none' => \$tileInert,", $tile);
        $this->assertStringNotContainsString("'pointer-events-none opacity-60 grayscale' => \$notBuyable,", $tile);
    }

    /** Bills builds its tile by hand and had to learn the same distinction. */
    public function test_the_bills_listing_makes_the_same_distinction(): void
    {
        $bills = File::get(resource_path('views/shop/bills.blade.php'));

        $this->assertStringContainsString('$tileInert = ! $categoryEnabled;', $bills);
        $this->assertStringContainsString("'pointer-events-none' => \$tileInert,", $bills);
        $this->assertStringNotContainsString("'pointer-events-none opacity-60 grayscale' => \$notBuyable,", $bills);
    }

    /** An out-of-stock product opens, and says what to do about it. */
    public function test_the_page_it_opens_offers_the_switcher(): void
    {
        $this->giftCard(outOfStock: true);

        $html = $this->withoutVite()
            ->get(route('shop.brand', ['brandSlug' => 'acme']))
            ->assertOk()
            ->getContent();

        $this->assertTrue(str_contains($html, e(__('products.detail_out_of_stock_notice'))), 'the notice is missing');
        $this->assertTrue(str_contains($html, e(__('products.switch_country'))), 'the notice offers no way to look elsewhere');
    }

    /** The notice names the region, since that is the thing worth changing. */
    public function test_the_notice_says_the_stock_is_a_fact_about_this_region(): void
    {
        foreach (array_map('basename', File::directories(lang_path())) as $locale) {
            $strings = require lang_path($locale.'/products.php');

            $this->assertArrayHasKey('detail_out_of_stock_notice', $strings, $locale.' is missing the notice');
            $this->assertNotSame('', trim((string) $strings['detail_out_of_stock_notice']));
        }

        // The English copy no longer tells them only to come back later.
        $this->assertStringNotContainsString('Please check back later', __('products.detail_out_of_stock_notice'));
    }

    /** A category switched off is still a wall, because there is nothing behind it. */
    public function test_a_switched_off_category_stays_inert(): void
    {
        $this->giftCard(outOfStock: false);
        SiteSetting::put('features.gift_cards_enabled', 'off', 'features');
        Cache::flush();

        $html = $this->withoutVite()->get('/gift-cards')->assertOk()->getContent();

        $this->assertTrue(str_contains($html, 'pointer-events-none'), 'a switched-off category should not be clickable');
    }
}
