<?php

namespace Tests\Feature\Availability;

use App\Domain\Cart\Services\CartValidationService;
use App\Models\Admin;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SiteSetting;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Availability enforcement for the two admin controls:
 *   - Feature A: a category feature flag switched OFF (features.<cat>_enabled)
 *   - Feature B: a product marked out of stock (products.is_out_of_stock)
 *
 * Greying the UI is cosmetic; these tests pin the SERVER-SIDE block so a disabled
 * or out-of-stock product cannot be bought via a direct/forged request, on both the
 * add-to-cart path (validateAddition) and the checkout re-validation (validateItem).
 */
class ProductAvailabilityGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pricing.safety_markup_percent' => 10.0, 'pricing.min_margin_percent' => 1.0]);

        $this->user = User::factory()->create();

        $category = Category::create(['name' => 'Gift Cards', 'slug' => 'gift-cards', 'type' => 'digital']);
        $subcategory = Subcategory::create(['category_id' => $category->id, 'name' => 'Gaming', 'slug' => 'gaming']);
        $this->product = Product::create([
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'zendit',
            'brand_key' => 'Steam',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'Steam Gift Card',
            'slug' => 'steam-gift-card',
            'is_active' => true,
            'is_out_of_stock' => false,
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'provider_offer_id' => 'steam-offer-10',
            'sku' => 'STEAM10',
            'currency' => 'USD',
            'face_value' => 10.00,
            'cost_price' => 9.50,
            'retail_price' => 10.50,
            'is_available' => true,
        ]);

        Cache::flush();
        PricingRule::create(['markup_type' => 'fixed', 'markup_value' => 1.00, 'is_active' => true]);
    }

    private function addToCart(): TestResponse
    {
        return $this->actingAs($this->user)->postJson(route('cart.items.add'), [
            'product_variant_id' => $this->variant->id,
            'quantity' => 1,
        ]);
    }

    public function test_in_stock_product_in_an_enabled_category_can_be_added(): void
    {
        $this->addToCart()->assertOk()->assertJsonPath('count', 1);
    }

    public function test_out_of_stock_product_cannot_be_added_to_cart(): void
    {
        $this->product->update(['is_out_of_stock' => true]);

        $this->addToCart()->assertStatus(422);
    }

    public function test_product_in_a_disabled_category_cannot_be_added_to_cart(): void
    {
        SiteSetting::put('features.gift_cards_enabled', 'off', 'features');

        $this->addToCart()->assertStatus(422);
    }

    public function test_a_stale_out_of_stock_item_is_flagged_at_checkout(): void
    {
        $itemId = $this->addToCart()->assertOk()->json('items.0.id');

        // Admin marks it out of stock AFTER it is already sitting in the cart.
        $this->product->update(['is_out_of_stock' => true]);

        $issues = app(CartValidationService::class)->validateItem(CartItem::findOrFail($itemId));

        $this->assertContains('out_of_stock', $issues);
    }

    public function test_a_stale_disabled_category_item_is_flagged_at_checkout(): void
    {
        $itemId = $this->addToCart()->assertOk()->json('items.0.id');

        SiteSetting::put('features.gift_cards_enabled', 'off', 'features');

        $issues = app(CartValidationService::class)->validateItem(CartItem::findOrFail($itemId));

        $this->assertContains('category_disabled', $issues);
    }

    public function test_is_buyable_reflects_every_condition(): void
    {
        $this->product->load(['category', 'variants']);
        $this->assertTrue($this->product->isBuyable());

        $this->product->update(['is_out_of_stock' => true]);
        $this->assertFalse($this->product->fresh()->load(['category', 'variants'])->isBuyable());

        $this->product->update(['is_out_of_stock' => false]);
        SiteSetting::put('features.gift_cards_enabled', 'off', 'features');
        $this->assertFalse($this->product->fresh()->load(['category', 'variants'])->isBuyable());
    }
}
