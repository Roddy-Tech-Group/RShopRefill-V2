<?php

namespace Tests\Feature\Payment;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Interfaces\PaymentProviderInterface;
use App\Domain\Payment\Jobs\ExpireStalePaymentSessionsJob;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Wallet\Events\FundingFailed;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The stale-session sweeper must drive EVERY record an abandoned checkout
 * created to a terminal state: session -> expired, order -> cancelled, and
 * the PaymentAttempt -> expired. The attempt is what the admin Transactions
 * page lists - leaving it on `pending` showed abandoned checkouts as Pending
 * in admin forever while the customer's own dashboard said Cancelled.
 */
class ExpireStalePaymentSessionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An abandoned checkout, aged $ageMinutes.
     *
     * The age has to be real, not just implied by expires_at. Sessions are minted
     * with a 15-minute TTL, so a session that lapsed 20 minutes ago was created 35
     * minutes ago - it cannot also have been created this instant. The sweeper's
     * promised-window floor measures from created_at, so a fixture that leaves
     * created_at at now() describes a session that could never exist and is waved
     * straight through the floor.
     *
     * The default is comfortably past both the TTL and the 30-minute floor. Pass a
     * smaller age to model a session the customer still has time to pay.
     */
    private function staleCheckout(int $ageMinutes = 45): array
    {
        $user = User::factory()->create();

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
            'payment_method' => 'flutterwave',
            'payment_status' => PaymentStatus::Unpaid,
        ]);

        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'flutterwave',
            'idempotency_key' => (string) Str::uuid(),
            'currency' => 'USD',
            'amount' => 25,
            'payment_status' => PaymentStatus::Pending,
        ]);

        $createdAt = now()->subMinutes($ageMinutes);

        $session = PaymentSession::create([
            'payment_attempt_id' => $attempt->id,
            'provider' => 'flutterwave',
            'session_type' => 'card',
            'status' => 'awaiting_confirmation',
            'client_reference' => 'SESS_'.Str::random(20),
            'amount' => 25.00,
            'currency' => 'USD',
            'display_currency' => 'USD',
            'customer_email' => $user->email,
            // The real 15-minute TTL, measured from when the session was minted.
            'expires_at' => now()->subMinutes($ageMinutes - 15),
        ]);

        // created_at is not fillable, so create() would have stamped it now() and
        // the session would look brand new to the promised-window floor.
        $session->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return [$order, $attempt, $session];
    }

    /** Force the gateway to report "unpaid" so the checkout is genuinely abandoned. */
    private function fakeGatewayUnpaid(): void
    {
        $provider = $this->createMock(PaymentProviderInterface::class);
        $provider->method('verifyPayment')->willReturn(false);
        $factory = $this->createMock(PaymentGatewayFactory::class);
        $factory->method('getProvider')->willReturn($provider);
        $this->app->instance(PaymentGatewayFactory::class, $factory);
    }

    public function test_abandoned_checkout_reaches_terminal_state_everywhere(): void
    {
        $this->fakeGatewayUnpaid();
        [$order, $attempt, $session] = $this->staleCheckout();

        (new ExpireStalePaymentSessionsJob)->handle(
            app(PaymentSessionService::class),
            app(OrderService::class),
        );

        $this->assertSame('expired', $session->fresh()->status);
        $this->assertSame(PaymentStatus::Expired, $attempt->fresh()->payment_status);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Failed, $order->fresh()->payment_status);
    }

    /**
     * The bank-transfer screens promise the customer a 30-minute window, and the
     * virtual account really does stay open that long, so a session past its own
     * 15-minute TTL but still inside that promise must survive the sweep. Killing
     * it marks the funding Failed while the customer's transfer is in flight.
     */
    /**
     * The production bug: a customer closes the payment popup, the browser
     * telemetry endpoint fails the session immediately, and nothing ever tells
     * the order. The session is no longer in ACTIVE_STATUSES, so the sweep used
     * to skip it and the order sat Pending on the customer's dashboard forever.
     */
    public function test_order_orphaned_behind_a_telemetry_failed_session_is_still_cleaned_up(): void
    {
        $this->fakeGatewayUnpaid();
        [$order, $attempt, $session] = $this->staleCheckout();

        // Exactly what PaymentTelemetryController does on popup-close: fail the
        // session and nothing else.
        app(PaymentSessionService::class)->failSession($session, 'popup_closed');

        $this->assertSame('failed', $session->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $attempt->fresh()->payment_status, 'precondition: attempt is still open');
        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status, 'precondition: order is still open');

        (new ExpireStalePaymentSessionsJob)->handle(
            app(PaymentSessionService::class),
            app(OrderService::class),
        );

        // The order and attempt now reach terminal states...
        $this->assertSame(PaymentStatus::Expired, $attempt->fresh()->payment_status);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Failed, $order->fresh()->payment_status);
        // ...while the session keeps the reason the customer was actually given.
        $this->assertSame('failed', $session->fresh()->status);
    }

    /**
     * The reason this cascade lives in the sweep rather than in failSession():
     * a customer can close the popup moments after authorising. The sweep's
     * last-chance gateway verification must still save that order.
     */
    public function test_a_paid_order_behind_a_failed_session_is_never_cancelled(): void
    {
        [$order, $attempt, $session] = $this->staleCheckout();
        app(PaymentSessionService::class)->failSession($session, 'popup_closed');

        // The gateway says the money actually arrived.
        $provider = $this->createMock(PaymentProviderInterface::class);
        $provider->method('verifyPayment')->willReturnCallback(function () use ($attempt) {
            $attempt->update(['payment_status' => PaymentStatus::Paid]);

            return true;
        });
        $factory = $this->createMock(PaymentGatewayFactory::class);
        $factory->method('getProvider')->willReturn($provider);
        $this->app->instance(PaymentGatewayFactory::class, $factory);

        (new ExpireStalePaymentSessionsJob)->handle(
            app(PaymentSessionService::class),
            app(OrderService::class),
        );

        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->payment_status);
        $this->assertNotSame(OrderStatus::Cancelled, $order->fresh()->order_status, 'a paid order must never be cancelled by the sweep');
    }

    public function test_session_inside_the_promised_window_survives_the_sweep(): void
    {
        $this->fakeGatewayUnpaid();

        // Minted 20 minutes ago: lapsed by TTL 5 minutes ago, but the customer was
        // told they had 30 minutes and 10 of them are left.
        [$order, $attempt, $session] = $this->staleCheckout(20);

        (new ExpireStalePaymentSessionsJob)->handle(
            app(PaymentSessionService::class),
            app(OrderService::class),
        );

        $this->assertSame('awaiting_confirmation', $session->fresh()->status, 'The session was killed inside its promised window');
        $this->assertSame(PaymentStatus::Pending, $attempt->fresh()->payment_status);
        $this->assertNotSame(OrderStatus::Cancelled, $order->fresh()->order_status);

        // Once the promise has run out, the same checkout does get swept.
        $session->forceFill(['created_at' => now()->subMinutes(45)])->save();

        (new ExpireStalePaymentSessionsJob)->handle(
            app(PaymentSessionService::class),
            app(OrderService::class),
        );

        $this->assertSame('expired', $session->fresh()->status);
        $this->assertSame(PaymentStatus::Expired, $attempt->fresh()->payment_status);
    }

    /**
     * An abandoned WALLET FUNDING must fail through the service, not a bare
     * status write. The direct update skipped the FundingFailed event, so the
     * customer never heard their top-up died and no failure payload reached
     * the attempt.
     */
    public function test_abandoned_wallet_funding_fails_through_the_service_and_notifies(): void
    {
        $this->fakeGatewayUnpaid();

        $user = User::factory()->create();
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'currency' => Currency::USD,
            'balance' => 0.00,
            'locked_balance' => 0.00,
            'is_active' => true,
        ]);

        $funding = WalletFunding::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'reference' => 'FUND-'.Str::upper(Str::random(10)),
            'currency' => 'USD',
            'display_currency' => 'USD',
            'amount' => 50,
            'requested_amount' => 50,
            'settled_amount_usd' => 50,
            'exchange_rate_snapshot' => 1.0,
            'gateway' => 'flutterwave',
            'status' => FundingStatus::Pending,
            'idempotency_key' => 'fund_init_'.Str::random(16),
        ]);

        $attempt = PaymentAttempt::create([
            'user_id' => $user->id,
            'payable_type' => WalletFunding::class,
            'payable_id' => $funding->id,
            'gateway' => 'flutterwave',
            'idempotency_key' => (string) Str::uuid(),
            'currency' => 'USD',
            'amount' => 50,
            'payment_status' => PaymentStatus::Pending,
        ]);

        $createdAt = now()->subMinutes(45);
        $session = PaymentSession::create([
            'payment_attempt_id' => $attempt->id,
            'provider' => 'flutterwave',
            'session_type' => 'card',
            'status' => 'awaiting_payment',
            'client_reference' => 'SESS_'.Str::random(20),
            'amount' => 50.00,
            'currency' => 'USD',
            'display_currency' => 'USD',
            'customer_email' => $user->email,
            'expires_at' => now()->subMinutes(30),
        ]);
        $session->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        Event::fake([FundingFailed::class]);

        (new ExpireStalePaymentSessionsJob)->handle(
            app(PaymentSessionService::class),
            app(OrderService::class),
        );

        $this->assertSame(FundingStatus::Failed, $funding->fresh()->status);
        $this->assertSame(PaymentStatus::Failed, $attempt->fresh()->payment_status, 'the attempt must carry the failure');
        $this->assertNotNull($funding->fresh()->failed_reason, 'the funding must record why it died');
        Event::assertDispatched(FundingFailed::class);
    }

    public function test_paid_attempt_is_never_touched_by_the_sweeper(): void
    {
        [$order, $attempt, $session] = $this->staleCheckout();

        // A late webhook confirmed the payment between TTL and sweep.
        $attempt->update(['payment_status' => PaymentStatus::Paid]);
        $order->update(['payment_status' => PaymentStatus::Paid]);

        (new ExpireStalePaymentSessionsJob)->handle(
            app(PaymentSessionService::class),
            app(OrderService::class),
        );

        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->payment_status);
        $this->assertNotSame(OrderStatus::Cancelled, $order->fresh()->order_status);
    }
}
