<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Providers\NowPaymentsProvider;
use App\Domain\Payment\Support\NowPaymentsSignature;
use App\Jobs\VerifyPaymentJob;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression coverage for the crypto-confirmation chain. Each of these used to be
 * broken: the webhook signature was computed over unsorted JSON (so every real
 * NOWPayments notification was rejected), the attempt was looked up by invoice_id
 * (we store payment_id), verifyPayment hit /invoice/{id} (status lives at
 * /payment/{id}), the middleware idempotency key was payment_id alone (so every
 * IPN after the first — including "finished" — was dropped as a duplicate), and
 * partially_paid had no underpayment tolerance. Any one of them stopped paid
 * orders from auto-completing.
 */
class NowPaymentsWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $ipnSecret = 'test-ipn-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.nowpayments.ipn_secret' => $this->ipnSecret]);
    }

    private function attempt(string $paymentId): PaymentAttempt
    {
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
            'payment_method' => 'nowpayments',
        ]);

        return PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'nowpayments',
            'gateway_reference' => $paymentId,
            'idempotency_key' => (string) Str::uuid(),
            'currency' => 'USD',
            'amount' => 25,
        ]);
    }

    /** Sign a payload the way NOWPayments does: key-sorted JSON, HMAC-SHA512. */
    private function sign(array $payload): string
    {
        $sorted = NowPaymentsSignature::sortRecursive($payload);

        return hash_hmac('sha512', json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $this->ipnSecret);
    }

    public function test_valid_signed_webhook_matches_attempt_by_payment_id_and_verifies(): void
    {
        Bus::fake();
        $attempt = $this->attempt('5524759814');

        $payload = ['payment_id' => 5524759814, 'payment_status' => 'finished', 'price_amount' => 25, 'price_currency' => 'usd'];

        $this->withHeaders(['x-nowpayments-sig' => $this->sign($payload)])
            ->postJson(route('api.webhooks.nowpayments'), $payload)
            ->assertOk()
            ->assertJson(['message' => 'Webhook processed']);

        Bus::assertDispatched(VerifyPaymentJob::class);
        $this->assertNotNull($attempt->fresh()->webhook_payload);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        Bus::fake();
        $this->attempt('5524759814');

        $payload = ['payment_id' => 5524759814, 'payment_status' => 'finished'];

        $this->withHeaders(['x-nowpayments-sig' => 'deadbeef'])
            ->postJson(route('api.webhooks.nowpayments'), $payload)
            ->assertStatus(401);

        Bus::assertNotDispatched(VerifyPaymentJob::class);
    }

    public function test_missing_payment_id_is_rejected(): void
    {
        $payload = ['payment_status' => 'finished', 'price_amount' => 25];

        $this->withHeaders(['x-nowpayments-sig' => $this->sign($payload)])
            ->postJson(route('api.webhooks.nowpayments'), $payload)
            ->assertStatus(400);
    }

    public function test_unknown_payment_id_returns_404(): void
    {
        $payload = ['payment_id' => 999, 'payment_status' => 'finished'];

        $this->withHeaders(['x-nowpayments-sig' => $this->sign($payload)])
            ->postJson(route('api.webhooks.nowpayments'), $payload)
            ->assertStatus(404);
    }

    public function test_pending_status_is_acknowledged_without_verifying(): void
    {
        Bus::fake();
        $this->attempt('5524759814');

        $payload = ['payment_id' => 5524759814, 'payment_status' => 'waiting'];

        $this->withHeaders(['x-nowpayments-sig' => $this->sign($payload)])
            ->postJson(route('api.webhooks.nowpayments'), $payload)
            ->assertOk();

        Bus::assertNotDispatched(VerifyPaymentJob::class);
    }

    public function test_verify_payment_reads_status_from_the_payment_endpoint(): void
    {
        config(['services.nowpayments.api_key' => 'live-key-xyz']);
        $attempt = $this->attempt('5524759814');

        Http::fake([
            '*' => Http::response(['payment_id' => 5524759814, 'payment_status' => 'finished'], 200),
        ]);

        $provider = new NowPaymentsProvider;

        $this->assertTrue($provider->verifyPayment($attempt));
        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->payment_status);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/payment/5524759814'));
    }

    public function test_webhook_with_unsorted_json_body_passes_signature_verification(): void
    {
        Bus::fake();
        $attempt = $this->attempt('5519782510');

        // Keys deliberately NOT in alphabetical order: the raw body differs from
        // the sorted JSON NOWPayments signs. The middleware used to HMAC the raw
        // body and reject every such notification with a 401.
        $payload = ['price_currency' => 'usd', 'payment_status' => 'finished', 'payment_id' => 5519782510, 'price_amount' => 35.7];

        $this->withHeaders(['x-nowpayments-sig' => $this->sign($payload)])
            ->postJson(route('api.webhooks.nowpayments'), $payload)
            ->assertOk()
            ->assertJson(['message' => 'Webhook processed']);

        Bus::assertDispatched(VerifyPaymentJob::class);
        $this->assertNotNull($attempt->fresh()->webhook_payload);
    }

    public function test_status_updates_for_the_same_payment_are_not_dropped_as_duplicates(): void
    {
        Bus::fake();
        $this->attempt('5524759814');

        // NOWPayments reuses payment_id across every status IPN. The old
        // idempotency key was payment_id alone, so this second ("finished")
        // webhook was swallowed as a replay and the order never confirmed.
        $waiting = ['payment_id' => 5524759814, 'payment_status' => 'waiting'];
        $this->withHeaders(['x-nowpayments-sig' => $this->sign($waiting)])
            ->postJson(route('api.webhooks.nowpayments'), $waiting)
            ->assertOk();

        $finished = ['payment_id' => 5524759814, 'payment_status' => 'finished'];
        $this->withHeaders(['x-nowpayments-sig' => $this->sign($finished)])
            ->postJson(route('api.webhooks.nowpayments'), $finished)
            ->assertOk()
            ->assertJson(['message' => 'Webhook processed']);

        Bus::assertDispatched(VerifyPaymentJob::class);
    }

    public function test_byte_identical_redelivery_is_deduplicated(): void
    {
        Bus::fake();
        $this->attempt('5524759814');

        $payload = ['payment_id' => 5524759814, 'payment_status' => 'finished'];
        $signature = $this->sign($payload);

        $this->withHeaders(['x-nowpayments-sig' => $signature])
            ->postJson(route('api.webhooks.nowpayments'), $payload)
            ->assertOk()
            ->assertJson(['message' => 'Webhook processed']);

        $this->withHeaders(['x-nowpayments-sig' => $signature])
            ->postJson(route('api.webhooks.nowpayments'), $payload)
            ->assertOk()
            ->assertJson(['message' => 'Already processed']);

        Bus::assertDispatchedTimes(VerifyPaymentJob::class, 1);
    }

    public function test_failed_delivery_is_not_marked_processed_so_the_retry_succeeds(): void
    {
        Bus::fake();

        // First delivery 404s (attempt not persisted yet). The old middleware
        // cached the event BEFORE processing, so the gateway's retry was
        // swallowed with "Already processed" and the payment was lost.
        $payload = ['payment_id' => 5524759814, 'payment_status' => 'finished'];
        $signature = $this->sign($payload);

        $this->withHeaders(['x-nowpayments-sig' => $signature])
            ->postJson(route('api.webhooks.nowpayments'), $payload)
            ->assertStatus(404);

        $this->attempt('5524759814');

        $this->withHeaders(['x-nowpayments-sig' => $signature])
            ->postJson(route('api.webhooks.nowpayments'), $payload)
            ->assertOk()
            ->assertJson(['message' => 'Webhook processed']);

        Bus::assertDispatched(VerifyPaymentJob::class);
    }

    public function test_partially_paid_webhook_dispatches_verification(): void
    {
        Bus::fake();
        $this->attempt('5519782510');

        $payload = ['payment_id' => 5519782510, 'payment_status' => 'partially_paid', 'pay_amount' => 39.638021, 'actually_paid' => 39.63];

        $this->withHeaders(['x-nowpayments-sig' => $this->sign($payload)])
            ->postJson(route('api.webhooks.nowpayments'), $payload)
            ->assertOk();

        Bus::assertDispatched(VerifyPaymentJob::class);
    }

    public function test_blank_ipn_secret_fails_closed(): void
    {
        Bus::fake();
        config(['services.nowpayments.ipn_secret' => '']);
        $this->attempt('5524759814');

        $payload = ['payment_id' => 5524759814, 'payment_status' => 'finished'];
        $signature = hash_hmac('sha512', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), '');

        $this->withHeaders(['x-nowpayments-sig' => $signature])
            ->postJson(route('api.webhooks.nowpayments'), $payload)
            ->assertStatus(401);

        Bus::assertNotDispatched(VerifyPaymentJob::class);
    }

    public function test_verify_payment_accepts_partially_paid_within_tolerance(): void
    {
        config(['services.nowpayments.api_key' => 'live-key-xyz']);
        $attempt = $this->attempt('5519782510');

        // The real-world case: 39.63 received against 39.638021 invoiced —
        // a 0.02% shortfall from an exchange withdrawal fee.
        Http::fake([
            '*' => Http::response([
                'payment_id' => 5519782510,
                'payment_status' => 'partially_paid',
                'pay_amount' => 39.638021,
                'actually_paid' => 39.63,
            ], 200),
        ]);

        $provider = new NowPaymentsProvider;

        $this->assertTrue($provider->verifyPayment($attempt));
        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->payment_status);
    }

    public function test_verify_payment_rejects_partially_paid_outside_tolerance(): void
    {
        config(['services.nowpayments.api_key' => 'live-key-xyz']);
        $attempt = $this->attempt('5519782510');

        Http::fake([
            '*' => Http::response([
                'payment_id' => 5519782510,
                'payment_status' => 'partially_paid',
                'pay_amount' => 100,
                'actually_paid' => 60,
            ], 200),
        ]);

        $provider = new NowPaymentsProvider;

        $this->assertFalse($provider->verifyPayment($attempt));
        $this->assertSame(PaymentStatus::PartiallyPaid, $attempt->fresh()->payment_status);
    }

    public function test_verify_payment_rejects_partially_paid_with_nothing_received(): void
    {
        config(['services.nowpayments.api_key' => 'live-key-xyz']);
        $attempt = $this->attempt('5519782510');

        Http::fake([
            '*' => Http::response([
                'payment_id' => 5519782510,
                'payment_status' => 'partially_paid',
                'pay_amount' => 39.638021,
                'actually_paid' => 0,
            ], 200),
        ]);

        $provider = new NowPaymentsProvider;

        $this->assertFalse($provider->verifyPayment($attempt));
        $this->assertSame(PaymentStatus::PartiallyPaid, $attempt->fresh()->payment_status);
    }
}
