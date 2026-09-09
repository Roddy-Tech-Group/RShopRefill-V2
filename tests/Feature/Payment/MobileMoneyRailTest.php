<?php

namespace Tests\Feature\Payment;

use App\Domain\Order\Services\CheckoutService;
use App\Domain\Payment\Providers\FlutterwavePaymentProvider;
use App\Domain\Payment\Support\FundingMethodCatalog;
use App\Domain\Payment\Support\MobileMoneyRail;
use App\Domain\Payment\Support\SupportedPaymentMethods;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\CurrencyRate;
use App\Models\Order;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SiteSetting;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Flutterwave charges mobile money on one rail per market and each rail
 * accepts only its own currency. The provider used to send every currency to
 * the Francophone rail with the country fixed to Cameroon, so a Ghana customer
 * paying GHS was refused with "This payment option is not allowed for your
 * currency" on every attempt (five in twenty minutes, on 2026-09-09) while the
 * checkout kept offering the method. These tests pin the rail each currency
 * takes, the network codes it is sent, and that a screen offering a network
 * the market lacks is refused before anything reaches the gateway.
 */
class MobileMoneyRailTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Cart $cart;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.zendit.api_key' => 'ZENDIT_API_KEY_MOCK',
            // A real-looking key: the mock branch answers nothing useful about
            // the request shape, and Http::fake intercepts the live path.
            'services.flutterwave.secret_key' => 'FLWSECK_TEST-realish',
            'services.flutterwave.public_key' => 'FLWPUBK_TEST-realish',
            'pricing.safety_markup_percent' => 10.0,
            'pricing.min_margin_percent' => 1.0,
        ]);

        SiteSetting::put('features.gateway_flutterwave_enabled', 'on', 'features');

        $this->user = User::factory()->create();

        foreach (['GHS' => 15.5, 'KES' => 129.0, 'XAF' => 600.0, 'XOF' => 600.0] as $code => $rate) {
            CurrencyRate::create(['code' => $code, 'name' => $code, 'type' => 'fiat', 'rate_per_usd' => $rate, 'is_active' => true]);
        }

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

    private function checkout(string $currency): Order
    {
        return app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart->fresh()->load('items.product', 'items.variant'),
            paymentMethod: 'flutterwave',
            displayCurrency: $currency,
        );
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function pay(Order $order, array $details): TestResponse
    {
        $session = $order->paymentAttempts->first()->paymentSession;

        return $this->actingAs($this->user)->postJson("/api/payment-sessions/{$session->id}/pay", [
            'method' => 'mobile_money',
            'details' => $details,
        ]);
    }

    /** A rail that answers with its own authorization page (Ghana, Uganda, Rwanda). */
    private function fakeRedirectRail(): void
    {
        Http::fake([
            'api.flutterwave.com/v3/charges*' => Http::response([
                'status' => 'success',
                'message' => 'Charge initiated',
                'data' => ['status' => 'pending'],
                'meta' => ['authorization' => ['mode' => 'redirect', 'redirect' => 'https://checkout.flutterwave.com/v3/hosted/pay/momo-gh']],
            ]),
        ]);
    }

    /** A rail that pushes a prompt to the handset (Francophone, M-Pesa). */
    private function fakePushRail(): void
    {
        Http::fake([
            'api.flutterwave.com/v3/charges*' => Http::response([
                'status' => 'success',
                'message' => 'Charge initiated',
                'data' => ['status' => 'pending', 'processor_response' => 'Approve the payment prompt sent to your phone.'],
            ]),
        ]);
    }

    private function sentCharge(): Request
    {
        $sent = null;
        Http::assertSent(function (Request $request) use (&$sent) {
            $sent = $request;

            return true;
        });

        return $sent;
    }

    public function test_ghana_is_charged_on_the_ghana_rail_with_the_gateway_network_code(): void
    {
        $this->fakeRedirectRail();

        $order = $this->checkout('GHS');
        $session = $order->paymentAttempts->first()->paymentSession;

        $response = $this->pay($order, ['phone_number' => '+233 24 400 0000', 'network' => 'Vodafone']);

        $response->assertOk();
        $this->assertSame('awaiting_redirect', $response->json('data.status'));
        $this->assertSame('https://checkout.flutterwave.com/v3/hosted/pay/momo-gh', $response->json('data.payment_payload.redirect_url'));

        $request = $this->sentCharge();
        $this->assertStringContainsString('/charges?type=mobile_money_ghana', $request->url());
        $this->assertSame('GHS', $request['currency']);
        $this->assertSame('VODAFONE', $request['network'], 'the rail wants its own code, not the label the screen shows');
        $this->assertSame('233244000000', $request['phone_number'], 'plus sign and spacing stripped');
        $this->assertArrayNotHasKey('country', $request->data(), 'country is a Francophone-rail field');
        $this->assertSame(route('shop.checkout.return', ['session' => $session->id]), $request['redirect_url']);
    }

    public function test_kenya_is_charged_on_mpesa_with_no_network(): void
    {
        $this->fakePushRail();

        $response = $this->pay($this->checkout('KES'), ['phone_number' => '0712345678']);

        $response->assertOk();
        $this->assertSame('awaiting_confirmation', $response->json('data.status'));
        $this->assertSame('Approve the payment prompt sent to your phone.', $response->json('data.payment_payload.message'));

        $request = $this->sentCharge();
        $this->assertStringContainsString('/charges?type=mpesa', $request->url());
        $this->assertArrayNotHasKey('network', $request->data());
        $this->assertArrayNotHasKey('country', $request->data());
    }

    public function test_the_francophone_rail_keeps_its_country_and_reads_it_from_the_number(): void
    {
        $this->fakePushRail();

        // Unchanged behaviour for the corridor that already worked.
        $this->pay($this->checkout('XAF'), ['phone_number' => '237670000000', 'network' => 'mtn'])->assertOk();

        $request = $this->sentCharge();
        $this->assertStringContainsString('/charges?type=mobile_money_franco', $request->url());
        $this->assertSame('CM', $request['country']);
        $this->assertSame('MTN', $request['network'], 'case-insensitive on the way in, the rail code on the way out');

        // XOF used to be sent as Cameroon too. The dialling prefix decides.
        $this->assertSame('SN', MobileMoneyRail::countryFor('XOF', '+221 77 000 0000'));
        $this->assertSame('CI', MobileMoneyRail::countryFor('XOF', '0700000000'), 'a local number falls back to the rail default');
        $this->assertSame('CM', MobileMoneyRail::countryFor('XAF', '670000000'));
        $this->assertNull(MobileMoneyRail::countryFor('GHS', '+233244000000'));
    }

    public function test_a_network_the_market_does_not_have_is_refused_before_the_gateway_is_called(): void
    {
        Http::fake();

        $order = $this->checkout('GHS');

        $this->pay($order, ['phone_number' => '0244000000', 'network' => 'Orange'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Choose a mobile money network available in GHS.');

        Http::assertNothingSent();
        $this->assertSame('awaiting_method', $order->paymentAttempts->first()->paymentSession->fresh()->status, 'a stale screen must not cost the customer their order');
    }

    public function test_a_gateway_refusal_is_recorded_as_a_failed_charge_with_the_provider_message(): void
    {
        // The exact answer the Francophone rail gave every Ghana attempt.
        Http::fake([
            'api.flutterwave.com/v3/charges*' => Http::response([
                'status' => 'error',
                'message' => 'This payment option is not allowed for your currency',
            ], 400),
        ]);

        $order = $this->checkout('GHS');
        $response = $this->pay($order, ['phone_number' => '0244000000', 'network' => 'MTN']);

        $response->assertOk();
        $this->assertSame('failed', $response->json('data.status'));
        // The customer sees the gateway's reason, and the structured verdict
        // the outcome classifier reads keeps the provider's verbatim message.
        $this->assertStringContainsString('This payment option is not allowed for your currency', $response->json('data.payment_payload.failure_reason'));

        $metadata = $order->paymentAttempts->first()->paymentSession->fresh()->metadata;
        $this->assertSame('This payment option is not allowed for your currency', $metadata['failure_details']['provider_message'] ?? null);
    }

    public function test_every_currency_that_offers_mobile_money_has_a_rail(): void
    {
        foreach (SupportedPaymentMethods::knownCurrencies() as $currency) {
            if (SupportedPaymentMethods::allows($currency, 'mobile_money')) {
                $this->assertTrue(MobileMoneyRail::supports($currency), "{$currency} is offered mobile money but no rail charges it");
            }
        }

        $this->assertFalse(MobileMoneyRail::supports('USD'));
    }

    public function test_the_provider_refuses_a_currency_without_a_rail_instead_of_calling_the_gateway(): void
    {
        Http::fake();

        $order = $this->checkout('GHS');
        $attempt = $order->paymentAttempts->first();
        $attempt->forceFill(['currency' => 'USD'])->save();

        $result = app(FlutterwavePaymentProvider::class)->chargeMobileMoney($attempt->fresh(), '0244000000', 'MTN');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('Mobile money is not available in USD.', $result['message']);
        Http::assertNothingSent();
    }

    public function test_the_funding_sheet_offers_exactly_the_networks_the_rail_accepts(): void
    {
        $momo = fn (string $currency) => collect(FundingMethodCatalog::forCurrency($currency))->firstWhere('type', 'mobile_money');

        $this->assertSame(['MTN', 'Vodafone', 'AirtelTigo'], $momo('GHS')['supported_networks']);
        $this->assertSame('MTN, Vodafone, AirtelTigo Mobile Money', $momo('GHS')['description']);
        $this->assertSame([], $momo('KES')['supported_networks'], 'M-Pesa takes the phone number alone, so the picker hides');
        $this->assertSame(['MTN', 'Orange'], $momo('XAF')['supported_networks']);
        $this->assertSame(['MTN', 'Airtel'], $momo('UGX')['supported_networks']);
    }
}
