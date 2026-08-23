<?php

namespace Tests\Feature\Payment;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Events\OrderConfirmed;
use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Jobs\VerifyPaymentJob;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The hosted-return race: the customer's browser lands on /checkout/return
 * BEFORE the gateway webhook arrives. The return leg used to stamp the
 * attempt Paid and then only redirect, trusting the webhook to finish the
 * order - but the webhook path stands down when it finds the attempt already
 * Paid, so the order sat Pending forever with the money kept. The return leg
 * must now run the full settlement chain itself, and VerifyPaymentJob must
 * finish any stranded Paid attempt instead of early-returning on it.
 */
class HostedReturnSettlementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A hosted-checkout in flight on a backup gateway: order open, attempt
     * pending, session parked on the awaiting_redirect state the customer is
     * sent away in.
     *
     * @return array{0: Order, 1: PaymentAttempt, 2: PaymentSession, 3: User}
     */
    private function hostedCheckout(string $attemptStatus = 'pending'): array
    {
        $user = User::factory()->create();

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
            'payment_method' => 'paystack',
            'payment_status' => PaymentStatus::Pending,
        ]);

        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'paystack',
            'idempotency_key' => (string) Str::uuid(),
            'currency' => 'NGN',
            'amount' => 5000,
            'payment_status' => $attemptStatus,
        ]);

        $session = PaymentSession::create([
            'payment_attempt_id' => $attempt->id,
            'provider' => 'paystack',
            'session_type' => 'card',
            'status' => 'awaiting_redirect',
            'client_reference' => 'SESS_'.Str::random(20),
            'amount' => 5000.00,
            'currency' => 'NGN',
            'display_currency' => 'NGN',
            'customer_email' => $user->email,
            'expires_at' => now()->addMinutes(15),
        ]);

        return [$order, $attempt, $session, $user];
    }

    public function test_the_return_leg_settles_the_order_before_redirecting(): void
    {
        Event::fake([OrderConfirmed::class]);

        // No Paystack secret configured -> the provider's MOCK verify answers
        // "paid" locally, standing in for a customer who really paid.
        [$order, $attempt, $session, $user] = $this->hostedCheckout();

        $response = $this->actingAs($user)
            ->get("/checkout/return/{$session->id}?reference={$attempt->idempotency_key}");

        $response->assertRedirectContains($order->order_number);

        // The whole chain ran inline - no webhook needed.
        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status, 'the order must be settled by the return leg itself');
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $this->assertSame('confirmed', $session->fresh()->status);
        Event::assertDispatched(OrderConfirmed::class);
    }

    public function test_verify_job_finishes_a_stranded_paid_attempt_without_reverifying(): void
    {
        Event::fake([OrderConfirmed::class]);

        // The exact stranded shape the old return leg produced: attempt Paid,
        // order still Pending, session still parked.
        [$order, $attempt, $session] = $this->hostedCheckout('paid');

        // The attempt is already Paid, so the job must NOT ask the gateway again.
        $factory = $this->createMock(PaymentGatewayFactory::class);
        $factory->expects($this->never())->method('getProvider');

        (new VerifyPaymentJob($attempt))->handle($factory, app(OrderService::class));

        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status, 'a stranded Paid attempt must settle its order');
        $this->assertSame('confirmed', $session->fresh()->status);
        Event::assertDispatched(OrderConfirmed::class);
    }

    public function test_verify_job_stands_down_when_settlement_already_landed(): void
    {
        Event::fake([OrderConfirmed::class]);

        [$order, $attempt, $session] = $this->hostedCheckout('paid');
        $order->update(['payment_status' => PaymentStatus::Paid]);
        $session->update(['status' => 'confirmed']);

        $factory = $this->createMock(PaymentGatewayFactory::class);
        $factory->expects($this->never())->method('getProvider');

        (new VerifyPaymentJob($attempt))->handle($factory, app(OrderService::class));

        // Nothing re-fires: replaying the job on a settled payment is a no-op.
        Event::assertNotDispatched(OrderConfirmed::class);
    }
}
