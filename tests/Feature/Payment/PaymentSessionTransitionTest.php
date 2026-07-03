<?php

namespace Tests\Feature\Payment;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression coverage for the production crash where confirming a session that
 * sat in `awaiting_confirmation` (e.g. a NowPayments crypto wallet funding)
 * threw "Invalid payment session status transition from awaiting_confirmation
 * to processing". confirmSession/failSession route every pre-terminal state
 * through `processing`, so the matrix must permit it.
 */
class PaymentSessionTransitionTest extends TestCase
{
    use RefreshDatabase;

    private function sessionWithStatus(string $status): PaymentSession
    {
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
            'payment_method' => 'nowpayments',
        ]);
        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'nowpayments',
            'idempotency_key' => (string) Str::uuid(),
            'currency' => 'USD',
            'amount' => 25,
        ]);

        return PaymentSession::create([
            'payment_attempt_id' => $attempt->id,
            'provider' => 'nowpayments',
            'session_type' => 'crypto',
            'status' => $status,
            'client_reference' => 'SESS_'.Str::random(20),
            'amount' => 25.00,
            'currency' => 'USD',
            'display_currency' => 'USD',
            'customer_email' => $user->email,
        ]);
    }

    public function test_awaiting_confirmation_can_transition_to_processing(): void
    {
        $session = $this->sessionWithStatus('awaiting_confirmation');

        $session->transitionTo('processing');

        $this->assertSame('processing', $session->fresh()->status);
    }

    public function test_awaiting_confirmation_reaches_confirmed_via_processing(): void
    {
        $session = $this->sessionWithStatus('awaiting_confirmation');

        $session->transitionTo('processing');
        $session->transitionTo('confirmed');

        $this->assertSame('confirmed', $session->fresh()->status);
    }

    /**
     * Every state PaymentSessionService::expire() treats as expirable must be
     * permitted to reach 'expired' in the transition matrix - otherwise
     * ExpireStalePaymentSessionsJob throws on it every run (the awaiting_method
     * / awaiting_customer_action prod-log flood this regression-guards).
     */
    public function test_every_expirable_state_can_transition_to_expired(): void
    {
        foreach (['awaiting_payment', 'awaiting_method', 'awaiting_transfer', 'awaiting_confirmation', 'awaiting_customer_action'] as $state) {
            $session = $this->sessionWithStatus($state);

            $session->transitionTo('expired');

            $this->assertSame('expired', $session->fresh()->status, "Could not expire from {$state}");
        }
    }
}
