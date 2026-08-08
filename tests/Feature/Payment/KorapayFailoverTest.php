<?php

namespace Tests\Feature\Payment;

use App\Domain\Order\Services\CheckoutService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Providers\KorapayPaymentProvider;
use App\Domain\Payment\Support\FiatGatewayRouter;
use App\Jobs\VerifyPaymentJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\CurrencyRate;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentWebhook;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SiteSetting;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Kora Pay as the second fiat backup: the francophone mobile-money corridors
 * (XAF first among them) and the NGN rails Paystack does not front. Pinned
 * here: failover partitions methods between the two backups exactly as the
 * router's priority chains say; Kora amounts stay in MAJOR units end to end;
 * and the webhook signature covers json_encode of the data object, not the
 * raw body.
 */
class KorapayFailoverTest extends TestCase
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

        CurrencyRate::create([
            'code' => 'XAF',
            'name' => 'Central African CFA Franc',
            'type' => 'fiat',
            'rate_per_usd' => 600.0,
            'is_active' => true,
        ]);

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

    private function checkout(string $currency = 'XAF'): Order
    {
        return app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart->fresh()->load('items.product', 'items.variant'),
            paymentMethod: 'flutterwave',
            displayCurrency: $currency,
        );
    }

    // ------------------------------------------------------------- routing

    public function test_failover_partitions_methods_between_the_two_backups(): void
    {
        $this->setGateway('flutterwave', false);
        $this->setGateway('paystack', true);
        $this->setGateway('korapay', true);

        // Kora's territory: ALL mobile money (it is the momo specialist,
        // ranked ahead of Paystack so every corridor lands on one gateway)
        // plus NGN pay-with-bank.
        $this->assertSame('korapay', FiatGatewayRouter::resolve('mobile_money', 'XAF'));
        $this->assertSame('korapay', FiatGatewayRouter::resolve('mobile_money', 'XOF'));
        $this->assertSame('korapay', FiatGatewayRouter::resolve('mobile_money', 'GHS'));
        $this->assertSame('korapay', FiatGatewayRouter::resolve('pay_with_bank', 'NGN'));

        // Paystack's territory: cards, USSD, NGN transfers.
        $this->assertSame('paystack', FiatGatewayRouter::resolve('card', 'NGN'));
        $this->assertSame('paystack', FiatGatewayRouter::resolve('bank_transfer', 'NGN'), 'bank_transfer prefers Paystack per the chain order');

        // With Kora dark, its mobile-money corridors fall to Paystack where
        // Paystack has coverage (GHS/KES/XOF), and grey where it does not.
        $this->setGateway('korapay', false);
        $this->assertSame('paystack', FiatGatewayRouter::resolve('mobile_money', 'GHS'));
        $this->assertNull(FiatGatewayRouter::resolve('mobile_money', 'XAF'), 'no Paystack coverage for Cameroon');
        $this->setGateway('korapay', true);

        // Kora alone still covers what only it can.
        $this->setGateway('paystack', false);
        $this->assertSame('korapay', FiatGatewayRouter::resolve('mobile_money', 'XAF'));
        $this->assertSame('korapay', FiatGatewayRouter::resolve('bank_transfer', 'NGN'), 'with Paystack down too, NGN transfers fall through to Kora');
        $this->assertSame('korapay', FiatGatewayRouter::resolve('card', 'NGN'));
        $this->assertNull(FiatGatewayRouter::resolve('apple_pay', 'NGN'), 'Kora has no Apple Pay');
        $this->assertNull(FiatGatewayRouter::resolve('ussd', 'NGN'), 'Kora has no USSD');
    }

    public function test_an_xaf_mobile_money_checkout_fails_over_to_kora_end_to_end(): void
    {
        $this->setGateway('flutterwave', false);
        $this->setGateway('korapay', true);

        $order = $this->checkout('XAF');
        $attempt = $order->paymentAttempts->first();
        $session = $attempt->paymentSession;

        $this->assertSame('korapay', $attempt->gateway, 'with Paystack dark, Kora is the default fiat gateway');

        $response = $this->actingAs($this->user)->postJson("/api/payment-sessions/{$session->id}/pay", [
            'method' => 'mobile_money',
            'details' => ['phone_number' => '237670000000', 'network' => 'MTN'],
        ]);

        $response->assertOk();
        $this->assertSame('awaiting_redirect', $response->json('status') ?? $response->json('data.status'), 'Kora mobile money goes out as a hosted redirect');
    }

    public function test_kora_never_sees_traffic_while_flutterwave_is_on(): void
    {
        $this->setGateway('korapay', true);

        $order = $this->checkout('XAF');

        $this->assertSame('flutterwave', $order->paymentAttempts->first()->gateway);
        $this->assertSame('flutterwave', FiatGatewayRouter::resolve('mobile_money', 'XAF'));
    }

    // --------------------------------------------------------- verification

    /**
     * @return array{0: PaymentAttempt, 1: bool}
     */
    private function verifyAttemptAgainst(array $data): array
    {
        config(['services.korapay.secret_key' => 'sk_live_korapay_realish']);

        $this->setGateway('flutterwave', false);
        $this->setGateway('korapay', true);

        $order = $this->checkout('XAF');
        $attempt = $order->paymentAttempts->first();

        Http::fake([
            'api.korapay.com/merchant/api/v1/charges/*' => Http::response([
                'status' => true,
                'message' => 'Charge retrieved successfully',
                'data' => array_merge([
                    'reference' => $attempt->idempotency_key,
                    'status' => 'success',
                    // MAJOR units as a string, exactly as Kora returns them.
                    'amount' => number_format((float) $attempt->amount, 2, '.', ''),
                    'amount_paid' => number_format((float) $attempt->amount, 2, '.', ''),
                    'currency' => 'XAF',
                ], $data),
            ], 200),
        ]);

        return [$attempt, app(KorapayPaymentProvider::class)->verifyPayment($attempt)];
    }

    public function test_verification_marks_the_attempt_paid_on_an_exact_major_unit_match(): void
    {
        [$attempt, $verified] = $this->verifyAttemptAgainst([]);

        $this->assertTrue($verified);
        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->payment_status);
    }

    public function test_verification_rejects_a_relayed_foreign_reference(): void
    {
        [$attempt, $verified] = $this->verifyAttemptAgainst(['reference' => 'KPY-someone-else']);

        $this->assertFalse($verified);
        $this->assertNotSame(PaymentStatus::Paid, $attempt->fresh()->payment_status);
    }

    public function test_verification_rejects_an_underpaid_charge(): void
    {
        [, $verified] = $this->verifyAttemptAgainst([
            'amount_paid' => '1.00',
        ]);

        $this->assertFalse($verified, 'amount_paid short of the order total must never verify');
    }

    // -------------------------------------------------------------- webhook

    /**
     * @return array{0: PaymentAttempt, 1: array<string, mixed>}
     */
    private function webhookFixture(): array
    {
        config(['services.korapay.secret_key' => 'sk_live_korapay_realish']);

        $this->setGateway('flutterwave', false);
        $this->setGateway('korapay', true);

        $order = $this->checkout('XAF');
        $attempt = $order->paymentAttempts->first();

        $data = [
            'reference' => 'KPY-C-'.$attempt->id,
            'payment_reference' => $attempt->idempotency_key,
            'status' => 'success',
            'amount' => (float) $attempt->amount,
            'currency' => 'XAF',
            'payment_method' => 'mobile_money',
        ];

        return [$attempt, ['event' => 'charge.success', 'data' => $data]];
    }

    public function test_a_signed_charge_success_webhook_queues_verification(): void
    {
        Queue::fake();
        [$attempt, $payload] = $this->webhookFixture();

        // The quirk under test: the signature covers json_encode(data) ONLY.
        $response = $this->call('POST', '/api/webhooks/korapay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KORAPAY_SIGNATURE' => hash_hmac('sha256', json_encode($payload['data']), 'sk_live_korapay_realish'),
        ], json_encode($payload));

        $response->assertOk();
        Queue::assertPushed(VerifyPaymentJob::class);
        $this->assertSame(1, PaymentWebhook::where('gateway', 'korapay')->count());
        $this->assertSame($attempt->idempotency_key, PaymentWebhook::where('gateway', 'korapay')->first()->reference, 'the webhook must bind to OUR reference via payment_reference');
    }

    public function test_a_signature_over_the_raw_body_is_rejected(): void
    {
        Queue::fake();
        [, $payload] = $this->webhookFixture();
        $body = json_encode($payload);

        // A correct-looking HMAC computed the WRONG way (over the raw body,
        // Paystack-style) must fail: proves we implement Kora's actual scheme.
        $response = $this->call('POST', '/api/webhooks/korapay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KORAPAY_SIGNATURE' => hash_hmac('sha256', $body, 'sk_live_korapay_realish'),
        ], $body);

        $response->assertStatus(401);
        Queue::assertNotPushed(VerifyPaymentJob::class);
        $this->assertSame(0, PaymentWebhook::count());
    }

    public function test_charge_failed_events_are_recorded_but_not_acted_on(): void
    {
        Queue::fake();
        [$attempt] = $this->webhookFixture();

        $data = ['payment_reference' => $attempt->idempotency_key, 'status' => 'failed'];
        $payload = ['event' => 'charge.failed', 'data' => $data];

        $response = $this->call('POST', '/api/webhooks/korapay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KORAPAY_SIGNATURE' => hash_hmac('sha256', json_encode($data), 'sk_live_korapay_realish'),
        ], json_encode($payload));

        $response->assertOk();
        Queue::assertNotPushed(VerifyPaymentJob::class);
        $this->assertSame('ignored', PaymentWebhook::where('gateway', 'korapay')->latest('id')->first()->processing_status);
    }

    // -------------------------------------------------------- return leg

    public function test_the_hosted_return_leg_verifies_and_lands_on_the_order_page(): void
    {
        // Mock secret: verifyPayment marks the attempt paid without HTTP.
        $this->setGateway('flutterwave', false);
        $this->setGateway('korapay', true);

        $order = $this->checkout('XAF');
        $session = $order->paymentAttempts->first()->paymentSession;

        $response = $this->actingAs($this->user)
            ->get("/checkout/return/{$session->id}?reference=".$order->paymentAttempts->first()->idempotency_key);

        $response->assertRedirect();
        $this->assertStringContainsString($order->order_number, $response->headers->get('Location'));
        $this->assertSame(PaymentStatus::Paid, $order->paymentAttempts->first()->fresh()->payment_status);
    }
}
