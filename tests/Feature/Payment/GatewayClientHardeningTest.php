<?php

namespace Tests\Feature\Payment;

use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Interfaces\PaymentProviderInterface;
use App\Domain\Payment\Jobs\ExpireStalePaymentSessionsJob;
use App\Domain\Payment\Providers\FlutterwavePaymentProvider;
use App\Domain\Payment\Services\FlutterwaveService;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\Payment\Support\CustomerFailureCopy;
use App\Domain\Payment\Support\GatewayRateLimit;
use App\Domain\PaymentInsights\Services\FailureReasonClassifier;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The gateway is spoken to the way it asks to be.
 *
 * Its guidance: a hung request is answered by the gateway itself at 28
 * seconds with a 503; a charge that timed out must be looked up by
 * reference before anything is sent again; other requests are retried after
 * a short interval; a 429 asks for a graceful back-off. Every call used to be
 * a bare bearer request with no timeout and no back-off, and the sweepers
 * verified every stale session back to back.
 */
class GatewayClientHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.flutterwave.secret_key' => 'FLWSECK_TEST-realish',
            'services.flutterwave.public_key' => 'FLWPUBK_TEST-realish',
        ]);

        GatewayRateLimit::clear('flutterwave');
        Sleep::fake();

        // A call this test did not fake must fail loudly, never reach the
        // gateway.
        Http::preventStrayRequests();
    }

    private function attempt(string $currency = 'USD', ?string $gatewayReference = '777'): PaymentAttempt
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'payment_status' => PaymentStatus::Pending]);

        return PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'flutterwave',
            'gateway_reference' => $gatewayReference,
            'idempotency_key' => 'hard_'.Str::random(10),
            'currency' => $currency,
            'amount' => $currency === 'XAF' ? 6600 : 25.00,
            'payment_status' => PaymentStatus::Pending,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function paidBody(PaymentAttempt $attempt, int $id = 777): array
    {
        return [
            'status' => 'success',
            'data' => [
                'id' => $id,
                'tx_ref' => $attempt->idempotency_key,
                'status' => 'successful',
                'amount' => (float) $attempt->amount,
                'currency' => $attempt->currency,
            ],
        ];
    }

    public function test_a_read_backs_off_on_a_rate_limit_then_succeeds_and_notes_the_cool_down(): void
    {
        $attempt = $this->attempt();

        Http::fake([
            'api.flutterwave.com/v3/transactions/777/verify' => Http::sequence()
                ->push(['status' => 'error', 'message' => 'Too many requests'], 429, ['Retry-After' => '2'])
                ->push($this->paidBody($attempt)),
        ]);

        $this->assertTrue(app(FlutterwavePaymentProvider::class)->verifyPayment($attempt));

        Http::assertSentCount(2);
        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->payment_status);
        $this->assertTrue(GatewayRateLimit::isCoolingDown('flutterwave'));
        Sleep::assertSleptTimes(1);
    }

    public function test_a_read_gives_up_after_its_retries_without_throwing(): void
    {
        $attempt = $this->attempt();

        Http::fake([
            'api.flutterwave.com/v3/transactions/*' => Http::response(['status' => 'error'], 503),
        ]);

        $this->assertFalse(app(FlutterwavePaymentProvider::class)->verifyPayment($attempt));

        // Four tries on the id, then four on the reference fallback.
        Http::assertSentCount(8);
        $this->assertSame(PaymentStatus::Pending, $attempt->fresh()->payment_status);
    }

    public function test_a_charge_is_never_retried_and_a_taken_charge_is_recognised_after_a_server_error(): void
    {
        $attempt = $this->attempt('XAF', null);

        Http::fake([
            'api.flutterwave.com/v3/charges*' => Http::response(['status' => 'error', 'message' => 'An error occurred. Please contact support.'], 503),
            'api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response($this->paidBody($attempt, 4242)),
        ]);

        $result = app(FlutterwavePaymentProvider::class)->chargeMobileMoney($attempt, '237670000000', 'MTN');

        $this->assertSame('confirmed', $result['status']);
        $this->assertSame('4242', $attempt->fresh()->gateway_reference);
        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->payment_status);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/charges'));
    }

    public function test_a_timed_out_prompt_charge_the_gateway_holds_stays_open(): void
    {
        $attempt = $this->attempt('XAF', null);

        Http::fake([
            'api.flutterwave.com/v3/charges*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 35000 milliseconds'),
            'api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response([
                'status' => 'success',
                'data' => ['id' => 4243, 'tx_ref' => $attempt->idempotency_key, 'status' => 'pending', 'processor_response' => 'Transaction in progress', 'amount' => 6600, 'currency' => 'XAF'],
            ]),
        ]);

        $result = app(FlutterwavePaymentProvider::class)->chargeMobileMoney($attempt, '237670000000', 'MTN');

        $this->assertSame('awaiting_confirmation', $result['status']);
        $this->assertStringStartsWith('We sent a payment request of ', $result['message']);
        $this->assertSame('4243', $attempt->fresh()->gateway_reference);
        $this->assertSame(PaymentStatus::Pending, $attempt->fresh()->payment_status);
    }

    public function test_a_timed_out_charge_unknown_to_the_gateway_fails_as_a_timeout(): void
    {
        $attempt = $this->attempt('XAF', null);

        Http::fake([
            'api.flutterwave.com/v3/charges*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 35000 milliseconds'),
            'api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response(['status' => 'error', 'message' => 'No transaction was found for this id'], 404),
        ]);

        $result = app(FlutterwavePaymentProvider::class)->chargeMobileMoney($attempt, '237670000000', 'MTN');

        $this->assertSame('failed', $result['status']);
        $this->assertSame(ConnectionException::class, $result['failure']['exception_class']);

        $code = app(FailureReasonClassifier::class)->classify('flutterwave', $result['message'], 'awaiting_confirmation', $result['failure'])['code'];
        $this->assertSame('provider_timeout', $code);
        $this->assertSame(__('checkout.failure_reason_provider_timeout'), CustomerFailureCopy::sentence($code));
    }

    public function test_a_rate_limited_charge_is_reported_honestly_and_noted(): void
    {
        $attempt = $this->attempt('XAF', null);

        Http::fake([
            'api.flutterwave.com/v3/charges*' => Http::response(['status' => 'error', 'message' => 'Too many requests'], 429, ['Retry-After' => '30']),
        ]);

        $result = app(FlutterwavePaymentProvider::class)->chargeMobileMoney($attempt, '237670000000', 'MTN');

        $this->assertSame('failed', $result['status']);
        Http::assertSentCount(1);

        $remaining = GatewayRateLimit::secondsRemaining('flutterwave');
        $this->assertGreaterThan(0, $remaining);
        $this->assertLessThanOrEqual(30, $remaining);

        $code = app(FailureReasonClassifier::class)->classify('flutterwave', $result['message'], 'awaiting_method', $result['failure'])['code'];
        $this->assertSame('provider_rate_limited', $code);
        $this->assertSame('provider_error', CustomerFailureCopy::customerCode($code));
    }

    public function test_the_sweeper_spends_a_budget_per_run_and_stands_down_during_a_cool_down(): void
    {
        Mail::fake();
        Notification::fake();

        // The sweep also asks the gateway for refused cards on each session
        // it expires; that listing is answered empty here.
        Http::fake([
            'api.flutterwave.com/v3/transactions*' => Http::response(['status' => 'success', 'data' => [], 'meta' => ['page_info' => ['total_pages' => 1]]]),
        ]);

        $budget = ExpireStalePaymentSessionsJob::MAX_GATEWAY_CHECKS_PER_RUN;
        $sessions = collect(range(1, $budget + 3))->map(fn () => $this->staleCheckout());

        $provider = $this->createMock(PaymentProviderInterface::class);
        $provider->expects($this->exactly($budget))->method('verifyPayment')->willReturn(false);
        $factory = $this->createMock(PaymentGatewayFactory::class);
        $factory->method('getProvider')->willReturn($provider);
        $this->app->instance(PaymentGatewayFactory::class, $factory);

        (new ExpireStalePaymentSessionsJob)->handle(app(PaymentSessionService::class), app(OrderService::class));

        $this->assertSame($budget, $sessions->filter(fn (PaymentSession $session) => $session->fresh()->status === 'expired')->count());
        $this->assertSame(3, $sessions->filter(fn (PaymentSession $session) => $session->fresh()->status === 'awaiting_confirmation')->count(), 'sessions past the budget wait for the next run');
        Sleep::assertSleptTimes($budget - 1);

        // The gateway asked us to slow down: nothing is verified and nothing
        // is expired until the cool-down passes.
        GatewayRateLimit::noteRateLimited('flutterwave', 60);
        $quiet = $this->createMock(PaymentProviderInterface::class);
        $quiet->expects($this->never())->method('verifyPayment');
        $factory = $this->createMock(PaymentGatewayFactory::class);
        $factory->method('getProvider')->willReturn($quiet);
        $this->app->instance(PaymentGatewayFactory::class, $factory);

        (new ExpireStalePaymentSessionsJob)->handle(app(PaymentSessionService::class), app(OrderService::class));

        $this->assertSame(3, $sessions->filter(fn (PaymentSession $session) => $session->fresh()->status === 'awaiting_confirmation')->count());
    }

    public function test_bank_lists_are_read_once_a_day(): void
    {
        Http::fake([
            'api.flutterwave.com/v3/banks/NG' => Http::response(['status' => 'success', 'data' => [['code' => '044', 'name' => 'A bank']]]),
        ]);

        $service = app(FlutterwaveService::class);

        $this->assertCount(1, $service->getBanks('NG'));
        $this->assertCount(1, $service->getBanks('ng'));

        Http::assertSentCount(1);
    }

    public function test_no_gateway_call_bypasses_the_shared_client(): void
    {
        foreach ([
            app_path('Domain/Payment/Providers/FlutterwavePaymentProvider.php'),
            app_path('Domain/Payment/Services/FlutterwaveService.php'),
        ] as $file) {
            $this->assertStringNotContainsString('Http::withToken(', file_get_contents($file), basename($file).' still makes a bare gateway call');
        }
    }

    /** An abandoned mobile money checkout, well past the sweeper's floor. */
    private function staleCheckout(): PaymentSession
    {
        $attempt = $this->attempt('USD', null);
        $createdAt = now()->subMinutes(45);

        $session = PaymentSession::create([
            'payment_attempt_id' => $attempt->id,
            'provider' => 'flutterwave',
            'session_type' => 'card',
            'status' => 'awaiting_confirmation',
            'client_reference' => 'SESS_'.Str::random(20),
            'amount' => 25.00,
            'currency' => 'USD',
            'display_currency' => 'USD',
            'customer_email' => $attempt->user->email,
            'expires_at' => now()->subMinutes(30),
        ]);
        $session->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $session;
    }
}
