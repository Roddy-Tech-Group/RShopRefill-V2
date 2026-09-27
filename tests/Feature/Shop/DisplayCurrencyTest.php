<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The display currency: the locale modal's currency pane.
 *
 * Picking one converts every browse-surface price into it - tile ranges and
 * the product page's pay prices - without touching WHICH products show:
 * ?display_currency= is deliberately distinct from ?currency=, the
 * gift-cards listing's own catalogue filter. A pair the rate registry
 * cannot price falls back to the native label, and the choice persists via
 * cookie, mirroring the region lock.
 */
class DisplayCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->category = Category::factory()->create(['slug' => 'gift-cards', 'name' => 'Gift Cards']);
    }

    /** A USD-priced brand with one $10 variant - tile label "$10" untouched. */
    private function stockUsd(): Product
    {
        $subcategory = Subcategory::factory()->create([
            'category_id' => $this->category->id,
            'name' => 'Gaming',
            'slug' => 'gaming',
        ]);

        $product = Product::factory()->create([
            'category_id' => $this->category->id,
            'brand_key' => 'Steam',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'is_active' => true,
        ]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'subcategory_id' => $subcategory->id,
            'is_available' => true,
            'currency' => 'USD',
            'retail_price' => 10,
            'face_value' => 10,
        ]);

        return $product;
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

    public function test_prices_convert_to_the_chosen_display_currency(): void
    {
        $this->stockUsd();
        $this->rate('USD', 'EUR', 0.5);

        $response = $this->get('/gift-cards?display_currency=EUR')->assertOk();

        // $10 at 0.5 -> €5, and the choice is persisted for later requests.
        $this->assertStringContainsString('€5', $response->getContent());
        $response->assertCookie('display_currency', 'EUR');
    }

    public function test_the_choice_persists_via_the_cookie(): void
    {
        $this->stockUsd();
        $this->rate('USD', 'EUR', 0.5);

        // Encrypted, like the region cookie it mirrors - withCookie(), not
        // withUnencryptedCookie(), or EncryptCookies decrypts it to null.
        $html = $this->withCookie('display_currency', 'EUR')
            ->get('/gift-cards')->assertOk()->getContent();

        $this->assertStringContainsString('€5', $html);
    }

    /** A right price in the wrong currency beats a wrong price in the right one. */
    public function test_an_unpriceable_pair_falls_back_to_the_native_label(): void
    {
        $this->stockUsd();
        // No USD->EUR rate seeded.

        $html = $this->get('/gift-cards?display_currency=EUR')->assertOk()->getContent();

        $this->assertStringContainsString('$10', $html);
        $this->assertStringNotContainsString('€', $html);
    }

    /** The display currency changes how a price is shown, never which products show. */
    public function test_the_display_currency_does_not_filter_the_catalogue(): void
    {
        $this->stockUsd();
        $this->rate('USD', 'EUR', 0.5);

        // ?currency= IS the listing's catalogue filter: EUR hides the USD card.
        $filtered = $this->get('/gift-cards?currency=EUR')->assertOk()->getContent();
        $this->assertStringNotContainsString('€5', $filtered);

        // ?display_currency= keeps the catalogue whole and converts the label.
        $displayed = $this->get('/gift-cards?display_currency=EUR')->assertOk()->getContent();
        $this->assertStringContainsString('€5', $displayed);
    }

    public function test_native_prices_render_when_nothing_is_chosen(): void
    {
        $this->stockUsd();

        $html = $this->get('/gift-cards')->assertOk()->getContent();

        $this->assertStringContainsString('$10', $html);
    }
}
