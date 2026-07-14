<?php

namespace Tests\Feature;

use App\Domain\Shared\Enums\Currency;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class EmbeddedPaymentOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.zendit.api_key' => 'ZENDIT_API_KEY_MOCK',
            'services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK',
            'services.flutterwave.public_key' => 'FLW_PUB_KEY_MOCK',
            'services.flutterwave.direct_charge_enabled' => true,
            'services.nowpayments.api_key' => 'NOWPAYMENTS_KEY_MOCK',
            'pricing.safety_markup_percent' => 10.0,
            'pricing.min_margin_percent' => 1.0,
        ]);

        $this->user = User::factory()->create();

        $this->wallet = Wallet::create([
            'user_id' => $this->user->id,
            'currency' => Currency::USD,
            'balance' => 100.0,
        ]);

        CurrencyRate::create([
            'code' => 'USD',
            'name' => 'United States Dollar',
            'type' => 'fiat',
            'rate_per_usd' => 1.0,
            'is_active' => true,
        ]);

        CurrencyRate::create([
            'code' => 'NGN',
            'name' => 'Nigerian Naira',
            'type' => 'fiat',
            'rate_per_usd' => 1400.0,
            'is_active' => true,
        ]);

        CurrencyRate::create([
            'code' => 'XAF',
            'name' => 'CFA Franc BEAC',
            'type' => 'fiat',
            'rate_per_usd' => 600.0,
            'is_active' => true,
        ]);
    }

    /**
     * Test initiating wallet funding returns structured payment session payload.
     */
    public function test_initiating_wallet_funding_returns_payment_session(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('api.wallets.fund.initiate'), [
            'currency' => 'USD',
            'amount' => 50.0,
            'display_currency' => 'NGN',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'payment_link',
                'payment_session' => [
                    'id',
                    'provider',
                    'session_type',
                    'status',
                    'client_reference',
                    'amount',
                    'currency',
                    'display_currency',
                    'payment_payload',
                    'expires_at',
                ],
                'reference',
                'requested_amount',
                'display_currency',
                'exchange_rate',
            ]);

        $sessionId = $response->json('payment_session.id');

        $this->assertDatabaseHas('payment_sessions', [
            'id' => $sessionId,
            'provider' => 'flutterwave',
            'status' => 'awaiting_method',
            'amount' => 70000.0000, // 50 USD converted to NGN
            'display_currency' => 'USD',
        ]);
    }

    /**
     * Test polling payment session show and status endpoints.
     */
    public function test_can_poll_payment_session_show_and_status(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('api.wallets.fund.initiate'), [
            'currency' => 'USD',
            'amount' => 20.0,
            'display_currency' => 'USD',
        ]);

        $sessionId = $response->json('payment_session.id');

        // Test Show endpoint
        $showResponse = $this->actingAs($this->user)->getJson(route('api.payment-sessions.show', $sessionId));
        $showResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'awaiting_method')
            ->assertJsonPath('data.provider', 'flutterwave');

        // Test Status poll endpoint
        $statusResponse = $this->actingAs($this->user)->getJson(route('api.payment-sessions.status', $sessionId));
        $statusResponse->assertStatus(200)
            ->assertExactJson([
                'id' => $sessionId,
                'status' => 'awaiting_method',
                'is_expired' => false,
                'confirmed_at' => null,
                'failed_at' => null,
            ]);
    }

    /**
     * Test active payment session can be cancelled.
     */
    public function test_can_cancel_payment_session(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('api.wallets.fund.initiate'), [
            'currency' => 'USD',
            'amount' => 30.0,
            'display_currency' => 'USD',
        ]);

        $sessionId = $response->json('payment_session.id');

        $cancelResponse = $this->actingAs($this->user)->postJson(route('api.payment-sessions.cancel', $sessionId));
        $cancelResponse->assertStatus(200)
            ->assertJson([
                'message' => 'Payment session cancelled successfully.',
                'status' => 'cancelled',
            ]);

        $this->assertDatabaseHas('payment_sessions', [
            'id' => $sessionId,
            'status' => 'cancelled',
        ]);

        $session = PaymentSession::find($sessionId);
        $this->assertDatabaseHas('payment_attempts', [
            'id' => $session->payment_attempt_id,
            'payment_status' => 'failed',
        ]);
    }

    /**
     * Test direct model transition to invalid state throws domain exception.
     */
    public function test_payment_session_state_machine_validation(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('api.wallets.fund.initiate'), [
            'currency' => 'USD',
            'amount' => 10.0,
            'display_currency' => 'USD',
        ]);

        $sessionId = $response->json('payment_session.id');
        $session = PaymentSession::find($sessionId);

        // Try direct transition pending -> confirmed (illegal)
        $session->status = 'pending';
        $session->save();

        $this->expectException(\DomainException::class);
        $session->transitionTo('confirmed');
    }

    /**
     * Test order checkout creates order and structured payment session.
     */
    public function test_order_checkout_generates_payment_session(): void
    {
        // 1. Setup storefront categories and product
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

        $cart = Cart::create([
            'user_id' => $this->user->id,
            'status' => 'active',
            'last_activity_at' => now(),
        ]);

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

        $response = $this->actingAs($this->user)->postJson(route('api.checkout.place-order'), [
            'cart_id' => $cart->id,
            'payment_method' => 'flutterwave',
            'preferred_currency' => 'USD',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'order' => ['id', 'order_number', 'total_amount'],
                'payment_session' => ['id', 'provider', 'session_type', 'status', 'payment_payload'],
            ]);

        $sessionId = $response->json('payment_session.id');
        $this->assertDatabaseHas('payment_sessions', [
            'id' => $sessionId,
            'provider' => 'flutterwave',
            'status' => 'awaiting_method',
        ]);
    }

    /**
     * Test immediate card payment confirmation when no mock auth mode is triggered.
     */
    public function test_card_payment_immediate_success(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('api.wallets.fund.initiate'), [
            'currency' => 'USD',
            'amount' => 10.0,
            'display_currency' => 'USD',
        ]);

        $sessionId = $response->json('payment_session.id');

        $payResponse = $this->actingAs($this->user)->postJson(route('api.payment-sessions.pay', $sessionId), [
            'method' => 'card',
            'details' => [
                'card_number' => '4000 1111 2222 3333',
                'cvv' => '123',
                'expiry_month' => '12',
                'expiry_year' => '28',
            ],
        ]);

        $payResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertDatabaseHas('payment_sessions', [
            'id' => $sessionId,
            'status' => 'confirmed',
        ]);
    }

    /**
     * Test card payment requiring PIN flow.
     */
    public function test_card_payment_pin_required(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('api.wallets.fund.initiate'), [
            'currency' => 'USD',
            'amount' => 10.0,
            'display_currency' => 'USD',
        ]);

        $sessionId = $response->json('payment_session.id');

        // Charge card with 5555 to trigger PIN authentication
        $payResponse = $this->actingAs($this->user)->postJson(route('api.payment-sessions.pay', $sessionId), [
            'method' => 'card',
            'details' => [
                'card_number' => '5555 5555 5555 5555',
                'cvv' => '123',
                'expiry_month' => '12',
                'expiry_year' => '28',
            ],
        ]);

        $payResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'awaiting_customer_action')
            ->assertJsonPath('data.payment_payload.action', 'pin');

        // Submit PIN
        $pinResponse = $this->actingAs($this->user)->postJson(route('api.payment-sessions.pay', $sessionId), [
            'method' => 'card',
            'pin' => '1234',
            'details' => [
                'card_number' => '5555 5555 5555 5555',
                'cvv' => '123',
                'expiry_month' => '12',
                'expiry_year' => '28',
            ],
        ]);

        $pinResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'confirmed');
    }

    /**
     * Test card payment requiring OTP flow.
     */
    public function test_card_payment_otp_required(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('api.wallets.fund.initiate'), [
            'currency' => 'USD',
            'amount' => 10.0,
            'display_currency' => 'USD',
        ]);

        $sessionId = $response->json('payment_session.id');

        // Charge card with 7777 to trigger OTP authentication
        $payResponse = $this->actingAs($this->user)->postJson(route('api.payment-sessions.pay', $sessionId), [
            'method' => 'card',
            'details' => [
                'card_number' => '7777 7777 7777 7777',
                'cvv' => '123',
                'expiry_month' => '12',
                'expiry_year' => '28',
            ],
        ]);

        $payResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'awaiting_customer_action')
            ->assertJsonPath('data.payment_payload.action', 'otp');

        $flwRef = $payResponse->json('data.payment_payload.flw_ref');

        // Submit OTP
        $otpResponse = $this->actingAs($this->user)->postJson(route('api.payment-sessions.pay', $sessionId), [
            'method' => 'card',
            'otp' => '123456',
            'flw_ref' => $flwRef,
        ]);

        $otpResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'confirmed');
    }

    /**
     * Test bank transfer flow.
     */
    public function test_bank_transfer_flow(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('api.wallets.fund.initiate'), [
            'currency' => 'NGN',
            'amount' => 5000.0,
            'display_currency' => 'NGN',
        ]);

        $sessionId = $response->json('payment_session.id');

        $payResponse = $this->actingAs($this->user)->postJson(route('api.payment-sessions.pay', $sessionId), [
            'method' => 'bank_transfer',
        ]);

        $payResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'awaiting_transfer')
            ->assertJsonStructure([
                'data' => [
                    'payment_payload' => [
                        'bank_details' => [
                            'bank_name',
                            'account_number',
                            'account_name',
                            'amount',
                        ],
                    ],
                ],
            ]);
    }

    /**
     * Test mobile money flow.
     */
    public function test_mobile_money_flow(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('api.wallets.fund.initiate'), [
            'currency' => 'XAF',
            'amount' => 5000.0,
            'display_currency' => 'XAF',
        ]);

        $sessionId = $response->json('payment_session.id');

        $payResponse = $this->actingAs($this->user)->postJson(route('api.payment-sessions.pay', $sessionId), [
            'method' => 'mobile_money',
            'details' => [
                'phone_number' => '237671234567',
                'network' => 'mtn',
            ],
        ]);

        $payResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'awaiting_confirmation')
            ->assertJsonStructure([
                'data' => [
                    'payment_payload' => [
                        'status',
                        'message',
                    ],
                ],
            ]);
    }

    /**
     * Test crypto payment flow.
     */
    public function test_crypto_payment_flow(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('api.wallets.fund.initiate'), [
            'currency' => 'USD',
            'amount' => 10.0,
            'display_currency' => 'USD',
        ]);

        $sessionId = $response->json('payment_session.id');

        // Note: Wallet funding uses flutterwave by default as defined in route, but we can update
        // gateway of the attempt to 'crypto' dynamically for this test.
        $session = PaymentSession::find($sessionId);
        $session->paymentAttempt->update(['gateway' => 'crypto']);

        $payResponse = $this->actingAs($this->user)->postJson(route('api.payment-sessions.pay', $sessionId), [
            'method' => 'crypto',
            'details' => [
                'pay_currency' => 'USDT',
            ],
        ]);

        $payResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'awaiting_transfer')
            ->assertJsonStructure([
                'data' => [
                    'payment_payload' => [
                        'status',
                        'pay_address',
                        'pay_amount',
                        'pay_currency',
                        'network',
                    ],
                ],
            ]);
    }

    /**
     * Test Apple Pay flow.
     */
    public function test_apple_pay_flow(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('api.wallets.fund.initiate'), [
            'currency' => 'USD',
            'amount' => 10.0,
            'display_currency' => 'USD',
        ]);

        $sessionId = $response->json('payment_session.id');

        $payResponse = $this->actingAs($this->user)->postJson(route('api.payment-sessions.pay', $sessionId), [
            'method' => 'apple_pay',
        ]);

        $payResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'confirmed');
    }

    /**
     * Test currency to payment method constraint validations.
     */
    public function test_currency_to_payment_method_validation_errors(): void
    {
        // 1. Initiate USD funding (should reject mobile_money and bank_transfer)
        $usdResponse = $this->actingAs($this->user)->postJson(route('api.wallets.fund.initiate'), [
            'currency' => 'USD',
            'amount' => 10.0,
            'display_currency' => 'USD',
        ]);
        $usdSessionId = $usdResponse->json('payment_session.id');

        $payMobileMoney = $this->actingAs($this->user)->postJson(route('api.payment-sessions.pay', ['id' => $usdSessionId]), [
            'method' => 'mobile_money',
            'details' => [
                'phone_number' => '237671234567',
                'network' => 'mtn',
            ],
        ]);
        $payMobileMoney->assertStatus(422)
            ->assertJsonFragment(['message' => 'The payment method mobile_money is not available for currency USD.']);

        // 2. Initiate NGN funding (should reject mobile_money)
        $ngnResponse = $this->actingAs($this->user)->postJson(route('api.wallets.fund.initiate'), [
            'currency' => 'NGN',
            'amount' => 5000.0,
            'display_currency' => 'NGN',
        ]);
        $ngnSessionId = $ngnResponse->json('payment_session.id');

        $payMobileMoneyNgn = $this->actingAs($this->user)->postJson(route('api.payment-sessions.pay', ['id' => $ngnSessionId]), [
            'method' => 'mobile_money',
            'details' => [
                'phone_number' => '237671234567',
                'network' => 'mtn',
            ],
        ]);
        $payMobileMoneyNgn->assertStatus(422)
            ->assertJsonFragment(['message' => 'The payment method mobile_money is not available for currency NGN.']);

        // 3. Initiate GBP funding (should reject bank_transfer)
        $gbpResponse = $this->actingAs($this->user)->postJson(route('api.wallets.fund.initiate'), [
            'currency' => 'GBP',
            'amount' => 10.0,
            'display_currency' => 'GBP',
        ]);
        $gbpSessionId = $gbpResponse->json('payment_session.id');

        $payBankTransfer = $this->actingAs($this->user)->postJson(route('api.payment-sessions.pay', ['id' => $gbpSessionId]), [
            'method' => 'bank_transfer',
        ]);
        $payBankTransfer->assertStatus(422)
            ->assertJsonFragment(['message' => 'The payment method bank_transfer is not available for currency GBP.']);
    }

    /**
     * Test that crypto is accepted on non-USD currencies (e.g. NGN, GBP).
     */
    public function test_global_crypto_support(): void
    {
        $ngnResponse = $this->actingAs($this->user)->postJson(route('api.wallets.fund.initiate'), [
            'currency' => 'NGN',
            'amount' => 5000.0,
            'display_currency' => 'NGN',
        ]);
        $ngnSessionId = $ngnResponse->json('payment_session.id');

        $payCrypto = $this->actingAs($this->user)->postJson(route('api.payment-sessions.pay', ['id' => $ngnSessionId]), [
            'method' => 'crypto',
            'details' => [
                'pay_currency' => 'USDT',
            ],
        ]);
        $payCrypto->assertStatus(200)
            ->assertJsonPath('data.status', 'awaiting_transfer');
    }

    /**
     * Test card payment inline initialization flow.
     */
    public function test_card_payment_inline_flow(): void
    {
        $this->withoutExceptionHandling();
        config(['services.flutterwave.direct_charge_enabled' => false]);

        $response = $this->actingAs($this->user)->postJson(route('api.wallets.fund.initiate'), [
            'currency' => 'USD',
            'amount' => 10.0,
            'display_currency' => 'USD',
        ]);

        $sessionId = $response->json('payment_session.id');

        $payResponse = $this->actingAs($this->user)->postJson(route('api.payment-sessions.pay', $sessionId), [
            'method' => 'card',
            'details' => [], // Inline flow doesn't send card details
        ]);

        $payResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonStructure([
                'data' => [
                    'payment_payload' => [
                        'inline' => [
                            'public_key',
                            'tx_ref',
                            'amount',
                            'currency',
                            'customer' => [
                                'email',
                                'name',
                            ],
                        ],
                    ],
                ],
            ]);
    }
}
