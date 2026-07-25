<?php

namespace Tests\Feature\Pricing;

use App\Domain\Order\Services\CouponApplicationService;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Pins the coupon cost-floor clamp (Stage 3 of the pricing floor rollout): in
 * enforce mode a coupon may eat the markup but never the supplier cost — the
 * discounted line stays at or above cost x (1 + min margin) unless the coupon
 * itself is flagged allow_below_cost. Preview and checkout share the math via
 * CouponApplicationService, asserted here through both entry points.
 */
class CouponCostFloorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ProductVariant $variant;

    private Cart $cart;

    private CartItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'pricing.safety_markup_percent' => 10.0,
            'pricing.min_margin_percent' => 1.0,
            'pricing.floor_mode' => 'enforce',
        ]);

        $this->user = User::factory()->create();

        $category = Category::create(['name' => 'Gift Cards', 'slug' => 'gift-cards', 'type' => 'digital']);
        $subcategory = Subcategory::create(['category_id' => $category->id, 'name' => 'Gaming', 'slug' => 'gaming']);
        $product = Product::create([
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'zendit',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'Steam Gift Card',
            'slug' => 'steam-gift-card-'.uniqid(),
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'provider_offer_id' => 'steam-offer-'.uniqid(),
            'currency' => 'USD',
            'face_value' => 10.00,
            'cost_price' => 9.50,
            'retail_price' => 10.50,
            'is_available' => true,
        ]);

        $this->cart = Cart::create([
            'user_id' => $this->user->id,
            'status' => 'active',
            'last_activity_at' => now(),
        ]);

        // Line: 1 x $11.00 against a $9.50 cost. Cost floor = 9.595, so the
        // largest grantable discount is $1.40 (rounded DOWN to stay >= floor).
        $this->item = CartItem::create([
            'cart_id' => $this->cart->id,
            'product_id' => $product->id,
            'product_variant_id' => $this->variant->id,
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 11.00,
            'provider_cost_usd' => 9.50,
            'markup_amount' => 1.50,
            'unit_price_snapshot' => 11.00,
            'subtotal_snapshot' => 11.00,
        ]);
    }

    private function makeCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'product_variant_id' => $this->variant->id,
            'code' => 'SAVE'.strtoupper(uniqid()),
            'discount_type' => 'fixed',
            'discount_value' => 5.00,
            'is_active' => true,
        ], $overrides));
    }

    public function test_discount_within_margin_is_granted_in_full(): void
    {
        $coupon = $this->makeCoupon(['discount_value' => 1.00]);

        $quote = app(CouponApplicationService::class)->discountFor($coupon, $this->item);

        $this->assertEqualsWithDelta(1.00, $quote['discount_usd'], 0.001);
        $this->assertFalse($quote['clamped']);
    }

    public function test_discount_past_the_cost_floor_is_clamped(): void
    {
        $coupon = $this->makeCoupon(['discount_value' => 5.00]);

        $quote = app(CouponApplicationService::class)->discountFor($coupon, $this->item);

        // 11.00 - 1.40 = 9.60, just above the 9.595 floor.
        $this->assertEqualsWithDelta(1.40, $quote['discount_usd'], 0.001);
        $this->assertTrue($quote['clamped']);
    }

    public function test_allow_below_cost_coupon_is_not_clamped(): void
    {
        $coupon = $this->makeCoupon(['discount_value' => 5.00, 'allow_below_cost' => true]);

        $quote = app(CouponApplicationService::class)->discountFor($coupon, $this->item);

        $this->assertEqualsWithDelta(5.00, $quote['discount_usd'], 0.001);
        $this->assertFalse($quote['clamped']);
    }

    public function test_legacy_mode_grants_the_full_discount(): void
    {
        config(['pricing.floor_mode' => 'legacy']);
        $coupon = $this->makeCoupon(['discount_value' => 5.00]);

        $quote = app(CouponApplicationService::class)->discountFor($coupon, $this->item);

        $this->assertEqualsWithDelta(5.00, $quote['discount_usd'], 0.001);
        $this->assertFalse($quote['clamped']);
    }

    public function test_shadow_mode_grants_the_full_discount(): void
    {
        config(['pricing.floor_mode' => 'shadow']);
        $coupon = $this->makeCoupon(['discount_value' => 5.00]);

        $quote = app(CouponApplicationService::class)->discountFor($coupon, $this->item);

        $this->assertEqualsWithDelta(5.00, $quote['discount_usd'], 0.001);
        $this->assertFalse($quote['clamped']);
    }

    public function test_clamp_scales_with_quantity(): void
    {
        $this->item->update(['quantity' => 2, 'subtotal_snapshot' => 22.00, 'display_amount' => 22.00]);
        $coupon = $this->makeCoupon(['discount_type' => 'percent', 'discount_value' => 50]);

        $quote = app(CouponApplicationService::class)->discountFor($coupon, $this->item->fresh());

        // 50% of 22.00 = 11.00 requested; line floor = 2 x 9.595 = 19.19, so
        // at most 2.81 is grantable.
        $this->assertEqualsWithDelta(2.81, $quote['discount_usd'], 0.001);
        $this->assertTrue($quote['clamped']);
    }

    public function test_line_with_no_cost_snapshot_is_not_clamped(): void
    {
        $this->item->update(['provider_cost_usd' => 0]);
        $coupon = $this->makeCoupon(['discount_value' => 5.00]);

        $quote = app(CouponApplicationService::class)->discountFor($coupon, $this->item->fresh());

        $this->assertEqualsWithDelta(5.00, $quote['discount_usd'], 0.001);
        $this->assertFalse($quote['clamped']);
    }

    public function test_preview_endpoint_reports_the_clamped_discount(): void
    {
        $coupon = $this->makeCoupon(['discount_value' => 5.00, 'code' => 'BIGSAVE']);

        $this->actingAs($this->user)
            ->postJson('/coupon/preview', ['code' => 'BIGSAVE', 'currency' => 'USD'])
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('discount_usd', 1.4);
    }

    public function test_preview_and_checkout_agree_on_the_clamped_discount(): void
    {
        $coupon = $this->makeCoupon(['discount_value' => 5.00, 'code' => 'AGREE5']);

        $preview = $this->actingAs($this->user)
            ->postJson('/coupon/preview', ['code' => 'AGREE5', 'currency' => 'USD'])
            ->json('discount_usd');

        $checkout = app(CouponApplicationService::class)
            ->discountFor($coupon, $this->item)['discount_usd'];

        $this->assertEqualsWithDelta($checkout, (float) $preview, 0.001);
    }
}
