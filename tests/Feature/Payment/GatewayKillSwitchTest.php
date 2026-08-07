<?php

namespace Tests\Feature\Payment;

use App\Domain\Order\Services\CheckoutService;
use App\Domain\Payment\Exceptions\GatewayDisabledException;
use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\Payment\Support\SupportedPaymentMethods;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Wallet\Services\WalletFundingService;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SiteSetting;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use App\Support\FeatureFlag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One admin toggle per payment gateway. The rule these tests pin: a disabled
 * gateway stops NEW money entering the pipe, before anything is written, and
 * money already in flight always lands. Getting the second half wrong strands
 * customers whose bank transfer is moving while the switch is off, which is
 * worse than the outage the switch exists to manage.
 */
class GatewayKillSwitchTest extends TestCase
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
        $this->cart->load('items.product', 'items.variant');
    }

    private function setGateway(string $gateway, bool $on): void
    {
        SiteSetting::put("features.gateway_{$gateway}_enabled", $on ? 'on' : 'off', 'features');
    }

    private function checkout(string $paymentMethod = 'flutterwave'): Order
    {
        return app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart->fresh()->load('items.product', 'items.variant'),
            paymentMethod: $paymentMethod,
            displayCurrency: 'USD',
        );
    }

    // ------------------------------------------------------------ the map

    /**
     * The drift guard. Every method key checkout can offer must resolve to a
     * gateway that has a kill switch; a new method added without a mapping
     * would silently bypass every guard in this file.
     */
    public function test_every_checkout_method_resolves_to_a_switchable_gateway(): void
    {
        foreach (SupportedPaymentMethods::allMethods() as $method) {
            $gateway = FeatureFlag::METHOD_GATEWAYS[$method] ?? null;

            $this->assertNotNull($gateway, "method '{$method}' has no gateway mapping");
            $this->assertArrayHasKey($gateway, FeatureFlag::GATEWAY_FLAGS, "gateway '{$gateway}' has no kill switch");
        }
    }

    public function test_flags_default_on_and_unknown_gateways_are_treated_as_on(): void
    {
        $this->assertTrue(FeatureFlag::gatewayEnabled('flutterwave'));
        $this->assertTrue(FeatureFlag::gatewayEnabled('nowpayments'));
        $this->assertTrue(FeatureFlag::gatewayEnabled('korapay'), 'a future gateway without a flag row must default on');
        $this->assertSame([], FeatureFlag::disabledGateways());
    }

    // ------------------------------------------------------- new money gated

    public function test_checkout_on_a_disabled_gateway_is_refused_before_any_write(): void
    {
        $this->setGateway('flutterwave', false);

        try {
            $this->checkout();
            $this->fail('checkout on a disabled gateway was allowed');
        } catch (GatewayDisabledException $e) {
            $this->assertStringContainsString('temporarily unavailable', $e->customerMessage());
            $this->assertStringNotContainsString('flutterwave', strtolower($e->customerMessage()), 'provider names never reach customers');
        }

        $this->assertSame(0, Order::count(), 'a refused checkout must cost zero rows');
        $this->assertSame(0, PaymentAttempt::count());
        $this->assertSame(0, PaymentSession::count());
    }

    public function test_crypto_checkout_maps_to_the_nowpayments_switch(): void
    {
        $this->setGateway('nowpayments', false);

        $this->expectException(GatewayDisabledException::class);
        $this->checkout('crypto');
    }

    public function test_disabling_one_gateway_leaves_the_others_payable(): void
    {
        $this->setGateway('nowpayments', false);

        $order = $this->checkout('flutterwave');

        $this->assertNotNull($order->id, 'an unrelated gateway was blocked');
    }

    public function test_wallet_funding_on_a_disabled_gateway_is_refused_before_any_write(): void
    {
        $wallet = Wallet::create([
            'user_id' => $this->user->id,
            'currency' => Currency::USD,
            'balance' => 0.00,
            'locked_balance' => 0.00,
            'is_active' => true,
        ]);

        $this->setGateway('flutterwave', false);

        $this->expectException(GatewayDisabledException::class);

        try {
            app(WalletFundingService::class)->initializeFunding($this->user, $wallet, 50.0, Currency::USD);
        } finally {
            $this->assertSame(0, WalletFunding::count(), 'a refused funding must cost zero rows');
        }
    }

    public function test_pay_endpoint_refuses_a_fresh_charge_on_a_disabled_gateway(): void
    {
        $order = $this->checkout();
        $session = $order->paymentAttempts->first()->paymentSession;

        $this->setGateway('flutterwave', false);

        $response = $this->actingAs($this->user)->postJson("/api/payment-sessions/{$session->id}/pay", [
            'method' => 'card',
        ]);

        $response->assertStatus(503)
            ->assertJson(['gateway_disabled' => true]);
    }

    // -------------------------------------------------- in-flight money lands

    /**
     * The critical half of the rule. A customer authorised their payment,
     * THEN the admin flipped the switch: the confirmation must still land,
     * the order must still be paid.
     */
    public function test_an_in_flight_payment_still_confirms_while_its_gateway_is_disabled(): void
    {
        $order = $this->checkout();
        $session = $order->paymentAttempts->first()->paymentSession;

        $this->setGateway('flutterwave', false);

        app(PaymentSessionService::class)->confirmSession($session, ['transaction_id' => 'FLW_'.Str::random(8)]);

        $this->assertSame('confirmed', $session->fresh()->status, 'in-flight money was stranded by the kill switch');
    }

    // ----------------------------------------------------------- the frontend

    public function test_checkout_page_shows_the_outage_notice_when_a_gateway_is_off(): void
    {
        $this->setGateway('flutterwave', false);

        $response = $this->actingAs($this->user)->get('/checkout');

        $response->assertOk()
            ->assertSee('Card and bank payments', false)
            ->assertSee('temporarily unavailable', false);
    }

    public function test_checkout_page_shows_no_notice_when_all_gateways_are_on(): void
    {
        $this->actingAs($this->user)->get('/checkout')
            ->assertOk()
            ->assertDontSee('temporarily unavailable', false);
    }
}
