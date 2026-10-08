<?php

namespace Tests\Feature\Payment;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Domain\Wallet\Jobs\ProcessFundingWebhookJob;
use App\Domain\Wallet\Services\WalletFundingService;
use App\Jobs\VerifyPaymentJob;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\PaymentWebhook;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Money that arrives after WE closed the payment is still the customer's
 * money. A cancel tapped while the phone prompt was still live, a top-up
 * window our sweeper closed, or a gateway hiccup while we were checking: none
 * of these is the gateway saying no. When the gateway later confirms the
 * payment, it is settled or credited, and a person is told.
 */
class LatePaymentRecoveryTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<int, mixed>> */
    private array $alerts = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.flutterwave.secret_key' => 'FLWSECK_TEST-realish',
            'services.flutterwave.public_key' => 'FLWPUBK_TEST-realish',
        ]);
        SiteSetting::put('features.gateway_flutterwave_enabled', 'on', 'features');
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();

        $this->mock(OperationalAlerter::class, function ($mock): void {
            $mock->shouldReceive('send')->andReturnUsing(function (...$args): bool {
                $this->alerts[] = $args;

                return true;
            });
        });
    }

    /** Flutterwave's answer to a lookup by reference. */
    private function gatewaySays(string $reference, string $status, float $amount, string $currency): void
    {
        Http::fake([
            'api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 777001,
                    'tx_ref' => $reference,
                    'status' => $status,
                    'amount' => $amount,
                    'currency' => $currency,
                    'processor_response' => 'Approved',
                ],
            ]),
        ]);
    }

    /**
     * @return array{0: Order, 1: PaymentAttempt, 2: PaymentSession}
     */
    private function orderMidMobileMoney(User $user): array
    {
        $order = Order::factory()->create(['user_id' => $user->id, 'order_status' => OrderStatus::Pending, 'payment_status' => PaymentStatus::Pending, 'display_currency' => 'XAF', 'total_amount' => 6000]);

        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'flutterwave',
            'payment_method' => 'mobile_money',
            'idempotency_key' => 'late_'.Str::random(12),
            'currency' => 'XAF',
            'amount' => 6000,
            'payment_status' => PaymentStatus::Pending,
        ]);

        $session = app(PaymentSessionService::class)->createForOrder($order, $attempt, []);
        $session->forceFill(['status' => 'awaiting_confirmation'])->save();

        return [$order, $attempt->fresh(), $session->fresh()];
    }

    /**
     * @return array{0: Wallet, 1: WalletFunding, 2: PaymentAttempt, 3: PaymentSession}
     */
    private function fundingMidMobileMoney(User $user, string $status = 'awaiting_confirmation'): array
    {
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => Currency::USD, 'balance' => 10.0]);
        $reference = 'FUND-USD-'.Str::upper(Str::random(6));

        $funding = WalletFunding::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'reference' => $reference,
            'currency' => 'USD',
            'amount' => 50.0,
            'requested_amount' => 50.0,
            'display_currency' => 'USD',
            'gateway' => 'flutterwave',
            'status' => FundingStatus::Pending,
        ]);

        $attempt = PaymentAttempt::create([
            'user_id' => $user->id,
            'payable_type' => WalletFunding::class,
            'payable_id' => $funding->id,
            'gateway' => 'flutterwave',
            'payment_method' => 'card',
            'idempotency_key' => $reference,
            'currency' => 'USD',
            'amount' => 50.0,
            'payment_status' => PaymentStatus::Pending,
        ]);

        $session = app(PaymentSessionService::class)->createForWalletFunding($funding, $attempt, []);
        $session->forceFill(['status' => $status])->save();

        return [$wallet, $funding, $attempt->fresh(), $session->fresh()];
    }

    private function webhookSaying(string $reference, string $status, float $amount, string $currency): PaymentWebhook
    {
        return PaymentWebhook::create([
            'gateway' => 'flutterwave',
            'event_type' => 'charge.completed',
            'reference' => $reference,
            'payload' => ['event' => 'charge.completed', 'data' => ['id' => 777001, 'tx_ref' => $reference, 'amount' => $amount, 'currency' => $currency, 'status' => $status]],
            'processed' => false,
            'processing_status' => 'pending',
        ]);
    }

    private function orderStatus(Order $order): string
    {
        $status = $order->fresh()->order_status;

        return $status instanceof OrderStatus ? $status->value : (string) $status;
    }

    private function runVerify(PaymentAttempt $attempt): void
    {
        (new VerifyPaymentJob($attempt))->handle(app(PaymentGatewayFactory::class), app(OrderService::class));
    }

    private function runWebhook(PaymentWebhook $webhook): void
    {
        try {
            (new ProcessFundingWebhookJob($webhook->id))->handle(app(WalletFundingService::class));
        } catch (\Throwable) {
            // The job rethrows so the queue retries; the state it left is what matters here.
        }
    }

    // ---------------------------------------------------------- A: cancel

    /** A: the customer taps Cancel, then approves the prompt on the phone. The money is real. */
    public function test_an_order_cancelled_while_the_prompt_was_live_is_settled_when_the_gateway_confirms_it(): void
    {
        $user = User::factory()->create();
        [$order, $attempt, $session] = $this->orderMidMobileMoney($user);

        app(PaymentSessionService::class)->cancelSession($session, 'Customer cancelled');
        $this->assertSame(OrderStatus::Cancelled->value, $this->orderStatus($order));

        $this->gatewaySays($attempt->idempotency_key, 'successful', 6000, 'XAF');
        $this->runVerify($attempt->fresh());

        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->payment_status, 'the customer paid; the attempt must say so');
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertNotEmpty($this->alerts, 'an order coming back from Cancelled is worth a human look');
    }

    /** A: a cancel before any prompt was sent stays cancelled; there is nothing to recover. */
    public function test_an_order_cancelled_before_any_charge_stays_cancelled(): void
    {
        $user = User::factory()->create();
        [$order, $attempt, $session] = $this->orderMidMobileMoney($user);
        $session->forceFill(['status' => 'awaiting_method'])->save();

        app(PaymentSessionService::class)->cancelSession($session->fresh(), 'Customer cancelled');

        $this->gatewaySays($attempt->idempotency_key, 'successful', 6000, 'XAF');
        $this->runVerify($attempt->fresh());

        $this->assertSame(PaymentStatus::Failed, $attempt->fresh()->payment_status);
        $this->assertSame(OrderStatus::Cancelled->value, $this->orderStatus($order));
        Http::assertNothingSent();
    }

    /** A: the same for a wallet top-up cancelled mid-prompt. */
    public function test_a_top_up_cancelled_while_the_prompt_was_live_is_credited_when_the_gateway_confirms_it(): void
    {
        $user = User::factory()->create();
        [$wallet, $funding, , $session] = $this->fundingMidMobileMoney($user);

        app(PaymentSessionService::class)->cancelSession($session, 'Customer cancelled');
        $this->assertSame(FundingStatus::Cancelled, $funding->fresh()->status);

        $this->gatewaySays($funding->reference, 'successful', 50.0, 'USD');
        $this->runWebhook($this->webhookSaying($funding->reference, 'successful', 50.0, 'USD'));

        $this->assertSame(FundingStatus::Completed, $funding->fresh()->status);
        $this->assertEqualsWithDelta(60.0, (float) $wallet->fresh()->balance, 0.001, 'the money arrived and must be credited');
        $this->assertNotEmpty($this->alerts);
    }

    // ------------------------------------------------- B: gateway hiccup

    /** B: a gateway outage while we check a top-up is not a refusal; the top-up waits. */
    public function test_a_gateway_outage_during_verification_leaves_the_top_up_pending(): void
    {
        $user = User::factory()->create();
        [$wallet, $funding] = $this->fundingMidMobileMoney($user);

        // One stub sequence: the first lookup meets an outage, the retry meets
        // the gateway back up (a second Http::fake would never be reached).
        Http::fakeSequence('api.flutterwave.com/v3/transactions/verify_by_reference*')
            // Flutterwave reads retry three times, so the outage must answer every try.
            ->push(['status' => 'error', 'message' => 'Service unavailable'], 503)
            ->push(['status' => 'error', 'message' => 'Service unavailable'], 503)
            ->push(['status' => 'error', 'message' => 'Service unavailable'], 503)
            ->push(['status' => 'error', 'message' => 'Service unavailable'], 503)
            ->push(['status' => 'success', 'data' => ['id' => 777001, 'tx_ref' => $funding->reference, 'status' => 'successful', 'amount' => 50.0, 'currency' => 'USD', 'processor_response' => 'Approved']], 200);

        $this->runWebhook($this->webhookSaying($funding->reference, 'successful', 50.0, 'USD'));

        $this->assertSame(FundingStatus::Pending, $funding->fresh()->status, 'an outage must not fail the funding for good');
        $this->assertEqualsWithDelta(10.0, (float) $wallet->fresh()->balance, 0.001);

        // The gateway is back: the next pass credits it.
        $this->runWebhook($this->webhookSaying($funding->reference, 'successful', 50.0, 'USD'));

        $this->assertSame(FundingStatus::Completed, $funding->fresh()->status);
        $this->assertEqualsWithDelta(60.0, (float) $wallet->fresh()->balance, 0.001);
    }

    /** B: the gateway's own "failed" still fails it, as before. */
    public function test_a_gateway_refusal_still_fails_the_top_up(): void
    {
        $user = User::factory()->create();
        [$wallet, $funding] = $this->fundingMidMobileMoney($user);

        $this->gatewaySays($funding->reference, 'failed', 50.0, 'USD');
        $this->runWebhook($this->webhookSaying($funding->reference, 'successful', 50.0, 'USD'));

        $this->assertSame(FundingStatus::Failed, $funding->fresh()->status);
        $this->assertEqualsWithDelta(10.0, (float) $wallet->fresh()->balance, 0.001);
    }

    // --------------------------------------------------- G: late top-up

    /** G: a top-up paid after our sweeper closed its window is still credited. */
    public function test_a_top_up_paid_after_its_window_closed_is_credited_when_the_gateway_confirms_it(): void
    {
        $user = User::factory()->create();
        [$wallet, $funding] = $this->fundingMidMobileMoney($user);

        app(WalletFundingService::class)->failFunding($funding, 'session_expired');
        $this->assertSame(FundingStatus::Failed, $funding->fresh()->status);

        $this->gatewaySays($funding->reference, 'successful', 50.0, 'USD');
        $this->runWebhook($this->webhookSaying($funding->reference, 'successful', 50.0, 'USD'));

        $this->assertSame(FundingStatus::Completed, $funding->fresh()->status);
        $this->assertEqualsWithDelta(60.0, (float) $wallet->fresh()->balance, 0.001);
        $this->assertNotEmpty($this->alerts, 'a late top-up is worth a human look');
    }

    /** G: a top-up the GATEWAY refused is final; a replayed success webhook cannot revive it. */
    public function test_a_top_up_the_gateway_refused_is_not_revived_by_a_replayed_webhook(): void
    {
        $user = User::factory()->create();
        [$wallet, $funding] = $this->fundingMidMobileMoney($user);

        $this->gatewaySays($funding->reference, 'failed', 50.0, 'USD');
        $this->runWebhook($this->webhookSaying($funding->reference, 'successful', 50.0, 'USD'));
        $this->assertSame(FundingStatus::Failed, $funding->fresh()->status);

        // Even if the lookup now claimed success, the gateway's own verdict stands.
        $this->gatewaySays($funding->reference, 'successful', 50.0, 'USD');
        $this->runWebhook($this->webhookSaying($funding->reference, 'successful', 50.0, 'USD'));

        $this->assertSame(FundingStatus::Failed, $funding->fresh()->status);
        $this->assertEqualsWithDelta(10.0, (float) $wallet->fresh()->balance, 0.001);
    }
}
