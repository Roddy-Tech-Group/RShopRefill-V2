<?php

namespace Tests\Feature;

use App\Domain\Cart\Services\CartManager;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The checkout coupon live-preview endpoint must return the same USD discount
 * CheckoutService applies at order time, so the total the customer sees before
 * paying matches what they're charged.
 */
class CouponPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The preview must mirror CheckoutService's cost-anchored coupon clamp,
        // which only exists in enforce mode. Pin it: the config default is
        // legacy (per-environment), so these assertions must not inherit it.
        config(['pricing.floor_mode' => 'enforce']);
    }

    private function cartWithVariant(User $user, float $unitUsd, int $qty = 1): ProductVariant
    {
        $category = Category::factory()->create(['slug' => 'gift-cards']);
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create(['category_id' => $category->id]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'currency' => 'USD',
            'face_value' => $unitUsd,
            'cost_price' => $unitUsd * 0.9,
            'retail_price' => $unitUsd,
        ]);

        $cart = app(CartManager::class)->resolveCart($user->id, null);
        $cart->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => $qty,
            'unit_price_snapshot' => $unitUsd,
            'subtotal_snapshot' => $unitUsd * $qty,
            'provider_cost_usd' => $unitUsd * 0.9,
            'markup_amount' => $unitUsd * 0.1,
            'display_currency' => 'USD',
            'display_amount' => $unitUsd * $qty,
            'metadata_snapshot' => [],
        ]);

        return $variant;
    }

    public function test_fixed_coupon_returns_its_usd_discount(): void
    {
        $user = User::factory()->create();
        $variant = $this->cartWithVariant($user, 89.00);

        Coupon::create([
            'product_variant_id' => $variant->id,
            'code' => 'LAUNCHV2',
            'discount_type' => 'fixed',
            'discount_value' => 9.00,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->postJson('/coupon/preview', ['code' => 'launchv2', 'currency' => 'USD'])
            ->assertOk()
            // The $9 coupon is clamped: the $89 line carries an $80.10 unit cost,
            // so the floor is $80.10 x 1.01 = $80.90 and at most $8.09 can come
            // off. The full $9 would have sold it a cent under cost.
            ->assertJson(['valid' => true, 'code' => 'LAUNCHV2', 'discount_usd' => 8.09]);
    }

    public function test_percent_coupon_scales_by_quantity(): void
    {
        $user = User::factory()->create();
        $variant = $this->cartWithVariant($user, 50.00, 2); // line = 100

        Coupon::create([
            'product_variant_id' => $variant->id,
            'code' => 'TEN',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'is_active' => true,
        ]);

        // 10% of $50 unit = $5, x2 qty = $10 before the floor. The line's cost
        // is $45/unit, so the cost floor is $45 x 2 x 1.01 = $90.90 and the most
        // the coupon may take off a $100 line is $9.10.
        $this->actingAs($user)
            ->postJson('/coupon/preview', ['code' => 'TEN', 'currency' => 'USD'])
            ->assertOk()
            ->assertJson(['valid' => true, 'discount_usd' => 9.1]);
    }

    public function test_a_fixed_coupon_is_clamped_to_the_cost_floor_in_enforce_mode(): void
    {
        // Unit $89.00, supplier cost $80.10, min margin 1% → floor $80.901.
        // The requested $9.00 would sell at $80.00, below cost-plus-margin, so
        // the platform grants $8.09 and eats the smaller discount instead of the
        // loss. Rounding is downward by design: never a cent under the floor.
        config(['pricing.floor_mode' => 'enforce', 'pricing.min_margin_percent' => 1]);

        $user = User::factory()->create();
        $variant = $this->cartWithVariant($user, 89.00);

        Coupon::create([
            'product_variant_id' => $variant->id,
            'code' => 'LAUNCHV2',
            'discount_type' => 'fixed',
            'discount_value' => 9.00,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->postJson('/coupon/preview', ['code' => 'launchv2', 'currency' => 'USD'])
            ->assertOk()
            ->assertJson(['valid' => true, 'discount_usd' => 8.09]);
    }

    public function test_a_percent_coupon_is_clamped_to_the_cost_floor_in_enforce_mode(): void
    {
        // Unit $50.00 × 2, cost $45.00/unit → line floor $90.90 against a $100
        // line, so the most that can be given away is $9.10 rather than $10.00.
        config(['pricing.floor_mode' => 'enforce', 'pricing.min_margin_percent' => 1]);

        $user = User::factory()->create();
        $variant = $this->cartWithVariant($user, 50.00, 2);

        Coupon::create([
            'product_variant_id' => $variant->id,
            'code' => 'TEN',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->postJson('/coupon/preview', ['code' => 'TEN', 'currency' => 'USD'])
            ->assertOk()
            ->assertJson(['valid' => true, 'discount_usd' => 9.10]);
    }

    public function test_a_coupon_flagged_allow_below_cost_is_not_clamped(): void
    {
        // A deliberate loss-leader promo still has to be possible — but only when
        // someone has explicitly said so on the coupon.
        config(['pricing.floor_mode' => 'enforce', 'pricing.min_margin_percent' => 1]);

        $user = User::factory()->create();
        $variant = $this->cartWithVariant($user, 89.00);

        Coupon::create([
            'product_variant_id' => $variant->id,
            'code' => 'LOSSLEADER',
            'discount_type' => 'fixed',
            'discount_value' => 9.00,
            'is_active' => true,
            'allow_below_cost' => true,
        ]);

        $this->actingAs($user)
            ->postJson('/coupon/preview', ['code' => 'LOSSLEADER', 'currency' => 'USD'])
            ->assertOk()
            ->assertJson(['valid' => true, 'discount_usd' => 9.0]);
    }

    public function test_unknown_coupon_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->cartWithVariant($user, 20.00);

        $this->actingAs($user)
            ->postJson('/coupon/preview', ['code' => 'NOPE', 'currency' => 'USD'])
            ->assertOk()
            ->assertJson(['valid' => false]);
    }

    public function test_coupon_for_a_variant_not_in_cart_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->cartWithVariant($user, 20.00);

        $otherProduct = Product::factory()->create(['category_id' => Category::factory()->create()->id]);
        $other = ProductVariant::factory()->create(['product_id' => $otherProduct->id, 'currency' => 'USD', 'face_value' => 5, 'cost_price' => 4, 'retail_price' => 5]);
        Coupon::create([
            'product_variant_id' => $other->id,
            'code' => 'OTHER',
            'discount_type' => 'fixed',
            'discount_value' => 5,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->postJson('/coupon/preview', ['code' => 'OTHER', 'currency' => 'USD'])
            ->assertOk()
            ->assertJson(['valid' => false]);
    }

    public function test_empty_cart_returns_invalid(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/coupon/preview', ['code' => 'ANY', 'currency' => 'USD'])
            ->assertOk()
            ->assertJson(['valid' => false]);
    }
}
