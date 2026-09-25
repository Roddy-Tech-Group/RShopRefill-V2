<?php

namespace Tests\Feature\Payment;

use App\Domain\Order\Services\CheckoutService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Providers\FlutterwavePaymentProvider;
use App\Domain\Payment\Support\FundingMethodCatalog;
use App\Domain\Payment\Support\MobileMoneyRail;
use App\Domain\Payment\Support\SupportedPaymentMethods;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\CurrencyRate;
use App\Models\Order;
use App\Models\PaymentSession;
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

        // USD has no rail of its own and borrows the Francophone one (charged
        // in CFA francs); a currency with neither is still refused.
        $this->assertTrue(MobileMoneyRail::supports('USD'));
        $this->assertFalse(MobileMoneyRail::supports('EUR'));
    }

    public function test_the_provider_refuses_a_currency_without_a_rail_instead_of_calling_the_gateway(): void
    {
        Http::fake();

        $order = $this->checkout('GHS');
        $attempt = $order->paymentAttempts->first();
        $attempt->forceFill(['currency' => 'EUR'])->save();

        $result = app(FlutterwavePaymentProvider::class)->chargeMobileMoney($attempt->fresh(), '0244000000', 'MTN');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('Mobile money is not available in EUR.', $result['message']);
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

    /**
     * A dollar order over MTN or Orange. No rail takes dollars, so the total
     * is converted into CFA francs (rounded up to whole francs) and sent to
     * the Francophone rail; the order itself stays in dollars, and the franc
     * figure is on the attempt before the gateway is called.
     */
    public function test_a_usd_order_is_charged_in_cfa_francs_on_the_francophone_rail(): void
    {
        $this->fakePushRail();

        $order = $this->checkout('USD');
        $attempt = $order->paymentAttempts->first();
        $francs = (float) ceil((float) $attempt->amount * 600);

        $response = $this->pay($order, ['phone_number' => '+237 6 70 00 00 00', 'network' => 'Orange']);

        $response->assertOk();
        $this->assertSame('awaiting_confirmation', $response->json('data.status'));

        $request = $this->sentCharge();
        $this->assertStringContainsString('/charges?type=mobile_money_franco', $request->url());
        $this->assertSame('XAF', $request['currency'], 'the rail takes no dollars');
        $this->assertSame($francs, (float) $request['amount']);
        $this->assertSame('CM', $request['country']);
        $this->assertSame('ORANGE', $request['network']);

        $attempt = $attempt->fresh();
        $this->assertSame('USD', $attempt->currency, 'the order is still priced in dollars');
        $this->assertSame('XAF', $attempt->pay_currency);
        $this->assertSame($francs, (float) $attempt->pay_amount);
        $this->assertSame(600.0, (float) data_get($attempt->metadata, 'mobile_money.rate'));
        $this->assertSame('USD', data_get($attempt->metadata, 'mobile_money.converted_from'));
    }

    /** The dialling prefix picks the franc: a Senegalese number is charged XOF, on Senegal. */
    public function test_a_west_african_number_on_a_usd_order_is_charged_in_xof(): void
    {
        $this->fakePushRail();

        $order = $this->checkout('USD');
        $this->pay($order, ['phone_number' => '+221 77 000 0000', 'network' => 'MTN'])->assertOk();

        $request = $this->sentCharge();
        $this->assertSame('XOF', $request['currency']);
        $this->assertSame('SN', $request['country']);
        $this->assertSame('XOF', $order->paymentAttempts->first()->fresh()->pay_currency);

        // A local-format number, and a prefix outside the franc zone, keep the
        // default; a market with its own rail is never converted.
        $this->assertSame('XAF', MobileMoneyRail::chargeCurrency('USD', '670000000'));
        $this->assertSame('XAF', MobileMoneyRail::chargeCurrency('USD', '+233 24 400 0000'));
        $this->assertSame('GHS', MobileMoneyRail::chargeCurrency('GHS', '+221 77 000 0000'));
    }

    /** The gateway answers in francs, and francs are what was asked for, so the attempt verifies. */
    public function test_a_converted_charge_verifies_against_the_franc_figure(): void
    {
        $order = $this->checkout('USD');
        $attempt = $order->paymentAttempts->first();
        $francs = (float) ceil((float) $attempt->amount * 600);

        Http::fake([
            'api.flutterwave.com/v3/charges*' => Http::response([
                'status' => 'success',
                'message' => 'Charge initiated',
                'data' => ['status' => 'pending'],
            ]),
            'api.flutterwave.com/v3/transactions/*' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 987654,
                    'status' => 'successful',
                    'amount' => $francs,
                    'currency' => 'XAF',
                    'tx_ref' => $attempt->idempotency_key,
                ],
            ]),
        ]);

        $this->pay($order, ['phone_number' => '237670000000', 'network' => 'MTN'])->assertOk();

        $this->assertTrue(app(FlutterwavePaymentProvider::class)->verifyPayment($attempt->fresh()));
        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->payment_status);
    }

    /**
     * No rate, no charge. CurrencyRate::resolve() answers a missing code with
     * the USD row, which would quietly ask the rail for 11 francs on an
     * 11-dollar order, so the conversion refuses before anything is sent.
     */
    public function test_a_usd_order_is_refused_before_the_gateway_when_the_rail_currency_has_no_rate(): void
    {
        Http::fake();
        CurrencyRate::query()->where('code', 'XAF')->update(['is_active' => false]);

        $order = $this->checkout('USD');
        $response = $this->pay($order, ['phone_number' => '237670000000', 'network' => 'MTN']);

        $response->assertOk();
        $this->assertSame('failed', $response->json('data.status'));
        $this->assertStringContainsString('temporarily unavailable', (string) $response->json('data.payment_payload.failure_reason'));
        Http::assertNothingSent();
        $this->assertNull($order->paymentAttempts->first()->fresh()->pay_currency, 'nothing is recorded as charged');
    }

    /**
     * The gateway holds the charge in francs, so a refund stated in dollars
     * (the whole attempt for a duplicate payment, an item's share for a
     * failed delivery) is sent as the same share of the franc figure.
     */
    public function test_a_converted_payment_is_refunded_in_francs(): void
    {
        $this->fakePushRail();

        $order = $this->checkout('USD');
        $this->pay($order, ['phone_number' => '237670000000', 'network' => 'MTN'])->assertOk();

        $attempt = $order->paymentAttempts->first()->fresh();
        $attempt->forceFill(['gateway_reference' => '987654'])->save();
        $francs = (float) $attempt->pay_amount;

        Http::fake(['api.flutterwave.com/v3/refunds' => Http::response(['status' => 'success'])]);

        $provider = app(FlutterwavePaymentProvider::class);
        $this->assertTrue($provider->refundPayment($attempt, (float) $attempt->amount, 'duplicate-payment'));
        $this->assertTrue($provider->refundPayment($attempt, round((float) $attempt->amount / 2, 2), 'item-1'));

        $sent = Http::recorded(fn (Request $request) => str_contains($request->url(), '/refunds'))
            ->map(fn (array $pair) => (float) $pair[0]['amount'])
            ->all();

        $this->assertSame([$francs, round($francs / 2)], $sent);
    }

    /** The funding sheet and the order page both offer a dollar customer the Francophone networks. */
    public function test_a_dollar_customer_is_offered_mtn_and_orange_on_every_surface(): void
    {
        $sheet = collect(FundingMethodCatalog::forCurrency('USD'))->firstWhere('type', 'mobile_money');
        $this->assertSame(['MTN', 'Orange'], $sheet['supported_networks']);
        $this->assertSame('MTN Mobile Money or Orange Money', $sheet['description']);

        // The order page reads the session's own list, which used to name
        // three currencies by hand and so offered nothing to the rest.
        $onOrderPage = fn (string $currency) => collect((new PaymentSession(['session_type' => 'card', 'currency' => $currency]))->getAvailableMethods())
            ->firstWhere('type', 'mobile_money');

        $this->assertSame(['MTN', 'Orange'], $onOrderPage('USD')['supported_networks']);
        $this->assertSame([], $onOrderPage('KES')['supported_networks'], 'M-Pesa takes the number alone');
        $this->assertNull($onOrderPage('EUR'), 'no rail, no tile');
    }
}
