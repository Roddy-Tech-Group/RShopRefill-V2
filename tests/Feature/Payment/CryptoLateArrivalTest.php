<?php

namespace Tests\Feature\Payment;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Events\PaymentConfirmed;
use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use App\Jobs\VerifyPaymentJob;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Coins that land after our sweeper cancelled the order.
 *
 * The customer paid the quoted amount and the gateway has it, so the order
 * is settled and sent for fulfilment like any late payment, and ops are
 * told, because an order coming back from Cancelled is worth a look. What
 * must never happen is the money sitting at the gateway with a cancelled
 * order and nobody knowing: that was only fixable with a hand-written
 * script.
 */
class CryptoLateArrivalTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> */
    private array $alerts = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.nowpayments.api_key' => 'live-key-xyz']);

        $alerts = &$this->alerts;
        $this->app->instance(OperationalAlerter::class, new class($alerts) extends OperationalAlerter
        {
            public function __construct(private array &$sent) {}

            public function send(AlertSeverity $severity, string $key, string $title, string $message, array $context = [], ?string $runbook = null): bool
            {
                $this->sent[] = compact('severity', 'key', 'title', 'message', 'context');

                return true;
            }
        });
    }

    /**
     * A crypto order our sweeper closed: order Failed/Cancelled, session
     * expired, attempt in the state the sweeper (or the gateway) left it.
     *
     * @return array{0: Order, 1: PaymentAttempt, 2: PaymentSession}
     */
    private function sweptOrder(PaymentStatus $attemptStatus, ?string $gatewayStatus = null): array
    {
        $user = User::factory()->create();

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
            'payment_method' => 'crypto',
            'payment_status' => PaymentStatus::Failed,
            'order_status' => OrderStatus::Cancelled,
            'failed_at' => now()->subHours(3),
        ]);

        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'nowpayments',
            'gateway_reference' => '5524759814',
            'idempotency_key' => (string) Str::uuid(),
            'currency' => 'USD',
            'amount' => 25,
            'payment_status' => $attemptStatus,
            'failed_at' => now()->subHours(3),
            'metadata' => $gatewayStatus !== null ? ['crypto' => ['status' => $gatewayStatus, 'status_rank' => 1]] : null,
        ]);

        $session = PaymentSession::create([
            'payment_attempt_id' => $attempt->id,
            'provider' => 'nowpayments',
            'session_type' => 'crypto',
            'status' => 'expired',
            'client_reference' => 'SESS_'.Str::random(20),
            'amount' => 25,
            'currency' => 'USD',
            'display_currency' => 'USD',
            'customer_email' => $user->email,
            'expires_at' => now()->subHours(3),
        ]);

        return [$order, $attempt, $session];
    }

    private function gatewaySaysFinished(): void
    {
        Http::fake(['*' => Http::response([
            'payment_id' => 5524759814,
            'payment_status' => 'finished',
            'pay_amount' => 0.01,
            'actually_paid' => 0.01,
            'price_amount' => 25,
            'price_currency' => 'usd',
        ], 200)]);
    }

    private function runJob(PaymentAttempt $attempt): void
    {
        (new VerifyPaymentJob($attempt))->handle(app(PaymentGatewayFactory::class), app(OrderService::class));
    }

    public function test_coins_landing_after_the_sweep_settle_the_cancelled_order_and_tell_ops(): void
    {
        Event::fake([PaymentConfirmed::class]);
        [$order, $attempt, $session] = $this->sweptOrder(PaymentStatus::Expired, 'confirming');
        $this->gatewaySaysFinished();

        $this->runJob($attempt);

        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status, 'the cancelled order is back in flight');
        $this->assertSame('confirmed', $session->fresh()->status);
        Event::assertDispatched(PaymentConfirmed::class);

        $this->assertNotNull(data_get($attempt->fresh()->metadata, 'crypto.late_arrival'));

        $alert = collect($this->alerts)->firstWhere('key', 'crypto:late-arrival:'.$attempt->id);
        $this->assertNotNull($alert, 'ops must hear about an order coming back from Cancelled');
        $this->assertSame(AlertSeverity::Sev2, $alert['severity']);
        $this->assertStringContainsString($order->order_number, $alert['message']);
    }

    /**
     * Our sweeper can also leave the attempt Failed (not Expired). Failed used
     * to be final for orders, so the coins landed against a dead attempt and
     * nothing moved. Only a failure the gateway itself reported is final.
     */
    public function test_an_attempt_our_sweeper_failed_is_still_settled_when_the_coins_land(): void
    {
        Event::fake([PaymentConfirmed::class]);
        [$order, $attempt] = $this->sweptOrder(PaymentStatus::Failed, 'confirming');
        $this->gatewaySaysFinished();

        $this->runJob($attempt);

        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        Event::assertDispatched(PaymentConfirmed::class);
        $this->assertNotNull(collect($this->alerts)->firstWhere('key', 'crypto:late-arrival:'.$attempt->id));
    }

    public function test_a_failure_the_gateway_itself_reported_stays_final(): void
    {
        Event::fake([PaymentConfirmed::class]);
        [$order, $attempt] = $this->sweptOrder(PaymentStatus::Failed, 'failed');
        Http::fake();

        $this->runJob($attempt);

        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::Failed, $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
        Event::assertNotDispatched(PaymentConfirmed::class);
        $this->assertSame([], $this->alerts);
    }

    public function test_an_ordinary_on_time_payment_raises_no_late_arrival_alert(): void
    {
        Event::fake([PaymentConfirmed::class]);
        [$order, $attempt, $session] = $this->sweptOrder(PaymentStatus::Pending, 'confirming');
        $order->update(['payment_status' => PaymentStatus::Pending, 'order_status' => OrderStatus::Pending, 'failed_at' => null]);
        $session->update(['status' => 'awaiting_transfer']);
        $this->gatewaySaysFinished();

        $this->runJob($attempt);

        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertNull(data_get($attempt->fresh()->metadata, 'crypto.late_arrival'));
        $this->assertSame([], collect($this->alerts)->where('key', 'crypto:late-arrival:'.$attempt->id)->all());
    }
}
