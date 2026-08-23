<?php

namespace Tests\Feature\Payment;

use App\Domain\Order\Services\CheckoutService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Providers\PaystackPaymentProvider;
use App\Domain\Payment\Services\PaymentSessionService;
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
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Paystack as the live fiat backup. The rules pinned here: with Flutterwave
 * off and Paystack on, covered methods fail over seamlessly (order stamped
 * paystack, charge goes out as a channel-restricted hosted redirect); while
 * Flutterwave is on Paystack never sees a byte; verification cross-binds
 * reference, amount subunits and currency; and the webhook rail only acts on
 * signed, matching charge.success events.
 */
class PaystackFailoverTest extends TestCase
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
            'code' => 'NGN',
            'name' => 'Nigerian Naira',
            'type' => 'fiat',
            'rate_per_usd' => 1500.0,
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

    private function failover(): void
    {
        $this->setGateway('flutterwave', false);
        $this->setGateway('paystack', true);
    }

    private function checkout(string $currency = 'NGN'): Order
    {
        return app(CheckoutService::class)->placeOrder(
            user: $this->user,
            cart: $this->cart->fresh()->load('items.product', 'items.variant'),
            paymentMethod: 'flutterwave',
            displayCurrency: $currency,
        );
    }

    // ------------------------------------------------------------- failover

    public function test_failover_routes_covered_methods_to_paystack(): void
    {
        $this->failover();

        $this->assertSame('paystack', FiatGatewayRouter::resolve('card', 'NGN'));
        $this->assertSame('paystack', FiatGatewayRouter::resolve('ussd', 'NGN'));
        $this->assertSame('paystack', FiatGatewayRouter::resolve('mobile_money', 'GHS'));
        $this->assertNull(FiatGatewayRouter::resolve('card', 'USD'), 'USD cards need account approval before failover serves them');
        $this->assertNull(FiatGatewayRouter::resolve('mobile_money', 'XAF'), 'XAF is Kora territory, not Paystack');
        $this->assertNull(FiatGatewayRouter::resolve('bank_qr', 'NGN'), 'no backup serves bank QR');
    }

    public function test_failover_checkout_stamps_paystack_and_charges_through_the_hosted_redirect(): void
    {
        $this->failover();

        $order = $this->checkout();
        $attempt = $order->paymentAttempts->first();
        $session = $attempt->paymentSession;

        $this->assertSame('paystack', $attempt->gateway, 'order placed during failover must stamp the backup');
        $this->assertSame('paystack', $session->provider);

        $response = $this->actingAs($this->user)->postJson("/api/payment-sessions/{$session->id}/pay", [
            'method' => 'card',
        ]);

        $response->assertOk();
        $this->assertSame('awaiting_redirect', $response->json('status') ?? $response->json('data.status'), 'card must go out as a hosted redirect on the backup');
    }

    public function test_a_flutterwave_order_reroutes_to_paystack_at_method_commitment(): void
    {
        // Placed in normal operation: stamped flutterwave.
        $order = $this->checkout();
        $attempt = $order->paymentAttempts->first();
        $session = $attempt->paymentSession;
        $this->assertSame('flutterwave', $attempt->gateway);

        // The outage begins before the customer picks a method.
        $this->failover();

        $response = $this->actingAs($this->user)->postJson("/api/payment-sessions/{$session->id}/pay", [
            'method' => 'card',
        ]);

        $response->assertOk();
        $this->assertSame('paystack', $attempt->fresh()->gateway, 'pre-charge session must re-stamp to the live backup');
        $this->assertSame('paystack', $session->fresh()->provider);
    }

    public function test_paystack_never_sees_traffic_while_flutterwave_is_on(): void
    {
        $this->setGateway('paystack', true);

        $order = $this->checkout();
        $attempt = $order->paymentAttempts->first();

        $this->assertSame('flutterwave', $attempt->gateway);
        $this->assertSame('flutterwave', FiatGatewayRouter::resolve('card', 'NGN'));
    }

    public function test_an_uncovered_method_is_refused_cleanly_during_failover(): void
    {
        $this->failover();

        $order = $this->checkout();
        $session = $order->paymentAttempts->first()->paymentSession;

        // bank_qr has no backup; the pay endpoint must refuse, not crash.
        $response = $this->actingAs($this->user)->postJson("/api/payment-sessions/{$session->id}/pay", [
            'method' => 'bank_qr',
        ]);

        $response->assertStatus(503)->assertJson(['gateway_disabled' => true]);
    }

    public function test_an_in_flight_flutterwave_session_still_lands_during_failover(): void
    {
        $order = $this->checkout();
        $session = $order->paymentAttempts->first()->paymentSession;

        $this->failover();

        app(PaymentSessionService::class)->confirmSession($session, ['transaction_id' => 'FLW_'.Str::random(8)]);

        $this->assertSame('confirmed', $session->fresh()->status, 'failover stranded an in-flight primary payment');
    }

    // --------------------------------------------------------- verification

    /**
     * One order + one verify stub per test: Http::fake() stubs registered
     * for the same pattern resolve first-registered-first, so stacking
     * scenarios in one test silently reuses the first response.
     */
    private function verifyAttemptAgainst(array $data): array
    {
        config(['services.paystack.secret_key' => 'sk_test_realish']);

        $this->failover();
        $order = $this->checkout();
        $attempt = $order->paymentAttempts->first();

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => array_merge([
                    'reference' => $attempt->idempotency_key,
                    'status' => 'success',
                    'amount' => (int) round(((float) $attempt->amount) * 100),
                    'currency' => 'NGN',
                    'id' => 999333,
                ], $data),
            ], 200),
        ]);

        return [$attempt, app(PaystackPaymentProvider::class)->verifyPayment($attempt)];
    }

    public function test_verification_rejects_a_relayed_foreign_reference(): void
    {
        [$attempt, $verified] = $this->verifyAttemptAgainst(['reference' => 'someone-elses-ref']);

        $this->assertFalse($verified, 'reference mismatch must fail verification');
        $this->assertNotSame(PaymentStatus::Paid, $attempt->fresh()->payment_status);
    }

    public function test_verification_rejects_a_payment_short_by_one_subunit(): void
    {
        [, $verified] = $this->verifyAttemptAgainst([
            'amount' => (int) round(11.0 * 1500 * 100) - 1,
        ]);

        $this->assertFalse($verified, 'a short payment must never verify');
    }

    public function test_verification_rejects_a_currency_mismatch(): void
    {
        [, $verified] = $this->verifyAttemptAgainst(['currency' => 'USD']);

        $this->assertFalse($verified, 'a currency swap must never verify');
    }

    public function test_verification_marks_the_attempt_paid_on_an_exact_match(): void
    {
        [$attempt, $verified] = $this->verifyAttemptAgainst([]);

        $this->assertTrue($verified);
        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->payment_status);
        $this->assertSame('999333', $attempt->fresh()->gateway_reference);
    }

    // -------------------------------------------------------------- webhook

    /**
     * @return array{0: PaymentAttempt, 1: string}
     */
    private function webhookFixture(): array
    {
        config(['services.paystack.secret_key' => 'sk_test_realish']);

        $this->failover();
        $order = $this->checkout();
        $attempt = $order->paymentAttempts->first();

        $body = json_encode([
            'event' => 'charge.success',
            'data' => [
                'reference' => $attempt->idempotency_key,
                'status' => 'success',
                'amount' => (int) round(((float) $attempt->amount) * 100),
                'currency' => 'NGN',
                'id' => 424242,
            ],
        ]);

        return [$attempt, $body];
    }

    public function test_a_signed_charge_success_webhook_binds_the_attempt_and_queues_verification(): void
    {
        Queue::fake();
        [$attempt, $body] = $this->webhookFixture();

        $response = $this->call('POST', '/api/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_realish'),
        ], $body);

        $response->assertOk();
        Queue::assertPushed(VerifyPaymentJob::class);
        $this->assertSame('424242', $attempt->fresh()->gateway_reference);
        $this->assertSame(1, PaymentWebhook::where('gateway', 'paystack')->count(), 'the raw event must be persisted for audit');
    }

    public function test_a_bad_signature_is_rejected_before_anything_is_written(): void
    {
        Queue::fake();
        [, $body] = $this->webhookFixture();

        $response = $this->call('POST', '/api/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'wrong-secret'),
        ], $body);

        $response->assertStatus(401);
        Queue::assertNotPushed(VerifyPaymentJob::class);
        $this->assertSame(0, PaymentWebhook::count(), 'an unsigned event must leave no trace but the log');
    }

    public function test_non_success_events_are_recorded_but_not_acted_on(): void
    {
        Queue::fake();
        [$attempt] = $this->webhookFixture();

        $body = json_encode([
            'event' => 'charge.failed',
            'data' => ['reference' => $attempt->idempotency_key, 'status' => 'failed'],
        ]);

        $response = $this->call('POST', '/api/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_realish'),
        ], $body);

        $response->assertOk();
        Queue::assertNotPushed(VerifyPaymentJob::class);
        $this->assertSame('ignored', PaymentWebhook::where('gateway', 'paystack')->latest('id')->first()->processing_status);
    }

    // -------------------------------------------------------- return leg

    public function test_the_hosted_return_leg_verifies_and_lands_on_the_order_page(): void
    {
        // Mock secret: verifyPayment marks the attempt paid without HTTP.
        $this->failover();
        $order = $this->checkout();
        $session = $order->paymentAttempts->first()->paymentSession;

        $response = $this->actingAs($this->user)
            ->get("/checkout/return/{$session->id}?reference=".$order->paymentAttempts->first()->idempotency_key);

        $response->assertRedirect();
        $this->assertStringContainsString($order->order_number, $response->headers->get('Location'), 'a verified return must land on the order page');
        $this->assertSame(PaymentStatus::Paid, $order->paymentAttempts->first()->fresh()->payment_status);
    }
}
