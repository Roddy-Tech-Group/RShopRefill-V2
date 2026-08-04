<?php

namespace Tests\Feature\Checkout;

use App\Domain\Order\Exceptions\InvalidCouponException;
use App\Domain\Order\Services\CheckoutService;
use App\Domain\Payment\Services\PaymentSessionService;
use App\Jobs\FulfillOrderItemJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Coupons used to be counted by incrementing `coupons.used_count` the moment a
 * checkout was created, and nothing ever decremented it. Every abandoned or
 * cancelled checkout therefore consumed a redemption permanently, so a customer
 * clicking Pay repeatedly could exhaust a capped promotion without paying once,
 * and the counter reported redemptions that had never happened.
 *
 * Redemption is now a reservation confirmed on payment and released on
 * abandonment. These tests pin both halves: the slot must come back when nobody
 * pays, and it must NOT be handed to a second customer while a live checkout is
 * still holding it.
 */
class CouponRedemptionLifecycleTest extends TestCase
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
            // These tests are about the redemption lifecycle, not the discount
            // clamp, so keep the cheaper legacy pricing path out of the way.
            'pricing.floor_mode' => 'legacy',
        ]);

        $this->user = User::factory()->create();
        $this->variant = $this->makeVariant();

        Cache::flush();
        PricingRule::create(['markup_type' => 'fixed', 'markup_value' => 1.00, 'is_active' => true]);

        $this->cart = $this->makeCart($this->user);

        Queue::fake([FulfillOrderItemJob::class]);
    }

    private function makeVariant(): ProductVariant
    {
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

        return ProductVariant::create([
            'product_id' => $product->id,
            'provider_offer_id' => 'steam-offer-10',
            'sku' => 'STEAM10',
            'currency' => 'USD',
            'face_value' => 10.00,
            'cost_price' => 9.50,
            'retail_price' => 10.50,
            'is_available' => true,
        ]);
    }

    private function makeCart(User $user): Cart
    {
        $cart = Cart::create([
            'user_id' => $user->id,
            'status' => 'active',
            'last_activity_at' => now(),
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $this->variant->product_id,
            'product_variant_id' => $this->variant->id,
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 11.00,
            'provider_cost_usd' => 9.50,
            'markup_amount' => 1.00,
            'unit_price_snapshot' => 11.00,
            'subtotal_snapshot' => 11.00,
        ]);

        return $cart->load('items.product', 'items.variant');
    }

    private function singleUseCoupon(string $code = 'ONEUSE'): Coupon
    {
        return Coupon::create([
            'product_variant_id' => $this->variant->id,
            'code' => $code,
            'discount_type' => 'percent',
            'discount_value' => 10,
            'is_active' => true,
            'max_uses' => 1,
        ]);
    }

    private function checkout(?Cart $cart = null, ?User $user = null, string $code = 'ONEUSE')
    {
        return app(CheckoutService::class)->placeOrder(
            user: $user ?? $this->user,
            cart: $cart ?? $this->cart,
            paymentMethod: 'flutterwave',
            displayCurrency: 'USD',
            couponCode: $code,
        );
    }

    private function sessionFor($order)
    {
        return $order->paymentAttempts()->latest('id')->first()->paymentSession;
    }

    public function test_an_abandoned_checkout_gives_the_coupon_slot_back(): void
    {
        $coupon = $this->singleUseCoupon();

        $order = $this->checkout();

        // Held while the checkout is live: nobody else may take the last slot.
        $this->assertSame(1, $coupon->consumedCount());
        $this->assertTrue($coupon->fresh()->isUsedUp());

        app(PaymentSessionService::class)->expireSession($this->sessionFor($order));

        // Nobody paid, so the promotion is available again. Under the old
        // used_count increment this coupon was dead forever.
        $this->assertSame(0, $coupon->consumedCount());
        $this->assertTrue($coupon->fresh()->isRedeemable());
        $this->assertSame(
            CouponRedemption::STATUS_RELEASED,
            CouponRedemption::where('order_id', $order->id)->sole()->status
        );
    }

    public function test_repeated_checkouts_on_one_cart_cannot_exhaust_a_capped_coupon(): void
    {
        $coupon = $this->singleUseCoupon();

        // The reported abuse: click Pay over and over without ever paying.
        $first = $this->checkout();
        $second = $this->checkout();
        $third = $this->checkout();

        // Only the live checkout holds a slot. The superseded ones gave theirs
        // back, so the coupon shows 1 use rather than the 3 the old code wrote.
        $this->assertSame(1, $coupon->consumedCount());
        $this->assertSame(3, CouponRedemption::count());
        $this->assertSame(
            2,
            CouponRedemption::where('status', CouponRedemption::STATUS_RELEASED)->count()
        );

        app(PaymentSessionService::class)->expireSession($this->sessionFor($third));

        $this->assertSame(0, $coupon->consumedCount());
        $this->assertTrue($coupon->fresh()->isRedeemable());
        $this->assertNotSame($first->id, $third->id);
        $this->assertNotSame($second->id, $third->id);
    }

    public function test_a_live_reservation_still_blocks_a_second_customer(): void
    {
        $this->singleUseCoupon();

        $this->checkout();

        // The reservation must behave like a real hold, otherwise releasing on
        // abandonment would just trade the leak for an oversell.
        $other = User::factory()->create();
        $otherCart = $this->makeCart($other);

        $this->expectException(InvalidCouponException::class);
        $this->expectExceptionMessage('This coupon is no longer available.');

        $this->checkout($otherCart, $other);
    }

    public function test_a_confirmed_payment_spends_the_slot_permanently(): void
    {
        $coupon = $this->singleUseCoupon();

        $order = $this->checkout();

        app(PaymentSessionService::class)->confirmSession($this->sessionFor($order));

        $redemption = CouponRedemption::where('order_id', $order->id)->sole();
        $this->assertSame(CouponRedemption::STATUS_REDEEMED, $redemption->status);
        $this->assertNotNull($redemption->redeemed_at);

        // The cached column the admin UI reads now reflects a real redemption.
        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->assertTrue($coupon->fresh()->isUsedUp());
    }

    public function test_a_confirmed_redemption_is_never_released_by_a_later_sweep(): void
    {
        $coupon = $this->singleUseCoupon();

        $order = $this->checkout();
        $session = $this->sessionFor($order);

        app(PaymentSessionService::class)->confirmSession($session);
        // A stale sweep arriving afterwards must not hand back a paid slot.
        app(PaymentSessionService::class)->failSession($session->fresh(), 'late_sweep');

        $this->assertSame(
            CouponRedemption::STATUS_REDEEMED,
            CouponRedemption::where('order_id', $order->id)->sole()->status
        );
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_a_late_confirm_after_failure_reclaims_the_redemption(): void
    {
        $coupon = $this->singleUseCoupon();

        $order = $this->checkout();
        $session = $this->sessionFor($order);

        // Popup closed, slot handed back...
        app(PaymentSessionService::class)->failSession($session, 'popup_closed');
        $this->assertSame(0, $coupon->consumedCount());

        // ...then the webhook lands and the money is real.
        app(PaymentSessionService::class)->confirmSession($session->fresh());

        $redemption = CouponRedemption::where('order_id', $order->id)->sole();
        $this->assertSame(CouponRedemption::STATUS_REDEEMED, $redemption->status);
        $this->assertNull($redemption->released_at);
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_a_replayed_confirmation_cannot_double_count(): void
    {
        $coupon = $this->singleUseCoupon();

        $order = $this->checkout();
        $session = $this->sessionFor($order);

        app(PaymentSessionService::class)->confirmSession($session);
        app(PaymentSessionService::class)->confirmSession($session->fresh());

        $this->assertSame(1, CouponRedemption::where('order_id', $order->id)->count());
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_an_order_with_no_coupon_creates_no_redemption(): void
    {
        $order = app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart,
            paymentMethod: 'flutterwave',
            displayCurrency: 'USD',
        );

        app(PaymentSessionService::class)->confirmSession($this->sessionFor($order));

        $this->assertSame(0, CouponRedemption::count());
    }
}
