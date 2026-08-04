<?php

namespace Tests\Feature\Payment;

use App\Domain\Order\Services\CheckoutService;
use App\Domain\PaymentInsights\Exceptions\PaymentBackoffException;
use App\Domain\PaymentInsights\Services\PaymentBackoffManager;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Wallet\Services\WalletFundingService;
use App\Jobs\FulfillOrderItemJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SiteSetting;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Progressive backoff used to be enforced inside PaymentSessionService, which
 * CheckoutService only reaches at step 6. By then the order, its items, the
 * payment attempt, the coupon reservation and the OrderPlaced event had all
 * committed, so throttling a customer committed an order that could never be
 * paid and that the stale-session sweeper could never see (it keys off
 * payment_sessions, and no session was ever created).
 *
 * The invariant these tests defend: a rate-limited payment attempt must cost
 * ZERO rows. Any backoff check that runs after the first durable write is a
 * stranding bug by construction, whatever its thresholds are.
 */
class PaymentBackoffPreflightTest extends TestCase
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

        Queue::fake([FulfillOrderItemJob::class]);
    }

    private function setBackoffFlag(bool $enabled): void
    {
        SiteSetting::put('features.progressive_backoff_enabled', $enabled ? 'on' : 'off');
        Cache::flush();
    }

    /**
     * Put the customer in a rate-limited state.
     *
     * Stubbed rather than seeded: payment_outcomes requires a payment_session_id,
     * so seeding real failures would create the very orders these tests assert
     * do not exist. What is under test here is WHERE the guard runs, not how
     * failures are counted (PaymentBackoffManager's own arithmetic is covered by
     * the payments:validate suite).
     */
    private function forceRateLimited(int $waitSeconds = 30): void
    {
        $manager = $this->createMock(PaymentBackoffManager::class);
        $manager->method('getBackoffStatus')->willReturn([
            'is_rate_limited' => true,
            'wait_seconds' => $waitSeconds,
            'consecutive_fails' => 3,
        ]);

        $this->app->instance(PaymentBackoffManager::class, $manager);
    }

    private function placeOrder()
    {
        return app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart,
            paymentMethod: 'flutterwave',
            displayCurrency: 'USD',
        );
    }

    public function test_a_throttled_checkout_writes_nothing_at_all(): void
    {
        $this->setBackoffFlag(true);
        $this->forceRateLimited();

        try {
            $this->placeOrder();
            $this->fail('Expected the checkout to be rate limited.');
        } catch (PaymentBackoffException $e) {
            $this->assertGreaterThan(0, $e->waitSeconds);
        }

        // The whole point: nothing was created, so there is no unpayable order
        // sitting at Pending and nothing for the sweeper to miss.
        $this->assertSame(0, Order::count());
        $this->assertSame(0, PaymentAttempt::count());
        $this->assertSame(0, CouponRedemption::count());
    }

    public function test_a_throttled_checkout_does_not_consume_a_coupon(): void
    {
        Coupon::create([
            'product_variant_id' => $this->variant->id,
            'code' => 'ONEUSE',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'is_active' => true,
            'max_uses' => 1,
        ]);

        $this->setBackoffFlag(true);
        $this->forceRateLimited();

        $this->expectException(PaymentBackoffException::class);

        app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart,
            paymentMethod: 'flutterwave',
            displayCurrency: 'USD',
            couponCode: 'ONEUSE',
        );
    }

    public function test_the_cart_survives_a_throttled_checkout(): void
    {
        $this->setBackoffFlag(true);
        $this->forceRateLimited();

        try {
            $this->placeOrder();
        } catch (PaymentBackoffException) {
            // expected
        }

        // A throttled customer must keep their basket, otherwise the cooldown
        // costs them the sale outright rather than delaying it.
        $this->assertSame(1, $this->cart->fresh()->items()->count());
    }

    public function test_the_kill_switch_lets_a_limited_customer_through(): void
    {
        $this->setBackoffFlag(false);
        $this->forceRateLimited();

        $order = $this->placeOrder();

        $this->assertNotNull($order->id);
        $this->assertSame(1, Order::count());
    }

    public function test_an_unthrottled_customer_checks_out_normally(): void
    {
        $this->setBackoffFlag(true);
        // No stub here: the real manager runs and sees no failures at all.

        $order = $this->placeOrder();

        $this->assertNotNull($order->id);
    }

    public function test_wallet_funding_is_guarded_before_it_writes(): void
    {
        $this->setBackoffFlag(true);
        $this->forceRateLimited();

        $wallet = Wallet::create([
            'user_id' => $this->user->id,
            'currency' => Currency::USD,
            'balance' => 0.00,
            'locked_balance' => 0.00,
            'is_active' => true,
        ]);

        $this->expectException(PaymentBackoffException::class);

        try {
            app(WalletFundingService::class)->initializeFunding($this->user, $wallet, 50.0, Currency::USD);
        } finally {
            $this->assertSame(0, WalletFunding::count());
            $this->assertSame(0, PaymentAttempt::count());
        }
    }

    public function test_the_checkout_api_answers_429_with_the_wait(): void
    {
        $this->setBackoffFlag(true);
        $this->forceRateLimited();

        $response = $this->actingAs($this->user)->postJson('/api/checkout/place-order', [
            'cart_id' => $this->cart->id,
            'payment_method' => 'flutterwave',
            'preferred_currency' => 'USD',
        ]);

        // Previously the catch-all swallowed this into a generic 400 with no
        // hint that waiting would fix it.
        $response->assertStatus(429)
            ->assertJson(['is_rate_limited' => true])
            ->assertJsonStructure(['message', 'wait_seconds']);

        $this->assertSame(0, Order::count());
    }
}
