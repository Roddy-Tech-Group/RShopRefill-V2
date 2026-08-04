<?php

namespace Tests\Feature\Checkout;

use App\Domain\Order\Exceptions\InvalidCouponException;
use App\Domain\Order\Services\CheckoutService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Wallet\Services\TransactionPinService;
use App\Jobs\FulfillOrderItemJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CouponRedemptionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ProductVariant $variant;

    private Cart $cart;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.zendit.api_key' => 'ZENDIT_API_KEY_MOCK',
            'services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK',
            'services.nowpayments.api_key' => 'NOWPAYMENTS_KEY_MOCK',
            'pricing.safety_markup_percent' => 10.0,
            'pricing.min_margin_percent' => 1.0,
            // Several tests here assert the cost-anchored coupon clamp, which
            // only exists in enforce mode. Pin it: the config DEFAULT is legacy
            // (and per-environment), so inheriting it would make these tests
            // assert whatever ops last exported rather than the clamp itself.
            // (An earlier note argued for tracking the default - that was
            // written when the default was enforce, and these clamp assertions
            // go red the moment an environment runs legacy.)
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
            'slug' => 'steam-gift-card',
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
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

        $this->cart = Cart::create([
            'user_id' => $this->user->id,
            'status' => 'active',
            'last_activity_at' => now(),
        ]);

        CartItem::create([
            'cart_id' => $this->cart->id,
            'product_id' => $product->id,
            'product_variant_id' => $this->variant->id,
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 11.00,
            'provider_cost_usd' => 9.50,
            'markup_amount' => 1.00,
            'unit_price_snapshot' => 11.00,
            'subtotal_snapshot' => 11.00,
        ]);

        Wallet::create([
            'user_id' => $this->user->id,
            'currency' => Currency::USD,
            'balance' => 100.00,
            'locked_balance' => 0.00,
            'is_active' => true,
        ]);

        Queue::fake([FulfillOrderItemJob::class]);
        app(TransactionPinService::class)->setupPin($this->user, '5283');
    }

    public function test_percent_coupon_discounts_the_order_total_and_reserves_a_redemption(): void
    {
        $coupon = Coupon::create([
            'product_variant_id' => $this->variant->id,
            'code' => 'SAVE10',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'is_active' => true,
        ]);

        $order = app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart,
            paymentMethod: 'wallet',
            displayCurrency: 'USD',
            couponCode: 'save10',
        );

        // $11.00 - 10% = $9.90
        $this->assertEqualsWithDelta(9.90, (float) $order->total_amount, 0.01);
        $this->assertSame('SAVE10', $order->metadata['coupon_code']);
        $this->assertEqualsWithDelta(1.10, (float) $order->metadata['coupon_discount_usd'], 0.01);

        // Checkout reserves the slot; it does not spend it. used_count stays at
        // zero until the money actually arrives, which is the whole point: the
        // old increment-on-checkout counted intent and never gave it back.
        $redemption = CouponRedemption::where('order_id', $order->id)->sole();
        $this->assertSame(CouponRedemption::STATUS_RESERVED, $redemption->status);
        $this->assertTrue($redemption->expires_at->isFuture());

        $coupon->refresh();
        $this->assertSame(0, $coupon->used_count);

        // The reservation still holds the slot against everyone else.
        $this->assertSame(1, $coupon->consumedCount());
    }

    public function test_fixed_coupon_subtracts_a_flat_amount(): void
    {
        Coupon::create([
            'product_variant_id' => $this->variant->id,
            'code' => 'FIVEOFF',
            'discount_type' => 'fixed',
            'discount_value' => 5,
            'is_active' => true,
        ]);

        $order = app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart,
            paymentMethod: 'wallet',
            displayCurrency: 'USD',
            couponCode: 'FIVEOFF',
        );

        // $11.00 - $5.00 would be $6.00, but the cost-anchored floor (enforce
        // mode) will not let a coupon push the line under supplier cost: the
        // floor is cost x (1 + min_margin), so the discount is clamped and the
        // order lands there instead. Selling at $6.00 would have been a loss.
        $this->assertEqualsWithDelta(9.60, (float) $order->total_amount, 0.01);
    }

    public function test_unknown_coupon_throws_invalid_coupon_exception(): void
    {
        $this->expectException(InvalidCouponException::class);
        $this->expectExceptionMessage('That coupon code is not valid.');

        app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart,
            paymentMethod: 'wallet',
            displayCurrency: 'USD',
            couponCode: 'NOPE',
        );
    }

    public function test_inactive_coupon_is_rejected_as_no_longer_available(): void
    {
        Coupon::create([
            'product_variant_id' => $this->variant->id,
            'code' => 'DISABLED',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'is_active' => false,
        ]);

        $this->expectException(InvalidCouponException::class);
        $this->expectExceptionMessage('This coupon is no longer available.');

        app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart,
            paymentMethod: 'wallet',
            displayCurrency: 'USD',
            couponCode: 'DISABLED',
        );
    }

    public function test_used_up_coupon_is_rejected(): void
    {
        $coupon = Coupon::create([
            'product_variant_id' => $this->variant->id,
            'code' => 'MAXED',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'is_active' => true,
            'max_uses' => 1,
        ]);

        // Availability comes from the redemptions table now, so exhausting the
        // coupon means a real redemption exists, not a hand-set counter.
        $paidOrder = Order::create([
            'user_id' => $this->user->id,
            'order_number' => 'RSR-MAXED-1',
            'payment_method' => 'wallet',
            'payment_status' => PaymentStatus::Paid,
        ]);
        CouponRedemption::create([
            'coupon_id' => $coupon->id,
            'order_id' => $paidOrder->id,
            'user_id' => $this->user->id,
            'status' => CouponRedemption::STATUS_REDEEMED,
            'redeemed_at' => now(),
        ]);

        $this->expectException(InvalidCouponException::class);
        $this->expectExceptionMessage('This coupon is no longer available.');

        app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart,
            paymentMethod: 'wallet',
            displayCurrency: 'USD',
            couponCode: 'MAXED',
        );
    }

    public function test_coupon_for_a_variant_not_in_cart_is_rejected(): void
    {
        $otherVariant = ProductVariant::create([
            'product_id' => $this->variant->product_id,
            'provider_offer_id' => 'steam-offer-25',
            'sku' => 'STEAM25',
            'currency' => 'USD',
            'face_value' => 25.00,
            'cost_price' => 24.00,
            'retail_price' => 26.00,
            'is_available' => true,
        ]);

        Coupon::create([
            'product_variant_id' => $otherVariant->id,
            'code' => 'WRONGSKU',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'is_active' => true,
        ]);

        $this->expectException(InvalidCouponException::class);
        $this->expectExceptionMessage('This coupon does not apply to any item in your cart.');

        app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart,
            paymentMethod: 'wallet',
            displayCurrency: 'USD',
            couponCode: 'WRONGSKU',
        );
    }

    public function test_no_coupon_code_still_places_the_order_normally(): void
    {
        $order = app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart,
            paymentMethod: 'wallet',
            displayCurrency: 'USD',
        );

        $this->assertEqualsWithDelta(11.00, (float) $order->total_amount, 0.01);
        $this->assertNull($order->metadata['coupon_id']);
    }

    public function test_expired_coupon_is_rejected(): void
    {
        Coupon::create([
            'product_variant_id' => $this->variant->id,
            'code' => 'EXPIRED10',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'is_active' => true,
            'valid_until' => now()->subDay(),
        ]);

        $this->expectException(InvalidCouponException::class);

        app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart,
            paymentMethod: 'wallet',
            displayCurrency: 'USD',
            couponCode: 'EXPIRED10',
        );
    }

    public function test_not_yet_valid_coupon_is_rejected(): void
    {
        Coupon::create([
            'product_variant_id' => $this->variant->id,
            'code' => 'TOMORROW10',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'is_active' => true,
            'valid_from' => now()->addDay(),
        ]);

        $this->expectException(InvalidCouponException::class);

        app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart,
            paymentMethod: 'wallet',
            displayCurrency: 'USD',
            couponCode: 'TOMORROW10',
        );
    }

    public function test_oversized_percent_coupon_floors_the_total_at_zero_not_negative(): void
    {
        Coupon::create([
            'product_variant_id' => $this->variant->id,
            'code' => 'EVERYTHING',
            'discount_type' => 'fixed',
            'discount_value' => 999,
            'is_active' => true,
        ]);

        $order = app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart,
            paymentMethod: 'wallet',
            displayCurrency: 'USD',
            couponCode: 'EVERYTHING',
        );

        // A $999 coupon on an $11 line used to zero the order out. Under the
        // cost-anchored floor it cannot: the discount is clamped so the line
        // stays at cost x (1 + min_margin). The original guarantee (never
        // negative) still holds, and the stronger one now does too - a coupon
        // can no longer give the product away below what we paid for it.
        $this->assertEqualsWithDelta(9.60, (float) $order->total_amount, 0.01);
        $this->assertGreaterThan(0.0, (float) $order->total_amount);
    }
}
