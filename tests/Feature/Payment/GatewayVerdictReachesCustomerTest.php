<?php

namespace Tests\Feature\Payment;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\Payment\Support\CustomerFailureCopy;
use App\Domain\PaymentInsights\Services\FailureReasonClassifier;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Wallet\Jobs\ProcessFundingWebhookJob;
use App\Domain\Wallet\Services\WalletFundingService;
use App\Domain\Wallet\Services\WalletService;
use App\Jobs\VerifyPaymentJob;
use App\Models\Admin;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\PaymentWebhook;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The gateway's verdict reaches the customer as a sentence they can act on.
 *
 * A refused mobile money prompt (no balance, over the daily limit, declined
 * on the handset) used to leave the session open: the verification returned
 * false, nothing failed the session, and the customer watched a spinner
 * until the sweeper expired it half an hour later. Even when a session was
 * failed, the status poll dropped the reason, so every page showed its
 * generic line. Now the verdict is graded into a customer-safe code, the
 * session fails with the sentence for it, the poll carries both, and the
 * admin page says whose side the failure was on.
 */
class GatewayVerdictReachesCustomerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.flutterwave.secret_key' => 'FLWSECK_TEST-realish',
            'services.flutterwave.public_key' => 'FLWPUBK_TEST-realish',
        ]);

        SiteSetting::put('features.gateway_flutterwave_enabled', 'on', 'features');

        // A call this test did not fake must fail loudly, never reach the
        // gateway.
        Http::preventStrayRequests();
    }

    /**
     * An order mid-payment on the given session status.
     *
     * @param  array<string, mixed>  $paymentPayload
     * @return array{0: Order, 1: PaymentAttempt, 2: PaymentSession}
     */
    private function orderMidPayment(User $user, string $sessionStatus = 'awaiting_confirmation', array $paymentPayload = []): array
    {
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'payment_status' => PaymentStatus::Pending,
        ]);

        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'flutterwave',
            'payment_method' => 'mobile_money',
            'idempotency_key' => 'verdict_'.Str::random(12),
            'currency' => 'USD',
            'amount' => 25.00,
            'payment_status' => PaymentStatus::Pending,
        ]);

        $session = app(PaymentSessionService::class)->createForOrder($order, $attempt, []);
        $session->forceFill(['status' => $sessionStatus, 'payment_payload' => $paymentPayload])->save();

        return [$order, $attempt, $session->fresh()];
    }

    /**
     * The gateway's answer to a lookup by reference.
     *
     * @param  array<string, mixed>  $extra
     */
    private function gatewayReports(PaymentAttempt $attempt, string $status, ?string $processorResponse, array $extra = []): void
    {
        Http::fake([
            'api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response([
                'status' => 'success',
                'message' => 'Transaction fetched successfully',
                'data' => array_merge([
                    'id' => 555,
                    'tx_ref' => $attempt->idempotency_key,
                    'status' => $status,
                    'processor_response' => $processorResponse,
                    'amount' => 25,
                    'currency' => 'USD',
                    'payment_type' => 'mobilemoneyfranco',
                ], $extra),
            ]),
        ]);
    }

    private function runVerification(PaymentAttempt $attempt): void
    {
        (new VerifyPaymentJob($attempt))->handle(app(PaymentGatewayFactory::class), app(OrderService::class));
    }

    public function test_a_failed_session_carries_a_customer_safe_code_and_the_poll_returns_it(): void
    {
        $user = User::factory()->create();
        [, , $session] = $this->orderMidPayment($user, 'awaiting_customer_action');

        // Still open: the poll says nothing about a failure.
        $this->actingAs($user)
            ->getJson("/api/payment-sessions/{$session->id}/status")
            ->assertOk()
            ->assertJsonPath('payment_payload', null);

        app(PaymentSessionService::class)->failSession($session, 'Card charge could not be processed', [
            'status' => 'failed',
            'failure' => ['provider_code' => 'Insufficient Funds', 'provider_message' => 'Insufficient Funds', 'http_status' => null],
        ]);

        $this->assertSame('insufficient_funds', $session->fresh()->payment_payload['failure_code']);
        $this->assertSame('Card charge could not be processed', $session->fresh()->payment_payload['failure_reason']);

        $this->actingAs($user)
            ->getJson("/api/payment-sessions/{$session->id}/status")
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('payment_payload.failure_code', 'insufficient_funds')
            ->assertJsonPath('payment_payload.failure_reason', 'Card charge could not be processed');
    }

    public function test_a_fraud_verdict_travels_as_a_plain_bank_decline(): void
    {
        $user = User::factory()->create();
        [, , $session] = $this->orderMidPayment($user, 'awaiting_customer_action');

        app(PaymentSessionService::class)->failSession($session, 'Your card was declined.', [
            'failure' => ['provider_code' => 'Suspected Fraud', 'provider_message' => 'Suspected Fraud', 'http_status' => null],
        ]);

        $session->refresh();
        $this->assertSame('bank_declined', $session->payment_payload['failure_code'], 'the customer must never learn what a fraud screen detected');
        $this->assertSame('Suspected Fraud', $session->metadata['failure_details']['provider_message'], 'the operator keeps the truth');
    }

    public function test_the_gateways_refusal_of_a_phone_prompt_ends_the_wait_with_the_reason(): void
    {
        $user = User::factory()->create();
        [$order, $attempt, $session] = $this->orderMidPayment($user);
        $this->gatewayReports($attempt, 'failed', 'Insufficient balance');

        $this->runVerification($attempt);

        $session->refresh();
        $attempt->refresh();

        $this->assertSame('failed', $session->status);
        $this->assertSame('insufficient_funds', $session->payment_payload['failure_code']);
        $this->assertSame(__('checkout.failure_reason_insufficient_funds'), $session->payment_payload['failure_reason']);
        $this->assertSame('Insufficient balance', $session->metadata['failure_details']['provider_message']);
        $this->assertSame('Insufficient balance', $attempt->metadata['gateway_verdict']['processor_response']);
        $this->assertSame(PaymentStatus::Failed, $attempt->payment_status);
        $this->assertNotNull($attempt->failed_at);
        $this->assertSame(PaymentStatus::Failed, $order->fresh()->payment_status);
    }

    public function test_an_unrecognised_mobile_money_verdict_still_tells_the_customer_what_to_check(): void
    {
        $user = User::factory()->create();
        [, $attempt, $session] = $this->orderMidPayment($user);
        $this->gatewayReports($attempt, 'failed', 'ZZ-77');

        $this->runVerification($attempt);

        $session->refresh();
        $this->assertSame('failed', $session->status);
        $this->assertNull($session->payment_payload['failure_code'], 'no sentence of its own: the page shows the reason');
        $this->assertSame(__('checkout.failure_reason_mobile_money_unknown'), $session->payment_payload['failure_reason']);
    }

    public function test_a_pending_verdict_leaves_the_prompt_open(): void
    {
        $user = User::factory()->create();
        [, $attempt, $session] = $this->orderMidPayment($user);
        $this->gatewayReports($attempt, 'pending', 'Transaction in progress');

        $this->runVerification($attempt);

        $this->assertSame('awaiting_confirmation', $session->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $attempt->fresh()->payment_status);
        $this->assertArrayNotHasKey('gateway_verdict', $attempt->fresh()->metadata ?? []);
    }

    /**
     * The inline popup lets a customer try another card under the same
     * reference, so a refused first card must not close the session a second
     * card may still pay.
     */
    public function test_a_popup_session_is_not_ended_by_a_failed_verdict(): void
    {
        $user = User::factory()->create();
        [, $attempt, $session] = $this->orderMidPayment($user, 'awaiting_payment');
        $this->gatewayReports($attempt, 'failed', 'Insufficient Funds', ['payment_type' => 'card']);

        $this->runVerification($attempt);

        $this->assertSame('awaiting_payment', $session->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $attempt->fresh()->payment_status);
    }

    public function test_mobile_money_verdicts_are_graded(): void
    {
        $classifier = app(FailureReasonClassifier::class);
        $grade = fn (string $verdict): string => $classifier->classify('flutterwave', $verdict, 'awaiting_confirmation', [
            'provider_code' => $verdict, 'provider_message' => $verdict, 'http_status' => null,
        ])['code'];

        $this->assertSame('insufficient_funds', $grade('Insufficient balance'));
        $this->assertSame('limit_exceeded', $grade('Transaction limit exceeded'));
        $this->assertSame('limit_exceeded', $grade('Amount exceeds daily limit'));
        $this->assertSame('prompt_declined', $grade('Customer did not approve the request'));
        $this->assertSame('prompt_declined', $grade('Transaction rejected by subscriber'));
        $this->assertSame('prompt_timed_out', $grade('Timed out waiting for customer approval'));
        $this->assertSame('mobile_account_not_found', $grade('Subscriber not registered'));
        $this->assertSame('incorrect_pin', $grade('Wrong PIN'));

        // The transport bucket is untouched for a gateway that hung.
        $this->assertSame('provider_timeout', $grade('Gateway timed out'));
    }

    public function test_the_checkout_ships_the_copy_in_the_customers_language(): void
    {
        $this->withoutVite();
        // The page itself may read rates or coin lists; none of that is under test.
        Http::fake();

        $html = $this->actingAs(User::factory()->create())
            ->withSession(['locale' => 'fr'])
            ->get('/checkout')
            ->assertOk()
            ->getContent();

        // Page-sized haystacks are asserted tersely: a dump of the whole page
        // on failure takes the runner minutes to print.
        $this->assertTrue(str_contains($html, 'failureMessage(payload, fallback)'), 'the failure copy helper is missing');
        $this->assertTrue(str_contains($html, 'this.failureMessage(data.payment_payload,'), 'the poll does not use the helper');
        // The map ships as one @js() expression, so it is matched as one.
        $this->assertTrue(str_contains($html, Js::from(CustomerFailureCopy::map('fr'))->toHtml()), 'the French copy map is missing');
        $this->assertFalse(str_contains($html, 'There was not enough money on the account'), 'English copy reached the French page');
    }

    public function test_the_order_page_tells_a_declined_customer_why(): void
    {
        $this->withoutVite();
        // The page itself may read rates or coin lists; none of that is under test.
        Http::fake();
        $user = User::factory()->create();
        [$order, , $session] = $this->orderMidPayment($user, 'failed', [
            'failure_reason' => 'Mobile money charge could not be initiated.',
            'failure_code' => 'limit_exceeded',
        ]);
        $order->update(['order_status' => OrderStatus::Failed, 'payment_status' => PaymentStatus::Failed]);

        $html = $this->actingAs($user)
            ->get(route('shop.order', $order->order_number))
            ->assertOk()
            ->getContent();

        // A declined order offers the checkout again rather than the inline
        // wizard, so the hero is where the reason has to be.
        $this->assertTrue(str_contains($html, 'data-failure-line'), 'the hero carries no reason line');
        $this->assertTrue(str_contains($html, __('checkout.failure_reason_limit_exceeded')), 'the reason sentence is missing');
    }

    public function test_the_admin_page_says_whose_side_the_failure_was_on(): void
    {
        $this->withoutVite();
        // The page itself may read rates or coin lists; none of that is under test.
        Http::fake();
        $user = User::factory()->create();
        [, $attempt, $session] = $this->orderMidPayment($user);

        app(PaymentSessionService::class)->failSession($session, 'Card charge could not be processed', [
            'failure' => ['provider_code' => 'Insufficient Funds', 'provider_message' => 'Insufficient Funds', 'http_status' => null],
        ]);
        $attempt->update(['payment_status' => PaymentStatus::Failed, 'failed_at' => now()]);

        $admin = Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.transaction', $attempt))
            ->assertOk()
            ->getContent();

        foreach (['data-failure-verdict', 'Why it failed', 'Customer side', 'Insufficient funds (insufficient_funds)', 'Card charge could not be processed', 'awaiting confirmation'] as $needle) {
            $this->assertTrue(str_contains($html, $needle), "the verdict card lacks: {$needle}");
        }
    }

    public function test_a_refused_top_up_webhook_fails_the_funding_with_the_reason(): void
    {
        Mail::fake();
        Notification::fake();

        $user = User::factory()->create();
        $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::USD);

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
            'payment_method' => 'mobile_money',
            'idempotency_key' => 'fund_ref_'.Str::random(12),
            'currency' => 'USD',
            'amount' => 50,
            'payment_status' => PaymentStatus::Pending,
        ]);

        $session = PaymentSession::create([
            'payment_attempt_id' => $attempt->id,
            'provider' => 'flutterwave',
            'session_type' => 'card',
            'status' => 'awaiting_confirmation',
            'client_reference' => 'SESS_'.Str::random(20),
            'amount' => 50.00,
            'currency' => 'USD',
            'display_currency' => 'USD',
            'customer_email' => $user->email,
            'expires_at' => now()->addMinutes(15),
        ]);

        // A refusal arrives as charge.completed, like a success does.
        $webhook = PaymentWebhook::create([
            'gateway' => 'flutterwave',
            'event_type' => 'charge.completed',
            'reference' => $attempt->idempotency_key,
            'payload' => [
                'event' => 'charge.completed',
                'data' => ['id' => 909, 'tx_ref' => $attempt->idempotency_key, 'status' => 'failed', 'processor_response' => 'Transaction limit exceeded'],
            ],
            'processed' => false,
            'processing_status' => 'pending',
            'processing_attempts' => 0,
        ]);

        Http::fake([
            'api.flutterwave.com/v3/transactions/909/verify' => Http::response([
                'status' => 'success',
                'data' => ['id' => 909, 'tx_ref' => $attempt->idempotency_key, 'status' => 'failed', 'processor_response' => 'Transaction limit exceeded', 'amount' => 50, 'currency' => 'USD'],
            ]),
        ]);

        (new ProcessFundingWebhookJob($webhook->id))->handle(app(WalletFundingService::class));

        $this->assertSame(FundingStatus::Failed, $funding->fresh()->status);
        $this->assertSame(__('checkout.failure_reason_limit_exceeded'), $funding->fresh()->failed_reason);
        $this->assertSame('failed', $session->fresh()->status);
        $this->assertSame('limit_exceeded', $session->fresh()->payment_payload['failure_code']);
        $this->assertSame(PaymentStatus::Failed, $attempt->fresh()->payment_status);
        $this->assertSame('completed', $webhook->fresh()->processing_status);
    }
}
