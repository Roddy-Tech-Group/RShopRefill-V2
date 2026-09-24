<?php

namespace Tests\Feature\Payment;

use App\Domain\Order\Events\PaymentConfirmed;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Jobs\RefundPaymentJob;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The customer-facing "verify my payment" button. It used to run its own
 * copy of the settlement chain with no row lock and no duplicate-payment
 * guard, beside the queued VerifyPaymentJob that has both. It now runs the
 * same job inline, so a double tap, a tap racing the webhook, or a second
 * attempt paying an order that is already paid all end the way the job ends.
 */
class PaymentSessionVerifyEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Order, 1: PaymentAttempt, 2: PaymentSession, 3: User}
     */
    private function hostedCheckout(?User $user = null, ?Order $order = null): array
    {
        $user ??= User::factory()->create();

        $order ??= Order::create([
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
            'payment_status' => 'pending',
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

    public function test_verifying_settles_the_order_once_however_many_times_it_is_tapped(): void
    {
        Event::fake([PaymentConfirmed::class]);
        // No Paystack secret configured: the mock verify answers "paid".
        [$order, $attempt, $session, $user] = $this->hostedCheckout();

        $this->actingAs($user)
            ->postJson(route('api.payment-sessions.verify', $session->id))
            ->assertOk()
            ->assertJson(['status' => 'confirmed']);

        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame('confirmed', $session->fresh()->status);
        Event::assertDispatchedTimes(PaymentConfirmed::class, 1);

        // The second tap finds it done and fires nothing again.
        $this->actingAs($user)
            ->postJson(route('api.payment-sessions.verify', $session->id))
            ->assertOk()
            ->assertJson(['status' => 'confirmed']);

        Event::assertDispatchedTimes(PaymentConfirmed::class, 1);
    }

    /**
     * The order was already paid through another attempt (a retry that
     * landed first). Verifying the second attempt must not deliver the order
     * twice: it marks the money received and queues its refund, exactly as
     * the webhook path does.
     */
    public function test_a_second_payment_for_a_paid_order_is_refunded_not_fulfilled_again(): void
    {
        Event::fake([PaymentConfirmed::class]);
        Queue::fake([RefundPaymentJob::class]);

        [$order, $first, , $user] = $this->hostedCheckout();
        $order->update(['payment_status' => PaymentStatus::Paid]);
        $first->update(['payment_status' => PaymentStatus::Paid]);

        [, $second, $secondSession] = $this->hostedCheckout($user, $order);

        $this->actingAs($user)
            ->postJson(route('api.payment-sessions.verify', $secondSession->id))
            ->assertOk();

        $this->assertSame(PaymentStatus::Paid, $second->fresh()->payment_status, 'the money did arrive');
        Queue::assertPushed(RefundPaymentJob::class, 1);
        Event::assertNotDispatched(PaymentConfirmed::class);
        $this->assertNotSame('confirmed', $secondSession->fresh()->status, 'a duplicate payment does not confirm a second session');
    }

    public function test_another_customer_cannot_verify_someone_elses_session(): void
    {
        [, , $session] = $this->hostedCheckout();

        $this->actingAs(User::factory()->create())
            ->postJson(route('api.payment-sessions.verify', $session->id))
            ->assertStatus(403);
    }
}
