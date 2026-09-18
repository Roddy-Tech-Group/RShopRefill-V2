<?php

namespace Tests\Feature\Payment;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Jobs\ExpireStalePaymentSessionsJob;
use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\PaymentInsights\Enums\IneligibilityReason;
use App\Domain\PaymentInsights\Jobs\ReconcileRefusedCardsJob;
use App\Domain\PaymentInsights\Services\PaymentBackoffManager;
use App\Domain\PaymentInsights\Services\PaymentInsightsMetrics;
use App\Domain\PaymentInsights\Services\RecoveryEligibilityService;
use App\Domain\PaymentInsights\Services\RefusedCardRecorder;
use App\Domain\PaymentInsights\Support\DeclineVerdict;
use App\Models\Admin;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentBackoffEvent;
use App\Models\PaymentDecline;
use App\Models\PaymentOutcome;
use App\Models\PaymentSession;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Flutterwave sends no webhook for a failed inline charge, lets a customer
 * try several cards under one reference, and our verify call only ever
 * looked for "successful". Six hundred refused cards in three months of
 * production were filed as "customer walked away"; fifty-four of them got a
 * "complete your purchase" email. These tests pin the pull that replaces the
 * missing push: when a popup session ends, ask the gateway, write down every
 * refused card, and file the session as what it was.
 */
class RefusedCardRecordingTest extends TestCase
{
    use RefreshDatabase;

    private const TELEMETRY = '/api/payment-sessions/telemetry/record-failure';

    protected function setUp(): void
    {
        parent::setUp();

        // A real-looking key: with the mock key the recorder never asks.
        config(['services.flutterwave.secret_key' => 'FLWSECK-realish-key']);

        $key = 'features.payment_backoff_enforce_enabled';
        SiteSetting::put($key, 'on', 'features');
        Cache::forget('site_setting_'.$key);
    }

