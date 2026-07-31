<?php

namespace Tests\Feature\Payment;

use App\Domain\Order\Services\CheckoutService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Wallet\Services\TransactionPinService;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * We must know how a customer paid, not merely who processed it.
 *
 * `gateway` records only the processor, and Flutterwave fronts eight different
 * methods, so before this every card charge, USSD dial and Nigerian bank
 * transfer was filed identically. That makes "are cards failing?" unanswerable,
 * and a per-method kill switch impossible to justify or to verify after the
 * fact.
 *
 * The method is not always known when the attempt is created — wallet funding
 * mints the attempt first and picks a method afterwards — so these tests pin
 * both recording points and the rule that an unknown value never overwrites a
 * known one.
 */
class PaymentMethodTelemetryTest extends TestCase
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
            'services.nowpayments.api_key' => null,
            'pricing.safety_markup_percent' => 10.0,
            'pricing.min_margin_percent' => 1.0,
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

        Cache::flush();

        PricingRule::create([
            'markup_type' => 'fixed',
            'markup_value' => 1.00,
            'is_active' => true,
        ]);

        $this->cart = Cart::create([
            'user_id' => $this->user->id,
            'status' => 'active',
            'last_activity_at' => now(),
        ]);

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

        $this->cart->load('items.product', 'items.variant');
    }

    // ---------------------------------------------------------------- checkout

    public function test_checkout_records_the_method_the_customer_picked(): void
    {
        $order = app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart,
            paymentMethod: 'flutterwave',
            displayCurrency: 'USD',
            selectedMethod: 'bank_transfer',
        );

        $this->assertSame('bank_transfer', $order->paymentAttempts()->first()->payment_method);
    }

    public function test_a_gateway_that_fronts_one_method_needs_no_explicit_choice(): void
    {
        Wallet::create([
            'user_id' => $this->user->id,
            'currency' => Currency::USD,
            'balance' => 100.00,
            'locked_balance' => 0.00,
            'is_active' => true,
        ]);
        app(TransactionPinService::class)->setupPin($this->user, '5283');

        $order = app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart,
            paymentMethod: 'wallet',
            displayCurrency: 'USD',
        );

        // 'wallet' and 'crypto' are the gateway *and* the method, so the fallback
        // to the gateway name is exact rather than a guess.
        $this->assertSame('wallet', $order->paymentAttempts()->first()->payment_method);
    }

    public function test_crypto_is_recorded_from_the_gateway_without_a_chosen_coin(): void
    {
        $order = app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart,
            paymentMethod: 'crypto',
            displayCurrency: 'USD',
        );

        $this->assertSame('crypto', $order->paymentAttempts()->first()->payment_method);
    }

    public function test_flutterwave_without_a_chosen_method_records_nothing_rather_than_guessing(): void
    {
        // The API checkout endpoint only accepts gateway-level values, so the
        // method is genuinely unknown here. Recording 'card' would be a fiction
        // that later analysis would treat as fact.
        $order = app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart,
            paymentMethod: 'flutterwave',
            displayCurrency: 'USD',
        );

        $this->assertNull($order->paymentAttempts()->first()->payment_method);
    }

    public function test_the_web_checkout_route_threads_the_chosen_tile_through(): void
    {
        $response = $this->actingAs($this->user)
            ->withCookie('guest_token', 'irrelevant')
            ->post(route('checkout.process'), [
                'delivery_email' => $this->user->email,
                'payment_method' => 'ussd',
                'currency' => 'NGN',
            ]);

        $response->assertRedirect();

        $attempt = PaymentAttempt::where('user_id', $this->user->id)->latest()->first();
        $this->assertNotNull($attempt, 'Checkout did not create a payment attempt.');
        $this->assertSame('ussd', $attempt->payment_method);
        // The gateway is still the processor — the two are recorded separately.
        $this->assertSame('flutterwave', $attempt->gateway);
    }

    // ------------------------------------------------------------- session pay

    /**
     * @return array{0: PaymentSession, 1: PaymentAttempt}
     */
    private function flutterwaveSession(string $currency = 'NGN'): array
    {
        $order = Order::create([
            'user_id' => $this->user->id,
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
            'payment_method' => 'flutterwave',
        ]);

        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'gateway' => 'flutterwave',
            'idempotency_key' => (string) Str::uuid(),
            'currency' => $currency,
            'amount' => 5000,
            'payment_status' => PaymentStatus::Pending,
        ]);

        $session = PaymentSession::create([
            'payment_attempt_id' => $attempt->id,
            'provider' => 'flutterwave',
            'session_type' => 'card',
            'status' => 'pending',
            'client_reference' => 'SESS_'.Str::random(40),
            'amount' => 5000,
            'currency' => $currency,
            'display_currency' => $currency,
            'exchange_rate_snapshot' => 1.0,
            'customer_email' => $this->user->email,
            'expires_at' => now()->addMinutes(20),
        ]);

        return [$session, $attempt];
    }

    public function test_paying_a_session_records_the_method_the_customer_committed_to(): void
    {
        [$session, $attempt] = $this->flutterwaveSession();

        $this->assertNull($attempt->payment_method, 'Precondition: the method is unknown until the customer picks one.');

        $this->actingAs($this->user)
            ->postJson(route('api.payment-sessions.pay', $session->id), ['method' => 'bank_transfer']);

        $this->assertSame('bank_transfer', $attempt->fresh()->payment_method);
    }

    public function test_the_method_is_recorded_even_when_the_gateway_call_fails(): void
    {
        // The failing case is precisely the one we need visibility into: an
        // attempt that dies at the provider is evidence a method is broken, and
        // recording only on success would hide every outage from the telemetry.
        config(['services.flutterwave.secret_key' => 'FLWSECK_TEST-not-a-mock-key']);
        Http::fake(['*' => Http::response(['status' => 'error'], 500)]);

        [$session, $attempt] = $this->flutterwaveSession();

        $this->actingAs($this->user)
            ->postJson(route('api.payment-sessions.pay', $session->id), ['method' => 'bank_transfer']);

        $this->assertSame('bank_transfer', $attempt->fresh()->payment_method);
    }

    public function test_a_method_rejected_by_the_allow_list_is_never_recorded(): void
    {
        // USD does not offer bank transfer. A rejected method must not pollute
        // the telemetry with a choice the customer was never actually given.
        [$session, $attempt] = $this->flutterwaveSession('USD');

        $this->actingAs($this->user)
            ->postJson(route('api.payment-sessions.pay', $session->id), ['method' => 'bank_transfer'])
            ->assertStatus(422);

        $this->assertNull($attempt->fresh()->payment_method);
    }

    // ------------------------------------------------------ recording contract

    public function test_an_unrecognised_method_never_overwrites_one_we_know(): void
    {
        [, $attempt] = $this->flutterwaveSession();
        $attempt->recordPaymentMethod('card');

        $this->assertFalse($attempt->recordPaymentMethod('something-we-do-not-offer'));
        $this->assertSame('card', $attempt->fresh()->payment_method);
    }

    public function test_recording_is_idempotent(): void
    {
        [, $attempt] = $this->flutterwaveSession();

        $this->assertTrue($attempt->recordPaymentMethod('card'));
        $this->assertFalse($attempt->recordPaymentMethod('card'), 'A repeat of the same method is not a change.');
        $this->assertSame('card', $attempt->fresh()->payment_method);
    }

    public function test_a_customer_switching_methods_updates_the_record(): void
    {
        // Real behaviour on the funding sheet: pick card, fail, go back, pick
        // bank transfer. The attempt must reflect what they actually used.
        [, $attempt] = $this->flutterwaveSession();

        $attempt->recordPaymentMethod('card');
        $this->assertTrue($attempt->recordPaymentMethod('bank_transfer'));
        $this->assertSame('bank_transfer', $attempt->fresh()->payment_method);
    }

    public function test_a_failure_to_record_telemetry_never_breaks_the_payment(): void
    {
        // This runs on the live payment path, immediately before the gateway is
        // charged. If a write here could throw, an unrelated database hiccup
        // would turn "we lost a row of telemetry" into "the customer could not
        // pay" — so the failure is swallowed and logged instead.
        [, $attempt] = $this->flutterwaveSession();

        // Force the write to fail the way a real outage would: the underlying
        // table is gone by the time we try to save.
        Schema::drop('payment_attempts');

        Log::shouldReceive('warning')->once();

        $this->assertFalse($attempt->recordPaymentMethod('card'));
    }

    public function test_the_legacy_opay_key_is_stored_under_the_canonical_name(): void
    {
        // PaymentSession::getAvailableMethods() still emits 'opay' where the
        // checkout calls the same tile 'mobile_wallet'. Storing both spellings
        // would split one method across two rows in every report.
        [, $attempt] = $this->flutterwaveSession();

        $attempt->recordPaymentMethod('opay');

        $this->assertSame('mobile_wallet', $attempt->fresh()->payment_method);
    }
}
