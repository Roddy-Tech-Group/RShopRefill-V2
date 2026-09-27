<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Switching locale converts prices; it never filters the catalogue.
 *
 * Two params look alike and mean opposite things. ?currency= is the
 * gift-card listing's own FILTER - show me only cards priced in this
 * currency. ?display_currency= is the shopper's chosen display currency -
 * show me everything, priced in this currency.
 *
 * The locale modal always sent the second. The Alpine locale watcher that
 * backs the nav search panel's "Change Country" sent the first, so picking a
 * country there silently hid every product not priced in that country's
 * currency - the listing went from the whole catalogue to a fraction of it.
 */
class LocaleSwitchCurrencyParamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /** Two brands in different currencies, so a filter is visible as a loss. */
    private function stockTwoCurrencies(): void
    {
        $category = Category::factory()->create(['slug' => 'gift-cards', 'name' => 'Gift Cards']);
        $subcategory = Subcategory::factory()->create([
            'category_id' => $category->id, 'name' => 'Gaming', 'slug' => 'gaming',
        ]);

        foreach ([['Steam', 'USD'], ['Chewy', 'EUR']] as [$brand, $currency]) {
            $product = Product::factory()->create([
                'category_id' => $category->id,
                'brand_key' => $brand,
                'country_code' => 'US',
                'currency_code' => $currency,
                'is_active' => true,
            ]);

            ProductVariant::factory()->create([
                'product_id' => $product->id,
                'subcategory_id' => $subcategory->id,
                'is_available' => true,
                'currency' => $currency,
                'retail_price' => 10,
                'face_value' => 10,
            ]);
        }
    }

    /**
     * The watcher is what a country pick from the search panel runs. It must
     * write the display param - the filter one belongs to the listing's own
     * chips, which the shopper sets deliberately.
     */
    public function test_the_locale_watcher_sends_the_display_param(): void
    {
        $js = File::get(resource_path('js/app.js'));

        $this->assertStringContainsString(
            "url.searchParams.set('display_currency', this.currency)",
            $js
        );
        $this->assertStringNotContainsString(
            "url.searchParams.set('currency', this.currency)",
            $js
        );
    }

    /**
     * The whole catalogue survives a display-currency switch.
     *
     * Asserted on the PRICES, not the brand names: the nav's mega menu lists
     * popular brands on every page, so a name can be present while its tile
     * is not.
     */
    public function test_switching_display_currency_keeps_every_brand(): void
    {
        $this->stockTwoCurrencies();
        $this->rate('USD', 'EUR', 0.5);

        $html = $this->get('/gift-cards?display_currency=EUR')->assertOk()->getContent();

        $this->assertStringContainsString('€5', $html, 'the USD card should convert, not vanish');
        $this->assertStringContainsString('€10', $html, 'the EUR card is already in the display currency');
        $this->assertDoesNotMatchRegularExpression('/\$10(?!\d)/', $html, 'nothing should still be priced in USD');
    }

    /**
     * And the filter param still filters - it is a real feature, just not the
     * one a locale switch should be reaching for.
     */
    public function test_the_filter_param_still_narrows_the_catalogue(): void
    {
        $this->stockTwoCurrencies();

        $html = $this->get('/gift-cards?currency=EUR')->assertOk()->getContent();

        $this->assertStringContainsString('€10', $html);
        $this->assertDoesNotMatchRegularExpression('/\$10(?!\d)/', $html, 'the USD card is filtered out');
    }

    private function rate(string $base, string $target, float $rate): void
    {
        ExchangeRate::create([
            'base_currency' => $base,
            'target_currency' => $target,
            'rate' => $rate,
            'provider' => 'test',
            'source' => 'test',
            'is_active' => true,
            'fetched_at' => now(),
        ]);
    }
}
