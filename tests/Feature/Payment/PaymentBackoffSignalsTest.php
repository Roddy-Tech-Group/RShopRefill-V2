<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\PaymentInsights\Enums\FailureStage;
use App\Domain\PaymentInsights\Exceptions\PaymentBackoffException;
use App\Domain\PaymentInsights\Services\PaymentBackoffManager;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The payment rate limiter, pinned rule by rule.
 *
 * Two rules. Failed attempts on any rail that brings new money in pause the
 * customer: three in a row for a minute, four or more for five minutes each.
 * Nothing is weighted or excused, because every one of them writes rows and
 * retrying straight away fixes none of them. And five different cards refused
 * as fraud, on a young account that has never paid, lock the device for six
 * hours. Nothing else can lock. Money already in the wallet stays spendable
 * throughout.
 */
class PaymentBackoffSignalsTest extends TestCase
{
    use RefreshDatabase;

    private PaymentBackoffManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK']);

        $this->manager = app(PaymentBackoffManager::class);
        $this->setEnforcement(true);
    }

    private function setEnforcement(bool $on): void
    {
        $key = 'features.payment_backoff_enforce_enabled';
        SiteSetting::put($key, $on ? 'on' : 'off', 'features');
        Cache::forget('site_setting_'.$key);
    }

    /**
     * A payment that ended without the money arriving, on whichever rail.
     */
    private function failedSession(User $user, string $provider = 'flutterwave', string $status = 'failed', array $overrides = []): PaymentSession
    {
        $order = Order::factory()->create(['user_id' => $user->id]);

        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => $provider,
            'idempotency_key' => 'k_'.Str::random(16),
            'currency' => 'USD',
            'amount' => 25.00,
            'payment_status' => 'failed',
        ]);

        return PaymentSession::create(array_merge([
            'payment_attempt_id' => $attempt->id,
            'provider' => $provider,
            'session_type' => 'inline',
            'status' => $status,
            'client_reference' => 'cr_'.Str::random(16),
            'amount' => 25.00,
            'currency' => 'USD',
            'display_currency' => 'USD',
            'customer_email' => $user->email,
            'customer_ip' => '203.0.113.77',
            'device_uuid' => 'device-one',
            'failed_at' => $status === 'failed' ? now()->subSeconds(5) : null,
        ], $overrides));
    }

    /**
     * A card the gateway refused, as the recorder would have written it.
     */
    private function refuse(User $user, string $fingerprint, string $class = 'fraud', array $overrides = []): PaymentDecline
    {
        return PaymentDecline::create(array_merge([
            'user_id' => $user->id,
            'product_line' => 'checkout',
            'gateway' => 'flutterwave',
            'gateway_transaction_id' => 'tx_'.Str::random(12),
            'tx_ref' => 'ref_'.$user->id,
            'payment_method' => 'card',
            'card_fingerprint' => $fingerprint,
            'card_brand' => 'VISA',
            'verdict' => $class === 'fraud' ? 'Payment declined. Suspected Fraud' : 'Insufficient Funds',
            'verdict_class' => $class,
            'reason_code' => $class === 'fraud' ? 'suspected_fraud' : 'insufficient_funds',
            'amount' => 25,
            'currency' => 'USD',
            'customer_ip' => '203.0.113.77',
            'device_uuid' => 'device-one',
            'declined_at' => now()->subSeconds(5),
        ], $overrides));
    }

    private function refuseDifferentFraudCards(User $user, int $count, string $prefix = 'card'): void
    {
        foreach (range(1, $count) as $n) {
            $this->refuse($user, $prefix.'-'.$n);
        }
    }

    /** Move everything this customer did back in time. */
    private function age(User $user, int $seconds): void
    {
        $when = now()->subSeconds($seconds);

        PaymentDecline::where('user_id', $user->id)->update(['declined_at' => $when]);
        PaymentSession::whereHas('paymentAttempt', fn ($query) => $query->where('user_id', $user->id))
            ->where('status', '!=', 'confirmed')
            ->update(['created_at' => $when, 'updated_at' => $when, 'failed_at' => $when]);
    }

    private function backoffFor(?User $user, string $ip = '203.0.113.77', ?string $device = 'device-one', string $line = 'checkout'): array
    {
        return $this->manager->getBackoffStatus($user, $line, $ip, $device);
    }

    // ------------------------------------------------------- the stage itself

    public function test_the_recorded_stage_is_where_the_customer_actually_stopped(): void
    {
        $user = User::factory()->create();
        $service = app(PaymentSessionService::class);

        $order = Order::factory()->create(['user_id' => $user->id]);
        $attempt = PaymentAttempt::create(['order_id' => $order->id, 'user_id' => $user->id, 'gateway' => 'flutterwave', 'idempotency_key' => 'k_'.Str::random(16), 'currency' => 'USD', 'amount' => 25, 'payment_status' => 'pending']);
        $service->failSession($service->createForOrder($order, $attempt, []), 'popup_closed');
        $this->assertSame(FailureStage::Initialization, PaymentOutcome::latest('id')->first()->failure_stage);

        $attempt = PaymentAttempt::create(['order_id' => $order->id, 'user_id' => $user->id, 'gateway' => 'flutterwave', 'idempotency_key' => 'k_'.Str::random(16), 'currency' => 'USD', 'amount' => 25, 'payment_status' => 'pending']);
        $session = $service->createForOrder($order, $attempt, []);
        $session->transitionTo('awaiting_confirmation');
        $service->failSession($session, 'popup_closed');
        $this->assertSame(FailureStage::Confirmation, PaymentOutcome::latest('id')->first()->failure_stage);
    }

    // ------------------------------------------------------ failed attempts

    public function test_two_failed_attempts_do_nothing(): void
    {
        $user = User::factory()->create();

        $this->failedSession($user);
        $this->failedSession($user);

        $status = $this->backoffFor($user);

        $this->assertSame(2, $status['attempts']);
        $this->assertSame(PaymentBackoffManager::STEP_NONE, $status['step']);
        $this->assertFalse($status['is_rate_limited']);
        $this->assertSame(0, PaymentBackoffEvent::count());
    }

    public function test_three_failed_attempts_in_a_row_pause_for_a_minute(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 3) as $_) {
            $this->failedSession($user);
        }

        $status = $this->backoffFor($user);

        $this->assertSame(3, $status['attempts']);
        $this->assertSame(PaymentBackoffManager::STEP_PAUSE, $status['step']);
        $this->assertSame(PaymentBackoffManager::SIGNAL_FAILED_ATTEMPTS, $status['signal']);
        $this->assertTrue($status['is_rate_limited']);
        $this->assertFalse($status['locked']);
        $this->assertLessThanOrEqual(PaymentBackoffManager::PAUSE_SECONDS, $status['wait_seconds']);

        try {
            app(PaymentSessionService::class)->assertNotRateLimited($user, 'checkout');
            $this->fail('Expected a paused customer to be refused.');
        } catch (PaymentBackoffException $e) {
            $this->assertFalse($e->locked);
            $this->assertStringStartsWith('Try again in', $e->customerMessage());
        }
    }

    public function test_the_fourth_failed_attempt_pauses_for_five_minutes(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 4) as $_) {
            $this->failedSession($user);
        }

        $status = $this->backoffFor($user);

        $this->assertSame(PaymentBackoffManager::STEP_HOLD, $status['step']);
        $this->assertGreaterThan(PaymentBackoffManager::PAUSE_SECONDS, $status['wait_seconds']);
        $this->assertLessThanOrEqual(PaymentBackoffManager::HOLD_SECONDS, $status['wait_seconds']);
        $this->assertFalse($status['locked']);
    }

    /**
     * Once past four, every further failure earns another five minutes, counted
     * from that failure. Nothing escalates past it except the lock.
     */
    public function test_every_failure_after_the_fourth_earns_another_five_minutes(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 4) as $_) {
            $this->failedSession($user);
        }
        $this->age($user, PaymentBackoffManager::HOLD_SECONDS + 10);
        $this->assertFalse($this->backoffFor($user)['is_rate_limited'], 'the five minute pause never ended');

        $this->failedSession($user);
        $status = $this->backoffFor($user);

        $this->assertSame(5, $status['attempts']);
        $this->assertSame(PaymentBackoffManager::STEP_HOLD, $status['step']);
        $this->assertTrue($status['is_rate_limited']);
        $this->assertGreaterThan(PaymentBackoffManager::PAUSE_SECONDS, $status['wait_seconds']);
    }

    public function test_a_pause_ends_when_its_time_is_served(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 3) as $_) {
            $this->failedSession($user);
        }
        $this->age($user, PaymentBackoffManager::PAUSE_SECONDS + 2);

        $this->assertFalse($this->backoffFor($user)['is_rate_limited']);
    }

    public function test_attempts_older_than_the_window_are_forgotten(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 6) as $_) {
            $this->failedSession($user);
        }
        $this->age($user, (PaymentBackoffManager::WINDOW_HOURS * 3600) + 60);

        $this->assertSame(0, $this->backoffFor($user)['attempts']);
    }

    /**
     * Card, bank transfer, mobile money and crypto all count the same. A failed
     * attempt is a failed attempt, whatever rail it was on.
     */
    public function test_every_rail_that_brings_new_money_in_counts_the_same(): void
    {
        $user = User::factory()->create();

        $this->failedSession($user, 'flutterwave');
        $this->failedSession($user, 'korapay', 'expired');
        $this->failedSession($user, 'nowpayments', 'expired');

        $status = $this->backoffFor($user);

        $this->assertSame(3, $status['attempts']);
        $this->assertTrue($status['is_rate_limited']);
    }

    public function test_walking_away_counts_as_a_failed_attempt(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 3) as $_) {
            $this->failedSession($user, 'flutterwave', 'cancelled');
        }

        $this->assertTrue($this->backoffFor($user)['is_rate_limited'], 'three closed payment windows did not pause');
    }

    /**
     * Compliance blocks and other faults on our side count too: retrying the
     * same payment straight away does not fix them, it only writes more rows.
     */
    public function test_compliance_blocks_count_as_failed_attempts(): void
    {
        $user = User::factory()->create();
        $service = app(PaymentSessionService::class);

        foreach (range(1, 3) as $_) {
            $order = Order::factory()->create(['user_id' => $user->id]);
            $attempt = PaymentAttempt::create(['order_id' => $order->id, 'user_id' => $user->id, 'gateway' => 'flutterwave', 'idempotency_key' => 'k_'.Str::random(16), 'currency' => 'USD', 'amount' => 25, 'payment_status' => 'pending']);
            $service->failSession($service->createForOrder($order, $attempt, []), 'Payment could not be started', [
                'failure' => ['provider_code' => null, 'provider_message' => 'Transaction failed compliance checks. Please try again later or use a different payment method.', 'http_status' => 400],
            ]);
        }

        $status = $this->backoffFor($user);

        $this->assertSame(3, $status['attempts']);
        $this->assertTrue($status['is_rate_limited']);
    }

    /**
     * Flutterwave lets a customer run several cards inside one popup. Every
     * refused card is its own attempt, so the session does not add one more
     * on top, and a tester who ran ten cards in one window comes back paused.
     */
    public function test_each_refused_card_inside_one_popup_is_its_own_attempt(): void
    {
        $user = User::factory()->create();
        $session = $this->failedSession($user);

        foreach (['a', 'b', 'c'] as $card) {
            $this->refuse($user, 'card-'.$card, 'soft', ['payment_session_id' => $session->id]);
        }

        $this->assertSame(3, $this->backoffFor($user)['attempts']);
    }

    public function test_retrying_the_same_card_counts_every_time(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 4) as $_) {
            $this->refuse($user, 'same-card', 'soft');
        }

        $status = $this->backoffFor($user);

        $this->assertSame(4, $status['attempts']);
        $this->assertSame(1, $status['refused_cards']);
        $this->assertSame(PaymentBackoffManager::STEP_HOLD, $status['step']);
    }

    /**
     * Paying from money already in the wallet cannot test a card, so it is never
     * counted and never held.
     */
    public function test_wallet_balance_payments_are_never_counted(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 6) as $_) {
            $this->failedSession($user, 'wallet');
        }

        $status = $this->backoffFor($user);

        $this->assertSame(0, $status['attempts']);
        $this->assertFalse($status['is_rate_limited']);
    }

    /**
     * A customer who finally pays is never left waiting: the failed attempts
     * start again from zero. The fraud-refused cards they tried on the way are
     * kept, and only time or an admin lift clears those.
     */
    public function test_a_confirmed_payment_clears_the_pauses_but_keeps_the_fraud_cards(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 3) as $_) {
            $this->failedSession($user);
        }
        $this->refuseDifferentFraudCards($user, 3);
        $this->assertTrue($this->backoffFor($user)['is_rate_limited']);

        $this->failedSession($user, 'flutterwave', 'confirmed', ['failed_at' => null, 'confirmed_at' => now()]);

        $status = $this->backoffFor($user);

        $this->assertSame(0, $status['attempts']);
        $this->assertFalse($status['is_rate_limited'], 'a customer who had just paid was still paused');
        $this->assertSame(3, $status['fraud_cards'], 'a payment going through erased the fraud-refused cards');
    }

    public function test_checkout_and_top_up_failures_count_together(): void
    {
        $user = User::factory()->create();

        $this->refuse($user, 'card-a', 'soft', ['product_line' => 'wallet_funding']);
        $this->refuse($user, 'card-b', 'soft', ['product_line' => 'wallet_funding']);
        $this->failedSession($user);

        $this->assertTrue($this->backoffFor($user, line: 'checkout')['is_rate_limited']);
        $this->assertTrue($this->backoffFor($user, line: 'wallet_funding')['is_rate_limited']);
    }

    /**
     * A customer tapping Pay again and again during a pause writes one event,
     * not one per tap: the limiter exists partly to stop rows piling up.
     */
    public function test_a_pause_is_recorded_once_not_once_per_tap(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 3) as $_) {
            $this->failedSession($user);
        }

        foreach (range(1, 5) as $_) {
            $this->backoffFor($user);
        }

        $this->assertSame(1, PaymentBackoffEvent::where('signal', PaymentBackoffManager::SIGNAL_FAILED_ATTEMPTS)->count());
    }

    // ------------------------------------------------------- page load state

    /**
     * A refreshed checkout or top-up page asks what hold is being served, so
     * the countdown starts on load instead of offering Continue to payment and
     * refusing it a moment later.
     */
    public function test_a_page_learns_the_hold_being_served_when_it_loads(): void
    {
        $user = User::factory()->create();
        $service = app(PaymentSessionService::class);

        $this->assertNull($service->currentHold($user, 'checkout'), 'a customer with nothing served was shown a hold');

        $this->refuseDifferentFraudCards($user, 5);
        $this->backoffFor($user);

        request()->attributes->remove('payment_backoff.current_hold.'.$user->id.'.checkout');
        $hold = $service->currentHold($user, 'checkout');

        $this->assertNotNull($hold);
        $this->assertTrue($hold['locked']);
        $this->assertGreaterThan(3600, $hold['wait_seconds']);
        $this->assertStringContainsString('contact support', $hold['message']);
    }

    /**
     * Rendering a page must never count as an attempt or write an event, and
     * shadow mode shows no hold because it holds nobody.
     */
    public function test_reading_the_hold_for_a_page_records_nothing_and_respects_shadow_mode(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 3) as $_) {
            $this->failedSession($user);
        }

        $this->setEnforcement(false);
        $this->assertNull(app(PaymentSessionService::class)->currentHold($user, 'checkout'));
        $this->assertSame(0, PaymentBackoffEvent::count(), 'loading a page wrote a rate-limit event');
    }

    public function test_reading_the_hold_for_a_page_fails_open(): void
    {
        Exceptions::fake();
        $user = User::factory()->create();

        $this->mock(PaymentBackoffManager::class)
            ->shouldReceive('peek')
            ->andThrow(new \RuntimeException('the database went away'));

        $this->assertNull(app(PaymentSessionService::class)->currentHold($user, 'checkout'));
        Exceptions::assertReported(\RuntimeException::class);
    }

    // ------------------------------------------------------------ resilience

    /**
     * The guard sits in front of every checkout and every top-up. A fault in
     * the limiter itself must be reported and let the payment through, never
     * turn into a payment outage.
     */
    public function test_the_guard_fails_open_when_the_limiter_itself_breaks(): void
    {
        Exceptions::fake();
        $user = User::factory()->create();

        $this->mock(PaymentBackoffManager::class)
            ->shouldReceive('getBackoffStatus')
            ->andThrow(new \RuntimeException('the database went away'));

        app(PaymentSessionService::class)->assertNotRateLimited($user, 'checkout');

        Exceptions::assertReported(\RuntimeException::class);
    }

    /**
     * Carrier networks in Cameroon and Nigeria put hundreds of customers behind
     * one address. A signed-in customer is never matched by IP, so strangers
     * who happen to share it never share a count or a lock.
     */
    public function test_strangers_on_one_network_address_never_share_a_count_or_a_lock(): void
    {
        $sharedAddress = '41.202.219.10';

        $stranger = User::factory()->create();
        foreach (range(1, 3) as $_) {
            $this->failedSession($stranger, overrides: ['device_uuid' => null, 'customer_ip' => $sharedAddress]);
        }
        foreach (range(1, 5) as $n) {
            $this->refuse($stranger, 'stranger-card-'.$n, 'fraud', ['device_uuid' => null, 'customer_ip' => $sharedAddress]);
        }
        $this->assertTrue($this->backoffFor($stranger, $sharedAddress, null)['locked'], 'the stranger should be locked');

        $customer = User::factory()->create();
        $status = $this->backoffFor($customer, $sharedAddress, null);

        $this->assertSame(0, $status['attempts'], "a stranger's failures were counted against another customer on the same address");
        $this->assertFalse($status['is_rate_limited']);
        $this->assertFalse($status['locked'], "a stranger's lock held another customer on the same address");
    }

    /**
     * A timestamp slightly in the future, from a gateway clock running ahead,
     * must never stretch a wait past the length the rule gives it.
     */
    public function test_a_future_timestamp_never_stretches_a_pause(): void
    {
        $user = User::factory()->create();

        $this->failedSession($user);
        $this->failedSession($user);
        $this->refuse($user, 'card-from-the-future', 'soft', ['declined_at' => now()->addMinutes(30)]);

        $status = $this->backoffFor($user);

        $this->assertSame(PaymentBackoffManager::STEP_PAUSE, $status['step']);
        $this->assertLessThanOrEqual(PaymentBackoffManager::PAUSE_SECONDS, $status['wait_seconds']);
    }

    // --------------------------------------------------------------- the lock

    /**
     * Users 400 and 428: several different cards, all refused as fraud, within
     * the hour, on a day-old account that had never paid.
     */
    public function test_five_different_fraud_cards_on_a_young_unpaid_account_lock_the_device_for_six_hours(): void
    {
        $user = User::factory()->create();
        $this->refuseDifferentFraudCards($user, 5);

        $status = $this->backoffFor($user);

        $this->assertSame(PaymentBackoffManager::STEP_LOCK, $status['step']);
        $this->assertSame(PaymentBackoffManager::SIGNAL_CARD_TESTING, $status['signal']);
        $this->assertTrue($status['locked']);
        $this->assertGreaterThan(3600, $status['wait_seconds']);
        $this->assertLessThanOrEqual(PaymentBackoffManager::LOCK_SECONDS, $status['wait_seconds']);

        $event = PaymentBackoffEvent::latest('id')->firstOrFail();
        $this->assertSame(PaymentBackoffManager::SIGNAL_CARD_TESTING, $event->signal);
        $this->assertSame('device-one', $event->device_uuid);
        $this->assertSame(5, $event->attempt_count);

        try {
            app(PaymentSessionService::class)->assertNotRateLimited($user, 'checkout');
            $this->fail('Expected the guard to refuse a locked identity.');
        } catch (PaymentBackoffException $e) {
            $this->assertTrue($e->locked);
            $this->assertStringContainsString('contact support', $e->customerMessage());
            $this->assertTrue($e->render(request())->getData(true)['locked']);
            // The real wait travels as a number for the live countdown.
            $this->assertGreaterThan(3600, $e->render(request())->getData(true)['wait_seconds']);
        }
    }

    public function test_four_different_fraud_cards_pause_but_never_lock(): void
    {
        $user = User::factory()->create();
        $this->refuseDifferentFraudCards($user, 4);

        $status = $this->backoffFor($user);

        $this->assertSame(4, $status['fraud_cards']);
        $this->assertSame(PaymentBackoffManager::STEP_HOLD, $status['step']);
        $this->assertFalse($status['locked']);
    }

    /**
     * Twenty failures with no fraud verdict is someone with no balance, not
     * someone testing stolen cards. Paused every time; never locked.
     */
    public function test_failures_without_a_fraud_verdict_never_lock_however_many(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 20) as $n) {
            $this->refuse($user, 'card-'.$n, 'soft');
        }

        $status = $this->backoffFor($user);

        $this->assertSame(20, $status['attempts']);
        $this->assertSame(0, $status['fraud_cards']);
        $this->assertTrue($status['is_rate_limited']);
        $this->assertFalse($status['locked']);
    }

    public function test_one_fraud_card_retried_twenty_times_never_locks(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 20) as $_) {
            $this->refuse($user, 'same-card');
        }

        $status = $this->backoffFor($user);

        $this->assertSame(1, $status['fraud_cards']);
        $this->assertFalse($status['locked']);
    }

    public function test_cardless_failures_never_lock(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 8) as $_) {
            $this->failedSession($user, 'korapay', 'expired');
        }

        $status = $this->backoffFor($user);

        $this->assertTrue($status['is_rate_limited']);
        $this->assertFalse($status['locked']);
    }

    /**
     * No immunity. A tester can get lucky with one card, age an account, or
     * pass identity verification, and five different cards the gateway calls
     * fraud is the behaviour, whoever is doing it. A real customer caught by
     * it contacts support and is lifted.
     */
    public function test_a_customer_who_has_paid_before_is_locked_like_anyone_else(): void
    {
        $user = User::factory()->create();
        $this->failedSession($user, 'flutterwave', 'confirmed', ['failed_at' => null, 'confirmed_at' => now()->subHours(3)]);

        $this->refuseDifferentFraudCards($user, 5);

        $status = $this->backoffFor($user);

        $this->assertSame(PaymentBackoffManager::TIER_TRUSTED, $status['tier']);
        $this->assertTrue($status['locked'], 'one earlier payment bought immunity from the lock');
    }

    public function test_a_verified_identity_is_locked_like_anyone_else(): void
    {
        $user = User::factory()->create(['kyc_status' => 'verified']);
        $this->refuseDifferentFraudCards($user, 5);

        $status = $this->backoffFor($user);

        $this->assertSame(PaymentBackoffManager::TIER_TRUSTED, $status['tier']);
        $this->assertTrue($status['locked'], 'a verified identity was spared the lock');
    }

    public function test_an_old_account_is_locked_like_anyone_else(): void
    {
        $user = User::factory()->create(['created_at' => now()->subYear()]);
        $this->refuseDifferentFraudCards($user, 5);

        $this->assertTrue($this->backoffFor($user)['locked'], 'an aged account was spared the lock');
    }

    /**
     * Spending wallet balance proves nothing about whether a card works. If a
     * wallet purchase wiped the slate, buying something tiny between runs of
     * stolen cards would be a free reset.
     */
    public function test_a_purchase_from_wallet_balance_does_not_wipe_the_count(): void
    {
        $user = User::factory()->create();
        $this->refuseDifferentFraudCards($user, 4);

        $this->failedSession($user, 'wallet', 'confirmed', ['failed_at' => null, 'confirmed_at' => now()]);
        $this->refuse($user, 'card-after-wallet-purchase');

        $status = $this->backoffFor($user);

        $this->assertSame(5, $status['fraud_cards']);
        $this->assertTrue($status['locked'], 'a wallet purchase reset the count between stolen cards');
    }

    /**
     * User 243, from the production replay: four different cards refused as
     * fraud, a lucky card went through, then a fifth was refused inside the
     * same two hours. The payment used to reset the count and they escaped.
     */
    public function test_a_lucky_payment_does_not_save_a_tester_from_the_lock(): void
    {
        $user = User::factory()->create();
        $this->refuseDifferentFraudCards($user, 4);

        $this->failedSession($user, 'flutterwave', 'confirmed', ['failed_at' => null, 'confirmed_at' => now()]);
        $this->refuse($user, 'card-after-lucky-payment', 'fraud', ['declined_at' => now()->addSecond()]);

        $status = $this->backoffFor($user);

        $this->assertSame(5, $status['fraud_cards']);
        $this->assertSame(1, $status['attempts'], 'the payment should still have cleared the earlier failures');
        $this->assertTrue($status['locked'], 'one lucky card bought a fresh start');
    }

    public function test_fraud_cards_still_age_out_of_the_window(): void
    {
        $user = User::factory()->create();
        $this->refuseDifferentFraudCards($user, 4);
        $this->age($user, (PaymentBackoffManager::WINDOW_HOURS * 3600) + 60);

        $this->refuse($user, 'card-much-later');

        $status = $this->backoffFor($user);

        $this->assertSame(1, $status['fraud_cards']);
        $this->assertFalse($status['locked']);
    }

    /**
     * A recovery email invites the customer back without a pause waiting for
     * them, but it is no more a clean slate for stolen cards than a payment is.
     */
    public function test_a_recovery_reset_clears_the_pauses_but_keeps_the_fraud_cards(): void
    {
        $user = User::factory()->create();
        $this->refuseDifferentFraudCards($user, 4);
        $this->assertTrue($this->backoffFor($user)['is_rate_limited']);

        $this->manager->resetBackoff($user, 'checkout', '203.0.113.77', 'device-one');

        $status = $this->backoffFor($user);

        $this->assertSame(0, $status['attempts']);
        $this->assertFalse($status['is_rate_limited']);
        $this->assertSame(4, $status['fraud_cards']);

        $this->refuse($user, 'card-after-recovery', 'fraud', ['declined_at' => now()->addSecond()]);
        $this->assertTrue($this->backoffFor($user)['locked'], 'a recovery link wiped the stolen cards tried before it');
    }

    /**
     * Users 400 and 428 were one phone with two accounts. The lock belongs to
     * the device.
     */
    public function test_the_lock_follows_the_device_to_a_new_account(): void
    {
        $tester = User::factory()->create();
        $this->refuseDifferentFraudCards($tester, 5);
        $this->backoffFor($tester);

        $secondAccount = User::factory()->create();
        $onSameDevice = $this->backoffFor($secondAccount, '198.51.100.9', 'device-one');

        $this->assertTrue($onSameDevice['locked'], 'a new account on the locked device sailed through');

        $bystander = User::factory()->create();
        $this->assertFalse($this->backoffFor($bystander, '203.0.113.77', 'device-two')['is_rate_limited'], 'the lock leaked to a different device on the same IP');
    }

    public function test_a_lock_is_recorded_once_and_reused(): void
    {
        $user = User::factory()->create();
        $this->refuseDifferentFraudCards($user, 5);

        $this->backoffFor($user);
        $this->backoffFor($user);
        $this->backoffFor($user);

        $this->assertSame(1, PaymentBackoffEvent::where('signal', PaymentBackoffManager::SIGNAL_CARD_TESTING)->count());
    }

    public function test_lifting_a_lock_releases_the_customer_and_the_same_cards_cannot_relock(): void
    {
        $user = User::factory()->create();
        $this->refuseDifferentFraudCards($user, 5);
        $this->assertTrue($this->backoffFor($user)['locked']);

        $lock = PaymentBackoffEvent::where('signal', PaymentBackoffManager::SIGNAL_CARD_TESTING)->firstOrFail();
        $this->manager->liftLock($lock, 7);

        $this->assertSame(7, $lock->fresh()->lifted_by);

        $status = $this->backoffFor($user);

        $this->assertFalse($status['is_rate_limited'], 'the lift did not release the customer');
        $this->assertSame(PaymentBackoffManager::STEP_NONE, $status['step']);
        $this->assertSame(1, PaymentBackoffEvent::where('signal', PaymentBackoffManager::SIGNAL_CARD_TESTING)->count());
    }

    public function test_a_lock_reaches_telegram_with_the_timeline_and_a_lift_link(): void
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
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $user = User::factory()->create(['email' => 'tester@example.test']);
        foreach (range(1, 5) as $n) {
            $this->refuse($user, 'card-'.$n, 'fraud', ['card_issuer_country' => $n % 2 === 0 ? 'MY' : 'US']);
        }

        $this->backoffFor($user);

        $messages = Http::recorded(fn (Request $request) => str_contains($request->url(), 'api.telegram.org'))
            ->map(fn (array $pair) => (string) $pair[0]['text'])
            ->values();

        $this->assertCount(1, $messages);
        $message = $messages[0];

        $this->assertStringContainsString('Card testing lock applied', $message);
        $this->assertStringContainsString('5 different cards refused as fraud', $message);
        $this->assertStringContainsString('wallet balance stays spendable', $message);
        $this->assertStringContainsString('/payments/backoff/', $message);
        $this->assertStringContainsString('signature=', $message);

        // Triage from a phone: who, whether they ever paid, each card's country.
        $this->assertStringContainsString('Email: tester@example.test', $message);
        $this->assertStringContainsString('Has paid us before: no', $message);
        $this->assertStringContainsString('Identity verified: no', $message);
        $this->assertStringContainsString('IP: 203.0.113.77', $message);
        $this->assertStringContainsString('VISA US #', $message);
        $this->assertStringContainsString('VISA MY #', $message);
        $this->assertStringContainsString('Runbook: docs/runbooks/fraud-attack.md', $message);
        $this->assertStringNotContainsString('trust band', $message);
        $this->assertFileExists(base_path('docs/runbooks/fraud-attack.md'));
    }

    /**
     * A lock on a customer who has paid us before deserves the fastest look, so
     * the alert says so in plain words.
     */
    public function test_a_lock_alert_says_when_the_customer_has_paid_before(): void
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
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $user = User::factory()->create(['kyc_status' => 'verified']);
        $this->failedSession($user, 'flutterwave', 'confirmed', ['failed_at' => null, 'confirmed_at' => now()->subHours(3)]);
        $this->refuseDifferentFraudCards($user, 5);

        $this->backoffFor($user);

        $message = (string) Http::recorded(fn (Request $request) => str_contains($request->url(), 'api.telegram.org'))->first()[0]['text'];

        $this->assertStringContainsString('Has paid us before: yes', $message);
        $this->assertStringContainsString('Identity verified: yes', $message);
    }

    public function test_a_locked_device_cannot_top_up_by_switching_payment_method(): void
    {
        $user = User::factory()->create();
        $this->refuseDifferentFraudCards($user, 5);
        $this->assertTrue($this->backoffFor($user)['locked']);

        $funding = $this->backoffFor($user, line: 'wallet_funding');

        $this->assertTrue($funding['is_rate_limited']);
        $this->assertTrue($funding['locked']);
    }

    // ---------------------------------------------------------------- guests

    public function test_a_guest_shadow_account_is_treated_as_a_guest(): void
    {
        $guest = User::factory()->create(['is_guest' => true]);

        $this->assertSame(PaymentBackoffManager::TIER_GUEST, $this->backoffFor($guest)['tier']);
    }

    public function test_a_guest_who_has_paid_before_is_trusted(): void
    {
        $guest = User::factory()->create(['is_guest' => true]);
        $this->failedSession($guest, 'flutterwave', 'confirmed', ['failed_at' => null, 'confirmed_at' => now()->subHours(3)]);

        $this->assertSame(PaymentBackoffManager::TIER_TRUSTED, $this->backoffFor($guest)['tier']);
    }

    // ------------------------------------------------------------ the wording

    /**
     * The copy is weighted to the rung. None of it says the payment failed:
     * a customer told their payment failed goes looking for another card,
     * which is exactly the behaviour being throttled.
     */
    public function test_each_rung_speaks_with_its_own_weight(): void
    {
        $short = new PaymentBackoffException(45);
        $this->assertSame('Try again in 45 seconds.', $short->customerMessage());

        $longer = new PaymentBackoffException(295);
        $this->assertSame('Too many attempts, try again in 5 minutes.', $longer->customerMessage());

        // No duration in the sentence: it would be frozen at the moment of
        // refusal, while the notice's live countdown carries the real wait.
        $locked = new PaymentBackoffException(21600, locked: true);
        $this->assertSame(
            'Several cards were declined, so new payments are paused for a while. If you believe this is a mistake, contact support.',
            $locked->customerMessage(),
        );

        foreach ([$short, $longer, $locked] as $case) {
            $this->assertStringNotContainsString('failed', strtolower($case->customerMessage()));
            $this->assertStringNotContainsString('fraud', strtolower($case->customerMessage()));
        }
    }

    // -------------------------------------------------------------- the shadow

    public function test_shadow_mode_records_the_decision_without_blocking(): void
    {
        $this->setEnforcement(false);

        $user = User::factory()->create();
        $this->refuseDifferentFraudCards($user, 5);

        $status = $this->backoffFor($user);

        $this->assertFalse($status['is_rate_limited'], 'shadow mode blocked a customer');
        $this->assertFalse($status['enforced']);
        $this->assertTrue($status['locked'], 'shadow mode measured nothing');

        $event = PaymentBackoffEvent::latest('id')->firstOrFail();
        $this->assertFalse($event->enforced);
        $this->assertSame(PaymentBackoffManager::SIGNAL_CARD_TESTING, $event->signal);

        app(PaymentSessionService::class)->assertNotRateLimited($user, 'checkout');
    }

    public function test_enforcement_is_off_unless_explicitly_enabled(): void
    {
        SiteSetting::query()->where('key', 'features.payment_backoff_enforce_enabled')->delete();
        Cache::forget('site_setting_features.payment_backoff_enforce_enabled');

        $this->assertFalse(app(PaymentBackoffManager::class)->enforcementEnabled());
    }
}
