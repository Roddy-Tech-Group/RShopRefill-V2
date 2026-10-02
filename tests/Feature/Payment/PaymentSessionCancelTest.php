<?php

namespace Tests\Feature\Payment;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Services\CheckoutService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Providers\WalletPaymentProvider;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Wallet\Services\TransactionPinService;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\CurrencyRate;
use App\Models\Order;
use App\Models\PaymentSession;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Cancelling a payment puts everything hanging off it back.
 *
 * The cancel route used to close the session and its attempt and stop there.
 * A wallet top-up stayed Pending, so the hourly reconciler asked the gateway
 * about it and, two hours on, failed it and emailed the customer that their
 * top-up had failed when they had simply closed the sheet. Wallet money a
 * previous authorisation had reserved stayed reserved, and Rcoin locked for
 * the order stayed locked. One routine now does the whole job.
 */
class PaymentSessionCancelTest extends TestCase
{
    use RefreshDatabase;

    private const PIN = '5283';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.zendit.api_key' => 'ZENDIT_API_KEY_MOCK',
            'services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK',
            'services.flutterwave.public_key' => 'FLW_PUB_KEY_MOCK',
            'services.nowpayments.api_key' => null,
            'pricing.safety_markup_percent' => 10.0,
            'pricing.min_margin_percent' => 1.0,
        ]);

        Cache::flush();

        CurrencyRate::create(['code' => 'USD', 'name' => 'United States Dollar', 'type' => 'fiat', 'rate_per_usd' => 1.0, 'is_active' => true]);

        $this->user = User::factory()->create(['email_verified_at' => now()]);
    }

    private function cancel(PaymentSession $session)
    {
        return $this->actingAs($this->user)->postJson(route('api.payment-sessions.cancel', $session->id));
    }

    private function fundingSession(): PaymentSession
    {
        Wallet::create(['user_id' => $this->user->id, 'currency' => Currency::USD, 'balance' => 0.0, 'is_active' => true]);

        $response = $this->actingAs($this->user)->postJson(route('api.wallets.fund.initiate'), [
            'currency' => 'USD',
            'amount' => 30.0,
            'display_currency' => 'USD',
        ])->assertOk();

        return PaymentSession::findOrFail($response->json('payment_session.id'));
    }

    private function walletOrderSession(): PaymentSession
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

        $cart = Cart::create(['user_id' => $this->user->id, 'status' => 'active', 'last_activity_at' => now()]);
        CartItem::create([
            'cart_id' => $cart->id,
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

        $order = app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $cart->load('items.product', 'items.variant'),
            paymentMethod: 'wallet',
            displayCurrency: 'USD',
        );

        return $order->paymentAttempts->first()->paymentSession;
    }

    /** THE one for wallet top-ups. */
    public function test_cancelling_a_wallet_top_up_marks_the_funding_cancelled(): void
    {
        $session = $this->fundingSession();
        $funding = WalletFunding::sole();
        $this->assertSame(FundingStatus::Pending, $funding->status);

        $this->cancel($session)
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $funding->refresh();
        $this->assertSame(FundingStatus::Cancelled, $funding->status);
        $this->assertNotNull($funding->processed_at);
        $this->assertNull($funding->failed_reason, 'a cancellation is not a failure');

        $this->assertSame('cancelled', $session->fresh()->status);
        $this->assertSame(PaymentStatus::Failed, $session->paymentAttempt->fresh()->payment_status);
    }

    /** A cancelled top-up is out of the hourly reconciler's reach. */
    public function test_a_cancelled_top_up_is_no_longer_pending_for_the_reconciler(): void
    {
        $session = $this->fundingSession();
        $this->cancel($session)->assertOk();

        $this->assertSame(0, WalletFunding::where('status', FundingStatus::Pending)->count());
    }

    /**
     * Wallet money a previous authorisation reserved comes back. This is the
     * state a confirm step that threw after the reservation leaves behind:
     * the attempt is Reserved, the session still live, the customer's balance
     * short by the hold.
     */
    public function test_cancelling_releases_wallet_funds_a_previous_authorisation_reserved(): void
    {
        app(TransactionPinService::class)->setupPin($this->user, self::PIN);
        $wallet = Wallet::create([
            'user_id' => $this->user->id,
            'currency' => Currency::USD,
            'balance' => 100.0,
            'locked_balance' => 0.0,
            'is_active' => true,
        ]);

        $session = $this->walletOrderSession();
        $attempt = $session->paymentAttempt;

        $token = $this->actingAs($this->user)
            ->postJson(route('api.wallets.pin.verify'), ['pin' => self::PIN])
            ->json('auth_token');

        // The authorisation went through and reserved the money; nothing after
        // it did (the session is still awaiting the customer).
        app(WalletPaymentProvider::class)->authorizeTransaction($attempt, $token);
        $this->assertEquals(11.0, (float) $wallet->fresh()->locked_balance);
        $this->assertSame(PaymentStatus::Reserved, $attempt->fresh()->payment_status);

        $this->cancel($session)->assertOk();

        $this->assertEquals(0.0, (float) $wallet->fresh()->locked_balance, 'the hold outlived the cancelled session');
        $this->assertEquals(100.0, (float) $wallet->fresh()->balance);
        $this->assertSame(PaymentStatus::Failed, $attempt->fresh()->payment_status);
        $this->assertSame('cancelled', $session->fresh()->status);
    }

    /** Rcoin a checkout locked comes back when the customer cancels. */
    public function test_cancelling_returns_the_rcoin_the_order_locked(): void
    {
        app(TransactionPinService::class)->setupPin($this->user, self::PIN);
        Wallet::create(['user_id' => $this->user->id, 'currency' => Currency::USD, 'balance' => 100.0, 'is_active' => true]);
        $rcoin = Wallet::create([
            'user_id' => $this->user->id,
            'currency' => Currency::RCOIN,
            'balance' => 5000,
            'locked_balance' => 0,
            'is_active' => true,
        ]);

        $session = $this->walletOrderSession();
        $order = Order::sole();

        // What a redemption at checkout leaves behind.
        $rcoin->update(['locked_balance' => 500]);
        $order->update(['metadata' => array_merge($order->metadata ?? [], ['rcoin_applied' => 500])]);

        $this->cancel($session)->assertOk();

        $this->assertEquals(0, (int) $rcoin->fresh()->locked_balance);
        $this->assertTrue($order->fresh()->metadata['rcoin_lock_released']);
    }

    /**
     * The order goes with its payment. A customer who cancelled at the card
     * popup used to leave an order Pending for good: its attempt was closed, so
     * the sweeper that looks for open attempts never saw it.
     */
    public function test_cancelling_an_orders_payment_cancels_the_order(): void
    {
        app(TransactionPinService::class)->setupPin($this->user, self::PIN);
        Wallet::create(['user_id' => $this->user->id, 'currency' => Currency::USD, 'balance' => 100.0, 'is_active' => true]);

        $session = $this->walletOrderSession();
        $order = Order::sole();
        $this->assertSame(OrderStatus::Pending, $order->order_status);

        $this->cancel($session)->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->order_status);
        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status, 'never paid, so not failed');
    }

    /** An order already paid by another route is never cancelled by a stale session's cancel. */
    public function test_cancelling_does_not_touch_an_order_that_was_paid_meanwhile(): void
    {
        app(TransactionPinService::class)->setupPin($this->user, self::PIN);
        Wallet::create(['user_id' => $this->user->id, 'currency' => Currency::USD, 'balance' => 100.0, 'is_active' => true]);

        $session = $this->walletOrderSession();
        $order = Order::sole();
        $order->update(['order_status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Paid]);

        $this->cancel($session)->assertOk();

        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
    }

    /**
     * A top-up is new money coming in; it cannot be paid from the balance it is
     * meant to raise. The currency's method list says wallet, and taking it
     * reserved the amount from the existing balance, failed at the fiat gateway
     * and never released the reservation.
     */
    public function test_a_wallet_top_up_cannot_be_paid_from_the_wallet(): void
    {
        app(TransactionPinService::class)->setupPin($this->user, self::PIN);
        $session = $this->fundingSession();
        $wallet = Wallet::sole();
        $wallet->update(['balance' => 100.0]);

        $token = $this->actingAs($this->user)
            ->postJson(route('api.wallets.pin.verify'), ['pin' => self::PIN])
            ->json('auth_token');

        $this->actingAs($this->user)
            ->postJson(route('api.payment-sessions.pay', $session->id), [
                'method' => 'wallet',
                'details' => ['auth_token' => $token],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The payment method wallet is not available for currency USD.');

        $this->assertEquals(0.0, (float) $wallet->fresh()->locked_balance, 'the top-up reserved money from the wallet it was raising');
        $this->assertSame(FundingStatus::Pending, WalletFunding::sole()->status);
        $this->assertSame('awaiting_method', $session->fresh()->status);
    }

    /** Unchanged: a session that is already settled or closed cannot be cancelled. */
    public function test_a_settled_session_cannot_be_cancelled(): void
    {
        $session = $this->fundingSession();
        $session->update(['status' => 'confirmed']);

        $this->cancel($session)
            ->assertStatus(400)
            ->assertJsonPath('message', 'Cannot cancel payment session that is already confirmed.');

        $this->assertSame(FundingStatus::Pending, WalletFunding::sole()->status, 'a refused cancel must not touch the funding');
    }
}
