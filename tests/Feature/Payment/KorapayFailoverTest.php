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
use App\Models\PaymentSession;
use App\Models\PaymentWebhook;
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

        // With Kora dark, mobile money greys everywhere: Paystack offers it
        // only to businesses registered in Ghana, Kenya or Côte d'Ivoire.
        $this->setGateway('korapay', false);
        $this->assertNull(FiatGatewayRouter::resolve('mobile_money', 'GHS'));
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
        $this->assertSame('awaiting_confirmation', $response->json('status') ?? $response->json('data.status'), 'Kora mobile money stays in our checkout: the prompt goes to the phone');
        $this->assertNull($response->json('payment_payload.redirect_url') ?? $response->json('data.payment_payload.redirect_url'), 'no redirect, ever');
    }

    // ------------------------------------------ mobile money in our checkout

    /**
     * @return array{0: Order, 1: PaymentAttempt, 2: PaymentSession}
     */
    private function koraLiveOrder(string $currency = 'XAF'): array
    {
        config(['services.korapay.secret_key' => 'sk_live_korapay_realish', 'services.korapay.webhook_url' => 'https://tunnel.example/api/webhooks/korapay']);
        $this->setGateway('flutterwave', false);
        $this->setGateway('korapay', true);

        if ($currency === 'GHS') {
            CurrencyRate::create(['code' => 'GHS', 'name' => 'Cedi', 'type' => 'fiat', 'rate_per_usd' => 15.5, 'is_active' => true]);
        }

        $order = $this->checkout($currency);
        $attempt = $order->paymentAttempts->first();

        return [$order, $attempt, $attempt->paymentSession];
    }

    /** @param  array<string, mixed>  $data */
    private function koraAnswers(string $urlPattern, array $data, bool $ok = true, int $status = 200): void
    {
        Http::fake([$urlPattern => Http::response(['status' => $ok, 'message' => $ok ? 'Authorization required' : ($data['message'] ?? 'error'), 'data' => $ok ? $data : null], $status)]);
    }

    /** Cameroon: Kora pushes the PIN prompt straight to the phone; the customer never leaves our page. */
    public function test_a_kora_mobile_money_charge_puts_the_prompt_on_the_phone_from_our_checkout(): void
    {
        [, $attempt, $session] = $this->koraLiveOrder('XAF');
        $this->koraAnswers('api.korapay.com/merchant/api/v1/charges/mobile-money', [
            'amount' => 6600, 'currency' => 'XAF', 'auth_model' => 'STK_PROMPT',
            'transaction_reference' => 'KPY-CA-abc123', 'payment_reference' => $attempt->idempotency_key, 'status' => 'processing',
            'message' => 'You will receive a pin prompt on your mobile number +237670000000 for XAF 6600. Kindly enter your wallet PIN to authorize the payment',
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/payment-sessions/{$session->id}/pay", [
            'method' => 'mobile_money',
            'details' => ['phone_number' => '670000000', 'network' => 'MTN'],
        ]);

        $response->assertOk();
        $this->assertSame('awaiting_confirmation', $session->fresh()->status);
        $this->assertStringContainsString('pin prompt', $session->fresh()->payment_payload['message']);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/charges/mobile-money')
            && $request['mobile_money']['number'] === '237670000000'
            && $request['currency'] === 'XAF'
            && $request['reference'] === $attempt->idempotency_key
            && $request['notification_url'] === 'https://tunnel.example/api/webhooks/korapay');

        $this->assertSame('KPY-CA-abc123', data_get($attempt->fresh()->metadata, 'korapay.transaction_reference'));
    }

    /** Ghana: a one-time code first, typed in our checkout; a valid code is followed by the prompt. */
    public function test_a_kora_mobile_money_charge_that_needs_a_code_takes_it_in_our_checkout(): void
    {
        [, $attempt, $session] = $this->koraLiveOrder('GHS');
        $this->koraAnswers('api.korapay.com/merchant/api/v1/charges/mobile-money', [
            'amount' => 170, 'currency' => 'GHS', 'auth_model' => 'OTP',
            'transaction_reference' => 'KPY-CA-otp1', 'payment_reference' => $attempt->idempotency_key, 'status' => 'processing',
            'message' => 'Token generated and sent out successfully',
        ]);

        $details = ['phone_number' => '+233 24 000 0000', 'network' => 'MTN'];
        $this->actingAs($this->user)->postJson("/api/payment-sessions/{$session->id}/pay", ['method' => 'mobile_money', 'details' => $details])->assertOk();

        $session->refresh();
        $this->assertSame('awaiting_customer_action', $session->status);
        $this->assertSame('otp', $session->payment_payload['action']);

        // The code goes to Kora's authorise call with Kora's own id, and the prompt follows.
        Http::fake([
            'api.korapay.com/merchant/api/v1/charges/mobile-money/authorize' => Http::response(['status' => true, 'message' => 'Authorization required', 'data' => [
                'auth_model' => 'STK_PROMPT', 'transaction_reference' => 'KPY-CA-otp1', 'status' => 'processing',
                'message' => 'You will receive a prompt on mobile number +233240000000. Kindly enter your wallet PIN.',
            ]], 200),
        ]);

        $this->actingAs($this->user)->postJson("/api/payment-sessions/{$session->id}/pay", ['method' => 'mobile_money', 'details' => $details, 'otp' => '123456'])->assertOk();

        $this->assertSame('awaiting_confirmation', $session->fresh()->status);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/mobile-money/authorize')
            && $request['reference'] === 'KPY-CA-otp1'
            && $request['token'] === '123456');
    }

    /** A wrong code is asked for again; the payment is not failed. */
    public function test_a_wrong_code_is_asked_for_again(): void
    {
        [, $attempt, $session] = $this->koraLiveOrder('GHS');
        $session->forceFill(['status' => 'awaiting_customer_action', 'payment_payload' => ['action' => 'otp']])->save();
        $attempt->forceFill(['metadata' => ['korapay' => ['transaction_reference' => 'KPY-CA-otp2', 'number' => '233240000000']]])->save();

        $this->koraAnswers('api.korapay.com/merchant/api/v1/charges/mobile-money/authorize', ['message' => 'The OTP you provided is invalid'], false, 400);

        $this->actingAs($this->user)->postJson("/api/payment-sessions/{$session->id}/pay", [
            'method' => 'mobile_money', 'details' => ['phone_number' => '+233 24 000 0000', 'network' => 'MTN'], 'otp' => '000000',
        ])->assertOk();

        $session->refresh();
        $this->assertSame('awaiting_customer_action', $session->status);
        $this->assertSame('otp', $session->payment_payload['action']);
        $this->assertStringContainsString('not accepted', $session->payment_payload['message']);
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
    private function verifyAttemptAgainst(array $data, ?\Closure $shape = null): array
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
                ], $shape ? $shape($data, $attempt) : $data),
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
    // ------------------------------------------- what Kora's documentation says

    /**
     * The verify response Kora's sandbox really returned for a completed XAF
     * mobile money checkout (RSRPROBE-HC-2BBFE3B1, 2026-10-08): our reference
     * in `reference`, amount_paid equal to the amount, a UTC transaction_date.
     * Pinned so the verify path is proven against the real shape, not only the
     * documented one.
     */
    public function test_verification_accepts_the_shape_koras_sandbox_really_returns(): void
    {
        [$attempt, $verified] = $this->verifyAttemptAgainst([], fn (array $data, $attempt) => [
            'reference' => $attempt->idempotency_key,
            'status' => 'success',
            'amount' => number_format((float) $attempt->amount, 2, '.', ''),
            'amount_paid' => number_format((float) $attempt->amount, 2, '.', ''),
            'fee' => 0,
            'currency' => 'XAF',
            'description' => 'RShopRefill sandbox check',
            'transaction_date' => '2026-10-08T14:37:57.000Z',
            'customer' => ['name' => 'Sandbox Probe', 'email' => 'probe@rshoprefill.com'],
            'mobile_money' => ['mobile_number' => '+237655123456'],
        ]);

        $this->assertTrue($verified);
        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->payment_status);
    }

    /**
     * Kora's verify response may carry ITS id in `reference` and ours in
     * `payment_reference` (both shapes appear in its docs). Ours in either
     * field is a match; someone else's in both is not.
     */
    public function test_verification_accepts_our_reference_in_payment_reference(): void
    {
        [$attempt, $verified] = $this->verifyAttemptAgainst(
            ['reference' => 'KPY-PAY-rYF4c5ZWioeb'],
            fn (array $data, $attempt) => array_merge($data, ['payment_reference' => $attempt->idempotency_key]),
        );

        $this->assertTrue($verified, 'our reference in payment_reference must verify');
        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->payment_status);
    }

    public function test_verification_rejects_a_foreign_reference_in_both_fields(): void
    {
        [, $verified] = $this->verifyAttemptAgainst([
            'reference' => 'KPY-PAY-someone',
            'payment_reference' => 'someone-elses-order',
        ]);

        $this->assertFalse($verified);
    }

    /**
     * Kora's own successful mobile money example reports amount_paid 0 and
     * says to give value from amount_accepted. Neither may stop a real
     * payment from settling, and a short amount_accepted must.
     */
    public function test_verification_settles_a_success_whose_amount_paid_is_zero(): void
    {
        [, $verified] = $this->verifyAttemptAgainst(['amount_paid' => 0]);

        $this->assertTrue($verified, "amount_paid 0 on a success is Kora's documented shape");
    }

    /** One stub per test: a second fake in the same test would answer with the first. */
    public function test_verification_reads_a_short_accepted_amount_as_an_underpayment(): void
    {
        [, $verified] = $this->verifyAttemptAgainst(['amount_paid' => 0, 'amount_accepted' => '1.00']);

        $this->assertFalse($verified, 'amount_accepted is what Kora processed; short of the order it must not settle');
    }

    /** Francs have no minor unit: a figure with decimals goes out rounded up, and verify reads that figure. */
    public function test_a_franc_amount_with_decimals_is_charged_as_whole_francs(): void
    {
        config(['services.korapay.secret_key' => 'sk_live_korapay_realish']);
        $this->setGateway('flutterwave', false);
        $this->setGateway('korapay', true);

        $order = $this->checkout('XAF');
        $attempt = $order->paymentAttempts->first();
        $attempt->forceFill(['amount' => 3015.617])->save();

        Http::fake([
            'api.korapay.com/merchant/api/v1/charges/initialize' => Http::response([
                'status' => true,
                'data' => ['checkout_url' => 'https://checkout.korapay.com/x', 'reference' => $attempt->idempotency_key],
            ], 200),
        ]);

        app(KorapayPaymentProvider::class)->chargeHosted($attempt->fresh(), 'mobile_money', 'https://rshoprefill.test/return');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'charges/initialize')
            && $request['amount'] === 3016.0
            && $request['currency'] === 'XAF');

        $attempt->refresh();
        $this->assertSame('XAF', $attempt->pay_currency);
        $this->assertEqualsWithDelta(3016.0, (float) $attempt->pay_amount, 0.0001);

        // Kora reports what it was asked for; the attempt's own figure is lower.
        Http::fake([
            'api.korapay.com/merchant/api/v1/charges/*' => Http::response([
                'status' => true,
                'data' => ['reference' => $attempt->idempotency_key, 'status' => 'success', 'amount' => '3016.00', 'amount_paid' => 0, 'currency' => 'XAF'],
            ], 200),
        ]);
        $this->assertTrue(app(KorapayPaymentProvider::class)->verifyPayment($attempt->fresh()));
    }

    /** A retried refund asks Kora for the same refund, never a second one. */
    public function test_a_refund_keeps_the_same_reference_across_retries(): void
    {
        config(['services.korapay.secret_key' => 'sk_live_korapay_realish']);
        $this->setGateway('korapay', true);

        $order = $this->checkout('XAF');
        $attempt = $order->paymentAttempts->first();
        $attempt->forceFill(['gateway' => 'korapay', 'payment_status' => PaymentStatus::Paid])->save();

        Http::fake(['api.korapay.com/merchant/api/v1/refunds/initiate' => Http::response(['status' => true, 'data' => []], 200)]);

        $provider = app(KorapayPaymentProvider::class);
        $provider->refundPayment($attempt->fresh(), 1000.0, 'item-42');
        $provider->refundPayment($attempt->fresh(), 1000.0, 'item-42');

        $references = [];
        Http::assertSent(function (Request $request) use (&$references) {
            $references[] = $request['reference'];

            return true;
        });

        $this->assertCount(2, $references);
        $this->assertSame(["RF-{$attempt->id}-item-42", "RF-{$attempt->id}-item-42"], $references);
        $this->assertSame(1000.0, Http::recorded()->first()[0]['amount'], 'whole francs');
    }

    /**
     * Kora signs the `data` object as IT serialised it. A body with a slash
     * and an accented name, signed the way JSON.stringify writes them, must
     * verify although PHP's default json_encode would escape both.
     */
    public function test_a_webhook_signed_over_koras_own_bytes_verifies(): void
    {
        config(['services.korapay.secret_key' => 'sk_live_korapay_realish']);
        $this->setGateway('korapay', true);

        $order = $this->checkout('XAF');
        $attempt = $order->paymentAttempts->first();
        $attempt->forceFill(['gateway' => 'korapay'])->save();

        $data = '{"fee":10,"amount":"'.number_format((float) $attempt->amount, 2, '.', '').'","status":"success","currency":"XAF","reference":"KPY-PAY-4uore940","payment_method":"mobile_money","payment_reference":"'.$attempt->idempotency_key.'","description":"Order/Aïcha "}';
        $body = '{"event":"charge.success","data":'.$data.'}';

        $this->call('POST', '/api/webhooks/korapay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KORAPAY_SIGNATURE' => hash_hmac('sha256', $data, 'sk_live_korapay_realish'),
        ], $body)->assertOk();

        $this->assertDatabaseHas('payment_webhooks', ['gateway' => 'korapay', 'reference' => $attempt->idempotency_key]);
    }
}
