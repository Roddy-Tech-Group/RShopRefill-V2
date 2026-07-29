<?php

namespace Tests\Feature\Pricing;

use App\Domain\Cart\Services\CartPricingService;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The percentage margin floor alone leaves cheap-to-buy items earning almost
 * nothing: 1% of a $39 top-up is 39 cents, which does not cover the cost of
 * making the sale. `pricing.min_margin_usd` adds a flat cash floor alongside it,
 * and the effective floor is cost plus whichever of the two is larger.
 *
 * It is off by default so it stays opt-in per environment; these tests set it
 * explicitly. The important properties are that it lifts ONLY the thin end of
 * the catalogue and never drags a healthy price down.
 */
class MinimumCashMarginTest extends TestCase
{
    use RefreshDatabase;

    private function variant(float $cost, float $face): ProductVariant
    {
        $category = Category::factory()->create(['slug' => 'mobile-airtime']);
        $product = Product::factory()->create(['category_id' => $category->id]);

        return ProductVariant::factory()->create([
            'product_id' => $product->id,
            'currency' => 'USD',
            'face_value' => $face,
            'cost_price' => $cost,
            'retail_price' => $face,
        ]);
    }

    private function pricing(): CartPricingService
    {
        return app(CartPricingService::class);
    }

    public function test_percentage_floor_alone_leaves_a_cheap_item_earning_pennies(): void
    {
        config(['pricing.floor_mode' => 'enforce', 'pricing.min_margin_usd' => 0]);

        // Straight Talk's shape: $39 cost, $35 face. 1% of $39 is 39 cents.
        $floor = $this->pricing()->costFloor(39.00);

        $this->assertEqualsWithDelta(39.39, $floor, 0.001);
    }

    public function test_cash_floor_lifts_a_thin_margin_item_to_a_full_dollar(): void
    {
        config(['pricing.floor_mode' => 'enforce', 'pricing.min_margin_usd' => 1]);

        $this->assertEqualsWithDelta(40.00, $this->pricing()->costFloor(39.00), 0.001);
    }

    public function test_cash_floor_does_not_touch_an_item_the_percentage_already_covers(): void
    {
        config(['pricing.floor_mode' => 'enforce', 'pricing.min_margin_usd' => 1]);

        // 1% of $500 is $5, already well over the $1 cash floor, so the
        // percentage stays in charge and the flat floor is a no-op.
        $this->assertEqualsWithDelta(505.00, $this->pricing()->costFloor(500.00), 0.001);
    }

    public function test_a_thin_margin_variant_is_repriced_to_earn_exactly_one_dollar(): void
    {
        config(['pricing.floor_mode' => 'enforce', 'pricing.min_margin_usd' => 1]);

        $variant = $this->variant(cost: 39.00, face: 35.00);
        $charged = (float) $this->pricing()->calculatePricing($variant, 1)['unit_price_snapshot'];

        $this->assertEqualsWithDelta(40.00, $charged, 0.01);
        $this->assertEqualsWithDelta(1.00, $charged - 39.00, 0.01);
    }

    public function test_a_healthy_price_is_never_lowered_by_the_cash_floor(): void
    {
        config(['pricing.floor_mode' => 'enforce', 'pricing.min_margin_usd' => 1]);

        // Face well above cost: the markup already clears the floor, so the
        // floor must not pull the price down to cost + $1.
        $variant = $this->variant(cost: 10.00, face: 50.00);
        $charged = (float) $this->pricing()->calculatePricing($variant, 1)['unit_price_snapshot'];

        $this->assertGreaterThan(11.00, $charged);
    }

    public function test_zero_cost_never_produces_a_floor(): void
    {
        config(['pricing.floor_mode' => 'enforce', 'pricing.min_margin_usd' => 1]);

        // No cost data means nothing to anchor on; inventing a $1 floor here
        // would price unknown-cost items off a number we made up.
        $this->assertSame(0.0, $this->pricing()->costFloor(0.0));
    }
}
