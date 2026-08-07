<?php

namespace Tests\Feature\Checkout;

use App\Domain\Order\Services\CheckoutService;
use App\Domain\PaymentInsights\Exceptions\PaymentBackoffException;
use App\Domain\PaymentInsights\Services\PaymentBackoffManager;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Tapping Pay twice is not two purchases.
 *
 * Every repeat tap used to build a whole second checkout — an order, one row
 * per unit, a payment attempt, a session and a coupon hold — and then cancel
 * the previous one. That is the single largest source of duplicate rows in the
 * system, and none of it was the customer buying anything twice.
 *
 * Reuse has to be exact, though: the fingerprint must refuse to match the
 * moment anything about what the customer is buying, or how they are paying,
 * has changed. Handing back the wrong checkout is far worse than building a
 * spare one.
 */
class IdempotentCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Cart $cart;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.zendit.api_key' => 'ZENDIT_API_KEY_MOCK',
            'services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK',
            'pricing.safety_markup_percent' => 10.0,
            'pricing.min_margin_percent' => 1.0,
            'pricing.floor_mode' => 'legacy',
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

        $this->cart->load('items.product', 'items.variant');
    }

    private function checkout(string $paymentMethod = 'flutterwave', ?string $couponCode = null, ?string $selectedMethod = null): Order
    {
        return app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart->fresh()->load('items.product', 'items.variant'),
            paymentMethod: $paymentMethod,
            displayCurrency: 'USD',
            couponCode: $couponCode,
            selectedMethod: $selectedMethod,
        );
    }

    public function test_tapping_pay_again_on_an_unchanged_cart_returns_the_same_checkout(): void
    {
        $first = $this->checkout();
        $second = $this->checkout();
        $third = $this->checkout();

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);

        // Three taps, one of everything. This is the whole point.
        $this->assertSame(1, Order::count());
        $this->assertSame(1, OrderItem::count());
        $this->assertSame(1, PaymentAttempt::count());
        $this->assertSame(1, PaymentSession::count());
    }

    public function test_the_reused_checkout_still_carries_a_payable_session(): void
    {
        $first = $this->checkout();
        $second = $this->checkout();

        $session = $second->paymentAttempts->sortByDesc('created_at')->first()?->paymentSession;

        $this->assertNotNull($session, 'reused checkout came back without a session to pay from');
        $this->assertSame(
            $first->paymentAttempts->first()->paymentSession->id,
            $session->id,
            'reuse handed back a different session than the one already open'
        );
    }

    public function test_changing_the_cart_forces_a_fresh_checkout(): void
    {
        $first = $this->checkout();

        $item = $this->cart->items()->first();
        $item->update([
            'quantity' => 2,
            'subtotal_snapshot' => 22.00,
            'display_amount' => 22.00,
        ]);

        $second = $this->checkout();

        $this->assertNotSame($first->id, $second->id, 'a changed cart reused the old checkout');
        $this->assertSame(2, Order::count());
    }

    /**
     * Same price, different recipient. The fingerprint has to notice, or an
     * airtime top-up gets delivered to the previous phone number.
     */
    public function test_changing_only_the_line_metadata_forces_a_fresh_checkout(): void
    {
        $this->cart->items()->first()->update(['metadata_snapshot' => ['recipient' => '08011111111']]);
        $first = $this->checkout();

        $this->cart->items()->first()->update(['metadata_snapshot' => ['recipient' => '08022222222']]);
        $second = $this->checkout();

        $this->assertNotSame($first->id, $second->id, 'a different recipient reused the previous checkout');
    }

    public function test_switching_payment_method_forces_a_fresh_checkout(): void
    {
        $first = $this->checkout(selectedMethod: 'card');
        $second = $this->checkout(selectedMethod: 'bank_transfer');

        $this->assertNotSame($first->id, $second->id, 'a method switch reused the old checkout');
    }

    public function test_an_expired_session_is_never_handed_back(): void
    {
        $first = $this->checkout();

        PaymentSession::query()->update(['expires_at' => now()->subMinute()]);

        $second = $this->checkout();

        $this->assertNotSame($first->id, $second->id, 'customer was sent back to a dead payment screen');
    }

    /**
     * Forty seconds of runway is not a usable payment window. The rapid repeat
     * taps this exists to absorb all land nowhere near the boundary, so a
     * customer returning at the end of the window gets a fresh checkout.
     */
    public function test_a_session_about_to_expire_is_not_handed_back(): void
    {
        $first = $this->checkout();

        PaymentSession::query()->update(['expires_at' => now()->addSeconds(40)]);

        $second = $this->checkout();

        $this->assertNotSame($first->id, $second->id, 'customer was handed a payment screen about to die');
    }

    /**
     * A guest cart is resolved from a cookie and every guest email mints its
     * own shadow account, so one cart genuinely can carry orders belonging to
     * different users. Matching on the cart alone would hand one buyer another
     * buyer's order.
     */
    public function test_another_customers_order_on_the_same_cart_is_never_handed_back(): void
    {
        $first = $this->checkout();

        $other = User::factory()->create();

        $second = app(CheckoutService::class)->placeOrder(
            user: $other,
            cart: $this->cart->fresh()->load('items.product', 'items.variant'),
            paymentMethod: 'flutterwave',
            displayCurrency: 'USD',
        );

        $this->assertNotSame($first->id, $second->id, 'a buyer was handed someone else\'s checkout');
        $this->assertSame($other->id, $second->user_id);
    }

    public function test_a_failed_session_is_never_handed_back(): void
    {
        $first = $this->checkout();

        PaymentSession::query()->update(['status' => 'failed']);

        $second = $this->checkout();

        $this->assertNotSame($first->id, $second->id, 'customer was sent back to a failed session');
    }

    /**
     * Reuse deliberately runs ahead of the rate limiter. Returning a checkout
     * that already exists writes nothing, so throttling it protects nothing
     * and only strands a customer away from a payment screen they are already
     * holding (and could reach directly through the pay endpoint regardless).
     */
    public function test_a_rate_limited_customer_can_still_return_to_their_live_checkout(): void
    {
        $first = $this->checkout();

        $manager = $this->createMock(PaymentBackoffManager::class);
        $manager->method('getBackoffStatus')->willReturn([
            'is_rate_limited' => true,
            'wait_seconds' => 30,
            'consecutive_fails' => 3,
        ]);
        $this->app->instance(PaymentBackoffManager::class, $manager);

        $second = $this->checkout();

        $this->assertSame($first->id, $second->id, 'a throttled customer was locked out of their own live checkout');
        $this->assertSame(1, Order::count());
    }

    public function test_a_rate_limited_customer_cannot_start_a_brand_new_checkout(): void
    {
        $manager = $this->createMock(PaymentBackoffManager::class);
        $manager->method('getBackoffStatus')->willReturn([
            'is_rate_limited' => true,
            'wait_seconds' => 30,
            'consecutive_fails' => 3,
        ]);
        $this->app->instance(PaymentBackoffManager::class, $manager);

        $this->expectException(PaymentBackoffException::class);

        try {
            $this->checkout();
        } finally {
            // The throttle must still cost zero rows.
            $this->assertSame(0, Order::count());
        }
    }

    /**
     * The coupon leak and the duplication problem are the same problem seen
     * twice: repeat taps used to burn a redemption each.
     */
    public function test_repeat_taps_consume_only_one_coupon_reservation(): void
    {
        Coupon::create([
            'product_variant_id' => $this->variant->id,
            'code' => 'SAVE10',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'is_active' => true,
            'max_uses' => 1,
        ]);

        $first = $this->checkout(couponCode: 'SAVE10');
        $second = $this->checkout(couponCode: 'SAVE10');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CouponRedemption::count(), 'a repeat tap burned a second redemption');
    }
}