    private function popupSession(User $user, string $status = 'awaiting_payment'): PaymentSession
    {
        $order = Order::factory()->create(['user_id' => $user->id]);

        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'flutterwave',
            'idempotency_key' => 'PAY-'.Str::random(12),
            'currency' => 'USD',
            'amount' => 25.00,
            'payment_status' => 'pending',
        ]);

        $session = app(PaymentSessionService::class)->createForOrder($order, $attempt, []);
        $session->update(['device_uuid' => 'device-one', 'customer_ip' => '203.0.113.5']);

        if ($session->status !== $status) {
            $session->transitionTo($status);
        }

        return $session->fresh();
    }

    /**
     * @param  list<array<string, mixed>>  $charges
     */
    private function gatewaySays(array $charges): void
    {
        Http::fake([
            'api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response(['status' => 'error', 'message' => 'No transaction was found for this id'], 404),
            'api.flutterwave.com/v3/transactions*' => Http::response(['status' => 'success', 'data' => $charges]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function refusedCharge(int $id, string $verdict = 'Payment declined. Suspected Fraud', string $lastFour = '3200', string $type = 'VISA', int $minutesAgo = 3): array
    {
        return [
            'id' => $id,
            'status' => 'failed',
            'processor_response' => $verdict,
            'amount' => 25,
            'currency' => 'USD',
            'payment_type' => 'card',
            'created_at' => now()->subMinutes($minutesAgo)->toIso8601String(),
            'card' => ['first_6digits' => '403283', 'last_4digits' => $lastFour, 'type' => $type, 'issuer' => 'TEST BANK', 'country' => 'US'],
        ];
    }

    private function telegramMessages(): array
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), 'api.telegram.org'))
            ->map(fn (array $pair) => (string) $pair[0]['text'])
            ->values()
            ->all();
    }

    private function enableTelegram(): void
    {
        config([
            'alerting.enabled' => true,
            'alerting.channels.telegram.bot_token' => 'bot-token',
            'alerting.channels.telegram.chat_id' => '-100',
            'alerting.channels.mail.to' => null,
            'alerting.channels.slack.enabled' => false,
            'alerting.routing.sev2' => ['telegram'],
            'alerting.throttle_minutes' => 60,
        ]);
    }

    // ------------------------------------------------------- popup closed

    public function test_a_closed_popup_after_a_refused_card_is_a_decline_not_a_cancellation(): void
    {
        $user = User::factory()->create();
        $session = $this->popupSession($user);
        $this->gatewaySays([$this->refusedCharge(9001)]);

        $this->actingAs($user)
            ->postJson(self::TELEMETRY, ['session_id' => $session->id, 'reason' => 'popup_closed', 'context' => ['provider' => 'flutterwave']])
            ->assertOk()
            ->assertJson(['status' => 'recorded', 'declined' => true]);

        $this->assertSame('failed', $session->fresh()->status);

        $decline = PaymentDecline::firstOrFail();
        $this->assertSame($user->id, $decline->user_id);
        $this->assertSame('9001', $decline->gateway_transaction_id);
        $this->assertSame(DeclineVerdict::CLASS_FRAUD, $decline->verdict_class);
        $this->assertSame('suspected_fraud', $decline->reason_code);
        $this->assertSame(DeclineVerdict::fingerprint('403283', '3200'), $decline->card_fingerprint);
        $this->assertSame('card', $decline->payment_method);
        $this->assertSame('device-one', $decline->device_uuid);

        $outcome = PaymentOutcome::where('payment_session_id', $session->id)->firstOrFail();
        $this->assertSame('failed', $outcome->outcome->value, 'a refused card was still filed as abandonment');
        $this->assertSame('suspected_fraud', $outcome->failure_reason_code);
        $this->assertSame('processing', $outcome->failure_stage->value);
        $this->assertFalse($outcome->retryable);
        $this->assertSame(1, $outcome->metadata['refused_cards']);
        $this->assertStringNotContainsString('Fraud', (string) $session->fresh()->payment_payload['failure_reason'], 'the customer copy must never say fraud');
    }

    /**
     * Bank transfers, mobile money and USSD carry no card, so there is nothing
     * to fingerprint and the gateway's own method name is the only thing that
     * tells them apart later. A rail failing in bulk is almost always our
     * problem, not a customer's, and this is where it becomes visible.
     */
    public function test_a_refused_bank_transfer_records_its_method_and_no_fingerprint(): void
    {
        $user = User::factory()->create();
        $session = $this->popupSession($user);

        $charge = $this->refusedCharge(9100, 'Transaction failed');
        $charge['payment_type'] = 'banktransfer';
        $charge['card'] = [];
        $this->gatewaySays([$charge]);

        $this->actingAs($user)
            ->postJson(self::TELEMETRY, ['session_id' => $session->id, 'reason' => 'popup_closed', 'context' => ['provider' => 'flutterwave']])
            ->assertOk();

        $decline = PaymentDecline::firstOrFail();
        $this->assertSame('banktransfer', $decline->payment_method);
        $this->assertNull($decline->card_fingerprint);
    }

    public function test_every_refused_charge_under_one_reference_is_stored_once(): void
    {
        $user = User::factory()->create();
        $session = $this->popupSession($user);
        $this->gatewaySays([
            $this->refusedCharge(9001, 'Payment declined. Suspected Fraud', '3200', 'VISA', 6),
            $this->refusedCharge(9002, 'Insufficient Funds', '3200', 'VISA', 5),
            $this->refusedCharge(9003, 'Restricted Card: bank restrictions', '5231', 'MASTERCARD', 4),
            ['id' => 9004, 'status' => 'successful', 'processor_response' => 'Approved', 'amount' => 25, 'currency' => 'USD', 'created_at' => now()->toIso8601String(), 'card' => []],
        ]);

        $declines = app(RefusedCardRecorder::class)->reconcile($session);
        $this->assertCount(3, $declines, 'the successful charge must not be recorded as a refusal');

        // Asking again changes nothing.
        app(RefusedCardRecorder::class)->reconcile($session);
        $this->assertSame(3, PaymentDecline::count());

        $status = app(PaymentBackoffManager::class)->peek($user, 'checkout', '203.0.113.5', 'device-one');
        $this->assertSame(2, $status['refused_cards'], 'the same card refused twice is one card');
    }

    public function test_a_popup_with_no_refused_charge_is_still_a_cancellation(): void
    {
        $user = User::factory()->create();
        $session = $this->popupSession($user);
        $this->gatewaySays([]);

        $this->actingAs($user)
            ->postJson(self::TELEMETRY, ['session_id' => $session->id, 'reason' => 'popup_closed'])
            ->assertOk()
            ->assertJsonMissing(['declined' => true]);

        $this->assertSame(0, PaymentDecline::count());
        $outcome = PaymentOutcome::where('payment_session_id', $session->id)->firstOrFail();
        $this->assertSame('user_cancelled', $outcome->failure_reason_code);
    }

    public function test_a_gateway_that_cannot_be_asked_changes_nothing(): void
    {
        $user = User::factory()->create();
        $session = $this->popupSession($user);
        Http::fake(['api.flutterwave.com/*' => Http::response('gateway down', 503)]);

        $this->actingAs($user)
            ->postJson(self::TELEMETRY, ['session_id' => $session->id, 'reason' => 'popup_closed'])
            ->assertOk();

        $this->assertSame('failed', $session->fresh()->status);
        $this->assertSame(0, PaymentDecline::count());
    }

    // ------------------------------------------------------------- cancel

    public function test_cancelling_after_a_refusal_fails_the_session_with_the_verdict(): void
    {
        $user = User::factory()->create();
        $session = $this->popupSession($user);
        $this->gatewaySays([$this->refusedCharge(9010, 'Insufficient Funds')]);

        $this->actingAs($user)
            ->postJson("/api/payment-sessions/{$session->id}/cancel")
            ->assertOk()
            ->assertJson(['status' => 'failed']);

        $this->assertSame('failed', $session->fresh()->status);
        $outcome = PaymentOutcome::where('payment_session_id', $session->id)->firstOrFail();
        $this->assertSame('insufficient_funds', $outcome->failure_reason_code);
        $this->assertTrue($outcome->retryable, 'a soft bank refusal is still worth a retry email');
    }

    // ------------------------------------------------------------ sweeper

    public function test_the_sweeper_files_a_declined_popup_as_declined_not_expired(): void
    {
        $user = User::factory()->create();
        $session = $this->popupSession($user);
        $createdAt = now()->subMinutes(45);
        $session->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt, 'expires_at' => now()->subMinutes(30)])->save();
        $this->gatewaySays([$this->refusedCharge(9020, 'Fraud/Security error, please contact your bank')]);

        (new ExpireStalePaymentSessionsJob)->handle(app(PaymentSessionService::class), app(OrderService::class));

        $this->assertSame('failed', $session->fresh()->status, 'the sweeper filed a refused card as expired');
        $this->assertSame(1, PaymentDecline::count());
        $this->assertSame(PaymentStatus::Failed, $session->paymentAttempt->fresh()->payment_status);
    }

    // ------------------------------------------------------------ confirm

    public function test_a_confirmed_session_queues_a_look_back_at_the_cards_refused_before_it(): void
    {
        Bus::fake([ReconcileRefusedCardsJob::class]);

        $user = User::factory()->create();
        $session = $this->popupSession($user);

        app(PaymentSessionService::class)->confirmSession($session);

        Bus::assertDispatched(ReconcileRefusedCardsJob::class, fn (ReconcileRefusedCardsJob $job) => $job->sessionId === $session->id);
    }

    public function test_a_payment_that_succeeded_after_three_fraud_refusals_raises_an_alert(): void
    {
        $this->enableTelegram();

        $user = User::factory()->create();
        $session = $this->popupSession($user);
        $session->forceFill(['status' => 'confirmed', 'confirmed_at' => now()])->save();
        $this->gatewaySays([
            $this->refusedCharge(9031, 'Payment declined. Suspected Fraud', '1111', 'VISA', 9),
            $this->refusedCharge(9032, 'Payment declined. Suspected Fraud', '2222', 'MASTERCARD', 7),
            $this->refusedCharge(9033, 'Payment declined. Suspected Fraud', '3333', 'VISA', 5),
            ['id' => 9034, 'status' => 'successful', 'processor_response' => 'Approved', 'amount' => 25, 'currency' => 'USD', 'created_at' => now()->toIso8601String(), 'card' => ['first_6digits' => '555555', 'last_4digits' => '4444', 'type' => 'MASTERCARD']],
        ]);

        (new ReconcileRefusedCardsJob($session->id))->handle(app(RefusedCardRecorder::class));

        $this->assertSame(3, PaymentDecline::count());

        $messages = $this->telegramMessages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Payment succeeded after 3 refused cards', $messages[0]);
        $this->assertStringContainsString('chargeback', $messages[0]);
    }

    /**
     * The look-back job records refusals whatever happened, but "paid after
     * refused cards" is only true of a payment that actually confirmed. A job
     * left behind by a rolled-back confirmation must not raise it.
     */
    public function test_the_paid_after_refusals_alert_needs_a_confirmed_payment(): void
    {
        $this->enableTelegram();

        $user = User::factory()->create();
        $session = $this->popupSession($user);
        $this->gatewaySays([
            $this->refusedCharge(9061, 'Payment declined. Suspected Fraud', '1111', 'VISA', 9),
            $this->refusedCharge(9062, 'Payment declined. Suspected Fraud', '2222', 'VISA', 7),
            $this->refusedCharge(9063, 'Payment declined. Suspected Fraud', '3333', 'VISA', 5),
        ]);

        (new ReconcileRefusedCardsJob($session->id))->handle(app(RefusedCardRecorder::class));

        $this->assertSame(3, PaymentDecline::count(), 'the refusals should still be recorded');
        $this->assertCount(0, $this->telegramMessages(), 'an unconfirmed session raised a paid-after-refusals alert');
    }

    // -------------------------------------------------- gateway timestamps

    /**
     * Flutterwave reports in UTC and the app runs on Africa/Douala. An
     * unconverted UTC timestamp was stored an hour early, so a refusal looked
     * an hour old the moment it arrived. The tests used local-time timestamps
     * and never saw it; this one sends exactly what the gateway sends.
     */
    public function test_a_utc_gateway_timestamp_is_stored_as_the_moment_it_really_happened(): void
    {
        $user = User::factory()->create();
        $session = $this->popupSession($user);

        $charges = [];
        foreach ([1, 2, 3] as $n) {
            $charge = $this->refusedCharge(9300 + $n, 'Insufficient Funds', '000'.$n);
            $charge['created_at'] = now()->utc()->subSeconds(20)->format('Y-m-d\TH:i:s.000\Z');
            $charges[] = $charge;
        }
        $this->gatewaySays($charges);

        app(RefusedCardRecorder::class)->reconcile($session);

        $this->assertEqualsWithDelta(
            now()->subSeconds(20)->getTimestamp(),
            PaymentDecline::firstOrFail()->declined_at->getTimestamp(),
            5,
            'a UTC gateway timestamp was stored an hour off',
        );

        // And the rule sees them as the fresh refusals they are.
        $status = app(PaymentBackoffManager::class)->peek($user, 'checkout', '203.0.113.5', 'device-one');
        $this->assertSame(PaymentBackoffManager::STEP_PAUSE, $status['step']);
        $this->assertTrue($status['is_rate_limited'], 'three fresh refusals did not pause because they looked an hour old');
    }

    public function test_a_gateway_timestamp_in_the_future_is_clamped_to_now(): void
    {
        $user = User::factory()->create();
        $session = $this->popupSession($user);

        $charge = $this->refusedCharge(9310);
        $charge['created_at'] = now()->utc()->addMinutes(10)->format('Y-m-d\TH:i:s.000\Z');
        $this->gatewaySays([$charge]);

        app(RefusedCardRecorder::class)->reconcile($session);

        $this->assertTrue(PaymentDecline::firstOrFail()->declined_at->lte(now()->addSecond()));
    }

    /**
     * The listing is paginated, and a tester can run more cards inside one
     * popup than one page holds. Every page is read, up to the cap.
     */
    public function test_every_page_of_the_charge_listing_is_read(): void
    {
        $user = User::factory()->create();
        $session = $this->popupSession($user);

        $pages = [
            1 => array_map(fn (int $n) => $this->refusedCharge(9400 + $n, 'Payment declined. Suspected Fraud', str_pad((string) $n, 4, '0', STR_PAD_LEFT)), range(1, 10)),
            2 => array_map(fn (int $n) => $this->refusedCharge(9400 + $n, 'Payment declined. Suspected Fraud', str_pad((string) $n, 4, '0', STR_PAD_LEFT)), range(11, 12)),
        ];

        Http::fake(function (Request $request) use ($pages) {
            if (str_contains($request->url(), 'verify_by_reference')) {
                return Http::response(['status' => 'error'], 404);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = (int) ($query['page'] ?? 1);

            return Http::response([
                'status' => 'success',
                'meta' => ['page_info' => ['total' => 12, 'current_page' => $page, 'total_pages' => 2]],
                'data' => $pages[$page] ?? [],
            ]);
        });

        app(RefusedCardRecorder::class)->reconcile($session);

        $this->assertSame(12, PaymentDecline::count(), 'refused cards beyond the first page were lost');
    }

    // ------------------------------------------ what never reaches a lock

    /**
     * Unsupported brands, 3-D Secure gaps and compliance blocks are still failed
     * attempts, and retrying them straight away fixes nothing, so they count
     * toward the pauses. They are filed as ignored rather than fraud, so none of
     * them can ever bring a device closer to the six hour lock.
     */
    public function test_unsupported_brands_and_three_d_secure_gaps_pause_but_never_count_toward_a_lock(): void
    {
        $user = User::factory()->create();
        $session = $this->popupSession($user);
        $this->gatewaySays([
            $this->refusedCharge(9041, 'Card verification failed, please contact your bank', '0001', 'AMERICAN EXPRESS', 8),
            $this->refusedCharge(9042, 'Sorry, your card is not enrolled for 3-D Secure Payments', '0002', 'VISA', 7),
            $this->refusedCharge(9043, 'Card verification failed, please contact your bank', '0003', 'DISCOVER', 6),
            $this->refusedCharge(9044, 'Transaction failed compliance checks. Please try again later', '0004', 'VISA', 5),
        ]);

        $declines = app(RefusedCardRecorder::class)->reconcile($session);

        $this->assertCount(4, $declines);
        $this->assertTrue($declines->every(fn (PaymentDecline $row) => $row->verdict_class === DeclineVerdict::CLASS_IGNORED));

        $status = app(PaymentBackoffManager::class)->peek($user, 'checkout', '203.0.113.5', 'device-one');
        $this->assertSame(4, $status['attempts']);
        $this->assertSame(0, $status['fraud_cards']);
        $this->assertSame(PaymentBackoffManager::STEP_HOLD, $status['step']);
        $this->assertFalse($status['locked']);
    }

    // ------------------------------------------------ recovery and dashboard

    /**
     * Three different cards refused as fraud is where we stop inviting the
     * customer back. It holds nobody on its own; it only silences the recovery
     * email and marks the dashboard.
     */
    public function test_recovery_stays_quiet_once_the_identity_reads_as_card_testing(): void
    {
        $user = User::factory()->create();
        $session = $this->popupSession($user);
        $this->gatewaySays([
            $this->refusedCharge(9051, 'Payment declined. Suspected Fraud', '1111', 'VISA', 9),
            $this->refusedCharge(9052, 'Payment declined. Suspected Fraud', '2222', 'VISA', 7),
            $this->refusedCharge(9053, 'Payment declined. Suspected Fraud', '3333', 'VISA', 5),
            $this->refusedCharge(9054, 'Insufficient Funds', '4444', 'VISA', 4),
        ]);

        $this->actingAs($user)
            ->postJson(self::TELEMETRY, ['session_id' => $session->id, 'reason' => 'popup_closed'])
            ->assertOk();

        $outcome = PaymentOutcome::where('payment_session_id', $session->id)->firstOrFail();
        $this->assertTrue($outcome->metadata['suspected_testing']);
        $this->assertSame(4, $outcome->metadata['refused_cards']);

        $verdict = app(RecoveryEligibilityService::class)->evaluate($outcome);
        $this->assertFalse($verdict['eligible']);
        $this->assertSame(IneligibilityReason::SuspectedTesting, $verdict['reason']);

        $recent = app(PaymentInsightsMetrics::class)->outcomes()['recentFailures']->first();
        $this->assertTrue($recent['suspected_testing']);
        $this->assertSame(4, $recent['refused_cards']);
    }

    // ------------------------------------------------------------- admin

    public function test_an_admin_lifts_a_lock_from_the_signed_link(): void
    {
        $user = User::factory()->create();
        foreach (['a', 'b', 'c', 'd', 'e'] as $card) {
            PaymentDecline::create([
                'user_id' => $user->id,
                'product_line' => 'checkout',
                'gateway' => 'flutterwave',
                'gateway_transaction_id' => 'lift-'.$card,
                'tx_ref' => 'ref-'.$user->id,
                'card_fingerprint' => 'card-'.$card,
                'verdict' => 'Payment declined. Suspected Fraud',
                'verdict_class' => DeclineVerdict::CLASS_FRAUD,
                'amount' => 25,
                'currency' => 'USD',
                'device_uuid' => 'device-one',
                'declined_at' => now()->subSeconds(5),
            ]);
        }

        $manager = app(PaymentBackoffManager::class);
        $this->assertTrue($manager->getBackoffStatus($user, 'checkout', '203.0.113.5', 'device-one')['locked']);
        $lock = PaymentBackoffEvent::where('signal', PaymentBackoffManager::SIGNAL_CARD_TESTING)->firstOrFail();

        $admin = Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);

        // An unsigned link is refused outright.
        $this->actingAs($admin, 'admin')->get(route('admin.backoff.lift', $lock))->assertForbidden();
        $this->assertNull($lock->fresh()->lifted_at);

        $signed = URL::temporarySignedRoute('admin.backoff.lift', now()->addHour(), ['event' => $lock->id]);
        $this->actingAs($admin, 'admin')->get($signed)->assertRedirect();

        $this->assertNotNull($lock->fresh()->lifted_at);
        $this->assertSame($admin->id, $lock->fresh()->lifted_by);
        $this->assertFalse($manager->getBackoffStatus($user, 'checkout', '203.0.113.5', 'device-one')['is_rate_limited']);
    }

    public function test_the_signed_link_still_needs_an_admin_session(): void
    {
        $event = PaymentBackoffEvent::create([
            'identifier' => 'user:1',
            'product_line' => 'checkout',
            'signal' => PaymentBackoffManager::SIGNAL_CARD_TESTING,
            'tier' => 'known',
            'decline_count' => 4,
            'wait_seconds' => 21600,
            'enforced' => true,
        ]);

        $signed = URL::temporarySignedRoute('admin.backoff.lift', now()->addHour(), ['event' => $event->id]);

        // Guests never reach the controller: the admin group either sends
        // them to the login page or hides the route entirely.
        $response = $this->get($signed);
        $this->assertContains($response->status(), [302, 403, 404]);
        $this->assertNull($event->fresh()->lifted_at);
    }
}
