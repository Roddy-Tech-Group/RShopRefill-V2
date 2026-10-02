<?php

namespace Tests\Feature\Checkout;

use App\Domain\Order\Services\CheckoutService;
use App\Domain\Wallet\Exceptions\UnknownCurrencyException;
use App\Domain\Wallet\Services\CurrencyRateService;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\CurrencyRate;
use App\Models\Order;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Three letters are not a currency.
 *
 * The rate table used to answer a code it did not know with the USD row, so a
 * conversion to "ZZZ" happened at 1.0: a $5 cart posted as ZZZ became an order
 * for 5.00 ZZZ and that figure went to the gateway. Every door into checkout and
 * wallet funding now refuses a currency without a live, active rate, and the
 * rate service itself refuses too, so a door added later cannot reopen it.
 */
class CheckoutCurrencyGuardTest extends TestCase
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

        CurrencyRate::create(['code' => 'USD', 'name' => 'United States Dollar', 'type' => 'fiat', 'rate_per_usd' => 1.0, 'is_active' => true]);
        CurrencyRate::create(['code' => 'ZAR', 'name' => 'South African Rand', 'type' => 'fiat', 'rate_per_usd' => 18.5, 'is_active' => true]);
        CurrencyRate::create(['code' => 'HRK', 'name' => 'Croatian Kuna', 'type' => 'fiat', 'rate_per_usd' => 6.95, 'is_active' => false]);
        // The migrations already seed the coins; make sure this one is live.
        CurrencyRate::updateOrCreate(['code' => 'BTC'], ['name' => 'Bitcoin', 'type' => 'crypto', 'rate_per_usd' => 0.00001, 'is_active' => true]);

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

    private function webCheckout(string $currency, string $method = 'card'): TestResponse
    {
        return $this->actingAs($this->user)->postJson(route('checkout.process'), [
            'delivery_email' => $this->user->email,
            'payment_method' => $method,
            'currency' => $currency,
        ]);
    }

    private function apiCheckout(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->user)->postJson(route('api.checkout.place-order'), $overrides + [
            'cart_id' => $this->cart->id,
            'payment_method' => 'flutterwave',
            'preferred_currency' => 'USD',
        ]);
    }

    // ------------------------------------------------------------ the service

    /**
     * THE one. The transactional resolver must refuse, not substitute. Before
     * this it returned 1.0 here, which is how a $5 order became 5.00 ZZZ.
     */
    public function test_the_transactional_rate_resolver_refuses_a_currency_it_does_not_know(): void
    {
        $this->expectException(UnknownCurrencyException::class);

        app(CurrencyRateService::class)->resolveRate('USD', 'ZZZ');
    }

    public function test_the_transactional_rate_resolver_refuses_a_deactivated_currency(): void
    {
        $this->expectException(UnknownCurrencyException::class);

        app(CurrencyRateService::class)->resolveRate('USD', 'HRK');
    }

    public function test_the_transactional_rate_resolver_still_prices_a_live_currency(): void
    {
        $this->assertEqualsWithDelta(18.5, app(CurrencyRateService::class)->resolveRate('USD', 'ZAR'), 0.0001);
        $this->assertEqualsWithDelta(1.0, app(CurrencyRateService::class)->resolveRate('ZAR', 'ZAR'), 0.0001);
    }

    /**
     * Read-only displays keep the old leniency: a price label in dollars is
     * better than no page, and nothing is charged off it.
     */
    public function test_the_display_rate_resolver_still_falls_back_for_an_unknown_currency(): void
    {
        $this->assertEqualsWithDelta(1.0, app(CurrencyRateService::class)->resolveRateForDisplay('USD', 'ZZZ'), 0.0001);
    }

    /**
     * The service behind every checkout door is fail-closed on its own, so a
     * controller added later that forgets to validate still cannot charge in
     * a currency we hold no rate for.
     */
    public function test_place_order_itself_refuses_an_unknown_currency(): void
    {
        $this->cart->load('items.product', 'items.variant');

        try {
            app(CheckoutService::class)->placeOrder(
                user: $this->user,
                cart: $this->cart,
                paymentMethod: 'flutterwave',
                displayCurrency: 'ZZZ',
            );
            $this->fail('placeOrder accepted a currency with no rate');
        } catch (UnknownCurrencyException) {
            // expected
        }

        $this->assertSame(0, Order::count(), 'an order was written for a currency with no rate');
    }

    // -------------------------------------------------------- the web form

    public function test_the_web_form_refuses_a_currency_with_no_rate(): void
    {
        $this->webCheckout('ZZZ')
            ->assertStatus(422)
            ->assertJsonPath('message', 'That currency is not available right now. Please choose another currency and try again.');

        $this->assertSame(0, Order::count());
    }

    public function test_the_web_form_refuses_a_deactivated_currency(): void
    {
        $this->webCheckout('HRK')->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    /** A coin is a way to pay, not a currency to be billed in. */
    public function test_the_web_form_refuses_a_crypto_code_as_the_charge_currency(): void
    {
        $this->webCheckout('BTC')->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    public function test_the_web_form_still_charges_in_a_live_currency(): void
    {
        $this->webCheckout('zar')->assertOk();

        $order = Order::sole();
        $this->assertSame('ZAR', $order->display_currency);
        // $11.00 at 18.5 is R203.50, charm-rounded up to the rand.
        $this->assertEqualsWithDelta(204.0, (float) $order->total_amount, 0.0001);
    }

    // -------------------------------------------------------- the API route

    public function test_the_api_route_refuses_a_currency_with_no_rate(): void
    {
        $this->apiCheckout(['preferred_currency' => 'ZZZ'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['preferred_currency']);

        $this->assertSame(0, Order::count());
    }

    public function test_the_api_route_refuses_a_deactivated_currency(): void
    {
        $this->apiCheckout(['preferred_currency' => 'HRK'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['preferred_currency']);
    }

    /**
     * The method has to be one the currency can take, the check the web form
     * always made and this route never did. ZAR has no rail list of its own, so
     * only a card can take it: wallet and crypto are refused, card goes through.
     */
    public function test_the_api_route_refuses_a_method_the_currency_cannot_take(): void
    {
        $this->apiCheckout(['preferred_currency' => 'ZAR', 'payment_method' => 'crypto'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The selected payment method is not available for ZAR.');

        Wallet::create(['user_id' => $this->user->id, 'currency' => 'USD', 'balance' => 1000.0, 'is_active' => true]);

        $this->apiCheckout(['preferred_currency' => 'ZAR', 'payment_method' => 'wallet'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The selected payment method is not available for ZAR.');

        $this->assertSame(0, Order::count());
    }

    public function test_the_api_route_still_charges_in_a_live_currency_however_it_is_spelt(): void
    {
        $this->apiCheckout(['preferred_currency' => 'zar'])->assertCreated();

        $this->assertSame('ZAR', Order::sole()->display_currency);
    }

    // ----------------------------------------------------- wallet funding

    public function test_wallet_funding_refuses_a_display_currency_with_no_rate(): void
    {
        Wallet::create(['user_id' => $this->user->id, 'currency' => 'USD', 'balance' => 0.0, 'is_active' => true]);

        $this->actingAs($this->user)->postJson(route('api.wallets.fund.initiate'), [
            'currency' => 'USD',
            'amount' => 50.0,
            'display_currency' => 'ZZZ',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['display_currency']);

        $this->assertSame(0, WalletFunding::count());
    }

    /**
     * Rcoin is earned and redeemed, never bought. The enum rule alone let a
     * caller open a funding on the rewards wallet, priced at whatever the
     * fallback said a point was worth.
     */
    public function test_wallet_funding_refuses_the_rewards_wallet(): void
    {
        $this->actingAs($this->user)->postJson(route('api.wallets.fund.initiate'), [
            'currency' => 'RCOIN',
            'amount' => 50.0,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['currency']);

        $this->assertSame(0, WalletFunding::count());
    }
}
