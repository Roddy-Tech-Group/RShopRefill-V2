<?php

namespace Tests\Feature\Checkout;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Services\CheckoutService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\PaymentInsights\Services\PaymentBackoffManager;
use App\Domain\Shared\Enums\Currency;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\PaymentSession;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A replaced checkout takes its payment with it.
 *
 * Changing the cart and checking out again cancels the earlier Pending order.
 * Its payment session used to stay live: paying it (the page was still open,
 * the API still answered) moved a cancelled order back to processing and
 * fulfilled it beside the new one, and the Rcoin it had locked stayed locked
 * so the retry could not use those points. The session is now closed with the
 * order, and because the customer simply changed their mind it must not count
 * as a failed payment anywhere.
 */
class SupersededCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Cart $cart;

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

        Cache::flush();

        $this->user = User::factory()->create(['email_verified_at' => now()]);

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
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'provider_offer_id' => 'steam-offer-10',
            'sku' => 'STEAM10',
            'currency' => 'USD',
            'face_value' => 10.00,
            'cost_price' => 9.50,
            'retail_price' => 10.50,
            'is_available' => true,
        ]);
        PricingRule::create(['markup_type' => 'fixed', 'markup_value' => 1.00, 'is_active' => true]);

        $this->cart = Cart::create(['user_id' => $this->user->id, 'status' => 'active', 'last_activity_at' => now()]);
        CartItem::create([
            'cart_id' => $this->cart->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 11.00,
            'provider_cost_usd' => 9.50,
            'markup_amount' => 1.00,
            'unit_price_snapshot' => 11.00,
            'subtotal_snapshot' => 11.00,
        ]);
    }

    private function checkout(): Order
    {
        return app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart->fresh()->load('items.product', 'items.variant'),
            paymentMethod: 'flutterwave',
            displayCurrency: 'USD',
        );
    }

    /** A different basket, so the fingerprint does not simply hand the first checkout back. */
    private function changeTheCart(int $quantity): void
    {
        $this->cart->items()->first()->update([
            'quantity' => $quantity,
            'subtotal_snapshot' => 11.00 * $quantity,
            'display_amount' => 11.00 * $quantity,
        ]);
    }

    private function sessionOf(Order $order): PaymentSession
    {
        return $order->paymentAttempts()->first()->paymentSession;
    }

    public function test_replacing_a_checkout_closes_the_old_payment_session(): void
    {
        $first = $this->checkout();
        $oldSession = $this->sessionOf($first);
        $this->assertSame('awaiting_method', $oldSession->status);

        $this->changeTheCart(2);
        $second = $this->checkout();

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(OrderStatus::Cancelled, $first->fresh()->order_status);

        $oldSession->refresh();
        $this->assertSame('expired', $oldSession->status);
        $this->assertSame($second->order_number, $oldSession->metadata['superseded_by']);
        $this->assertSame('awaiting_method', $oldSession->metadata['status_at_failure']);
        $this->assertSame(PaymentStatus::Expired, $oldSession->paymentAttempt->fresh()->payment_status);

        // The live checkout is untouched.
        $this->assertSame('awaiting_method', $this->sessionOf($second)->status);
    }

    /** The whole point: the old session can no longer be paid. */
    public function test_the_old_payment_session_cannot_be_paid(): void
    {
        $first = $this->checkout();
        $oldSession = $this->sessionOf($first);

        $this->changeTheCart(2);
        $this->checkout();

        $this->actingAs($this->user)
            ->postJson(route('api.payment-sessions.pay', $oldSession->id), ['method' => 'card'])
            ->assertStatus(400)
            ->assertJsonPath('status', 'expired');

        $this->assertSame(OrderStatus::Cancelled, $first->fresh()->order_status);
    }

    /**
     * Belt and braces for sessions that predate this, or an order an admin
     * closed by hand: a live session whose order is no longer open is refused
     * at the pay step on the order's state, not only the session's.
     */
    public function test_a_live_session_whose_order_was_cancelled_is_refused_at_pay(): void
    {
        $order = $this->checkout();
        $session = $this->sessionOf($order);

        // The order closes underneath a session nobody touched.
        $order->update(['order_status' => OrderStatus::Cancelled]);
        $this->assertSame('awaiting_method', $session->fresh()->status);

        $this->actingAs($this->user)
            ->postJson(route('api.payment-sessions.pay', $session->id), ['method' => 'card'])
            ->assertStatus(409)
            ->assertJsonPath('status', 'order_closed');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
        $this->assertSame('awaiting_method', $session->fresh()->status, 'the refusal must not move the session');
    }

    public function test_a_live_session_whose_order_is_already_paid_is_refused_at_pay(): void
    {
        $order = $this->checkout();
        $session = $this->sessionOf($order);

        $order->update(['payment_status' => PaymentStatus::Paid]);

        $this->actingAs($this->user)
            ->postJson(route('api.payment-sessions.pay', $session->id), ['method' => 'card'])
            ->assertStatus(409)
            ->assertJsonPath('status', 'order_paid');
    }

    /**
     * The Rcoin the first checkout locked comes back the moment it is replaced,
     * so the retry can apply the same points. It used to stay locked until the
     * sweeper expired the old session half an hour later.
     */
    public function test_replacing_a_checkout_returns_its_locked_rcoin(): void
    {
        $rcoin = Wallet::create([
            'user_id' => $this->user->id,
            'currency' => Currency::RCOIN,
            'balance' => 5000,
            'locked_balance' => 0,
            'is_active' => true,
        ]);

        $first = $this->checkout();

        // What a redemption at checkout leaves behind: the points held against
        // the order, and the order remembering how many.
        $rcoin->update(['locked_balance' => 500]);
        $first->update(['metadata' => array_merge($first->metadata ?? [], ['rcoin_applied' => 500])]);

        $this->changeTheCart(2);
        $this->checkout();

        $this->assertEquals(0, (int) $rcoin->fresh()->locked_balance);
        $this->assertTrue($first->fresh()->metadata['rcoin_lock_released']);
    }

    /**
     * Editing the basket three times is not three failed payments. The limiter
     * reads terminal sessions straight off the table, so a replaced checkout has
     * to be marked for it to leave out, or the fourth checkout greets the
     * customer with a pause.
     */
    public function test_replaced_checkouts_do_not_count_toward_the_payment_pause(): void
    {
        $this->checkout();

        foreach ([2, 3, 4] as $quantity) {
            $this->changeTheCart($quantity);
            $this->checkout();
        }

        $this->assertSame(3, PaymentSession::where('status', 'expired')->count());

        $status = app(PaymentBackoffManager::class)->peek($this->user, 'checkout', '127.0.0.1');

        $this->assertSame(0, $status['attempts'], 'replaced checkouts were counted as failed attempts');
        $this->assertFalse($status['is_rate_limited']);
    }

    /** No change for the case that never had a session to close. */
    public function test_a_superseded_order_with_no_session_is_still_just_cancelled(): void
    {
        $first = $this->checkout();
        $this->sessionOf($first)->delete();

        $this->changeTheCart(2);
        $this->checkout();

        $this->assertSame(OrderStatus::Cancelled, $first->fresh()->order_status);
    }
}
